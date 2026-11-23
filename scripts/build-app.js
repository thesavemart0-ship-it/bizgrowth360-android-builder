/**
 * build-app.js
 * Isolated, dynamic Android build orchestrator for Bizgrowth360.
 *
 * Responsibilities:
 *   1. Load + validate a build configuration (file, inline JSON and/or env).
 *   2. Download and validate optional icon/splash assets (HTTPS only).
 *   3. Copy the reusable Capacitor template into a unique workspace.
 *   4. Apply branding, generate resources and run Capacitor sync.
 *   5. Build APK / AAB / both from the isolated workspace.
 *   6. Verify the resulting packages and write dynamic artifacts + summary.
 *   7. Remove temporary files (unless --keep-workspace is used).
 *
 * The script never interpolates customer input into a shell command; all
 * subprocesses are spawned with argument arrays.
 */

const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const { spawn } = require('child_process');

const {
  validateConfig,
  buildArtifactBaseName,
  sanitizeFileComponent,
  escapeAndroidString,
  isDisallowedAssetHost
} = require('./validate-config');
const { prepareApp } = require('./prepare-app');
const { generateIcons, validateImageAsset } = require('./generate-icons');
const { validateApk, validateAab, computeSha256 } = require('./validate-output');

const ROOT_DIR = path.resolve(__dirname, '..');
const TEMPLATE_DIR = path.join(ROOT_DIR, 'app-template');

const REQUIRED_TEMPLATE_FILES = [
  'package.json',
  'capacitor.config.json',
  'capacitor.config.ts',
  'public/index.html',
  'public/app.js',
  'public/app-config.js',
  'android/settings.gradle',
  'android/build.gradle',
  'android/variables.gradle',
  'android/gradlew',
  'android/gradlew.bat',
  'android/gradle/wrapper/gradle-wrapper.jar',
  'android/gradle/wrapper/gradle-wrapper.properties',
  'android/app/build.gradle',
  'android/app/src/main/AndroidManifest.xml',
  'android/app/src/main/res/values/strings.xml',
  'android/app/src/main/res/values/styles.xml',
  'android/app/src/main/res/values/colors.xml',
  'android/app/src/main/res/drawable/splash.png',
  'android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml'
];

const COPY_EXCLUDES = new Set([
  'node_modules',
  '.git',
  '.gradle',
  'build',
  'local.properties',
  'capacitor-cordova-android-plugins',
  '.idea',
  '.vscode'
]);

function parseArgs(argv) {
  const args = {};
  const aliases = {
    '--config': 'configPath',
    '--config-json': 'configJson',
    '--project-dir': 'projectDir',
    '--workspace-root': 'workspaceRoot',
    '--output-dir': 'outputDir',
    '--build-id': 'buildId',
    '--build-type': 'buildType',
    '--build-variant': 'buildVariant',
    '--app-name': 'appName',
    '--package-id': 'packageId',
    '--website-url': 'websiteUrl',
    '--version-name': 'versionName',
    '--version-code': 'versionCode',
    '--primary-color': 'primaryColor',
    '--background-color': 'backgroundColor',
    '--orientation': 'orientation',
    '--icon-url': 'iconUrl',
    '--splash-url': 'splashUrl'
  };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--keep-workspace') {
      args.keepWorkspace = true;
      continue;
    }
    if (arg === '--dry-run') {
      args.dryRun = true;
      continue;
    }
    if (!arg.startsWith('--')) continue;
    const key = aliases[arg];
    if (!key) continue;
    const value = (i + 1 < argv.length && !argv[i + 1].startsWith('--')) ? argv[++i] : null;
    if (value === null) continue;
    args[key] = key === 'versionCode' ? parseInt(value, 10) : value;
  }
  return args;
}

function envValue(name) {
  const value = process.env[name];
  return value === undefined || value === null ? '' : String(value).trim();
}

function applyEnvOverrides(config) {
  const merged = { ...config };
  const mapping = {
    APP_NAME: 'app_name',
    PACKAGE_ID: 'package_id',
    WEBSITE_URL: 'website_url',
    VERSION_NAME: 'version_name',
    VERSION_CODE: 'version_code',
    PRIMARY_COLOR: 'primary_color',
    BACKGROUND_COLOR: 'background_color',
    SPLASH_BACKGROUND_COLOR: 'splash_background_color',
    STATUS_BAR_COLOR: 'status_bar_color',
    NAVIGATION_BAR_COLOR: 'navigation_bar_color',
    ORIENTATION: 'orientation',
    BUILD_TYPE: 'build_type',
    BUILD_VARIANT: 'build_variant',
    ICON_URL: 'icon_url',
    SPLASH_URL: 'splash_url'
  };

  for (const [envName, configKey] of Object.entries(mapping)) {
    const value = envValue(envName);
    if (!value) continue;
    if (configKey === 'version_code') {
      merged[configKey] = /^\d+$/.test(value) ? parseInt(value, 10) : value;
    } else {
      merged[configKey] = value;
    }
  }

  const external = envValue('ALLOW_EXTERNAL_LINKS');
  if (external === 'true' || external === 'false') {
    merged.allow_external_links = external === 'true';
  }
  const statusBar = envValue('ENABLE_STATUS_BAR');
  if (statusBar === 'true' || statusBar === 'false') {
    merged.enable_status_bar = statusBar === 'true';
  }

  return merged;
}

function loadConfiguration(options) {
  let config = {};

  const configPath = options.configPath
    ? path.resolve(ROOT_DIR, options.configPath)
    : path.join(ROOT_DIR, 'config', 'default-app.json');

  const relativeConfig = path.relative(ROOT_DIR, configPath);
  if (relativeConfig.startsWith('..') || path.isAbsolute(relativeConfig)) {
    throw new Error(`Configuration file must stay inside the repository: ${options.configPath}`);
  }

  if (options.configPath || fs.existsSync(configPath)) {
    if (!fs.existsSync(configPath)) {
      throw new Error(`Configuration file not found: ${configPath}`);
    }
    try {
      config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    } catch (err) {
      throw new Error(`Invalid JSON in ${configPath}: ${err.message}`);
    }
  }

  // Inline JSON is intended for the future WordPress dispatch payload.
  const inline = options.configJson || envValue('BUILD_CONFIG_JSON') || envValue('CONFIG_JSON');
  if (inline) {
    let parsed;
    try {
      parsed = JSON.parse(inline);
    } catch (err) {
      throw new Error(`Invalid inline BUILD_CONFIG_JSON: ${err.message}`);
    }
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new Error('Inline BUILD_CONFIG_JSON must be a JSON object.');
    }
    config = { ...config, ...parsed };
  }

  if (options.buildType) config.build_type = options.buildType;
  if (options.buildVariant) config.build_variant = options.buildVariant;
  if (options.appName) config.app_name = options.appName;
  if (options.packageId) config.package_id = options.packageId;
  if (options.websiteUrl) config.website_url = options.websiteUrl;
  if (options.versionName) config.version_name = options.versionName;
  if (options.versionCode !== undefined && !Number.isNaN(options.versionCode)) config.version_code = options.versionCode;
  if (options.primaryColor) config.primary_color = options.primaryColor;
  if (options.backgroundColor) config.background_color = options.backgroundColor;
  if (options.orientation) config.orientation = options.orientation;
  if (options.iconUrl) config.icon_url = options.iconUrl;
  if (options.splashUrl) config.splash_url = options.splashUrl;

  return applyEnvOverrides(config);
}

function verifyTemplate() {
  const missing = REQUIRED_TEMPLATE_FILES.filter(f => !fs.existsSync(path.join(TEMPLATE_DIR, f)));
  if (missing.length > 0) {
    throw new Error(`Android template is incomplete. Missing files:\n  - ${missing.join('\n  - ')}`);
  }
}

function makeBuildId(provided) {
  const suffix = crypto.randomBytes(3).toString('hex');
  if (provided) {
    const base = sanitizeFileComponent(provided, `build-${Date.now()}`, 60);
    return `${base}-${suffix}`;
  }
  const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14);
  return `${stamp}-${suffix}`;
}

function copyTemplate(workspaceProjectDir) {
  fs.mkdirSync(workspaceProjectDir, { recursive: true });
  fs.cpSync(TEMPLATE_DIR, workspaceProjectDir, {
    recursive: true,
    dereference: false,
    filter: (src) => {
      const rel = path.relative(TEMPLATE_DIR, src);
      if (!rel) return true;
      const parts = rel.split(path.sep);
      if (parts.some(part => COPY_EXCLUDES.has(part))) return false;
      // Generated web assets and build intermediates are recreated by cap sync.
      if (rel === path.join('android', 'app', 'src', 'main', 'assets')) return false;
      if (rel.startsWith(path.join('android', 'app', 'src', 'main', 'assets') + path.sep)) return false;
      return true;
    }
  });
}

function linkNodeModules(workspaceProjectDir) {
  const source = path.join(TEMPLATE_DIR, 'node_modules');
  const target = path.join(workspaceProjectDir, 'node_modules');
  if (!fs.existsSync(source)) {
    throw new Error(
      'app-template/node_modules is missing. Run "npm install" inside app-template before building.'
    );
  }
  if (fs.existsSync(target)) return;
  try {
    fs.symlinkSync(source, target, process.platform === 'win32' ? 'junction' : 'dir');
    console.log('[OK] Linked template node_modules into isolated workspace');
  } catch (err) {
    throw new Error(`Unable to link node_modules into the isolated workspace: ${err.message}`);
  }
}

function writeLocalProperties(workspaceProjectDir) {
  const sdk = process.env.ANDROID_HOME || process.env.ANDROID_SDK_ROOT;
  if (!sdk) return;
  const localProperties = path.join(workspaceProjectDir, 'android', 'local.properties');
  const escaped = sdk.replace(/\\/g, '\\\\');
  fs.writeFileSync(localProperties, `sdk.dir=${escaped}\n`, 'utf8');
}

function appendGithubOutput(key, value) {
  const outputFile = process.env.GITHUB_OUTPUT;
  if (!outputFile) return;
  const clean = String(value === undefined || value === null ? '' : value).replace(/[\r\n]/g, ' ');
  try {
    fs.appendFileSync(outputFile, `${key}=${clean}\n`, 'utf8');
  } catch (err) {
    console.warn(`[WARN] Could not write GITHUB_OUTPUT (${key}): ${err.message}`);
  }
}

function appendStepSummary(markdown) {
  const summaryFile = process.env.GITHUB_STEP_SUMMARY;
  if (!summaryFile) return;
  try {
    fs.appendFileSync(summaryFile, markdown, 'utf8');
  } catch (err) {
    console.warn(`[WARN] Could not write GITHUB_STEP_SUMMARY: ${err.message}`);
  }
}

function sniffImageExtension(buffer) {
  if (buffer.length >= 8 &&
      buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4E && buffer[3] === 0x47) {
    return '.png';
  }
  if (buffer.length >= 3 && buffer[0] === 0xFF && buffer[1] === 0xD8 && buffer[2] === 0xFF) {
    return '.jpg';
  }
  if (buffer.length >= 12 &&
      buffer.toString('ascii', 0, 4) === 'RIFF' &&
      buffer.toString('ascii', 8, 12) === 'WEBP') {
    return '.webp';
  }
  return null;
}

/**
 * Download an asset with HTTPS enforcement, redirect limits, timeout and a
 * hard byte cap. The file is never executed; it is only parsed as an image.
 */
async function downloadAsset(assetUrl, destinationDir, fieldName) {
  const MAX_REDIRECTS = 3;
  const MAX_BYTES = 10 * 1024 * 1024;
  let currentUrl = assetUrl;

  for (let hop = 0; hop <= MAX_REDIRECTS; hop++) {
    const parsed = new URL(currentUrl);
    if (parsed.protocol !== 'https:') {
      throw new Error(`${fieldName} must use HTTPS. Refusing to download ${currentUrl}`);
    }
    if (isDisallowedAssetHost(parsed.hostname)) {
      throw new Error(`${fieldName} must not target a loopback, private or link-local host.`);
    }

    let response;
    try {
      response = await fetch(currentUrl, {
        redirect: 'manual',
        signal: AbortSignal.timeout(30000),
        headers: { 'User-Agent': 'Bizgrowth360-Android-Builder/1.0' }
      });
    } catch (err) {
      throw new Error(`${fieldName} download failed: ${err.message}`);
    }

    if (response.status >= 300 && response.status < 400) {
      const location = response.headers.get('location');
      if (!location) {
        throw new Error(`${fieldName} download returned a redirect without a Location header.`);
      }
      currentUrl = new URL(location, currentUrl).toString();
      continue;
    }

    if (!response.ok) {
      throw new Error(`${fieldName} download failed with HTTP ${response.status}.`);
    }

    const contentLength = Number(response.headers.get('content-length') || 0);
    if (contentLength > MAX_BYTES) {
      throw new Error(`${fieldName} is too large (${contentLength} bytes). Maximum is ${MAX_BYTES} bytes.`);
    }

    const arrayBuffer = await response.arrayBuffer();
    if (arrayBuffer.byteLength > MAX_BYTES) {
      throw new Error(`${fieldName} is too large (${arrayBuffer.byteLength} bytes). Maximum is ${MAX_BYTES} bytes.`);
    }
    if (arrayBuffer.byteLength === 0) {
      throw new Error(`${fieldName} download was empty.`);
    }

    const buffer = Buffer.from(arrayBuffer);
    const ext = sniffImageExtension(buffer);
    if (!ext) {
      throw new Error(`${fieldName} is not a PNG, JPEG or WebP image.`);
    }
    const dest = path.join(destinationDir, `${fieldName}-${crypto.randomBytes(4).toString('hex')}${ext}`);
    fs.writeFileSync(dest, buffer, { mode: 0o600 });
    console.log(`[OK] Downloaded ${fieldName} (${(buffer.length / 1024).toFixed(0)} KB) from ${parsed.host}`);
    return dest;
  }

  throw new Error(`${fieldName} exceeded the maximum of ${MAX_REDIRECTS} redirects.`);
}

function runProcess(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    console.log(`\n$ ${command} ${args.join(' ')}`);
    const child = spawn(command, args, {
      cwd: options.cwd,
      env: { ...process.env, ...(options.env || {}) },
      shell: !!options.shell,
      windowsHide: true
    });

    const logStream = options.logStream;
    const forward = (chunk) => {
      if (logStream) logStream.write(chunk);
      if (options.quiet) return;
      process.stdout.write(chunk);
    };
    child.stdout.on('data', forward);
    child.stderr.on('data', forward);

    child.on('error', err => reject(new Error(`Failed to start ${command}: ${err.message}`)));
    child.on('close', code => {
      if (code === 0) resolve();
      else reject(new Error(`${command} exited with code ${code}`));
    });
  });
}

async function runCapacitorSync(projectDir, logStream) {
  const cliPath = path.join(projectDir, 'node_modules', '@capacitor', 'cli', 'bin', 'capacitor');
  if (!fs.existsSync(cliPath)) {
    throw new Error('Capacitor CLI not found in app-template/node_modules.');
  }
  await runProcess(process.execPath, [cliPath, 'sync', 'android'], {
    cwd: projectDir,
    logStream
  });
}

function readJsonIfExists(filePath) {
  if (!fs.existsSync(filePath)) return null;
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (err) {
    return null;
  }
}

function readOutputMetadata(projectDir, variant, kind) {
  const metadataPath = path.join(
    projectDir,
    'android',
    'app',
    'build',
    'outputs',
    kind,
    variant,
    'output-metadata.json'
  );
  return readJsonIfExists(metadataPath);
}

function findOutputFile(projectDir, variant, kind, extension) {
  const dir = path.join(projectDir, 'android', 'app', 'build', 'outputs', kind, variant);
  if (!fs.existsSync(dir)) return null;
  const files = fs.readdirSync(dir).filter(f => f.endsWith(extension));
  if (files.length === 0) return null;
  return path.join(dir, files[0]);
}

function buildTasks(buildType, buildVariant) {
  const tasks = [];
  if (buildType === 'apk' || buildType === 'both') {
    tasks.push(buildVariant === 'release' ? 'assembleRelease' : 'assembleDebug');
  }
  if (buildType === 'aab' || buildType === 'both') {
    tasks.push(buildVariant === 'release' ? 'bundleRelease' : 'bundleDebug');
  }
  return tasks;
}

function runGradle(projectDir, tasks, logStream) {
  const androidDir = path.join(projectDir, 'android');
  const gradleArgs = [...tasks, '--stacktrace', '--console=plain'];
  if (process.platform === 'win32') {
    return runProcess(
      'cmd.exe',
      ['/d', '/s', '/c', `gradlew.bat ${gradleArgs.join(' ')}`],
      { cwd: androidDir, logStream }
    );
  }
  // Git may not preserve the executable bit for gradlew (mode 100644).
  try {
    fs.chmodSync(path.join(androidDir, 'gradlew'), 0o755);
  } catch (err) {
    console.warn(`[WARN] Could not chmod gradlew: ${err.message}`);
  }
  return runProcess('./gradlew', gradleArgs, { cwd: androidDir, logStream });
}

function getSigningStatus(buildVariant) {
  const keystoreFile = process.env.KEYSTORE_FILE;
  if (keystoreFile && fs.existsSync(keystoreFile)) {
    return 'Signed with production keystore';
  }
  if (buildVariant === 'debug') {
    return 'Debug signed (debug variant)';
  }
  return 'Debug signed (no production keystore configured)';
}

function escapeMarkdown(value) {
  return String(value === undefined || value === null ? '' : value)
    .replace(/\|/g, '\\|')
    .replace(/[\r\n]+/g, ' ');
}

function writeSummary(outputDir, summary) {
  const jsonPath = path.join(outputDir, 'build-summary.json');
  fs.writeFileSync(jsonPath, JSON.stringify(summary, null, 2), 'utf8');

  const lines = [];
  lines.push('## 🚀 Bizgrowth360 Android Dynamic Build Summary');
  lines.push('');
  lines.push('| Property | Value |');
  lines.push('| :--- | :--- |');
  lines.push(`| **Status** | **${summary.status.toUpperCase()}** |`);
  lines.push(`| **Build ID** | \`${escapeMarkdown(summary.buildId)}\` |`);
  lines.push(`| **App Name** | \`${escapeMarkdown(summary.config.app_name)}\` |`);
  lines.push(`| **Package ID** | \`${escapeMarkdown(summary.config.package_id)}\` |`);
  lines.push(`| **Website URL** | \`${escapeMarkdown(summary.config.website_url)}\` |`);
  lines.push(`| **Version** | \`${escapeMarkdown(summary.config.version_name)} (code ${summary.config.version_code})\` |`);
  lines.push(`| **Build Type** | \`${escapeMarkdown(summary.build.type)} / ${escapeMarkdown(summary.build.variant)}\` |`);
  lines.push(`| **Orientation** | \`${escapeMarkdown(summary.config.orientation)}\` |`);
  lines.push(`| **Signing** | ${escapeMarkdown(summary.signing.status)} |`);
  lines.push(`| **APK** | ${summary.artifacts.apk ? '✅ Generated' : '— Not requested'} |`);
  lines.push(`| **AAB** | ${summary.artifacts.aab ? '✅ Generated' : '— Not requested'} |`);
  lines.push('');
  lines.push('### Validation');
  lines.push('');
  lines.push('| Check | Result | Detail |');
  lines.push('| :--- | :--- | :--- |');
  for (const check of summary.validation.checks) {
    lines.push(`| ${escapeMarkdown(check.name)} | ${check.passed ? '✅ PASS' : '❌ FAIL'} | ${escapeMarkdown(check.detail || '')} |`);
  }
  lines.push('');

  const artifactList = [summary.artifacts.apk, summary.artifacts.aab].filter(Boolean);
  if (artifactList.length > 0) {
    lines.push('### 📦 Generated Artifacts');
    lines.push('');
    lines.push('| Artifact | File | Size | SHA-256 |');
    lines.push('| :--- | :--- | :--- | :--- |');
    for (const artifact of artifactList) {
      lines.push(
        `| \`${escapeMarkdown(artifact.artifactName)}\` | \`${escapeMarkdown(artifact.fileName)}\` | ` +
        `${(artifact.sizeBytes / 1024 / 1024).toFixed(2)} MB | \`${artifact.sha256.slice(0, 16)}…\` |`
      );
    }
    lines.push('');
  }

  lines.push(`> Configuration source: \`${escapeMarkdown(summary.configSource)}\``);
  lines.push('');

  const markdown = lines.join('\n');
  fs.writeFileSync(path.join(outputDir, 'build-summary.md'), markdown, 'utf8');
  appendStepSummary(markdown);
  return jsonPath;
}

function createBaseSummary({ buildId, config, buildType, buildVariant, configSource, startedAt }) {
  return {
    schemaVersion: 1,
    buildId,
    status: 'running',
    startedAt,
    finishedAt: null,
    durationSeconds: null,
    configSource,
    config: {
      app_name: config.app_name,
      package_id: config.package_id,
      website_url: config.website_url,
      version_name: config.version_name,
      version_code: config.version_code,
      primary_color: config.primary_color,
      background_color: config.background_color,
      splash_background_color: config.splash_background_color,
      status_bar_color: config.status_bar_color,
      navigation_bar_color: config.navigation_bar_color,
      orientation: config.orientation,
      allow_external_links: config.allow_external_links,
      enable_status_bar: config.enable_status_bar
    },
    build: {
      type: buildType,
      variant: buildVariant,
      tasks: buildTasks(buildType, buildVariant)
    },
    assetInputs: {
      icon_url: config.icon_url || null,
      splash_url: config.splash_url || null
    },
    assets: {},
    signing: { status: getSigningStatus(buildVariant) },
    validation: { checks: [] },
    artifacts: {
      apk: null,
      aab: null,
      artifactNames: {
        apk: null,
        aab: null
      }
    },
    workspaceRetained: false
  };
}

function addCheck(summary, name, passed, detail) {
  summary.validation.checks.push({ name, passed: !!passed, detail: detail || '' });
}

async function buildApp(options = {}) {
  const startedAtDate = new Date();
  const startedAt = startedAtDate.toISOString();
  const buildId = makeBuildId(options.buildId || envValue('BUILD_ID'));
  const configSource = options.configPath
    || (envValue('BUILD_CONFIG_JSON') || envValue('CONFIG_JSON') ? 'inline-json' : 'config/default-app.json');

  const workspaceRoot = options.workspaceRoot
    ? path.resolve(ROOT_DIR, options.workspaceRoot)
    : path.join(os.tmpdir(), 'bizgrowth360-builds');
  const workspaceDir = path.join(workspaceRoot, buildId);
  const workspaceProjectDir = path.join(workspaceDir, 'app-template');
  const outputDir = options.outputDir
    ? path.resolve(ROOT_DIR, options.outputDir)
    : path.join(ROOT_DIR, 'dist', 'builds', buildId);

  fs.mkdirSync(outputDir, { recursive: true });
  const logStream = fs.createWriteStream(path.join(outputDir, 'build.log'), { flags: 'a' });

  let summary = null;
  let exitError = null;
  let apkDir = null;
  let aabDir = null;

  try {
    console.log('=== Bizgrowth360 Android Dynamic Build ===');
    console.log(`Build ID: ${buildId}`);
    console.log(`Workspace: ${workspaceDir}`);

    // ---------------------------------------------------------------
    // 1. Configuration
    // ---------------------------------------------------------------
    const rawConfig = loadConfiguration(options);
    const validation = validateConfig(rawConfig);
    const buildType = validation.normalizedConfig.build_type;
    const buildVariant = validation.normalizedConfig.build_variant;
    summary = createBaseSummary({
      buildId,
      config: validation.normalizedConfig,
      buildType,
      buildVariant,
      configSource,
      startedAt
    });
    summary.validation.checks = [];

    addCheck(summary, 'Configuration schema', validation.valid, validation.valid
      ? 'All required fields are present and valid.'
      : validation.errors.join('; '));
    for (const warning of validation.warnings) {
      addCheck(summary, 'Configuration warning', true, warning);
    }
    if (!validation.valid) {
      throw new Error(`Configuration validation failed:\n  - ${validation.errors.join('\n  - ')}`);
    }

    const config = validation.normalizedConfig;
    console.log('\n--- Validated configuration ---');
    console.log(JSON.stringify(config, null, 2));

    // ---------------------------------------------------------------
    // 2. Template completeness
    // ---------------------------------------------------------------
    verifyTemplate();
    addCheck(summary, 'Template completeness', true, `${REQUIRED_TEMPLATE_FILES.length} required files verified.`);

    // ---------------------------------------------------------------
    // 3. Isolated workspace
    // ---------------------------------------------------------------
    if (fs.existsSync(workspaceDir)) {
      fs.rmSync(workspaceDir, { recursive: true, force: true });
    }
    fs.mkdirSync(workspaceDir, { recursive: true });
    copyTemplate(workspaceProjectDir);
    linkNodeModules(workspaceProjectDir);
    writeLocalProperties(workspaceProjectDir);
    addCheck(summary, 'Isolated workspace', true, 'Template copied to a unique build directory.');

    // ---------------------------------------------------------------
    // 4. Assets
    // ---------------------------------------------------------------
    const downloadedAssetsDir = path.join(workspaceDir, '_assets');
    fs.mkdirSync(downloadedAssetsDir, { recursive: true });

    let iconPath = null;
    let splashPath = null;
    if (config.icon_url) {
      iconPath = await downloadAsset(config.icon_url, downloadedAssetsDir, 'icon');
      const check = await validateImageAsset(iconPath, { kind: 'icon', minWidth: 192, minHeight: 192, maxDimension: 4096 });
      if (!check.valid) {
        throw new Error(`Downloaded icon failed validation:\n  - ${check.errors.join('\n  - ')}`);
      }
      addCheck(summary, 'Custom icon download', true, `${check.metadata.width}x${check.metadata.height} ${check.metadata.format}`);
      summary.assets.iconInput = { source: 'url', width: check.metadata.width, height: check.metadata.height };
    } else {
      addCheck(summary, 'Custom icon download', true, 'No icon URL supplied; default icon will be generated.');
    }

    if (config.splash_url) {
      splashPath = await downloadAsset(config.splash_url, downloadedAssetsDir, 'splash');
      const check = await validateImageAsset(splashPath, { kind: 'splash', minWidth: 320, minHeight: 320, maxDimension: 8192 });
      if (!check.valid) {
        throw new Error(`Downloaded splash image failed validation:\n  - ${check.errors.join('\n  - ')}`);
      }
      addCheck(summary, 'Custom splash download', true, `${check.metadata.width}x${check.metadata.height} ${check.metadata.format}`);
      summary.assets.splashInput = { source: 'url', width: check.metadata.width, height: check.metadata.height };
    } else {
      addCheck(summary, 'Custom splash download', true, 'No splash URL supplied; default splash will be generated.');
    }

    // ---------------------------------------------------------------
    // 5. Apply configuration + generate resources
    // ---------------------------------------------------------------
    const preparedConfig = prepareApp({ projectDir: workspaceProjectDir, config });
    addCheck(summary, 'Configuration applied', true,
      'capacitor.config, app-config.js, strings.xml, colors.xml, styles.xml, manifest, Gradle and MainActivity updated.');

    const preparedGradle = fs.readFileSync(path.join(workspaceProjectDir, 'android', 'app', 'build.gradle'), 'utf8');
    const preparedStrings = fs.readFileSync(
      path.join(workspaceProjectDir, 'android', 'app', 'src', 'main', 'res', 'values', 'strings.xml'),
      'utf8'
    );
    const preparedManifest = fs.readFileSync(
      path.join(workspaceProjectDir, 'android', 'app', 'src', 'main', 'AndroidManifest.xml'),
      'utf8'
    );

    const packageCheck = preparedGradle.includes(`applicationId "${config.package_id}"`)
      && preparedGradle.includes(`namespace "${config.package_id}"`);
    addCheck(summary, 'Package ID applied', packageCheck, `applicationId + namespace = ${config.package_id}`);

    const versionCheck = preparedGradle.includes(`versionCode ${config.version_code}`)
      && preparedGradle.includes(`versionName "${config.version_name}"`);
    addCheck(summary, 'Version applied', versionCheck, `${config.version_name} (${config.version_code})`);

    const appNameCheck = preparedStrings.includes('name="app_name"')
      && preparedStrings.includes(escapeAndroidString(config.app_name));
    addCheck(summary, 'App name resource', appNameCheck, 'strings.xml contains the configured display name.');

    const urlCheck = preparedStrings.includes(new URL(config.website_url).hostname)
      && fs.readFileSync(path.join(workspaceProjectDir, 'public', 'app-config.js'), 'utf8')
        .includes(JSON.stringify(config.website_url));
    addCheck(summary, 'Website URL applied', urlCheck, config.website_url);

    const orientationCheck = preparedManifest.includes('android:screenOrientation=');
    addCheck(summary, 'Orientation applied', orientationCheck, config.orientation);

    const assetReport = await generateIcons({
      projectDir: workspaceProjectDir,
      config: preparedConfig,
      iconPath,
      splashPath
    });
    summary.assets.icon = assetReport.icon;
    summary.assets.splash = assetReport.splash;
    addCheck(summary, 'Launcher icon generation', true,
      `${assetReport.icon.source} icon, ${assetReport.densities.length} mipmap densities + adaptive icon.`);
    addCheck(summary, 'Splash screen generation', true,
      `${assetReport.splash.source} splash, ${assetReport.splashDensities.length} drawable configurations.`);

    if (options.dryRun) {
      addCheck(summary, 'Dry run', true, 'Preparation completed; Gradle build skipped.');
      summary.status = 'success';
      console.log('\n[Dry run] Configuration, workspace and assets are valid. Build skipped.');
      return summary;
    }

    // ---------------------------------------------------------------
    // 6. Capacitor sync + Gradle build
    // ---------------------------------------------------------------
    await runCapacitorSync(workspaceProjectDir, logStream);
    addCheck(summary, 'Capacitor sync', true, 'Web assets synced into the Android project.');

    const tasks = buildTasks(buildType, buildVariant);
    const buildStart = Date.now();
    await runGradle(workspaceProjectDir, tasks, logStream);
    const buildSeconds = ((Date.now() - buildStart) / 1000).toFixed(1);
    addCheck(summary, 'Gradle build', true, `Tasks: ${tasks.join(', ')} (${buildSeconds}s)`);

    // ---------------------------------------------------------------
    // 7. Collect artifacts
    // ---------------------------------------------------------------
    const artifactBase = buildArtifactBaseName(config.app_name, config.version_name);
    apkDir = path.join(outputDir, 'apk');
    aabDir = path.join(outputDir, 'aab');
    const wantApk = buildType === 'apk' || buildType === 'both';
    const wantAab = buildType === 'aab' || buildType === 'both';

    const apkMetadata = wantApk ? readOutputMetadata(workspaceProjectDir, buildVariant, 'apk') : null;
    const aabMetadata = wantAab ? readOutputMetadata(workspaceProjectDir, buildVariant, 'bundle') : null;

    if (wantApk) {
      const src = findOutputFile(workspaceProjectDir, buildVariant, 'apk', '.apk');
      if (!src) throw new Error('Gradle completed but no APK was produced.');
      fs.mkdirSync(apkDir, { recursive: true });
      const dest = path.join(apkDir, `${artifactBase}-${buildVariant}.apk`);
      fs.copyFileSync(src, dest);
      const apkValidation = validateApk(dest, config, { strict: true });
      if (!apkValidation.valid) {
        throw new Error(`APK validation failed:\n  - ${apkValidation.errors.join('\n  - ')}`);
      }
      const metadataElement = apkMetadata && apkMetadata.elements && apkMetadata.elements[0];
      const metadataOk = !metadataElement
        || (metadataElement.versionCode === config.version_code
          && String(metadataElement.versionName) === String(config.version_name)
          && apkMetadata.applicationId === config.package_id);
      addCheck(summary, 'APK output validation', metadataOk,
        metadataOk
          ? `${apkValidation.metadata.sizeMb}, package + version verified.`
          : 'output-metadata.json does not match the requested config.');

      summary.artifacts.apk = {
        artifactName: `${artifactBase}-APK`,
        fileName: path.basename(dest),
        path: dest,
        sizeBytes: apkValidation.metadata.sizeBytes,
        sha256: apkValidation.metadata.sha256,
        signed: apkValidation.metadata.isSigned,
        signatureType: apkValidation.metadata.signatureType,
        badging: apkValidation.badging || null
      };
      summary.artifacts.artifactNames.apk = `${artifactBase}-APK`;
    }

    if (wantAab) {
      const src = findOutputFile(workspaceProjectDir, buildVariant, 'bundle', '.aab');
      if (!src) throw new Error('Gradle completed but no AAB was produced.');
      fs.mkdirSync(aabDir, { recursive: true });
      const dest = path.join(aabDir, `${artifactBase}-${buildVariant}.aab`);
      fs.copyFileSync(src, dest);
      const aabValidation = validateAab(dest, config, { strict: true });
      if (!aabValidation.valid) {
        throw new Error(`AAB validation failed:\n  - ${aabValidation.errors.join('\n  - ')}`);
      }
      const metadataElement = aabMetadata && aabMetadata.elements && aabMetadata.elements[0];
      const metadataOk = !metadataElement
        || (metadataElement.versionCode === config.version_code
          && String(metadataElement.versionName) === String(config.version_name)
          && aabMetadata.applicationId === config.package_id);
      addCheck(summary, 'AAB output validation', metadataOk,
        metadataOk
          ? `${aabValidation.metadata.sizeMb}, package + version verified.`
          : 'output-metadata.json does not match the requested config.');

      summary.artifacts.aab = {
        artifactName: `${artifactBase}-AAB`,
        fileName: path.basename(dest),
        path: dest,
        sizeBytes: aabValidation.metadata.sizeBytes,
        sha256: aabValidation.metadata.sha256,
        signed: aabValidation.metadata.isSigned
      };
      summary.artifacts.artifactNames.aab = `${artifactBase}-AAB`;
    }

    // The requested artifact types must all exist.
    if (wantApk && !summary.artifacts.apk) throw new Error('Requested APK is missing.');
    if (wantAab && !summary.artifacts.aab) throw new Error('Requested AAB is missing.');

    addCheck(summary, 'Artifact naming', true,
      [summary.artifacts.artifactNames.apk, summary.artifacts.artifactNames.aab].filter(Boolean).join(', '));

    summary.status = 'success';
    summary.outputDir = outputDir;
    return summary;
  } catch (err) {
    exitError = err;
    if (summary) {
      summary.status = 'failed';
      summary.error = err.message;
      addCheck(summary, 'Build failure', false, err.message);
    }
    console.error(`\n[BUILD FAILED] ${err.message}`);
    return summary;
  } finally {
    const finished = new Date();
    if (summary) {
      summary.finishedAt = finished.toISOString();
      summary.durationSeconds = Math.round((finished - startedAtDate) / 1000);
      summary.workspaceDir = workspaceDir;
      summary.outputDir = outputDir;
      summary.workspaceRetained = !!options.keepWorkspace;

      try {
        writeSummary(outputDir, summary);
        appendGithubOutput('build_id', buildId);
        appendGithubOutput('build_status', summary.status);
        appendGithubOutput('output_dir', outputDir);
        appendGithubOutput('artifact_base', summary.artifacts.artifactNames.apk
          ? summary.artifacts.artifactNames.apk.replace(/-APK$/, '')
          : summary.artifacts.artifactNames.aab
            ? summary.artifacts.artifactNames.aab.replace(/-AAB$/, '')
            : '');
        if (summary.artifacts.apk) {
          appendGithubOutput('apk_artifact_name', summary.artifacts.apk.artifactName);
          appendGithubOutput('apk_dir', apkDir);
        }
        if (summary.artifacts.aab) {
          appendGithubOutput('aab_artifact_name', summary.artifacts.aab.artifactName);
          appendGithubOutput('aab_dir', aabDir);
        }
      } catch (summaryErr) {
        console.error(`[WARN] Failed to write build summary: ${summaryErr.message}`);
      }
    }

    logStream.end();

    if (summary && summary.status === 'success') {
      console.log('\n=== BUILD SUCCEEDED ===');
      console.log(`APK: ${summary.artifacts.apk ? summary.artifacts.apk.path : 'not requested'}`);
      console.log(`AAB: ${summary.artifacts.aab ? summary.artifacts.aab.path : 'not requested'}`);
    }

    if (options.keepWorkspace) {
      if (summary) summary.workspaceRetained = true;
      console.log(`Workspace retained at: ${workspaceDir}`);
    } else {
      try {
        if (fs.existsSync(workspaceDir)) {
          fs.rmSync(workspaceDir, { recursive: true, force: true });
          console.log(`Cleaned temporary workspace: ${workspaceDir}`);
        }
      } catch (cleanupErr) {
        console.warn(`[WARN] Could not clean workspace ${workspaceDir}: ${cleanupErr.message}`);
      }
    }
  }

  if (exitError) {
    throw exitError;
  }
  return summary;
}

if (require.main === module) {
  const options = parseArgs(process.argv.slice(2));
  buildApp(options)
    .then(summary => {
      if (!summary || summary.status !== 'success') {
        process.exit(1);
      }
      process.exit(0);
    })
    .catch(err => {
      console.error(`\n[FATAL] ${err.message}`);
      process.exit(1);
    });
}

module.exports = {
  buildApp,
  loadConfiguration,
  parseArgs,
  buildTasks,
  downloadAsset,
  verifyTemplate,
  copyTemplate
};
