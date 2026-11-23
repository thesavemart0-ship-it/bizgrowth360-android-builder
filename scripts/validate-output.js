/**
 * validate-output.js
 * Inspects and validates generated Android APK and AAB packages.
 *
 * Validation is intentionally independent from Gradle: it parses the actual
 * ZIP container, checks Android-specific entries, verifies package/version
 * metadata (using aapt2 when available) and confirms that the configured app
 * name and website URL are embedded in the artifact.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const zlib = require('zlib');
const { spawnSync } = require('child_process');

/**
 * Parses the central directory of a ZIP file (APK/AAB) in pure Node.js.
 */
function inspectZipArchive(filePath) {
  const buffer = fs.readFileSync(filePath);
  const totalLength = buffer.length;

  if (totalLength < 22) {
    throw new Error('File is too small to be a valid ZIP archive.');
  }

  let eocdOffset = -1;
  const maxSearch = Math.min(totalLength - 22, 65536);

  for (let i = totalLength - 22; i >= totalLength - 22 - maxSearch && i >= 0; i--) {
    if (buffer.readUInt32LE(i) === 0x06054b50) {
      eocdOffset = i;
      break;
    }
  }

  if (eocdOffset === -1) {
    throw new Error('Invalid archive format: End of Central Directory (EOCD) signature not found.');
  }

  const totalEntries = buffer.readUInt16LE(eocdOffset + 10);
  const cdSize = buffer.readUInt32LE(eocdOffset + 12);
  const cdOffset = buffer.readUInt32LE(eocdOffset + 16);

  if (cdOffset + cdSize > totalLength) {
    throw new Error('Corrupted ZIP central directory offset.');
  }

  const entries = [];
  let currentOffset = cdOffset;

  for (let i = 0; i < totalEntries && currentOffset < eocdOffset; i++) {
    const signature = buffer.readUInt32LE(currentOffset);
    if (signature !== 0x02014b50) break;

    const compressionMethod = buffer.readUInt16LE(currentOffset + 10);
    const compressedSize = buffer.readUInt32LE(currentOffset + 20);
    const uncompressedSize = buffer.readUInt32LE(currentOffset + 24);
    const fileNameLength = buffer.readUInt16LE(currentOffset + 28);
    const extraFieldLength = buffer.readUInt16LE(currentOffset + 30);
    const commentLength = buffer.readUInt16LE(currentOffset + 32);
    const localHeaderOffset = buffer.readUInt32LE(currentOffset + 42);

    const fileName = buffer.toString('utf8', currentOffset + 46, currentOffset + 46 + fileNameLength);
    entries.push({
      name: fileName,
      size: uncompressedSize,
      compressedSize,
      compressionMethod,
      localHeaderOffset
    });

    currentOffset += 46 + fileNameLength + extraFieldLength + commentLength;
  }

  return {
    totalEntries: entries.length,
    entries,
    fileSize: totalLength,
    rawBuffer: buffer
  };
}

/**
 * Reads and decompresses a single ZIP entry from an inspected archive.
 */
function readZipEntry(zip, entryName) {
  const entry = zip.entries.find(e => e.name === entryName);
  if (!entry) return null;

  const buffer = zip.rawBuffer;
  const offset = entry.localHeaderOffset;
  if (offset + 30 > buffer.length || buffer.readUInt32LE(offset) !== 0x04034b50) {
    throw new Error(`Corrupted local header for ZIP entry: ${entryName}`);
  }

  const fileNameLength = buffer.readUInt16LE(offset + 26);
  const extraFieldLength = buffer.readUInt16LE(offset + 28);
  const dataStart = offset + 30 + fileNameLength + extraFieldLength;

  if (dataStart + entry.compressedSize > buffer.length) {
    throw new Error(`ZIP entry extends beyond file bounds: ${entryName}`);
  }

  const data = buffer.subarray(dataStart, dataStart + entry.compressedSize);
  if (entry.compressionMethod === 0) {
    return Buffer.from(data);
  }
  if (entry.compressionMethod === 8) {
    return zlib.inflateRawSync(data);
  }
  throw new Error(`Unsupported ZIP compression method ${entry.compressionMethod} for ${entryName}`);
}

function computeSha256(filePath) {
  const hash = crypto.createHash('sha256');
  hash.update(fs.readFileSync(filePath));
  return hash.digest('hex');
}

/**
 * Reads a protobuf string field whose value follows an XmlAttribute name.
 *
 * Android App Bundle manifests are protobuf-encoded XmlNode trees. Attributes
 * are encoded as: field 2 = name, field 3 = value. This helper locates an
 * attribute by name and decodes its length-delimited value without pulling in a
 * protobuf dependency.
 */
function readProtobufAttribute(buffer, attributeName) {
  const needle = Buffer.from(attributeName, 'utf8');
  let index = buffer.indexOf(needle);
  while (index !== -1) {
    let pos = index + needle.length;
    if (buffer[pos] === 0x1a) {
      pos += 1;
      const lengthResult = readVarint(buffer, pos);
      if (lengthResult) {
        const start = pos + lengthResult.bytes;
        const end = start + lengthResult.value;
        if (end <= buffer.length) {
          return buffer.toString('utf8', start, end);
        }
      }
    }
    index = buffer.indexOf(needle, index + 1);
  }
  return null;
}

function readVarint(buffer, offset) {
  let value = 0;
  let shift = 0;
  let bytes = 0;
  while (offset + bytes < buffer.length && bytes < 10) {
    const byte = buffer[offset + bytes];
    value |= (byte & 0x7f) << shift;
    bytes += 1;
    if ((byte & 0x80) === 0) {
      return { value, bytes };
    }
    shift += 7;
  }
  return null;
}

/**
 * Locates the newest aapt2 available in the Android SDK.
 */
function findAapt2() {
  if (process.env.AAPT2_PATH && fs.existsSync(process.env.AAPT2_PATH)) {
    return process.env.AAPT2_PATH;
  }
  const sdkRoots = [
    process.env.ANDROID_HOME,
    process.env.ANDROID_SDK_ROOT,
    process.env.LOCALAPPDATA ? path.join(process.env.LOCALAPPDATA, 'Android', 'Sdk') : null,
    process.env.HOME ? path.join(process.env.HOME, 'Android', 'Sdk') : null
  ].filter(Boolean);

  for (const sdkRoot of sdkRoots) {
    const buildToolsDir = path.join(sdkRoot, 'build-tools');
    if (!fs.existsSync(buildToolsDir)) continue;
    const versions = fs.readdirSync(buildToolsDir)
      .filter(v => /^\d+\.\d+\.\d+$/.test(v))
      .sort((a, b) => {
        const pa = a.split('.').map(Number);
        const pb = b.split('.').map(Number);
        for (let i = 0; i < 3; i++) {
          if (pa[i] !== pb[i]) return pb[i] - pa[i];
        }
        return 0;
      });
    for (const version of versions) {
      const candidate = path.join(buildToolsDir, version, process.platform === 'win32' ? 'aapt2.exe' : 'aapt2');
      if (fs.existsSync(candidate)) return candidate;
    }
  }
  return null;
}

/**
 * Runs `aapt2 dump badging` and parses the key metadata fields.
 */
function readApkBadging(apkPath) {
  const aapt2 = findAapt2();
  if (!aapt2) return { available: false, badging: null, error: 'aapt2 not found in Android SDK' };

  const result = spawnSync(aapt2, ['dump', 'badging', apkPath], {
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024
  });

  if (result.error || result.status !== 0) {
    return {
      available: true,
      badging: null,
      error: (result.error && result.error.message) || result.stderr || 'aapt2 dump badging failed'
    };
  }

  const output = result.stdout || '';
  const badging = { raw: output };
  const packageMatch = output.match(/^package:\s+name='([^']+)'\s+versionCode='([^']*)'\s+versionName='([^']*)'/m);
  if (packageMatch) {
    badging.packageName = packageMatch[1];
    badging.versionCode = packageMatch[2];
    badging.versionName = packageMatch[3];
  }
  const labelMatch = output.match(/^application-label:(.*)$/m);
  if (labelMatch) {
    // aapt2 prints the raw label; apostrophes are not escaped, so consume the
    // whole line and only strip the optional surrounding single quotes.
    badging.applicationLabel = labelMatch[1].trim().replace(/^'(.*)'$/s, '$1');
  }
  return { available: true, badging, error: null };
}

function normalizeLabel(value) {
  return String(value || '')
    .replace(/\\'/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

function extractAppConfig(zip, config) {
  const candidates = [
    'assets/public/app-config.js',
    'base/assets/public/app-config.js'
  ];
  for (const entryName of candidates) {
    try {
      const data = readZipEntry(zip, entryName);
      if (!data) continue;
      const text = data.toString('utf8');
      const result = { entryName, valid: true, errors: [] };
      if (!text.includes(JSON.stringify(config.website_url))) {
        result.valid = false;
        result.errors.push(`Website URL is missing from ${entryName}.`);
      }
      if (!text.includes(JSON.stringify(config.package_id))) {
        result.valid = false;
        result.errors.push(`Package ID is missing from ${entryName}.`);
      }
      if (config.app_name && !text.includes(JSON.stringify(config.app_name))) {
        result.valid = false;
        result.errors.push(`App name is missing from ${entryName}.`);
      }
      return result;
    } catch (err) {
      return { entryName, valid: false, errors: [`Could not read ${entryName}: ${err.message}`] };
    }
  }
  return { entryName: null, valid: false, errors: ['Bundled app-config.js was not found in the package.'] };
}

/**
 * Validates an APK file.
 */
function validateApk(apkPath, expectedConfig = {}, options = {}) {
  console.log(`\n--- Validating Android APK: ${apkPath} ---`);
  const errors = [];
  const warnings = [];
  const strict = options.strict === true;

  if (!fs.existsSync(apkPath)) {
    return { valid: false, errors: [`APK file not found at: ${apkPath}`], warnings };
  }

  const stat = fs.statSync(apkPath);
  if (stat.size <= 0) {
    return { valid: false, errors: ['APK file is empty (0 bytes).'], warnings };
  }
  if (strict && stat.size < 50 * 1024) {
    errors.push(`APK is suspiciously small (${stat.size} bytes); expected at least 50 KB.`);
  }

  const sizeMb = (stat.size / (1024 * 1024)).toFixed(2);
  const sha256 = computeSha256(apkPath);
  console.log(`File Size: ${stat.size} bytes (${sizeMb} MB)`);
  console.log(`SHA-256:   ${sha256}`);

  let zip;
  try {
    zip = inspectZipArchive(apkPath);
  } catch (err) {
    return { valid: false, errors: [`Failed parsing APK archive: ${err.message}`], warnings };
  }

  const entryNames = zip.entries.map(e => e.name);
  for (const req of ['AndroidManifest.xml', 'classes.dex', 'resources.arsc']) {
    if (!entryNames.includes(req)) {
      errors.push(`Missing critical APK entry: "${req}". This is not a valid Android package.`);
    } else {
      console.log(`[PASS] Contains entry: ${req}`);
    }
  }

  const signatureFiles = entryNames.filter(name =>
    name.startsWith('META-INF/') && /\.(RSA|DSA|EC|SF)$/.test(name)
  );
  const hasApkSigSchemeV2 = zip.rawBuffer.includes(Buffer.from('APK Sig Block 42'));
  const isSigned = signatureFiles.length > 0 || hasApkSigSchemeV2;
  const signatureType = isSigned
    ? (hasApkSigSchemeV2 ? 'APK Signature Scheme v2/v3' : 'JAR Signing (v1)')
    : 'Unsigned';

  if (isSigned) {
    console.log(`[PASS] APK signature present: ${signatureType}`);
  } else if (strict) {
    errors.push('APK is unsigned and cannot be installed.');
  } else {
    warnings.push('APK is unsigned.');
  }

  let badging = null;
  const badgingResult = readApkBadging(apkPath);
  if (badgingResult.badging) {
    badging = badgingResult.badging;
    console.log(`[PASS] aapt2 badging: ${badging.packageName} v${badging.versionName} (${badging.versionCode})`);
  } else {
    warnings.push(`aapt2 metadata unavailable: ${badgingResult.error}`);
  }

  if (expectedConfig.package_id) {
    const pkgId = expectedConfig.package_id;
    if (badging && badging.packageName) {
      if (badging.packageName !== pkgId) {
        (strict ? errors : warnings).push(
          `Package ID mismatch. Expected "${pkgId}", found "${badging.packageName}".`
        );
      } else {
        console.log(`[PASS] Package ID verified: "${pkgId}"`);
      }
    } else {
      const present = zip.rawBuffer.includes(Buffer.from(pkgId, 'utf8'));
      if (!present) {
        (strict ? errors : warnings).push(`Expected package ID "${pkgId}" was not found in the APK manifest.`);
      } else {
        console.log(`[PASS] Package ID found in APK string pool: "${pkgId}"`);
      }
    }

    if (badging && badging.versionName && String(expectedConfig.version_name) !== badging.versionName) {
      (strict ? errors : warnings).push(
        `Version name mismatch. Expected "${expectedConfig.version_name}", found "${badging.versionName}".`
      );
    } else if (badging && badging.versionName) {
      console.log(`[PASS] Version name verified: "${badging.versionName}"`);
    }

    if (badging && badging.versionCode && Number(expectedConfig.version_code) !== Number(badging.versionCode)) {
      (strict ? errors : warnings).push(
        `Version code mismatch. Expected ${expectedConfig.version_code}, found ${badging.versionCode}.`
      );
    } else if (badging && badging.versionCode) {
      console.log(`[PASS] Version code verified: ${badging.versionCode}`);
    }
  }

  if (expectedConfig.app_name) {
    if (badging && badging.applicationLabel) {
      if (normalizeLabel(badging.applicationLabel) !== normalizeLabel(expectedConfig.app_name)) {
        (strict ? errors : warnings).push(
          `App label mismatch. Expected "${expectedConfig.app_name}", found "${badging.applicationLabel}".`
        );
      } else {
        console.log(`[PASS] Launcher label verified: "${badging.applicationLabel}"`);
      }
    } else {
      const present = zip.rawBuffer.includes(Buffer.from(expectedConfig.app_name, 'utf8'));
      if (!present) {
        warnings.push(`App name "${expectedConfig.app_name}" was not directly matched in resources.arsc.`);
      }
    }
  }

  if (expectedConfig.website_url) {
    const present = zip.rawBuffer.includes(Buffer.from(expectedConfig.website_url, 'utf8'));
    if (!present) {
      warnings.push(`Website URL "${expectedConfig.website_url}" was not found in compiled resources.`);
    } else {
      console.log('[PASS] Website URL found in compiled resources.');
    }
    const appConfig = extractAppConfig(zip, expectedConfig);
    if (appConfig.valid) {
      console.log(`[PASS] Bundled runtime config verified (${appConfig.entryName}).`);
    } else {
      (strict ? errors : warnings).push(...appConfig.errors);
    }
  }

  return {
    valid: errors.length === 0,
    errors,
    warnings,
    badging,
    metadata: {
      type: 'APK',
      path: apkPath,
      sizeBytes: stat.size,
      sizeMb: `${sizeMb} MB`,
      sha256,
      entryCount: zip.totalEntries,
      isSigned,
      signatureType,
      packageName: badging ? badging.packageName : (expectedConfig.package_id || null),
      versionName: badging ? badging.versionName : (expectedConfig.version_name || null),
      versionCode: badging ? badging.versionCode : (expectedConfig.version_code || null),
      applicationLabel: badging ? badging.applicationLabel : null
    }
  };
}

/**
 * Validates an AAB file.
 */
function validateAab(aabPath, expectedConfig = {}, options = {}) {
  console.log(`\n--- Validating Android App Bundle (AAB): ${aabPath} ---`);
  const errors = [];
  const warnings = [];
  const strict = options.strict === true;

  if (!fs.existsSync(aabPath)) {
    return { valid: false, errors: [`AAB file not found at: ${aabPath}`], warnings };
  }

  const stat = fs.statSync(aabPath);
  if (stat.size <= 0) {
    return { valid: false, errors: ['AAB file is empty (0 bytes).'], warnings };
  }
  if (strict && stat.size < 50 * 1024) {
    errors.push(`AAB is suspiciously small (${stat.size} bytes); expected at least 50 KB.`);
  }

  const sizeMb = (stat.size / (1024 * 1024)).toFixed(2);
  const sha256 = computeSha256(aabPath);
  console.log(`File Size: ${stat.size} bytes (${sizeMb} MB)`);
  console.log(`SHA-256:   ${sha256}`);

  let zip;
  try {
    zip = inspectZipArchive(aabPath);
  } catch (err) {
    return { valid: false, errors: [`Failed parsing AAB archive: ${err.message}`], warnings };
  }

  const entryNames = zip.entries.map(e => e.name);
  for (const req of ['BundleConfig.pb', 'base/manifest/AndroidManifest.xml', 'base/dex/classes.dex']) {
    if (!entryNames.includes(req)) {
      errors.push(`Missing critical AAB entry: "${req}". This is not a valid Android App Bundle.`);
    } else {
      console.log(`[PASS] Contains entry: ${req}`);
    }
  }

  const signatureFiles = entryNames.filter(name =>
    name.startsWith('META-INF/') && /\.(RSA|DSA|EC|SF)$/.test(name)
  );
  const isSigned = signatureFiles.length > 0;
  if (isSigned) {
    console.log(`[PASS] AAB signature present (${signatureFiles.length} file(s)).`);
  } else if (strict) {
    errors.push('AAB is unsigned and cannot be uploaded to Google Play.');
  } else {
    warnings.push('AAB has no v1 signature entries. Play Console accepts upload-key signed bundles only.');
  }

  let manifestText = '';
  let manifestBuffer = null;
  try {
    const manifestEntry = readZipEntry(zip, 'base/manifest/AndroidManifest.xml');
    manifestBuffer = manifestEntry;
    manifestText = manifestEntry ? manifestEntry.toString('utf8') : '';
  } catch (err) {
    warnings.push(`Could not decompress AAB manifest: ${err.message}`);
  }

  const manifestPackage = manifestBuffer ? readProtobufAttribute(manifestBuffer, 'package') : null;
  const manifestVersionName = manifestBuffer ? readProtobufAttribute(manifestBuffer, 'versionName') : null;
  const manifestVersionCode = manifestBuffer ? readProtobufAttribute(manifestBuffer, 'versionCode') : null;

  if (expectedConfig.package_id) {
    const pkgId = expectedConfig.package_id;
    const pkgPresent = manifestPackage === pkgId
      || manifestText.includes(pkgId)
      || zip.rawBuffer.includes(Buffer.from(pkgId, 'utf8'));
    if (!pkgPresent) {
      (strict ? errors : warnings).push(`Expected package ID "${pkgId}" was not found in the AAB manifest.`);
    } else {
      console.log(`[PASS] Package ID found in AAB manifest: "${manifestPackage || pkgId}"`);
    }
  }

  // AAB resource/manifest entries are compressed protobuf, so checks must be
  // performed against decompressed entries rather than the raw ZIP bytes.
  let bundleResourcesText = '';
  try {
    const resourcesEntry = readZipEntry(zip, 'base/resources.pb');
    if (resourcesEntry) bundleResourcesText = resourcesEntry.toString('utf8');
  } catch (err) {
    warnings.push(`Could not decompress base/resources.pb: ${err.message}`);
  }

  if (expectedConfig.version_name) {
    if (manifestVersionName !== null && manifestVersionName !== String(expectedConfig.version_name)) {
      (strict ? errors : warnings).push(
        `AAB version name mismatch. Expected "${expectedConfig.version_name}", found "${manifestVersionName}".`
      );
    } else if (manifestVersionName !== null) {
      console.log(`[PASS] AAB manifest version name verified: "${manifestVersionName}"`);
    } else {
      const versionPresent = manifestText.includes(expectedConfig.version_name)
        || bundleResourcesText.includes(expectedConfig.version_name)
        || zip.rawBuffer.includes(Buffer.from(expectedConfig.version_name, 'utf8'));
      if (!versionPresent) {
        warnings.push(`Version name "${expectedConfig.version_name}" was not found as a direct string.`);
      } else {
        console.log(`[PASS] Version name found in AAB: "${expectedConfig.version_name}"`);
      }
    }
  }

  if (expectedConfig.version_code !== undefined && expectedConfig.version_code !== null) {
    if (manifestVersionCode !== null && Number(manifestVersionCode) !== Number(expectedConfig.version_code)) {
      (strict ? errors : warnings).push(
        `AAB version code mismatch. Expected ${expectedConfig.version_code}, found ${manifestVersionCode}.`
      );
    } else if (manifestVersionCode !== null) {
      console.log(`[PASS] AAB manifest version code verified: ${manifestVersionCode}`);
    } else {
      warnings.push('AAB manifest version code could not be decoded; relying on Gradle output metadata.');
    }
  }

  if (expectedConfig.website_url) {
    const urlPresent = bundleResourcesText.includes(expectedConfig.website_url)
      || zip.rawBuffer.includes(Buffer.from(expectedConfig.website_url, 'utf8'));
    if (!urlPresent) {
      warnings.push(`Website URL "${expectedConfig.website_url}" was not found in the AAB resource table.`);
    } else {
      console.log('[PASS] Website URL found in AAB resources.');
    }
    const appConfig = extractAppConfig(zip, expectedConfig);
    if (appConfig.valid) {
      console.log(`[PASS] Bundled runtime config verified (${appConfig.entryName}).`);
    } else {
      (strict ? errors : warnings).push(...appConfig.errors);
    }
  }

  if (expectedConfig.app_name) {
    const labelPresent = bundleResourcesText.includes(expectedConfig.app_name)
      || zip.rawBuffer.includes(Buffer.from(expectedConfig.app_name, 'utf8'));
    if (!labelPresent) {
      warnings.push(`App name "${expectedConfig.app_name}" was not found as a direct string in the AAB.`);
    } else {
      console.log(`[PASS] App name found in AAB: "${expectedConfig.app_name}"`);
    }
  }

  return {
    valid: errors.length === 0,
    errors,
    warnings,
    metadata: {
      type: 'AAB',
      path: aabPath,
      sizeBytes: stat.size,
      sizeMb: `${sizeMb} MB`,
      sha256,
      entryCount: zip.totalEntries,
      isSigned,
      packageName: manifestPackage || expectedConfig.package_id || null,
      versionName: manifestVersionName || expectedConfig.version_name || null,
      versionCode: manifestVersionCode || expectedConfig.version_code || null
    }
  };
}

function findBuildArtifacts(projectDir) {
  const apkDir = path.join(projectDir, 'app', 'build', 'outputs', 'apk');
  const bundleDir = path.join(projectDir, 'app', 'build', 'outputs', 'bundle');
  const apks = [];
  const aabs = [];

  function scan(dir, ext, list) {
    if (!fs.existsSync(dir)) return;
    for (const item of fs.readdirSync(dir)) {
      const full = path.join(dir, item);
      const stat = fs.statSync(full);
      if (stat.isDirectory()) scan(full, ext, list);
      else if (item.endsWith(ext)) list.push(full);
    }
  }

  scan(apkDir, '.apk', apks);
  scan(bundleDir, '.aab', aabs);
  return { apks, aabs };
}

// CLI entrypoint
if (require.main === module) {
  const args = process.argv.slice(2);
  let targetApk = null;
  let targetAab = null;
  let configPath = path.join(__dirname, '..', 'config', 'default-app.json');
  let strict = false;

  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--apk' && args[i + 1]) targetApk = args[++i];
    else if (args[i] === '--aab' && args[i + 1]) targetAab = args[++i];
    else if (args[i] === '--config' && args[i + 1]) configPath = args[++i];
    else if (args[i] === '--strict') strict = true;
  }

  let expectedConfig = {};
  if (fs.existsSync(configPath)) {
    try {
      expectedConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    } catch (e) {
      console.error(`Invalid config JSON: ${e.message}`);
      process.exit(1);
    }
  }

  if (!targetApk && !targetAab) {
    const androidDir = path.join(__dirname, '..', 'app-template', 'android');
    const discovered = findBuildArtifacts(androidDir);
    if (discovered.apks.length > 0) targetApk = discovered.apks[0];
    if (discovered.aabs.length > 0) targetAab = discovered.aabs[0];
  }

  console.log('=== Bizgrowth360 Android Output Artifact Validator ===');
  let hasFailures = false;

  if (targetApk) {
    const apkResult = validateApk(targetApk, expectedConfig, { strict });
    if (!apkResult.valid) {
      hasFailures = true;
      console.error('\n[FAILED] APK Validation failed:');
      apkResult.errors.forEach(e => console.error(`  - ${e}`));
    } else {
      console.log('\n[SUCCESS] APK is structurally valid and ready for installation.');
    }
  }

  if (targetAab) {
    const aabResult = validateAab(targetAab, expectedConfig, { strict });
    if (!aabResult.valid) {
      hasFailures = true;
      console.error('\n[FAILED] AAB Validation failed:');
      aabResult.errors.forEach(e => console.error(`  - ${e}`));
    } else {
      console.log('\n[SUCCESS] AAB is structurally valid and ready for Google Play.');
    }
  }

  if (!targetApk && !targetAab) {
    console.error('Error: No APK or AAB files specified or found in build outputs.');
    process.exit(1);
  }

  process.exit(hasFailures ? 1 : 0);
}

module.exports = {
  validateApk,
  validateAab,
  inspectZipArchive,
  readZipEntry,
  computeSha256,
  findBuildArtifacts,
  findAapt2,
  readApkBadging
};
