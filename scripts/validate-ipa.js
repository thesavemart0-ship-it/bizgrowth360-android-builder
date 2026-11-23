/**
 * validate-ipa.js
 * Structural (and on macOS, cryptographic) validation for exported iOS IPAs.
 *
 * An IPA is a ZIP archive containing Payload/<App>.app. A build must never be
 * reported as successful unless this validator accepts the real exported file.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawnSync } = require('child_process');
const { inspectZipArchive, readZipEntry } = require('./validate-output');

function computeSha256(filePath) {
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function extractAppInfo(ipaPath) {
  const zip = inspectZipArchive(ipaPath);
  const infoEntry = zip.entries
    .map(entry => entry.name)
    .find(name => /^Payload\/[^/]+\.app\/Info\.plist$/.test(name));

  if (!infoEntry) return null;
  const plistData = readZipEntry(zip, infoEntry);
  if (!plistData) return null;

  const temp = path.join(os.tmpdir(), `bg360-ipa-info-${Date.now()}-${process.pid}.plist`);
  fs.writeFileSync(temp, plistData);

  let parsed = null;
  if (process.platform === 'darwin') {
    const plutil = spawnSync('plutil', ['-convert', 'json', '-o', '-', temp], { encoding: 'utf8' });
    if (plutil.status === 0) {
      try {
        parsed = JSON.parse(plutil.stdout);
      } catch (err) {
        parsed = null;
      }
    }
  }

  try {
    fs.unlinkSync(temp);
  } catch (err) {
    // ignore temp cleanup failure
  }

  return { entry: infoEntry, info: parsed, raw: plistData };
}

/**
 * Validate an exported IPA.
 *
 * @param {string} ipaPath
 * @param {{bundleId?: string, versionName?: string, buildNumber?: number|string, appName?: string}} expected
 * @returns {{valid: boolean, errors: string[], warnings: string[], metadata: Object}}
 */
function validateIpa(ipaPath, expected = {}) {
  const errors = [];
  const warnings = [];

  if (!fs.existsSync(ipaPath)) {
    return { valid: false, errors: [`IPA not found: ${ipaPath}`], warnings, metadata: null };
  }

  const stat = fs.statSync(ipaPath);
  if (stat.size < 50 * 1024) {
    errors.push(`IPA is suspiciously small (${stat.size} bytes).`);
  }

  let zip;
  try {
    zip = inspectZipArchive(ipaPath);
  } catch (err) {
    return {
      valid: false,
      errors: [`IPA is not a valid ZIP archive: ${err.message}`],
      warnings,
      metadata: null
    };
  }

  const names = zip.entries.map(entry => entry.name);
  const appInfoEntry = names.find(name => /^Payload\/[^/]+\.app\/Info\.plist$/.test(name));

  if (!appInfoEntry) {
    errors.push('IPA does not contain Payload/<App>.app/Info.plist.');
  }

  const appPrefix = appInfoEntry ? appInfoEntry.replace(/\/Info\.plist$/, '') : null;
  const appName = appPrefix ? path.basename(appPrefix).replace(/\.app$/, '') : null;

  if (appPrefix && !names.includes(`${appPrefix}/${appName}`)) {
    errors.push('IPA is missing the application executable.');
  }
  if (appPrefix && !names.includes(`${appPrefix}/_CodeSignature/CodeResources`)) {
    errors.push('IPA is missing _CodeSignature/CodeResources; it is not code-signed.');
  }
  if (appPrefix && !names.includes(`${appPrefix}/embedded.mobileprovision`)) {
    warnings.push('IPA does not include embedded.mobileprovision. Confirm the export was signed with a provisioning profile.');
  }

  let info = null;
  if (appPrefix) {
    try {
      const extracted = extractAppInfo(ipaPath);
      info = extracted ? extracted.info : null;
    } catch (err) {
      warnings.push(`Could not read Info.plist: ${err.message}`);
    }
  }

  if (info) {
    const actualBundle = info.CFBundleIdentifier;
    const actualVersion = info.CFBundleShortVersionString;
    const actualBuild = info.CFBundleVersion;
    const actualName = info.CFBundleDisplayName || info.CFBundleName;

    if (expected.bundleId && actualBundle && actualBundle !== expected.bundleId) {
      errors.push(`Bundle ID mismatch: expected ${expected.bundleId}, found ${actualBundle}.`);
    }
    if (expected.versionName && actualVersion && String(actualVersion) !== String(expected.versionName)) {
      errors.push(`Version mismatch: expected ${expected.versionName}, found ${actualVersion}.`);
    }
    if (expected.buildNumber && actualBuild && String(actualBuild) !== String(expected.buildNumber)) {
      errors.push(`Build number mismatch: expected ${expected.buildNumber}, found ${actualBuild}.`);
    }
    if (expected.appName && actualName && actualName !== expected.appName) {
      warnings.push(`Display name differs: expected ${expected.appName}, found ${actualName}.`);
    }
  } else {
    warnings.push('Info.plist metadata could not be parsed on this host.');
  }

  let signature = { checked: false, valid: false, detail: 'Not checked on this host' };
  if (process.platform === 'darwin' && appPrefix) {
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'bg360-ipa-'));
    const unzip = spawnSync('unzip', ['-q', ipaPath, '-d', tempDir], { encoding: 'utf8' });

    if (unzip.status === 0) {
      const appPath = path.join(tempDir, appPrefix);
      const codesign = spawnSync('codesign', ['--verify', '--deep', '--strict', appPath], { encoding: 'utf8' });
      signature = {
        checked: true,
        valid: codesign.status === 0,
        detail: codesign.status === 0
          ? 'codesign verification passed'
          : (codesign.stderr || codesign.stdout || 'codesign failed').slice(0, 2000)
      };
      if (!signature.valid) {
        errors.push(`codesign verification failed: ${signature.detail}`);
      }
      try {
        fs.rmSync(tempDir, { recursive: true, force: true });
      } catch (err) {
        // ignore temp cleanup failure
      }
    } else {
      warnings.push('Could not extract the IPA for codesign verification.');
    }
  }

  return {
    valid: errors.length === 0,
    errors,
    warnings,
    metadata: {
      path: ipaPath,
      sizeBytes: stat.size,
      sizeMb: `${(stat.size / 1024 / 1024).toFixed(2)} MB`,
      sha256: computeSha256(ipaPath),
      entries: zip.totalEntries,
      bundleId: info ? info.CFBundleIdentifier : expected.bundleId || null,
      versionName: info ? info.CFBundleShortVersionString : expected.versionName || null,
      buildNumber: info ? info.CFBundleVersion : expected.buildNumber || null,
      appName: info ? (info.CFBundleDisplayName || info.CFBundleName) : null,
      executable: appName,
      signature
    }
  };
}

if (require.main === module) {
  const args = process.argv.slice(2);
  let ipa = null;
  const expected = {};

  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--ipa' && args[i + 1]) ipa = args[++i];
    else if (args[i] === '--bundle-id' && args[i + 1]) expected.bundleId = args[++i];
    else if (args[i] === '--version-name' && args[i + 1]) expected.versionName = args[++i];
    else if (args[i] === '--build-number' && args[i + 1]) expected.buildNumber = args[++i];
    else if (args[i] === '--app-name' && args[i + 1]) expected.appName = args[++i];
  }

  if (!ipa) {
    console.error('Usage: node scripts/validate-ipa.js --ipa <file.ipa> [--bundle-id ...] [--version-name ...] [--build-number ...]');
    process.exit(1);
  }

  const result = validateIpa(ipa, expected);
  console.log(JSON.stringify(result, null, 2));
  process.exit(result.valid ? 0 : 1);
}

module.exports = { validateIpa, computeSha256 };
