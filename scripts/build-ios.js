/**
 * build-ios.js
 * Isolated iOS project generation, compilation, IPA export and optional
 * TestFlight upload for Bizgrowth360.
 *
 * Compilation requires macOS + Xcode. On other hosts only a dry run that
 * generates and validates the project is possible; the script will never claim
 * an IPA exists unless xcodebuild actually exported one.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');
const plist = require('plist');

const {
  loadConfiguration,
  loadDesign,
  loadFeatures,
  copyTemplate,
  parseArgs: parseSharedArgs,
  downloadAsset
} = require('./build-app');
const { validateConfig, buildArtifactBaseName } = require('./validate-config');
const { applyDesignToConfig, designSummary } = require('./design-config');
const { featuresSummary } = require('./native-features-config');
const { validateIosConfig } = require('./ios-config');
const { prepareIosApp } = require('./prepare-ios');
const { validateIpa, computeSha256 } = require('./validate-ipa');
const { validateImageAsset } = require('./generate-icons');

const ROOT_DIR = path.resolve(__dirname, '..');
const TEMPLATE_DIR = path.join(ROOT_DIR, 'app-template');

function envValue(name) {
  const value = process.env[name];
  return value === undefined || value === null ? '' : String(value).trim();
}

function parseIosArgs(argv) {
  const options = parseSharedArgs(argv);
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--mode' && argv[i + 1]) options.mode = argv[++i];
    else if (argv[i] === '--export-method' && argv[i + 1]) options.exportMethod = argv[++i];
    else if (argv[i] === '--team-id' && argv[i + 1]) options.teamId = argv[++i];
    else if (argv[i] === '--bundle-id' && argv[i + 1]) options.bundleId = argv[++i];
    else if (argv[i] === '--upload-testflight') options.uploadToTestflight = true;
    else if (argv[i] === '--confirm-upload') options.confirmAppStoreUpload = true;
  }
  return options;
}

function makeBuildId(provided) {
  const suffix = crypto.randomBytes(3).toString('hex');
  const base = provided
    ? String(provided).toLowerCase().replace(/[^a-z0-9_-]/g, '-').replace(/^-+|-+$/g, '').slice(0, 60)
    : new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14);
  return `${base || 'ios-build'}-${suffix}`;
}

function linkNodeModules(projectDir) {
  const source = path.join(TEMPLATE_DIR, 'node_modules');
  const target = path.join(projectDir, 'node_modules');
  if (!fs.existsSync(source)) {
    throw new Error('app-template/node_modules is missing. Run npm install in app-template.');
  }
  if (fs.existsSync(target)) return;
  fs.symlinkSync(source, target, process.platform === 'win32' ? 'junction' : 'dir');
}

function runProcess(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const logStream = options.logStream;
    const child = spawn(command, args, {
      cwd: options.cwd,
      env: { ...process.env, ...(options.env || {}) },
      windowsHide: true
    });

    let output = '';
    const forward = chunk => {
      const text = chunk.toString();
      output += text;
      if (logStream) logStream.write(text);
      if (!options.quiet) process.stdout.write(text);
    };

    child.stdout.on('data', forward);
    child.stderr.on('data', forward);
    child.on('error', err => reject(new Error(`Failed to start ${command}: ${err.message}`)));
    child.on('close', code => {
      if (code === 0) resolve(output);
      else reject(new Error(`${command} exited with code ${code}`));
    });
  });
}

function buildSummaryMarkdown(summary) {
  const lines = [];
  lines.push('## Bizgrowth360 iOS Build Summary');
  lines.push('');
  lines.push('| Property | Value |');
  lines.push('| :--- | :--- |');
  lines.push(`| **Status** | **${String(summary.status || 'unknown').toUpperCase()}** |`);
  lines.push(`| **Build ID** | \`${summary.buildId}\` |`);
  lines.push(`| **App Name** | \`${summary.config ? summary.config.app_name : '—'}\` |`);
  lines.push(`| **Bundle ID** | \`${summary.ios ? summary.ios.bundle_id : '—'}\` |`);
  lines.push(`| **Version** | \`${summary.config ? `${summary.config.version_name} (${summary.config.version_code})` : '—'}\` |`);
  lines.push(`| **Mode** | \`${summary.mode}\` |`);
  lines.push(`| **Export Method** | \`${summary.ios ? summary.ios.export_method : '—'}\` |`);
  lines.push(`| **Signing** | ${summary.signing ? summary.signing.status : '—'} |`);
  lines.push(`| **IPA** | ${summary.artifact ? 'Generated' : 'Not generated'} |`);
  lines.push(`| **TestFlight Upload** | ${summary.testflight ? summary.testflight.status : 'Not requested'} |`);
  lines.push('');

  if (summary.error) {
    lines.push('### Error');
    lines.push('');
    lines.push('```');
    lines.push(String(summary.error).slice(0, 4000));
    lines.push('```');
    lines.push('');
  }

  lines.push('### Validation');
  lines.push('');
  lines.push('| Check | Result | Detail |');
  lines.push('| :--- | :--- | :--- |');
  for (const check of summary.checks || []) {
    lines.push(`| ${check.name} | ${check.passed ? 'PASS' : 'FAIL'} | ${String(check.detail || '').replace(/\|/g, '\\|')} |`);
  }

  if (summary.artifact) {
    lines.push('');
    lines.push('### IPA Artifact');
    lines.push('');
    lines.push('| File | Size | SHA-256 |');
    lines.push('| :--- | :--- | :--- |');
    lines.push(`| \`${summary.artifact.fileName}\` | ${(summary.artifact.sizeBytes / 1024 / 1024).toFixed(2)} MB | \`${summary.artifact.sha256}\` |`);
  }

  lines.push('');
  lines.push('> iOS builds use macOS GitHub-hosted runners and consume GitHub Actions macOS minutes.');
  return lines.join('\n');
}

async function ensureCocoaPodsWorkspace(projectDir, logStream) {
  const iosAppDir = path.join(projectDir, 'ios', 'App');
  let workspace = path.join(iosAppDir, 'App.xcworkspace');

  if (fs.existsSync(workspace)) return workspace;

  try {
    await runProcess('pod', ['install'], { cwd: iosAppDir, logStream });
  } catch (err) {
    throw new Error(`CocoaPods is required to build the iOS workspace, and "pod install" failed: ${err.message}`);
  }

  workspace = path.join(iosAppDir, 'App.xcworkspace');
  if (!fs.existsSync(workspace)) {
    throw new Error('CocoaPods did not generate App.xcworkspace.');
  }
  return workspace;
}

async function buildIos(options = {}) {
  const started = new Date();
  const buildId = makeBuildId(options.buildId || envValue('BUILD_ID'));
  const workspaceRoot = options.workspaceRoot
    ? path.resolve(ROOT_DIR, options.workspaceRoot)
    : path.join(os.tmpdir(), 'bizgrowth360-ios-builds');
  const workspaceDir = path.join(workspaceRoot, buildId);
  const projectDir = path.join(workspaceDir, 'app-template');
  const outputDir = options.outputDir
    ? path.resolve(ROOT_DIR, options.outputDir)
    : path.join(ROOT_DIR, 'dist', 'ios-builds', buildId);

  fs.mkdirSync(outputDir, { recursive: true });
  const logStream = fs.createWriteStream(path.join(outputDir, 'build.log'), { flags: 'a' });
  const mode = (options.mode || envValue('IOS_MODE') || 'ipa').toLowerCase() === 'validation' ? 'validation' : 'ipa';
  let summary = null;

  try {
    const rawConfig = loadConfiguration(options);
    const validation = validateConfig(rawConfig);
    if (!validation.valid) {
      throw new Error(`Configuration validation failed:\n  - ${validation.errors.join('\n  - ')}`);
    }

    const designResult = loadDesign(options, rawConfig);
    const features = loadFeatures(options, rawConfig);
    const design = designResult.design;
    const effective = applyDesignToConfig(validation.normalizedConfig, design);

    const iosResult = validateIosConfig(rawConfig, {
      bundleId: options.bundleId || envValue('IOS_BUNDLE_ID'),
      exportMethod: options.exportMethod || envValue('IOS_EXPORT_METHOD'),
      teamId: options.teamId || envValue('IOS_TEAM_ID'),
      uploadToTestflight: options.uploadToTestflight || envValue('UPLOAD_TO_TESTFLIGHT') === 'true',
      confirmAppStoreUpload: options.confirmAppStoreUpload || envValue('CONFIRM_APP_STORE_UPLOAD') === 'true',
      mode
    });
    if (!iosResult.valid) {
      throw new Error(`iOS configuration failed validation:\n  - ${iosResult.errors.join('\n  - ')}`);
    }
    const ios = iosResult.ios;

    summary = {
      schemaVersion: 1,
      platform: 'ios',
      buildId,
      status: 'running',
      startedAt: started.toISOString(),
      finishedAt: null,
      mode,
      config: {
        app_name: effective.app_name,
        bundle_id: ios.bundle_id,
        website_url: effective.website_url,
        version_name: effective.version_name,
        version_code: effective.version_code,
        orientation: effective.orientation,
        primary_color: design.theme.primary_color,
        background_color: design.theme.background_color
      },
      ios,
      design: designSummary(design),
      features: featuresSummary(features),
      signing: {
        status: mode === 'validation'
          ? 'Unsigned simulator validation (no IPA signing)'
          : (envValue('IOS_PROFILE_NAME') ? 'Manual signing profile prepared' : 'Signed IPA requires GitHub iOS signing secrets')
      },
      testflight: { status: 'Not requested' },
      checks: [],
      warnings: iosResult.warnings,
      artifact: null,
      outputDir
    };

    const addCheck = (name, passed, detail) => summary.checks.push({ name, passed: !!passed, detail: detail || '' });

    addCheck('Configuration schema', true, 'App, design and native features validated.');
    addCheck('iOS Bundle Identifier', true, ios.bundle_id);
    addCheck('iOS version and build number', true, `${effective.version_name} (${effective.version_code})`);
    addCheck('iOS export method', true, ios.export_method);

    if (fs.existsSync(workspaceDir)) fs.rmSync(workspaceDir, { recursive: true, force: true });
    fs.mkdirSync(workspaceDir, { recursive: true });
    copyTemplate(projectDir);
    linkNodeModules(projectDir);
    addCheck('Isolated iOS workspace', true, 'Template copied; shared template untouched.');

    let iconPath = options.iconPath || null;
    let splashPath = options.splashPath || null;
    const downloadsDir = path.join(workspaceDir, 'downloaded-assets');
    if (!iconPath && effective.icon_url) {
      fs.mkdirSync(downloadsDir, { recursive: true });
      iconPath = await downloadAsset(effective.icon_url, downloadsDir, 'icon');
      const check = await validateImageAsset(iconPath, { kind: 'icon', minWidth: 192, minHeight: 192, maxDimension: 4096 });
      if (!check.valid) throw new Error(`Downloaded iOS icon failed validation:\n  - ${check.errors.join('\n  - ')}`);
      addCheck('Custom icon download', true, `${check.metadata.width}x${check.metadata.height} ${check.metadata.format}`);
    }
    if (!splashPath && effective.splash_url) {
      fs.mkdirSync(downloadsDir, { recursive: true });
      splashPath = await downloadAsset(effective.splash_url, downloadsDir, 'splash');
      const check = await validateImageAsset(splashPath, { kind: 'splash', minWidth: 320, minHeight: 320, maxDimension: 8192 });
      if (!check.valid) throw new Error(`Downloaded iOS splash failed validation:\n  - ${check.errors.join('\n  - ')}`);
      addCheck('Custom splash download', true, `${check.metadata.width}x${check.metadata.height} ${check.metadata.format}`);
    }

    const prepared = await prepareIosApp({
      projectDir,
      config: {
        ...effective,
        ios_bundle_id: ios.bundle_id,
        ios_export_method: ios.export_method,
        ios_team_id: ios.team_id
      },
      design,
      features,
      mode,
      exportMethod: ios.export_method,
      teamId: ios.team_id,
      iconPath,
      splashPath
    });

    summary.assets = prepared.assets;
    addCheck('iOS project generation', true, 'Info.plist, entitlements, bundle ID, version, orientation, icons and splash applied.');
    addCheck('iOS icon generation', true, `${prepared.assets.icon.source} icon, 1024x1024 opaque App Store icon.`);
    addCheck('iOS splash generation', true, `${prepared.assets.splash.source} splash, 2732x2732 launch assets.`);

    if (options.dryRun) {
      summary.status = 'success';
      addCheck('Dry run', true, 'Project generated; Xcode/CocoaPods/IPA build skipped.');
      summary.finishedAt = new Date().toISOString();
      return summary;
    }

    if (process.platform !== 'darwin') {
      throw new Error('iOS compilation requires macOS and Xcode. Use the ios-build.yml macOS workflow or a Mac. No IPA was produced on this host.');
    }

    const cli = path.join(projectDir, 'node_modules', '@capacitor', 'cli', 'bin', 'capacitor');
    await runProcess(process.execPath, [cli, 'sync', 'ios'], { cwd: projectDir, logStream });
    addCheck('Capacitor iOS sync', true, 'Web assets and native plugins synced.');

    const workspace = await ensureCocoaPodsWorkspace(projectDir, logStream);
    addCheck('CocoaPods workspace', true, 'App.xcworkspace is available.');
    const archivePath = path.join(workspaceDir, 'App.xcarchive');
    const derivedData = path.join(workspaceDir, 'DerivedData');

    const profileName = envValue('IOS_PROFILE_NAME');
    const teamId = ios.team_id || envValue('IOS_TEAM_ID');
    const keychain = envValue('IOS_KEYCHAIN_PATH');

    if (mode === 'validation' || !profileName || !teamId) {
      if (!profileName || !teamId) {
        console.warn('[WARN] Apple signing secrets not configured in environment. Compiling for iOS Simulator without signing to validate build readiness.');
        addCheck('Signing configuration', false, 'Signed IPA requires Apple signing secrets in GitHub repository. Compiling for iOS Simulator.');
      }

      await runProcess('xcodebuild', [
        '-workspace', workspace,
        '-scheme', 'App',
        '-configuration', 'Debug',
        '-sdk', 'iphonesimulator',
        '-destination', 'generic/platform=iOS Simulator',
        '-derivedDataPath', derivedData,
        'CODE_SIGNING_ALLOWED=NO',
        'CODE_SIGNING_REQUIRED=NO',
        'build'
      ], { logStream });

      summary.status = 'success';
      addCheck('Xcode simulator compilation', true, 'Compiled for the iOS Simulator without signing.');
      addCheck('IPA export', true, (!profileName || !teamId) ? 'Skipped: configure Apple signing secrets in GitHub for signed IPA export.' : 'Skipped in validation mode by design; no IPA is claimed.');
      summary.finishedAt = new Date().toISOString();
      return summary;
    }

    const identity = ios.export_method === 'development' ? 'Apple Development' : 'Apple Distribution';
    const signArgs = [
      `DEVELOPMENT_TEAM=${teamId}`,
      'CODE_SIGN_STYLE=Manual',
      `CODE_SIGN_IDENTITY=${identity}`,
      `PROVISIONING_PROFILE_SPECIFIER=${profileName}`
    ];
    if (keychain) signArgs.push(`OTHER_CODE_SIGN_FLAGS=--keychain=${keychain}`);

    await runProcess('xcodebuild', [
      '-workspace', workspace,
      '-scheme', 'App',
      '-configuration', 'Release',
      '-destination', 'generic/platform=iOS',
      '-archivePath', archivePath,
      ...signArgs,
      'archive'
    ], { logStream });
    addCheck('Xcode archive', true, 'Release archive created with manual signing.');

    const exportDir = path.join(workspaceDir, 'export');
    fs.mkdirSync(exportDir, { recursive: true });
    const exportPlist = path.join(workspaceDir, 'ExportOptions.plist');
    const exportOptions = {
      method: ios.export_method,
      signingStyle: 'manual',
      teamID: teamId,
      provisioningProfiles: { [ios.bundle_id]: profileName },
      compileBitcode: false,
      uploadSymbols: true,
      stripSwiftSymbols: true
    };
    fs.writeFileSync(exportPlist, plist.build(exportOptions), 'utf8');

    await runProcess('xcodebuild', [
      '-exportArchive',
      '-archivePath', archivePath,
      '-exportPath', exportDir,
      '-exportOptionsPlist', exportPlist
    ], { logStream });

    const ipaFiles = fs.readdirSync(exportDir).filter(file => file.endsWith('.ipa'));
    if (!ipaFiles.length) {
      throw new Error('xcodebuild export completed without an IPA file.');
    }

    const sourceIpa = path.join(exportDir, ipaFiles[0]);
    const ipaDir = path.join(outputDir, 'ipa');
    fs.mkdirSync(ipaDir, { recursive: true });
    const artifactBase = buildArtifactBaseName(effective.app_name, effective.version_name);
    const fileName = `${artifactBase}-${ios.export_method}.ipa`;
    const destination = path.join(ipaDir, fileName);
    fs.copyFileSync(sourceIpa, destination);

    const ipaValidation = validateIpa(destination, {
      bundleId: ios.bundle_id,
      versionName: effective.version_name,
      buildNumber: effective.version_code,
      appName: effective.app_name
    });
    addCheck(
      'IPA package validation',
      ipaValidation.valid,
      ipaValidation.valid ? 'IPA structure, Payload and code signature verified.' : ipaValidation.errors.join('; ')
    );
    if (!ipaValidation.valid) {
      throw new Error(`IPA validation failed:\n  - ${ipaValidation.errors.join('\n  - ')}`);
    }

    summary.artifact = {
      artifactName: `${artifactBase}-IPA`,
      fileName,
      path: destination,
      sizeBytes: fs.statSync(destination).size,
      sha256: computeSha256(destination),
      validation: ipaValidation.metadata
    };
    summary.signing.status = `Signed IPA (${ios.export_method}, ${identity})`;

    if (ios.upload_to_testflight && ios.confirm_app_store_upload) {
      const keyId = envValue('APPSTORE_CONNECT_KEY_ID');
      const issuer = envValue('APPSTORE_CONNECT_ISSUER_ID');
      const keyPath = envValue('ASC_KEY_PATH');

      if (!keyId || !issuer || !keyPath || !fs.existsSync(keyPath)) {
        throw new Error('TestFlight upload was requested but the App Store Connect API key, key ID or issuer ID is missing. The IPA was built but not uploaded.');
      }

      await runProcess('xcrun', [
        'altool', '--validate-app', '-f', destination, '-t', 'ios',
        '--apiKey', keyId, '--apiIssuer', issuer
      ], { logStream });
      addCheck('App Store Connect validation', true, 'altool validation completed.');

      await runProcess('xcrun', [
        'altool', '--upload-app', '-f', destination, '-t', 'ios',
        '--apiKey', keyId, '--apiIssuer', issuer
      ], { logStream });

      summary.testflight = {
        status: 'Uploaded to TestFlight',
        keyId,
        issuer
      };
      addCheck('TestFlight upload', true, 'Build uploaded to App Store Connect / TestFlight. No App Store release was submitted.');
    } else {
      summary.testflight = {
        status: ios.upload_to_testflight
          ? 'Skipped (explicit confirmation required)'
          : 'Not requested'
      };
    }

    summary.status = 'success';
    summary.finishedAt = new Date().toISOString();
    return summary;
  } catch (err) {
    if (!summary) {
      summary = {
        schemaVersion: 1,
        platform: 'ios',
        buildId,
        status: 'failed',
        startedAt: started.toISOString(),
        mode,
        checks: [],
        artifact: null,
        error: err.message
      };
    }
    summary.status = 'failed';
    summary.error = err.message;
    summary.finishedAt = new Date().toISOString();
    summary.checks.push({ name: 'Build failure', passed: false, detail: err.message });
    console.error(`\n[IOS BUILD FAILED] ${err.message}`);
    return summary;
  } finally {
    if (summary) {
      try {
        const markdown = buildSummaryMarkdown(summary);
        fs.writeFileSync(path.join(outputDir, 'build-summary.json'), JSON.stringify(summary, null, 2));
        fs.writeFileSync(path.join(outputDir, 'build-summary.md'), markdown);

        if (process.env.GITHUB_STEP_SUMMARY) {
          fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, markdown);
        }
        if (process.env.GITHUB_OUTPUT) {
          fs.appendFileSync(process.env.GITHUB_OUTPUT, `ios_build_id=${buildId}\n`);
          fs.appendFileSync(process.env.GITHUB_OUTPUT, `ios_build_status=${summary.status}\n`);
          fs.appendFileSync(process.env.GITHUB_OUTPUT, `ios_output_dir=${outputDir}\n`);
          if (summary.artifact) {
            fs.appendFileSync(process.env.GITHUB_OUTPUT, `ipa_dir=${path.dirname(summary.artifact.path)}\n`);
            fs.appendFileSync(process.env.GITHUB_OUTPUT, `ipa_artifact_name=${summary.artifact.artifactName}\n`);
          }
        }
      } catch (summaryErr) {
        console.warn(`[WARN] Could not write iOS summary: ${summaryErr.message}`);
      }
    }

    logStream.end();

    if (!options.keepWorkspace) {
      try {
        if (fs.existsSync(workspaceDir)) {
          fs.rmSync(workspaceDir, { recursive: true, force: true });
        }
      } catch (cleanupErr) {
        console.warn(`[WARN] Could not clean iOS workspace: ${cleanupErr.message}`);
      }
    }
  }
}

if (require.main === module) {
  const options = parseIosArgs(process.argv.slice(2));
  buildIos(options)
    .then(summary => process.exit(summary && summary.status === 'success' ? 0 : 1))
    .catch(err => {
      console.error(`[FATAL] ${err.message}`);
      process.exit(1);
    });
}

module.exports = { buildIos, parseIosArgs };
