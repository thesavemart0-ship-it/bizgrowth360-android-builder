/**
 * iOS engine tests: configuration, project generation, IPA validation and
 * workflow security invariants.
 *
 * Run with: node --test tests/ios.test.js
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const yaml = require('yaml');
const plist = require('plist');

const ROOT = path.resolve(__dirname, '..');
const TEMPLATE = path.join(ROOT, 'app-template');

const {
  validateBundleId,
  defaultBundleId,
  validateIosConfig,
  normalizeIosConfig
} = require('../scripts/ios-config');
const { prepareIosApp } = require('../scripts/prepare-ios');
const { validateIpa } = require('../scripts/validate-ipa');
const { buildIos } = require('../scripts/build-ios');
const { defaultDesign } = require('../scripts/design-config');
const { defaultFeatures } = require('../scripts/native-features-config');

const BASE_CONFIG = {
  app_name: 'Mahankali Store',
  package_id: 'in.bizgrowth360.mahankalistore',
  website_url: 'https://mahankalistore.example.com',
  version_name: '2.3.1',
  version_code: 12,
  primary_color: '#7C3AED',
  background_color: '#FFFFFF',
  app_icon: 'public/icon.png',
  splash_image: 'public/splash.png',
  orientation: 'portrait',
  allow_external_links: true,
  enable_status_bar: true,
  build_variant: 'release'
};

const fixtures = [];

function createProjectFixture(label = 'ios') {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), `bg360-${label}-`));
  const project = path.join(root, 'app-template');
  fs.cpSync(TEMPLATE, project, {
    recursive: true,
    filter: (src) => {
      const rel = path.relative(TEMPLATE, src);
      if (!rel) return true;
      const parts = rel.split(path.sep);
      if (parts.includes('node_modules') || parts.includes('.git') || parts.includes('build') || parts.includes('Pods')) {
        return false;
      }
      return true;
    }
  });
  fixtures.push(root);
  return project;
}

function parsePlist(filePath) {
  return plist.parse(fs.readFileSync(filePath, 'utf8'));
}

test.after(() => {
  for (const dir of fixtures) {
    try {
      fs.rmSync(dir, { recursive: true, force: true });
    } catch (err) {
      // ignore cleanup failure
    }
  }
});

test('iOS bundle identifier validation accepts valid reverse-DNS IDs', () => {
  for (const id of ['in.bizgrowth360.demoapp', 'com.example.my-app', 'com.360app.x']) {
    assert.equal(validateBundleId(id).valid, true, id);
  }
});

test('iOS bundle identifier validation rejects invalid values', () => {
  for (const id of ['', '-bad.com', 'com..x', 'com.example_underscore', 'single', 'com.example.']) {
    assert.equal(validateBundleId(id).valid, false, `expected invalid: ${id}`);
  }
});

test('default iOS bundle ID converts underscores and removes invalid characters', () => {
  assert.equal(defaultBundleId('in.bizgrowth_360.demo'), 'in.bizgrowth-360.demo');
  assert.equal(defaultBundleId('com.example_app'), 'com.example-app');
});

test('iOS configuration enforces numeric version and export method', () => {
  const good = validateIosConfig(BASE_CONFIG, { exportMethod: 'app-store', mode: 'ipa' });
  assert.equal(good.valid, true);
  assert.equal(good.ios.bundle_id, 'in.bizgrowth360.mahankalistore');
  assert.equal(good.ios.build_number, 12);

  const badVersion = validateIosConfig({ ...BASE_CONFIG, version_name: '1.0.0-beta' }, {});
  assert.equal(badVersion.valid, false);

  const badExport = normalizeIosConfig(BASE_CONFIG, { exportMethod: 'not-real' });
  assert.equal(badExport.export_method, 'app-store');

  const testflightWithoutConfirm = validateIosConfig(BASE_CONFIG, {
    exportMethod: 'app-store',
    uploadToTestflight: true,
    confirmAppStoreUpload: false
  });
  assert.equal(testflightWithoutConfirm.valid, true);
  assert.ok(testflightWithoutConfirm.warnings.some(w => w.includes('explicit confirmation')));
});

test('iOS project generation is isolated and applies app/design/feature settings', async () => {
  const project = createProjectFixture('ios-prepare');
  const templateInfoBefore = fs.readFileSync(path.join(TEMPLATE, 'ios/App/App/Info.plist'), 'utf8');
  const templatePbxBefore = fs.readFileSync(path.join(TEMPLATE, 'ios/App/App.xcodeproj/project.pbxproj'), 'utf8');

  const features = defaultFeatures(BASE_CONFIG);
  features.push_notifications.enabled = true;
  features.deep_linking.enabled = true;
  features.deep_linking.domain = 'mahankalistore.example.com';
  features.deep_linking.scheme = 'mahankali';
  features.camera_upload.camera_enabled = true;
  features.camera_upload.gallery_enabled = true;
  features.permission_manager.location_enabled = true;

  const result = await prepareIosApp({
    projectDir: project,
    config: { ...BASE_CONFIG, ios_bundle_id: 'in.bizgrowth360.mahankalistore' },
    design: defaultDesign(BASE_CONFIG),
    features,
    exportMethod: 'app-store',
    teamId: 'ABCDE12345'
  });

  const appDir = path.join(project, 'ios/App/App');
  const info = parsePlist(path.join(appDir, 'Info.plist'));
  const entitlements = parsePlist(path.join(appDir, 'App.entitlements'));
  const pbx = fs.readFileSync(path.join(project, 'ios/App/App.xcodeproj/project.pbxproj'), 'utf8');
  const icon = fs.statSync(path.join(appDir, 'Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png'));

  assert.equal(info.CFBundleDisplayName, 'Mahankali Store');
  assert.equal(info.CFBundleShortVersionString, '2.3.1');
  assert.equal(info.CFBundleVersion, '12');
  assert.ok(info.NSCameraUsageDescription);
  assert.ok(info.NSPhotoLibraryUsageDescription);
  assert.ok(info.NSLocationWhenInUseUsageDescription);
  assert.ok(info.UIBackgroundModes.includes('remote-notification'));
  assert.ok(info.CFBundleURLTypes.some(t => t.CFBundleURLSchemes.includes('mahankali')));
  assert.deepEqual(info.UIRequiredDeviceCapabilities, ['arm64']);

  assert.equal(entitlements['aps-environment'], 'production');
  assert.ok(entitlements['com.apple.developer.associated-domains'].includes('applinks:mahankalistore.example.com'));

  assert.ok(pbx.includes('PRODUCT_BUNDLE_IDENTIFIER = in.bizgrowth360.mahankalistore;'));
  assert.ok(pbx.includes('MARKETING_VERSION = 2.3.1;'));
  assert.ok(pbx.includes('CURRENT_PROJECT_VERSION = 12;'));
  assert.ok(pbx.includes('DEVELOPMENT_TEAM = ABCDE12345;'));
  assert.ok(pbx.includes('CODE_SIGN_ENTITLEMENTS = App/App.entitlements;'));

  assert.ok(icon.size > 1000);
  assert.equal(result.assets.icon.source, 'custom');

  // Shared template must remain untouched.
  assert.equal(fs.readFileSync(path.join(TEMPLATE, 'ios/App/App/Info.plist'), 'utf8'), templateInfoBefore);
  assert.equal(fs.readFileSync(path.join(TEMPLATE, 'ios/App/App.xcodeproj/project.pbxproj'), 'utf8'), templatePbxBefore);
});

test('iOS dry run reports success but never claims an IPA', async () => {
  const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'bg360-ios-out-'));
  fixtures.push(outputDir);

  const summary = await buildIos({
    dryRun: true,
    buildId: 'ios-unit-test',
    outputDir,
    configJson: JSON.stringify(BASE_CONFIG)
  });

  assert.equal(summary.status, 'success');
  assert.equal(summary.platform, 'ios');
  assert.equal(summary.artifact, null);
  assert.ok(summary.checks.some(c => c.name === 'Dry run' && c.passed));
  assert.ok(summary.checks.some(c => c.name === 'IPA export' && c.detail.includes('no IPA is claimed')) === false);
  assert.ok(fs.existsSync(path.join(outputDir, 'build-summary.json')));
  assert.ok(fs.existsSync(path.join(outputDir, 'build-log')) === false);
});

function crc32(buffer) {
  let crc = 0xFFFFFFFF;
  for (let i = 0; i < buffer.length; i++) {
    crc ^= buffer[i];
    for (let j = 0; j < 8; j++) {
      crc = (crc >>> 1) ^ (0xEDB88320 & -(crc & 1));
    }
  }
  return (crc ^ 0xFFFFFFFF) >>> 0;
}

/**
 * Build a minimal store-only ZIP with forward-slash entry names, matching the
 * structure of a real IPA. Used to test the validator without Xcode.
 */
function buildStoredZip(entries) {
  const localParts = [];
  const centralParts = [];
  let offset = 0;

  for (const entry of entries) {
    const name = Buffer.from(entry.name, 'utf8');
    const data = Buffer.isBuffer(entry.data) ? entry.data : Buffer.from(entry.data);
    const crc = crc32(data);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6);
    local.writeUInt16LE(0, 8);
    local.writeUInt16LE(0, 10);
    local.writeUInt16LE(0, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(data.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(name.length, 26);
    local.writeUInt16LE(0, 28);

    localParts.push(local, name, data);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt16LE(0, 10);
    central.writeUInt16LE(0, 12);
    central.writeUInt16LE(0, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(data.length, 20);
    central.writeUInt32LE(data.length, 24);
    central.writeUInt16LE(name.length, 28);
    central.writeUInt16LE(0, 30);
    central.writeUInt16LE(0, 32);
    central.writeUInt16LE(0, 34);
    central.writeUInt16LE(0, 36);
    central.writeUInt32LE(0, 38);
    central.writeUInt32LE(offset, 42);

    centralParts.push(central, name);
    offset += local.length + name.length + data.length;
  }

  const centralBuffer = Buffer.concat(centralParts);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0);
  eocd.writeUInt16LE(0, 4);
  eocd.writeUInt16LE(0, 6);
  eocd.writeUInt16LE(entries.length, 8);
  eocd.writeUInt16LE(entries.length, 10);
  eocd.writeUInt32LE(centralBuffer.length, 12);
  eocd.writeUInt32LE(offset, 16);
  eocd.writeUInt16LE(0, 20);

  return Buffer.concat([Buffer.concat(localParts), centralBuffer, eocd]);
}

function createSyntheticIpa({ signed = true } = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'bg360-ipa-'));
  fixtures.push(dir);
  const ipaPath = path.join(dir, 'test.ipa');

  const infoPlist = `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
<key>CFBundleIdentifier</key><string>in.bizgrowth360.mahankalistore</string>
<key>CFBundleShortVersionString</key><string>2.3.1</string>
<key>CFBundleVersion</key><string>12</string>
<key>CFBundleDisplayName</key><string>Mahankali Store</string>
</dict></plist>`;

  const entries = [
    { name: 'Payload/App.app/Info.plist', data: infoPlist },
    { name: 'Payload/App.app/App', data: Buffer.alloc(200000, 0x41) },
    { name: 'Payload/App.app/embedded.mobileprovision', data: 'profile' }
  ];
  if (signed) {
    entries.push({ name: 'Payload/App.app/_CodeSignature/CodeResources', data: '<plist></plist>' });
  }

  fs.writeFileSync(ipaPath, buildStoredZip(entries));
  return ipaPath;
}

test('IPA validator accepts a structurally valid signed IPA', () => {
  const ipa = createSyntheticIpa({ signed: true });
  const result = validateIpa(ipa, {
    bundleId: 'in.bizgrowth360.mahankalistore',
    versionName: '2.3.1',
    buildNumber: 12
  });

  if (process.platform === 'darwin') {
    // A synthetic archive has no real Apple signature; codesign must fail.
    assert.equal(result.valid, false);
    assert.ok(result.errors.some(e => e.includes('codesign')));
  } else {
    assert.equal(result.valid, true, result.errors.join('; '));
    assert.equal(result.metadata.executable, 'App');
    assert.ok(result.metadata.sha256.length === 64);
  }
});

test('IPA validator rejects an unsigned archive and a non-ZIP file', () => {
  const unsigned = createSyntheticIpa({ signed: false });
  const unsignedResult = validateIpa(unsigned, {});
  assert.equal(unsignedResult.valid, false);
  assert.ok(unsignedResult.errors.some(e => e.includes('not code-signed')));

  const badPath = path.join(path.dirname(unsigned), 'bad.ipa');
  fs.writeFileSync(badPath, 'this is not a zip');
  const badResult = validateIpa(badPath, {});
  assert.equal(badResult.valid, false);
  assert.ok(badResult.errors.some(e => e.includes('not a valid ZIP')));
});

test('iOS workflow is macOS-only, secret-driven and gated for TestFlight', () => {
  const workflowPath = path.join(ROOT, '.github', 'workflows', 'ios-build.yml');
  const raw = fs.readFileSync(workflowPath, 'utf8');
  const workflow = yaml.parse(raw);

  assert.ok(workflow.on.workflow_dispatch);
  assert.equal(workflow.on.push, undefined, 'iOS workflow must not run on push');
  assert.ok(String(workflow.jobs['build-ios']['runs-on']).includes('inputs.macos_runner'));
  assert.ok(workflow.jobs['build-ios']['timeout-minutes'] <= 60);

  const inputs = workflow.on.workflow_dispatch.inputs;
  assert.ok(inputs.upload_to_testflight);
  assert.ok(inputs.confirm_app_store_upload);
  assert.ok(inputs.ios_bundle_id);
  assert.ok(inputs.ios_mode);
  assert.ok(inputs.macos_runner);

  for (const secret of [
    'IOS_CERTIFICATE_BASE64',
    'IOS_CERTIFICATE_PASSWORD',
    'IOS_PROVISIONING_PROFILE_BASE64',
    'APPSTORE_CONNECT_KEY_ID',
    'APPSTORE_CONNECT_ISSUER_ID',
    'APPSTORE_CONNECT_PRIVATE_KEY'
  ]) {
    assert.ok(raw.includes(`secrets.${secret}`), `missing secret reference: ${secret}`);
  }

  assert.ok(!/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(raw), 'workflow must not embed private keys');
  assert.ok(!raw.includes('--submit-app'), 'workflow must never submit an App Store release');
  assert.ok(raw.includes('ios_mode != \'validation\''), 'signing must be skipped in validation mode');
  assert.ok(raw.includes('openssl base64 -d -A'), 'macOS-compatible base64 decoding');
});
