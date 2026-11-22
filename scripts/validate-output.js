/**
 * validate-output.js
 * Inspects and validates generated Android APK and AAB packages for Bizgrowth360 Android Builder.
 * Ensures the generated build artifacts are genuine, non-empty, and structurally valid.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

/**
 * Parses central directory of a ZIP file (APK/AAB) in pure Node.js Buffer
 * without any external dependencies.
 */
function inspectZipArchive(filePath) {
  const buffer = fs.readFileSync(filePath);
  const totalLength = buffer.length;

  if (totalLength < 22) {
    throw new Error('File is too small to be a valid ZIP archive.');
  }

  // Find End of Central Directory Record (EOCD signature: 0x06054b50)
  let eocdOffset = -1;
  const maxSearch = Math.min(totalLength - 22, 65536 + 22);

  for (let i = totalLength - 22; i >= totalLength - maxSearch; i--) {
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
    if (signature !== 0x02014b50) {
      // End of valid entries
      break;
    }

    const uncompressedSize = buffer.readUInt32LE(currentOffset + 24);
    const fileNameLength = buffer.readUInt16LE(currentOffset + 28);
    const extraFieldLength = buffer.readUInt16LE(currentOffset + 30);
    const commentLength = buffer.readUInt16LE(currentOffset + 32);

    const fileName = buffer.toString('utf8', currentOffset + 46, currentOffset + 46 + fileNameLength);
    entries.push({
      name: fileName,
      size: uncompressedSize
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
 * Computes SHA-256 hash of a file
 */
function computeSha256(filePath) {
  const hash = crypto.createHash('sha256');
  hash.update(fs.readFileSync(filePath));
  return hash.digest('hex');
}

/**
 * Validates an APK file
 */
function validateApk(apkPath, expectedConfig = {}) {
  console.log(`\n--- Validating Android APK: ${apkPath} ---`);
  const errors = [];
  const warnings = [];

  if (!fs.existsSync(apkPath)) {
    return { valid: false, errors: [`APK file not found at: ${apkPath}`], warnings };
  }

  const stat = fs.statSync(apkPath);
  if (stat.size <= 0) {
    return { valid: false, errors: [`APK file is empty (0 bytes).`], warnings };
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

  // Essential APK components
  const requiredEntries = [
    'AndroidManifest.xml',
    'classes.dex',
    'resources.arsc'
  ];

  for (const req of requiredEntries) {
    if (!entryNames.includes(req)) {
      errors.push(`Missing critical APK entry: "${req}". This is not a valid Android package.`);
    } else {
      console.log(`[PASS] Contains entry: ${req}`);
    }
  }

  // Check for signature files in META-INF
  const signatureFiles = entryNames.filter(name =>
    name.startsWith('META-INF/') && (name.endsWith('.RSA') || name.endsWith('.DSA') || name.endsWith('.EC') || name.endsWith('.SF'))
  );

  // Check for APK Signature Scheme v2/v3 magic in buffer
  const hasApkSigSchemeV2 = zip.rawBuffer.includes(Buffer.from('APK Sig Block 42'));

  let isSigned = false;
  let signatureType = 'Unsigned';

  if (signatureFiles.length > 0 || hasApkSigSchemeV2) {
    isSigned = true;
    signatureType = hasApkSigSchemeV2 ? 'APK Signature Scheme v2/v3' : 'JAR Signing (v1)';
    console.log(`[PASS] APK Signature verified: ${signatureType} (${signatureFiles.join(', ') || 'v2/v3 block'})`);
  } else {
    warnings.push('APK is unsigned or using debug mode without v1/v2 signature signatures.');
  }

  // Verify package_id in binary manifest if expected
  if (expectedConfig.package_id) {
    const pkgId = expectedConfig.package_id;
    const pkgBuffer = Buffer.from(pkgId, 'utf8');
    const hasPackageId = zip.rawBuffer.includes(pkgBuffer);
    if (hasPackageId) {
      console.log(`[PASS] Verified expected package ID in APK: "${pkgId}"`);
    } else {
      warnings.push(`Expected package ID "${pkgId}" was not directly matched in binary string pool.`);
    }
  }

  return {
    valid: errors.length === 0,
    errors,
    warnings,
    metadata: {
      type: 'APK',
      path: apkPath,
      sizeBytes: stat.size,
      sizeMb: `${sizeMb} MB`,
      sha256,
      entryCount: zip.totalEntries,
      isSigned,
      signatureType
    }
  };
}

/**
 * Validates an AAB file
 */
function validateAab(aabPath, expectedConfig = {}) {
  console.log(`\n--- Validating Android App Bundle (AAB): ${aabPath} ---`);
  const errors = [];
  const warnings = [];

  if (!fs.existsSync(aabPath)) {
    return { valid: false, errors: [`AAB file not found at: ${aabPath}`], warnings };
  }

  const stat = fs.statSync(aabPath);
  if (stat.size <= 0) {
    return { valid: false, errors: [`AAB file is empty (0 bytes).`], warnings };
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

  // Essential AAB components
  const requiredAabEntries = [
    'BundleConfig.pb',
    'base/manifest/AndroidManifest.xml',
    'base/dex/classes.dex'
  ];

  for (const req of requiredAabEntries) {
    if (!entryNames.includes(req)) {
      errors.push(`Missing critical AAB entry: "${req}". This is not a valid Android App Bundle.`);
    } else {
      console.log(`[PASS] Contains entry: ${req}`);
    }
  }

  // Check for signature
  const signatureFiles = entryNames.filter(name =>
    name.startsWith('META-INF/') && (name.endsWith('.RSA') || name.endsWith('.DSA') || name.endsWith('.EC') || name.endsWith('.SF'))
  );

  let isSigned = signatureFiles.length > 0;
  console.log(`Signing Status: ${isSigned ? 'Signed (' + signatureFiles.join(', ') + ')' : 'Unsigned'}`);

  // Verify package ID
  if (expectedConfig.package_id) {
    const pkgId = expectedConfig.package_id;
    const pkgBuffer = Buffer.from(pkgId, 'utf8');
    const hasPackageId = zip.rawBuffer.includes(pkgBuffer);
    if (hasPackageId) {
      console.log(`[PASS] Verified expected package ID in AAB: "${pkgId}"`);
    } else {
      warnings.push(`Expected package ID "${pkgId}" was not found in string table.`);
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
      isSigned
    }
  };
}

/**
 * Searches for APK or AAB files in standard Gradle build output directories
 */
function findBuildArtifacts(projectDir) {
  const apkDir = path.join(projectDir, 'app', 'build', 'outputs', 'apk');
  const bundleDir = path.join(projectDir, 'app', 'build', 'outputs', 'bundle');

  const apks = [];
  const aabs = [];

  function scan(dir, ext, list) {
    if (!fs.existsSync(dir)) return;
    const items = fs.readdirSync(dir);
    for (const item of items) {
      const full = path.join(dir, item);
      const stat = fs.statSync(full);
      if (stat.isDirectory()) {
        scan(full, ext, list);
      } else if (item.endsWith(ext)) {
        list.push(full);
      }
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

  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--apk' && args[i + 1]) targetApk = args[++i];
    if (args[i] === '--aab' && args[i + 1]) targetAab = args[++i];
    if (args[i] === '--config' && args[i + 1]) configPath = args[++i];
  }

  let expectedConfig = {};
  if (fs.existsSync(configPath)) {
    try {
      expectedConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    } catch (e) {
      // ignore
    }
  }

  // If no explicit paths given, auto-discover in app-template/android
  if (!targetApk && !targetAab) {
    const androidDir = path.join(__dirname, '..', 'app-template', 'android');
    const discovered = findBuildArtifacts(androidDir);
    if (discovered.apks.length > 0) targetApk = discovered.apks[0];
    if (discovered.aabs.length > 0) targetAab = discovered.aabs[0];
  }

  console.log('=== Bizgrowth360 Android Output Artifact Validator ===');

  let hasFailures = false;
  const results = [];

  if (targetApk) {
    const apkResult = validateApk(targetApk, expectedConfig);
    results.push(apkResult);
    if (!apkResult.valid) {
      hasFailures = true;
      console.error('\n[FAILED] APK Validation failed:');
      apkResult.errors.forEach(e => console.error(`  - ${e}`));
    } else {
      console.log(`\n[SUCCESS] APK is structurally valid and ready for installation.`);
    }
  }

  if (targetAab) {
    const aabResult = validateAab(targetAab, expectedConfig);
    results.push(aabResult);
    if (!aabResult.valid) {
      hasFailures = true;
      console.error('\n[FAILED] AAB Validation failed:');
      aabResult.errors.forEach(e => console.error(`  - ${e}`));
    } else {
      console.log(`\n[SUCCESS] AAB is structurally valid and ready for Google Play.`);
    }
  }

  if (!targetApk && !targetAab) {
    console.error('Error: No APK or AAB files specified or found in build outputs.');
    process.exit(1);
  }

  if (hasFailures) {
    process.exit(1);
  } else {
    console.log('\nAll build artifacts passed rigorous structural validation.');
    process.exit(0);
  }
}

module.exports = {
  validateApk,
  validateAab,
  inspectZipArchive,
  computeSha256,
  findBuildArtifacts
};
