/**
 * Unit and isolation tests for the dynamic Android build engine.
 * Run with: node --test tests/unit.test.js
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const TEMPLATE = path.join(ROOT, 'app-template');

const {
  validateConfig,
  validatePackageId,
  validateWebsiteUrl,
  validateAssetUrl,
  validateColor,
  escapeAndroidString,
  sanitizeFileComponent,
  buildArtifactBaseName,
  findDuplicatePackageIds
} = require('../scripts/validate-config');
const { prepareApp, isLightColor } = require('../scripts/prepare-app');
const {
  generateIcons,
  validateImageAsset,
  resolveAssetPath
} = require('../scripts/generate-icons');
const {
  buildTasks,
  downloadAsset,
  loadConfiguration,
  loadDesign
} = require('../scripts/build-app');
const {
  defaultDesign,
  validateDesign,
  sanitizeDesign,
  applyDesignToConfig,
  designSummary
} = require('../scripts/design-config');
const {
  defaultFeatures,
  validateFeatures,
  featuresSummary
} = require('../scripts/native-features-config');
const { loadFeatures } = require('../scripts/build-app');
const { validateApk, validateAab, inspectZipArchive } = require('../scripts/validate-output');

const BASE_CONFIG = {
  app_name: 'Mahankali Store',
  package_id: 'in.bizgrowth360.mahankalistore',
  website_url: 'https://mahankalistore.example.com',
  version_name: '1.0.0',
  version_code: 1,
  primary_color: '#7C3AED',
  background_color: '#FFFFFF'
};

const EXCLUDED = new Set([
  'node_modules',
  '.gradle',
  'build',
  'capacitor-cordova-android-plugins',
  'local.properties'
]);

const fixtures = [];

function createProjectFixture(label = 'test') {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), `bg360-${label}-`));
  const project = path.join(root, 'app-template');
  fs.cpSync(TEMPLATE, project, {
    recursive: true,
    filter: (src) => {
      const rel = path.relative(TEMPLATE, src);
      if (!rel) return true;
      const parts = rel.split(path.sep);
      if (parts.some(p => EXCLUDED.has(p))) return false;
      if (rel === path.join('android', 'app', 'src', 'main', 'assets')) return false;
      if (rel.startsWith(path.join('android', 'app', 'src', 'main', 'assets') + path.sep)) return false;
      return true;
    }
  });
  fixtures.push(root);
  return { root, project };
}

test.after(() => {
  for (const dir of fixtures) {
    try {
      fs.rmSync(dir, { recursive: true, force: true });
    } catch (e) {
      // best effort
    }
  }
});

// ---------------------------------------------------------------------------
// Configuration validation
// ---------------------------------------------------------------------------

test('valid configuration normalizes branding and build fields', () => {
  const result = validateConfig({
    ...BASE_CONFIG,
    splash_background_color: '#f5f3ff',
    status_bar_color: '#7c3aed',
    navigation_bar_color: '#fff',
    orientation: 'PORTRAIT',
    allow_external_links: false,
    enable_status_bar: false,
    build_type: 'aab',
    build_variant: 'debug'
  });
  assert.equal(result.valid, true, result.errors.join('; '));
  assert.equal(result.normalizedConfig.splash_background_color, '#F5F3FF');
  assert.equal(result.normalizedConfig.navigation_bar_color, '#FFFFFF');
  assert.equal(result.normalizedConfig.orientation, 'portrait');
  assert.equal(result.normalizedConfig.allow_external_links, false);
  assert.equal(result.normalizedConfig.enable_status_bar, false);
  assert.equal(result.normalizedConfig.build_type, 'aab');
  assert.equal(result.normalizedConfig.build_variant, 'debug');
});

test('invalid package IDs are rejected', () => {
  const invalid = [
    '',
    'bizgrowth360',
    'com.123app',
    'com.my-app.app',
    'com..app',
    '.com.app',
    'com.app.',
    'com.switch.app',
    'com.app two',
    'com.class'
  ];
  for (const id of invalid) {
    const result = validatePackageId(id);
    assert.equal(result.valid, false, `Expected "${id}" to be rejected`);
  }
  assert.equal(validatePackageId('in.bizgrowth360.mahankalistore').valid, true);
  assert.equal(validatePackageId('com.example.app_2').valid, true);
});

test('duplicate package IDs are detected across configurations', () => {
  const duplicates = findDuplicatePackageIds([
    { package_id: 'in.bizgrowth360.one' },
    { package_id: 'in.bizgrowth360.two' },
    { package_id: 'in.bizgrowth360.one' }
  ]);
  assert.deepEqual(duplicates, ['in.bizgrowth360.one']);
});

test('website URL validation rejects insecure and dangerous schemes', () => {
  assert.equal(validateWebsiteUrl('https://example.com').valid, true);
  assert.equal(validateWebsiteUrl('https://example.com/path?x=1').valid, true);
  for (const url of [
    'http://example.com',
    'javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'file:///etc/passwd',
    'ftp://example.com',
    'https://user:pass@example.com',
    'not a url',
    'https://exa mple.com'
  ]) {
    assert.equal(validateWebsiteUrl(url).valid, false, `Expected URL to be rejected: ${url}`);
  }
});

test('asset URLs must be HTTPS without credentials', () => {
  assert.equal(validateAssetUrl('https://cdn.example.com/icon.png', 'icon_url').valid, true);
  assert.equal(validateAssetUrl('', 'icon_url').valid, true);
  assert.equal(validateAssetUrl('http://cdn.example.com/icon.png', 'icon_url').valid, false);
  assert.equal(validateAssetUrl('https://u:p@cdn.example.com/icon.png', 'icon_url').valid, false);
  assert.equal(validateAssetUrl('javascript:alert(1)', 'icon_url').valid, false);
  assert.equal(validateAssetUrl('https://127.0.0.1/icon.png', 'icon_url').valid, false);
  assert.equal(validateAssetUrl('https://localhost/icon.png', 'icon_url').valid, false);
  assert.equal(validateAssetUrl('https://192.168.1.10/icon.png', 'icon_url').valid, false);
  assert.equal(validateAssetUrl('https://169.254.169.254/latest/meta-data/', 'icon_url').valid, false);
});

test('color validation accepts #RGB/#RRGGBB and rejects alpha/names', () => {
  assert.equal(validateColor('#fff', 'primary_color').value, '#FFFFFF');
  assert.equal(validateColor('#2563eb', 'primary_color').value, '#2563EB');
  assert.equal(validateColor('blue', 'primary_color').valid, false);
  assert.equal(validateColor('#12345', 'primary_color').valid, false);
  assert.equal(validateColor('#2563EB80', 'primary_color').valid, false);
});

test('version name and version code validation', () => {
  for (const version of ['1.0', '1.0.0', '2.1.4-beta', '10.20.30.40']) {
    assert.equal(validateConfig({ ...BASE_CONFIG, version_name: version }).valid, true, version);
  }
  for (const version of ['', 'v1.0.0', '1.0.0 build 2', '1..0', '-1.0']) {
    assert.equal(validateConfig({ ...BASE_CONFIG, version_name: version }).valid, false, version);
  }
  for (const code of [0, -1, 1.5, 'abc', true, [1], 2100000001]) {
    assert.equal(validateConfig({ ...BASE_CONFIG, version_code: code }).valid, false, String(code));
  }
  assert.equal(validateConfig({ ...BASE_CONFIG, version_code: 42 }).valid, true);
  assert.equal(validateConfig({ ...BASE_CONFIG, version_code: '42' }).valid, true);
});

test('legacy build_type debug/release maps to build_variant', () => {
  const result = validateConfig({ ...BASE_CONFIG, build_type: 'debug', build_target: 'apk' });
  assert.equal(result.valid, true);
  assert.equal(result.normalizedConfig.build_variant, 'debug');
  assert.equal(result.normalizedConfig.build_type, 'apk');
});

test('invalid booleans, orientation and build type are rejected', () => {
  assert.equal(validateConfig({ ...BASE_CONFIG, allow_external_links: 'yes' }).valid, false);
  assert.equal(validateConfig({ ...BASE_CONFIG, enable_status_bar: 1 }).valid, false);
  assert.equal(validateConfig({ ...BASE_CONFIG, orientation: 'diagonal' }).valid, false);
  assert.equal(validateConfig({ ...BASE_CONFIG, build_type: 'zip' }).valid, false);
  assert.equal(validateConfig({ ...BASE_CONFIG, build_variant: 'prod' }).valid, false);
});

test('app names reject control characters and excessive length', () => {
  assert.equal(validateConfig({ ...BASE_CONFIG, app_name: 'A'.repeat(61) }).valid, false);
  assert.equal(validateConfig({ ...BASE_CONFIG, app_name: 'Bad\nName' }).valid, false);
  assert.equal(validateConfig({ ...BASE_CONFIG, app_name: '   ' }).valid, false);
  assert.equal(validateConfig({ ...BASE_CONFIG, app_name: 'Mahankali Store' }).valid, true);
});

// ---------------------------------------------------------------------------
// Naming / XML safety
// ---------------------------------------------------------------------------

test('artifact naming is safe against traversal and invalid characters', () => {
  assert.equal(buildArtifactBaseName('Mahankali Store', '1.0.0'), 'Mahankali-Store-1.0.0');
  assert.equal(buildArtifactBaseName('../../Mahankali/Store', '1.0.0'), 'Mahankali-Store-1.0.0');
  assert.equal(buildArtifactBaseName('My:App*?<>|', '2.0.0'), 'My-App-2.0.0');
  assert.equal(sanitizeFileComponent('CON'), 'app-CON');
  assert.equal(sanitizeFileComponent('...'), 'App');
  assert.ok(buildArtifactBaseName('A'.repeat(200), '1.0.0').length <= 60 + 1 + 32);
});

test('Android string escaping prevents XML/AAPT injection', () => {
  const escaped = escapeAndroidString("Mahankali's <Store> & Co\\");
  assert.ok(escaped.includes("\\'"));
  assert.ok(escaped.includes('&lt;Store&gt;'));
  assert.ok(escaped.includes('&amp;'));
  assert.ok(escaped.includes('\\\\'));
  assert.ok(!escaped.includes('<Store>'));
});

test('system bar icon contrast helper', () => {
  assert.equal(isLightColor('#FFFFFF'), true);
  assert.equal(isLightColor('#000000'), false);
  assert.equal(isLightColor('#7C3AED'), false);
});

// ---------------------------------------------------------------------------
// Preparation and generation
// ---------------------------------------------------------------------------

test('prepare-app applies app name, package, version, URL and branding', () => {
  const { project } = createProjectFixture('prepare');
  const config = {
    ...BASE_CONFIG,
    app_name: "Mahankali's Store & Co",
    package_id: 'in.bizgrowth360.specialapp',
    website_url: 'https://shop.example.com/path?a=1&b=2',
    version_name: '2.3.4',
    version_code: 17,
    primary_color: '#123456',
    background_color: '#F0F0F0',
    splash_background_color: '#EEEEEE',
    status_bar_color: '#111111',
    navigation_bar_color: '#FFFFFF',
    orientation: 'landscape',
    allow_external_links: false,
    enable_status_bar: false
  };

  const prepared = prepareApp({ projectDir: project, config });
  assert.equal(prepared.package_id, config.package_id);

  const strings = fs.readFileSync(
    path.join(project, 'android/app/src/main/res/values/strings.xml'),
    'utf8'
  );
  assert.ok(strings.includes('name="app_name"'));
  assert.ok(strings.includes("Mahankali\\'s Store &amp; Co"));
  assert.ok(!strings.includes("Mahankali's Store & Co"));
  assert.ok(strings.includes('in.bizgrowth360.specialapp'));

  const gradle = fs.readFileSync(path.join(project, 'android/app/build.gradle'), 'utf8');
  assert.ok(gradle.includes('applicationId "in.bizgrowth360.specialapp"'));
  assert.ok(gradle.includes('namespace "in.bizgrowth360.specialapp"'));
  assert.ok(gradle.includes('versionCode 17'));
  assert.ok(gradle.includes('versionName "2.3.4"'));

  const bools = fs.readFileSync(
    path.join(project, 'android/app/src/main/res/values/bools.xml'),
    'utf8'
  );
  assert.ok(bools.includes('name="allow_external_links">false'));
  assert.ok(bools.includes('name="enable_status_bar">false'));

  const manifest = fs.readFileSync(
    path.join(project, 'android/app/src/main/AndroidManifest.xml'),
    'utf8'
  );
  assert.ok(manifest.includes('android:screenOrientation="landscape"'));
  assert.ok(manifest.includes('android:usesCleartextTraffic="false"'));
  assert.ok(manifest.includes('ACCESS_NETWORK_STATE'));

  const capConfig = JSON.parse(fs.readFileSync(path.join(project, 'capacitor.config.json'), 'utf8'));
  assert.equal(capConfig.appId, config.package_id);
  assert.equal(capConfig.appName, config.app_name);
  assert.deepEqual(capConfig.server.allowNavigation, ['shop.example.com', '*.shop.example.com']);

  const appConfig = fs.readFileSync(path.join(project, 'public/app-config.js'), 'utf8');
  assert.ok(appConfig.includes(JSON.stringify(config.website_url)));
  assert.ok(appConfig.includes('enableStatusBar: false'));

  const activityPath = path.join(
    project,
    'android/app/src/main/java/in/bizgrowth360/specialapp/MainActivity.java'
  );
  assert.ok(fs.existsSync(activityPath));
  assert.ok(fs.readFileSync(activityPath, 'utf8').includes('package in.bizgrowth360.specialapp;'));
  assert.ok(!fs.existsSync(
    path.join(project, 'android/app/src/main/java/in/bizgrowth360/demoapp/MainActivity.java')
  ));
});

test('prepare-app rejects invalid configuration', () => {
  const { project } = createProjectFixture('prepare-invalid');
  assert.throws(() => {
    prepareApp({ projectDir: project, config: { ...BASE_CONFIG, package_id: 'bad-package' } });
  }, /Configuration validation failed/);
});

test('default icon and splash are generated with all densities', async () => {
  const { project } = createProjectFixture('assets-default');
  const config = validateConfig({ ...BASE_CONFIG, app_icon: null, splash_image: null }).normalizedConfig;
  const report = await generateIcons({ projectDir: project, config });
  assert.equal(report.icon.source, 'default');
  assert.equal(report.splash.source, 'default');
  assert.equal(report.densities.length, 5);

  const sharp = require('sharp');
  const icon = await sharp(path.join(project, 'android/app/src/main/res/mipmap-xxxhdpi/ic_launcher.png')).metadata();
  assert.equal(icon.width, 192);
  assert.equal(icon.height, 192);
  const fg = await sharp(path.join(project, 'android/app/src/main/res/mipmap-xxxhdpi/ic_launcher_foreground.png')).metadata();
  assert.equal(fg.width, 432);
  const splash = await sharp(path.join(project, 'android/app/src/main/res/drawable-port-xxxhdpi/splash.png')).metadata();
  assert.equal(splash.width, 1280);
  assert.equal(splash.height, 1920);
});

test('missing custom icon fails validation instead of silently ignoring it', async () => {
  const { project } = createProjectFixture('assets-missing');
  const config = validateConfig({
    ...BASE_CONFIG,
    app_icon: 'assets/does-not-exist.png',
    splash_image: null
  }).normalizedConfig;
  await assert.rejects(
    () => generateIcons({ projectDir: project, config }),
    /Custom icon is invalid/
  );
});

test('invalid custom icon format is rejected', async () => {
  const { project } = createProjectFixture('assets-invalid');
  const badIcon = path.join(project, 'assets', 'bad.png');
  fs.mkdirSync(path.dirname(badIcon), { recursive: true });
  fs.writeFileSync(badIcon, 'not really a png', 'utf8');

  const check = await validateImageAsset(badIcon, { kind: 'icon' });
  assert.equal(check.valid, false);

  const config = validateConfig({
    ...BASE_CONFIG,
    app_icon: 'assets/bad.png',
    splash_image: null
  }).normalizedConfig;
  await assert.rejects(() => generateIcons({ projectDir: project, config }), /Custom icon is invalid/);
});

test('custom non-square icon and splash keep aspect ratio', async () => {
  const { project } = createProjectFixture('assets-custom');
  const sharp = require('sharp');
  const iconPath = path.join(project, 'assets', 'icon.png');
  const splashPath = path.join(project, 'assets', 'splash.png');
  fs.mkdirSync(path.dirname(iconPath), { recursive: true });
  await sharp({
    create: { width: 600, height: 300, channels: 4, background: { r: 20, g: 40, b: 200, alpha: 1 } }
  }).png().toFile(iconPath);
  await sharp({
    create: { width: 1600, height: 900, channels: 4, background: { r: 250, g: 240, b: 220, alpha: 1 } }
  }).png().toFile(splashPath);

  const config = validateConfig({
    ...BASE_CONFIG,
    app_icon: 'assets/icon.png',
    splash_image: 'assets/splash.png',
    splash_background_color: '#F5F3FF'
  }).normalizedConfig;
  const report = await generateIcons({ projectDir: project, config });
  assert.equal(report.icon.source, 'custom');
  assert.equal(report.splash.source, 'custom');

  const launcher = await sharp(path.join(project, 'android/app/src/main/res/mipmap-xxxhdpi/ic_launcher.png')).metadata();
  assert.equal(launcher.width, 192);
  assert.equal(launcher.height, 192);
  const fg = await sharp(path.join(project, 'android/app/src/main/res/mipmap-xxxhdpi/ic_launcher_foreground.png')).metadata();
  assert.equal(fg.width, 432);
  assert.equal(fg.height, 432);
  const splash = await sharp(path.join(project, 'android/app/src/main/res/drawable-port-xxxhdpi/splash.png')).metadata();
  assert.equal(splash.width, 1280);
  assert.equal(splash.height, 1920);
});

test('asset paths cannot escape the project directory', () => {
  const { project } = createProjectFixture('asset-path');
  assert.throws(() => resolveAssetPath(project, '../../etc/passwd'), /escapes the project/);
  assert.throws(() => resolveAssetPath(project, 'C:\\Windows\\win.ini'), /relative/);
});

test('inline BUILD_CONFIG_JSON payload merges over the config file', () => {
  const previous = process.env.BUILD_CONFIG_JSON;
  process.env.BUILD_CONFIG_JSON = JSON.stringify({
    app_name: 'Inline Payload App',
    package_id: 'in.bizgrowth360.inlinepayload',
    website_url: 'https://inline.example.com',
    version_name: '3.2.1',
    version_code: 9,
    primary_color: '#112233',
    background_color: '#FFFFFF',
    build_type: 'aab',
    build_variant: 'release'
  });
  try {
    const cfg = loadConfiguration({});
    assert.equal(cfg.app_name, 'Inline Payload App');
    assert.equal(cfg.package_id, 'in.bizgrowth360.inlinepayload');
    assert.equal(cfg.version_code, 9);
    assert.equal(cfg.build_type, 'aab');
    const result = validateConfig(cfg);
    assert.equal(result.valid, true, result.errors.join('; '));
  } finally {
    if (previous === undefined) delete process.env.BUILD_CONFIG_JSON;
    else process.env.BUILD_CONFIG_JSON = previous;
  }
});

test('configuration file path cannot escape the repository', () => {
  assert.throws(
    () => loadConfiguration({ configPath: '../outside-config.json' }),
    /must stay inside the repository/
  );
});

test('insecure asset download is refused before any network request', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'bg360-download-'));
  fixtures.push(dir);
  await assert.rejects(
    () => downloadAsset('http://example.com/icon.png', dir, 'icon'),
    /must use HTTPS/
  );
  await assert.rejects(
    () => downloadAsset('https://127.0.0.1/icon.png', dir, 'icon'),
    /loopback, private or link-local/
  );
  await assert.rejects(
    () => downloadAsset('https://169.254.169.254/latest/meta-data/', dir, 'icon'),
    /loopback, private or link-local/
  );
});

// ---------------------------------------------------------------------------
// Build mode tasks
// ---------------------------------------------------------------------------

test('build type maps to the correct Gradle tasks', () => {
  assert.deepEqual(buildTasks('apk', 'release'), ['assembleRelease']);
  assert.deepEqual(buildTasks('aab', 'release'), ['bundleRelease']);
  assert.deepEqual(buildTasks('both', 'release'), ['assembleRelease', 'bundleRelease']);
  assert.deepEqual(buildTasks('both', 'debug'), ['assembleDebug', 'bundleDebug']);
});

// ---------------------------------------------------------------------------
// Isolation / concurrency
// ---------------------------------------------------------------------------

test('two builds with different configs stay isolated (sequential)', async () => {
  const a = createProjectFixture('iso-a');
  const b = createProjectFixture('iso-b');

  const cfgA = {
    ...BASE_CONFIG,
    app_name: 'Alpha App',
    package_id: 'in.bizgrowth360.alpha',
    website_url: 'https://alpha.example.com',
    version_name: '1.0.0',
    version_code: 1
  };
  const cfgB = {
    ...BASE_CONFIG,
    app_name: 'Beta App',
    package_id: 'in.bizgrowth360.beta',
    website_url: 'https://beta.example.com',
    version_name: '2.0.0',
    version_code: 2
  };

  prepareApp({ projectDir: a.project, config: cfgA });
  await generateIcons({ projectDir: a.project, config: cfgA });
  prepareApp({ projectDir: b.project, config: cfgB });
  await generateIcons({ projectDir: b.project, config: cfgB });

  const gradleA = fs.readFileSync(path.join(a.project, 'android/app/build.gradle'), 'utf8');
  const gradleB = fs.readFileSync(path.join(b.project, 'android/app/build.gradle'), 'utf8');
  assert.ok(gradleA.includes('in.bizgrowth360.alpha'));
  assert.ok(!gradleA.includes('in.bizgrowth360.beta'));
  assert.ok(gradleB.includes('in.bizgrowth360.beta'));
  assert.ok(!gradleB.includes('in.bizgrowth360.alpha'));

  const configA = fs.readFileSync(path.join(a.project, 'public/app-config.js'), 'utf8');
  const configB = fs.readFileSync(path.join(b.project, 'public/app-config.js'), 'utf8');
  assert.ok(configA.includes('https://alpha.example.com'));
  assert.ok(!configA.includes('https://beta.example.com'));
  assert.ok(configB.includes('https://beta.example.com'));
  assert.ok(!configB.includes('https://alpha.example.com'));

  const appNamesA = fs.readFileSync(
    path.join(a.project, 'android/app/src/main/res/values/strings.xml'),
    'utf8'
  );
  assert.ok(appNamesA.includes('Alpha App'));
  assert.ok(!appNamesA.includes('Beta App'));
});

test('concurrent builds do not leak configuration or assets', async () => {
  const a = createProjectFixture('concurrent-a');
  const b = createProjectFixture('concurrent-b');

  const cfgA = {
    ...BASE_CONFIG,
    app_name: 'Concurrent One',
    package_id: 'in.bizgrowth360.concurrentone',
    website_url: 'https://one.example.com',
    primary_color: '#AA0000',
    background_color: '#FFFFFF'
  };
  const cfgB = {
    ...BASE_CONFIG,
    app_name: 'Concurrent Two',
    package_id: 'in.bizgrowth360.concurrenttwo',
    website_url: 'https://two.example.com',
    primary_color: '#0000AA',
    background_color: '#FFFFFF'
  };

  await Promise.all([
    (async () => {
      prepareApp({ projectDir: a.project, config: cfgA });
      await generateIcons({ projectDir: a.project, config: cfgA });
    })(),
    (async () => {
      prepareApp({ projectDir: b.project, config: cfgB });
      await generateIcons({ projectDir: b.project, config: cfgB });
    })()
  ]);

  for (const [fixture, own, other] of [
    [a, cfgA, cfgB],
    [b, cfgB, cfgA]
  ]) {
    const configJs = fs.readFileSync(path.join(fixture.project, 'public/app-config.js'), 'utf8');
    const strings = fs.readFileSync(
      path.join(fixture.project, 'android/app/src/main/res/values/strings.xml'),
      'utf8'
    );
    const colors = fs.readFileSync(
      path.join(fixture.project, 'android/app/src/main/res/values/colors.xml'),
      'utf8'
    );
    assert.ok(configJs.includes(own.package_id));
    assert.ok(!configJs.includes(other.package_id));
    assert.ok(strings.includes(own.app_name));
    assert.ok(!strings.includes(other.app_name));
    assert.ok(colors.includes(own.primary_color));
    assert.ok(!colors.includes(other.primary_color));
  }
});

// ---------------------------------------------------------------------------
// Design Studio configuration
// ---------------------------------------------------------------------------

test('design defaults are seeded from app colors', () => {
  const design = defaultDesign({ primary_color: '#123456', background_color: '#FAFAFA', status_bar_color: '#111111', navigation_bar_color: '#EEEEEE' });
  assert.equal(design.schema_version, 1);
  assert.equal(design.theme.primary_color, '#123456');
  assert.equal(design.theme.status_bar_color, '#111111');
  assert.equal(design.navigation.type, 'none');
  assert.equal(design.homepage.enabled, false);
});

test('design validation rejects malformed JSON and sanitizes unsafe values', () => {
  const malformed = validateDesign('{not json');
  assert.equal(malformed.valid, false);

  const result = validateDesign({
    schema_version: 99,
    theme: { primary_color: 'red', accent_color: '#abc', mode: 'dark' },
    navigation: {
      type: 'bottom',
      items: Array.from({ length: 12 }, (_, i) => ({ label: 'Item ' + i, type: 'website', url: 'https://example.com', visible: true }))
    },
    menu: {
      groups: [{ id: 'main', label: 'Main' }],
      items: [
        { label: 'Home', type: 'home', group: 'missing', enabled: true },
        { label: 'Bad', type: 'website', url: 'javascript:alert(1)' }
      ]
    },
    homepage: {
      enabled: true,
      sections: [
        { type: 'welcome', title: 'Hello', enabled: true },
        { type: 'custom_html', text: '<p>Safe</p><script>alert(1)</script><a href="javascript:evil()" onclick="x()">x</a>' }
      ]
    },
    components: { card_radius: 999, style_preset: 'bad' },
    typography: { body_font: 'comic-sans', base_size: 100 },
    layout: { page_padding: 999, max_width: 10 }
  }, BASE_CONFIG);

  assert.equal(result.valid, true, result.errors.join('; '));
  const d = result.design;
  assert.equal(d.schema_version, 1);
  assert.equal(d.theme.primary_color, '#7C3AED');
  assert.equal(d.theme.accent_color, '#AABBCC');
  assert.equal(d.navigation.items.length, 8);
  assert.equal(d.menu.items[0].group, 'main');
  assert.equal(d.menu.items[1].url, '');
  assert.equal(d.homepage.sections.length, 2);
  assert.ok(!/script/i.test(d.homepage.sections[1].text));
  assert.ok(!/onclick/i.test(d.homepage.sections[1].text));
  assert.ok(!/javascript:/i.test(d.homepage.sections[1].text));
  assert.equal(d.components.card_radius, 32);
  assert.equal(d.components.style_preset, 'modern');
  assert.equal(d.typography.body_font, 'system');
  assert.equal(d.typography.base_size, 20);
  assert.equal(d.layout.page_padding, 32);
  assert.equal(d.layout.max_width, 320);
});

test('design URLs allow app pages and reject dangerous schemes', () => {
  const result = validateDesign({
    navigation: {
      type: 'bottom',
      items: [
        { label: 'Home', type: 'home', url: 'app:home', visible: true },
        { label: 'Menu', type: 'custom', url: 'app:menu', visible: true },
        { label: 'Bad app', type: 'custom', url: 'app:evil', visible: true },
        { label: 'Bad js', type: 'custom', url: 'javascript:alert(1)', visible: true },
        { label: 'Phone', type: 'contact', url: 'tel:+911234567890', visible: true }
      ]
    }
  }, BASE_CONFIG);
  const items = result.design.navigation.items;
  assert.equal(items[0].url, 'app:home');
  assert.equal(items[1].url, 'app:menu');
  assert.equal(items[2].url, '');
  assert.equal(items[3].url, '');
  assert.equal(items[4].url, 'tel:+911234567890');
});

test('DESIGN_JSON environment payload is accepted by the build engine', () => {
  const previous = process.env.DESIGN_JSON;
  process.env.DESIGN_JSON = JSON.stringify({
    schema_version: 1,
    theme: { mode: 'dark', primary_color: '#A78BFA' },
    navigation: { type: 'bottom', items: [{ id: 'n1', label: 'Home', icon: 'home', type: 'home', visible: true }] }
  });
  try {
    const result = loadDesign({}, BASE_CONFIG);
    assert.equal(result.valid, true);
    assert.equal(result.design.theme.mode, 'dark');
    assert.equal(result.design.navigation.type, 'bottom');
  } finally {
    if (previous === undefined) delete process.env.DESIGN_JSON;
    else process.env.DESIGN_JSON = previous;
  }
});

test('design palette is applied to Android build config', () => {  const design = sanitizeDesign({
    theme: {
      mode: 'light',
      primary_color: '#7C3AED',
      background_color: '#F8F7FF',
      status_bar_color: '#5B21B6',
      navigation_bar_color: '#FFFFFF'
    }
  }, BASE_CONFIG);
  const effective = applyDesignToConfig({ ...BASE_CONFIG, splash_background_color: '#FFFFFF' }, design);
  assert.equal(effective.primary_color, '#7C3AED');
  assert.equal(effective.background_color, '#F8F7FF');
  assert.equal(effective.status_bar_color, '#5B21B6');
  assert.equal(effective.navigation_bar_color, '#FFFFFF');

  const summary = designSummary(design);
  assert.equal(summary.schema_version, 1);
  assert.equal(summary.navigation_type, 'none');
});

test('prepare-app embeds the design config and design.json in the app shell', () => {
  const { project } = createProjectFixture('design-prepare');
  const design = sanitizeDesign({
    homepage: { enabled: true, sections: [{ id: 's1', type: 'hero', title: 'Hello' }] },
    navigation: { type: 'bottom', items: [{ id: 'n1', label: 'Home', icon: 'home', type: 'home', visible: true }] }
  }, BASE_CONFIG);
  prepareApp({
    projectDir: project,
    config: { ...BASE_CONFIG, primary_color: '#7C3AED', background_color: '#F8F7FF' },
    design
  });
  const appConfig = fs.readFileSync(path.join(project, 'public/app-config.js'), 'utf8');
  assert.ok(appConfig.includes('design: {'));
  assert.ok(appConfig.includes('"schema_version":1'));
  const designFile = JSON.parse(fs.readFileSync(path.join(project, 'public/design.json'), 'utf8'));
  assert.equal(designFile.schema_version, 1);
  assert.equal(designFile.homepage.sections[0].title, 'Hello');
});

// ---------------------------------------------------------------------------
// Output validation error handling
// ---------------------------------------------------------------------------

test('output validator rejects missing and empty artifacts', () => {
  const missing = validateApk(path.join(os.tmpdir(), 'does-not-exist.apk'), BASE_CONFIG, { strict: true });
  assert.equal(missing.valid, false);

  const emptyDir = fs.mkdtempSync(path.join(os.tmpdir(), 'bg360-empty-'));
  fixtures.push(emptyDir);
  const empty = path.join(emptyDir, 'empty.apk');
  fs.writeFileSync(empty, '');
  assert.equal(validateApk(empty, BASE_CONFIG, { strict: true }).valid, false);

  const corrupt = path.join(emptyDir, 'corrupt.aab');
  fs.writeFileSync(corrupt, 'not a zip archive at all');
  assert.equal(validateAab(corrupt, BASE_CONFIG, { strict: true }).valid, false);
});

test('inspectZipArchive rejects random data', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'bg360-zip-'));
  fixtures.push(dir);
  const file = path.join(dir, 'random.bin');
  fs.writeFileSync(file, Buffer.from('this is definitely not a zip file'));
  assert.throws(() => inspectZipArchive(file), /ZIP|EOCD|invalid/i);
});

// ---------------------------------------------------------------------------
// Phase 6: Native Features validation & build engine tests
// ---------------------------------------------------------------------------

test('native features defaults include all 7 features', () => {
  const defs = defaultFeatures(BASE_CONFIG);
  assert.equal(defs.schema_version, 1);
  assert.equal(typeof defs.push_notifications, 'object');
  assert.equal(defs.push_notifications.enabled, false);
  assert.equal(defs.push_notifications.channel_id, 'default_channel');

  assert.equal(typeof defs.deep_linking, 'object');
  assert.equal(defs.deep_linking.enabled, false);
  assert.equal(defs.deep_linking.domain, 'mahankalistore.example.com');
  assert.equal(defs.deep_linking.scheme, 'bizgrowth360');

  assert.equal(typeof defs.native_share, 'object');
  assert.equal(defs.native_share.enabled, true);

  assert.equal(typeof defs.camera_upload, 'object');
  assert.equal(defs.camera_upload.camera_enabled, true);
  assert.equal(defs.camera_upload.gallery_enabled, true);

  assert.equal(typeof defs.offline_engine, 'object');
  assert.equal(defs.offline_engine.enabled, true);
  assert.equal(defs.offline_engine.auto_reconnect, true);

  assert.equal(typeof defs.permission_manager, 'object');
  assert.equal(defs.permission_manager.enabled, true);
  assert.equal(defs.permission_manager.location_enabled, false);

  assert.equal(typeof defs.app_updates, 'object');
  assert.equal(defs.app_updates.enabled, true);
  assert.equal(defs.app_updates.mode, 'recommended');
});

test('validateFeatures normalizes inputs and sanitizes invalid strings', () => {
  const custom = {
    push_notifications: {
      enabled: 1,
      channel_id: 'sales alert!',
      firebase_project_id: 'my-proj-123',
      firebase_server_key: 'key-abc'
    },
    deep_linking: {
      enabled: true,
      domain: 'https://Sub.MySite.com/extra/',
      scheme: 'MY-APP-123!'
    },
    camera_upload: {
      camera_enabled: false,
      max_file_size_mb: 500
    },
    app_updates: {
      min_version_code: '5',
      mode: 'mandatory'
    }
  };

  const res = validateFeatures(custom, BASE_CONFIG);
  assert.equal(res.valid, true);
  assert.equal(res.normalizedFeatures.push_notifications.enabled, true);
  assert.equal(res.normalizedFeatures.push_notifications.channel_id, 'sales_alert_');
  assert.equal(res.normalizedFeatures.deep_linking.domain, 'sub.mysite.com');
  assert.equal(res.normalizedFeatures.deep_linking.scheme, 'my-app-123');
  assert.equal(res.normalizedFeatures.camera_upload.camera_enabled, false);
  assert.equal(res.normalizedFeatures.camera_upload.max_file_size_mb, 200);
  assert.equal(res.normalizedFeatures.app_updates.min_version_code, 5);
  assert.equal(res.normalizedFeatures.app_updates.mode, 'mandatory');
});

test('validateFeatures handles JSON strings and malformed input', () => {
  const jsonStr = JSON.stringify({
    push_notifications: { enabled: true, channel_id: 'promo_channel' }
  });
  const res = validateFeatures(jsonStr, BASE_CONFIG);
  assert.equal(res.valid, true);
  assert.equal(res.normalizedFeatures.push_notifications.enabled, true);
  assert.equal(res.normalizedFeatures.push_notifications.channel_id, 'promo_channel');

  const badJson = validateFeatures('{ not json at all', BASE_CONFIG);
  assert.equal(badJson.valid, false);
  assert.ok(badJson.errors[0].includes('Invalid features JSON'));
});

test('featuresSummary generates descriptive output for logs', () => {
  const defs = defaultFeatures(BASE_CONFIG);
  const summary = featuresSummary(defs);
  assert.ok(Array.isArray(summary));
  assert.ok(summary.some(s => s.includes('Push Notifications: DISABLED')));
  assert.ok(summary.some(s => s.includes('Native Share: ENABLED')));
});

test('prepareApp injects native feature permissions, intent filters, and FCM metadata', () => {
  const { project } = createProjectFixture('features-test');
  const customFeatures = {
    push_notifications: {
      enabled: true,
      channel_id: 'order_updates',
      google_services_json: '{"test_project": "firebase_ok"}'
    },
    deep_linking: {
      enabled: true,
      domain: 'shop.example.com',
      scheme: 'shopapp',
      auto_verify: true
    },
    camera_upload: {
      camera_enabled: true,
      gallery_enabled: true
    },
    permission_manager: {
      location_enabled: true
    }
  };

  prepareApp({
    projectDir: project,
    config: BASE_CONFIG,
    features: customFeatures
  });

  const manifest = fs.readFileSync(path.join(project, 'android/app/src/main/AndroidManifest.xml'), 'utf8');

  // Permissions
  assert.ok(manifest.includes('android.permission.POST_NOTIFICATIONS'));
  assert.ok(manifest.includes('android.permission.CAMERA'));
  assert.ok(manifest.includes('android.permission.READ_MEDIA_IMAGES'));
  assert.ok(manifest.includes('android.permission.ACCESS_FINE_LOCATION'));

  // Notification Channel meta-data
  assert.ok(manifest.includes('default_notification_channel_id'));
  assert.ok(manifest.includes('order_updates'));

  // Deep Link Intent Filters
  assert.ok(manifest.includes('android:scheme="https"'));
  assert.ok(manifest.includes('android:host="shop.example.com"'));
  assert.ok(manifest.includes('android:scheme="shopapp"'));

  // google-services.json written
  const gsPath = path.join(project, 'android/app/google-services.json');
  assert.ok(fs.existsSync(gsPath));
  assert.ok(fs.readFileSync(gsPath, 'utf8').includes('firebase_ok'));

  // MainActivity contains deep linking handlers
  const mainActivity = fs.readFileSync(
    path.join(project, 'android/app/src/main/java/in/bizgrowth360/mahankalistore/MainActivity.java'),
    'utf8'
  );
  assert.ok(mainActivity.includes('onNewIntent'));
  assert.ok(mainActivity.includes('handleDeepLinkIntent'));

  // app-config.js contains features block
  const appConfig = fs.readFileSync(path.join(project, 'public/app-config.js'), 'utf8');
  assert.ok(appConfig.includes('features: {'));
  assert.ok(appConfig.includes('order_updates'));
});

test('native javascript bridge exists and is injected in HTML', () => {
  const publicBridge = path.join(TEMPLATE, 'public/bizgrowth360-bridge.js');
  const srcBridge = path.join(TEMPLATE, 'src/bizgrowth360-bridge.js');
  assert.ok(fs.existsSync(publicBridge));
  assert.ok(fs.existsSync(srcBridge));

  const bridgeContent = fs.readFileSync(publicBridge, 'utf8');
  assert.ok(bridgeContent.includes('Bizgrowth360Bridge'));
  assert.ok(bridgeContent.includes('getAppInfo'));
  assert.ok(bridgeContent.includes('share: function'));
  assert.ok(bridgeContent.includes('checkUpdate: function'));
  assert.ok(bridgeContent.includes('registerPushToken: function'));

  const publicHtml = fs.readFileSync(path.join(TEMPLATE, 'public/index.html'), 'utf8');
  assert.ok(publicHtml.includes('bizgrowth360-bridge.js'));
});
