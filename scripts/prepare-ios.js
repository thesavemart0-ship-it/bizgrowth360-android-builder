/**
 * prepare-ios.js
 * Applies a saved app configuration to an isolated Capacitor iOS project.
 *
 * This module never modifies the shared app-template. It only writes into the
 * workspace project passed as `projectDir`.
 */

const fs = require('fs');
const path = require('path');
const plist = require('plist');
const sharp = require('sharp');

const { validateConfig, escapeHtml } = require('./validate-config');
const { validateDesign, applyDesignToConfig } = require('./design-config');
const { validateFeatures } = require('./native-features-config');
const { validateIosConfig } = require('./ios-config');
const {
  createDefaultIconSvg,
  createDefaultSplashSvg,
  validateImageAsset,
  resolveAssetPath
} = require('./generate-icons');

function writeFile(filePath, content) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, content, 'utf8');
}

function readJson(filePath, fallback = {}) {
  if (!fs.existsSync(filePath)) return fallback;
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (err) {
    throw new Error(`Invalid JSON in ${filePath}: ${err.message}`);
  }
}

function addUsage(dict, key, description) {
  if (description && !dict[key]) dict[key] = description;
}

function normalizeHostname(value) {
  return String(value || '')
    .trim()
    .replace(/^https?:\/\//i, '')
    .replace(/\/.*$/, '')
    .replace(/[^A-Za-z0-9.-]/g, '')
    .toLowerCase();
}

function applyInfoPlist(appDir, config, design, features, ios) {
  const infoPath = path.join(appDir, 'Info.plist');
  if (!fs.existsSync(infoPath)) {
    throw new Error('iOS Info.plist is missing from the template.');
  }

  let info;
  try {
    info = plist.parse(fs.readFileSync(infoPath, 'utf8'));
  } catch (err) {
    throw new Error(`Could not parse iOS Info.plist: ${err.message}`);
  }

  info.CFBundleDisplayName = String(config.app_name);
  info.CFBundleName = String(config.app_name);
  info.CFBundleShortVersionString = String(config.version_name);
  info.CFBundleVersion = String(config.version_code);
  info.ITSAppUsesNonExemptEncryption = false;

  const portrait = ['UIInterfaceOrientationPortrait'];
  const landscape = ['UIInterfaceOrientationLandscapeLeft', 'UIInterfaceOrientationLandscapeRight'];
  const all = portrait.concat(landscape);

  let orientations;
  if (config.orientation === 'portrait') orientations = portrait;
  else if (config.orientation === 'landscape') orientations = landscape;
  else orientations = all;

  info.UISupportedInterfaceOrientations = orientations;
  // iPad apps that do not support all orientations must require full screen,
  // otherwise App Store validation rejects the binary.
  if (info.UIRequiresFullScreen === undefined) {
    info.UIRequiresFullScreen = 'auto' !== config.orientation && 'sensor' !== config.orientation;
  }
  info['UISupportedInterfaceOrientations~ipad'] = (info.UIRequiresFullScreen ? orientations : all)
    .concat(orientations.includes('UIInterfaceOrientationPortrait')
      ? ['UIInterfaceOrientationPortraitUpsideDown']
      : []);

  if (config.enable_status_bar === false) {
    info.UIStatusBarHidden = true;
    info.UIViewControllerBasedStatusBarAppearance = false;
  }

  // iOS 14+ requires arm64; the stock Capacitor template still lists armv7.
  info.UIRequiredDeviceCapabilities = ['arm64'];

  const camera = features.camera_upload || {};
  if (camera.camera_enabled) {
    addUsage(info, 'NSCameraUsageDescription', 'This app uses the camera so you can take photos to upload.');
  }
  if (camera.gallery_enabled) {
    addUsage(info, 'NSPhotoLibraryUsageDescription', 'This app accesses your photo library so you can select images to upload.');
    addUsage(info, 'NSPhotoLibraryAddUsageDescription', 'This app can save images you create or download.');
  }
  if (features.permission_manager && features.permission_manager.location_enabled) {
    addUsage(info, 'NSLocationWhenInUseUsageDescription', 'This app uses your location to provide nearby content and services.');
  }

  if (features.deep_linking && features.deep_linking.enabled) {
    const scheme = String(features.deep_linking.scheme || 'bizgrowth360')
      .toLowerCase()
      .replace(/[^a-z0-9_-]/g, '');
    if (scheme) {
      const existing = Array.isArray(info.CFBundleURLTypes) ? info.CFBundleURLTypes : [];
      const hasScheme = existing.some(type => Array.isArray(type.CFBundleURLSchemes) && type.CFBundleURLSchemes.includes(scheme));
      if (!hasScheme) {
        existing.push({
          CFBundleURLName: `${ios.bundle_id}.deeplink`,
          CFBundleURLSchemes: [scheme]
        });
      }
      info.CFBundleURLTypes = existing;
    }
  }

  if (features.push_notifications && features.push_notifications.enabled) {
    const modes = Array.isArray(info.UIBackgroundModes) ? info.UIBackgroundModes : [];
    if (!modes.includes('remote-notification')) modes.push('remote-notification');
    info.UIBackgroundModes = modes;
  }

  writeFile(infoPath, plist.build(info));
}

function buildEntitlements(config, design, features, ios) {
  const entitlements = {};

  if (features.push_notifications && features.push_notifications.enabled) {
    entitlements['aps-environment'] = process.env.IOS_APS_ENVIRONMENT ||
      (config.build_variant === 'debug' ? 'development' : 'production');
  }

  if (features.deep_linking && features.deep_linking.enabled) {
    const domain = normalizeHostname(features.deep_linking.domain);
    if (domain) {
      entitlements['com.apple.developer.associated-domains'] = [`applinks:${domain}`];
    }
  }

  return plist.build(entitlements);
}

function updatePbxproj(appDir, config, ios) {
  const pbxPath = path.join(appDir, '..', 'App.xcodeproj', 'project.pbxproj');
  if (!fs.existsSync(pbxPath)) {
    throw new Error('iOS Xcode project.pbxproj is missing from the template.');
  }

  let pbx = fs.readFileSync(pbxPath, 'utf8');

  pbx = pbx.replace(/PRODUCT_BUNDLE_IDENTIFIER = [^;]+;/g, `PRODUCT_BUNDLE_IDENTIFIER = ${ios.bundle_id};`);
  pbx = pbx.replace(/MARKETING_VERSION = [^;]+;/g, `MARKETING_VERSION = ${config.version_name};`);
  pbx = pbx.replace(/CURRENT_PROJECT_VERSION = [^;]+;/g, `CURRENT_PROJECT_VERSION = ${config.version_code};`);
  pbx = pbx.replace(/PRODUCT_NAME = [^;]+;/g, 'PRODUCT_NAME = "$(TARGET_NAME)";');
  pbx = pbx.replace(/IPHONEOS_DEPLOYMENT_TARGET = [^;]+;/g, 'IPHONEOS_DEPLOYMENT_TARGET = 14.0;');

  if (ios.team_id) {
    if (/DEVELOPMENT_TEAM = [^;]+;/.test(pbx)) {
      pbx = pbx.replace(/DEVELOPMENT_TEAM = [^;]+;/g, `DEVELOPMENT_TEAM = ${ios.team_id};`);
    } else {
      pbx = pbx.replace(/CODE_SIGN_STYLE = Automatic;/g, `CODE_SIGN_STYLE = Automatic;\n\t\t\t\tDEVELOPMENT_TEAM = ${ios.team_id};`);
    }
  }

  if (!pbx.includes('CODE_SIGN_ENTITLEMENTS')) {
    pbx = pbx.replace(
      /INFOPLIST_FILE = App\/Info\.plist;/g,
      'INFOPLIST_FILE = App/Info.plist;\n\t\t\t\tCODE_SIGN_ENTITLEMENTS = App/App.entitlements;'
    );
  }

  if (/ENABLE_USER_SCRIPT_SANDBOXING = [^;]+;/.test(pbx)) {
    pbx = pbx.replace(/ENABLE_USER_SCRIPT_SANDBOXING = [^;]+;/g, 'ENABLE_USER_SCRIPT_SANDBOXING = NO;');
  }

  fs.writeFileSync(pbxPath, pbx, 'utf8');
}

async function writeIosAssets(projectDir, config, design, options = {}) {
  const appDir = path.join(projectDir, 'ios', 'App', 'App');
  const assetsDir = path.join(appDir, 'Assets.xcassets');
  const iconSet = path.join(assetsDir, 'AppIcon.appiconset');
  const splashSet = path.join(assetsDir, 'Splash.imageset');
  fs.mkdirSync(iconSet, { recursive: true });
  fs.mkdirSync(splashSet, { recursive: true });

  let iconSource;
  let iconReport = { source: 'default' };
  const iconPath = options.iconPath || resolveAssetPath(projectDir, config.app_icon);

  if (iconPath && fs.existsSync(iconPath)) {
    const check = await validateImageAsset(iconPath, { kind: 'icon', minWidth: 192, minHeight: 192, maxDimension: 4096 });
    if (!check.valid) {
      throw new Error(`iOS icon is invalid:\n  - ${check.errors.join('\n  - ')}`);
    }
    iconSource = await sharp(iconPath).rotate().png().toBuffer();
    iconReport = { source: 'custom', width: check.metadata.width, height: check.metadata.height };
  } else {
    iconSource = await sharp(createDefaultIconSvg(config.app_name, config.primary_color)).png().toBuffer();
  }

  // App Store icons must be 1024x1024 and fully opaque.
  await sharp(iconSource)
    .resize(1024, 1024, { fit: 'cover' })
    .flatten({ background: config.background_color || '#FFFFFF' })
    .removeAlpha()
    .png()
    .toFile(path.join(iconSet, 'AppIcon-512@2x.png'));

  let splashReport = { source: 'default' };
  let splashBuffer;
  const splashPath = options.splashPath || resolveAssetPath(projectDir, config.splash_image);
  const splashBackground = config.splash_background_color || config.background_color || '#FFFFFF';
  const designPalette = design.theme.mode === 'dark' ? design.theme.dark : design.theme;

  if (splashPath && fs.existsSync(splashPath)) {
    const check = await validateImageAsset(splashPath, { kind: 'splash', minWidth: 320, minHeight: 320, maxDimension: 8192 });
    if (!check.valid) {
      throw new Error(`iOS splash image is invalid:\n  - ${check.errors.join('\n  - ')}`);
    }
    splashBuffer = await sharp(splashPath)
      .rotate()
      .resize(2732, 2732, { fit: 'contain', background: splashBackground })
      .flatten({ background: splashBackground })
      .png()
      .toBuffer();
    splashReport = { source: 'custom', width: check.metadata.width, height: check.metadata.height };
  } else {
    const svg = createDefaultSplashSvg(
      2732,
      2732,
      config.app_name,
      designPalette.primary_color || config.primary_color,
      splashBackground
    );
    splashBuffer = await sharp(svg).png().toBuffer();
  }

  for (const name of ['splash-2732x2732.png', 'splash-2732x2732-1.png', 'splash-2732x2732-2.png']) {
    fs.writeFileSync(path.join(splashSet, name), splashBuffer);
  }

  return { icon: iconReport, splash: splashReport };
}

/**
 * Apply a validated configuration to an isolated Capacitor iOS project.
 *
 * @param {Object} options
 * @returns {Promise<{config: Object, design: Object, features: Object, ios: Object, assets: Object}>}
 */
async function prepareIosApp(options = {}) {
  const rootDir = options.rootDir || path.resolve(__dirname, '..');
  const projectDir = options.projectDir ? path.resolve(options.projectDir) : path.join(rootDir, 'app-template');
  const appDir = path.join(projectDir, 'ios', 'App', 'App');

  if (!fs.existsSync(appDir)) {
    throw new Error(`iOS project is incomplete. Missing: ${appDir}`);
  }

  const baseConfig = options.config || readJson(options.configPath || path.join(rootDir, 'config', 'default-app.json'));
  const validation = validateConfig(baseConfig);
  if (!validation.valid) {
    throw new Error(`iOS configuration failed validation:\n  - ${validation.errors.join('\n  - ')}`);
  }

  const designResult = validateDesign(options.design === undefined ? baseConfig.design : options.design, baseConfig);
  if (!designResult.valid) {
    throw new Error(`iOS design configuration failed validation:\n  - ${designResult.errors.join('\n  - ')}`);
  }
  const design = designResult.design;

  const featuresResult = validateFeatures(
    options.features === undefined ? baseConfig.features : options.features,
    baseConfig
  );
  if (!featuresResult.valid) {
    throw new Error(`iOS native features failed validation:\n  - ${featuresResult.errors.join('\n  - ')}`);
  }
  const features = featuresResult.normalizedFeatures;

  const iosResult = validateIosConfig(baseConfig, options);
  if (!iosResult.valid) {
    throw new Error(`iOS project configuration failed validation:\n  - ${iosResult.errors.join('\n  - ')}`);
  }
  const ios = iosResult.ios;

  const effective = applyDesignToConfig(validation.normalizedConfig, design);
  const website = new URL(effective.website_url);
  const allowNavigation = [website.hostname];
  if (!/^\d+\.\d+\.\d+\.\d+$/.test(website.hostname)) {
    allowNavigation.push(`*.${website.hostname}`);
  }

  const capConfig = {
    appId: ios.bundle_id,
    appName: effective.app_name,
    webDir: 'public',
    backgroundColor: design.theme.background_color,
    server: {
      androidScheme: 'https',
      cleartext: false,
      allowNavigation
    },
    ios: {
      contentInset: 'always',
      backgroundColor: design.theme.background_color,
      limitsNavigationsToAppBoundDomains: false
    },
    plugins: {
      SplashScreen: {
        launchShowDuration: 1500,
        launchAutoHide: true,
        backgroundColor: design.theme.background_color,
        androidSplashResourceName: 'splash',
        androidScaleType: 'CENTER_CROP',
        showSpinner: true,
        splashFullScreen: true,
        splashImmersive: true
      },
      StatusBar: {
        style: 'DARK',
        backgroundColor: design.theme.status_bar_color
      }
    }
  };

  writeFile(path.join(projectDir, 'capacitor.config.json'), JSON.stringify(capConfig, null, 2));
  writeFile(path.join(projectDir, 'capacitor.config.ts'), `import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = ${JSON.stringify(capConfig, null, 2)};

export default config;
`);
  writeFile(path.join(appDir, 'capacitor.config.json'), JSON.stringify(capConfig, null, 2));

  const appConfigJs = `// Dynamic runtime configuration generated for iOS
window.APP_CONFIG = {
  platform: 'ios',
  appName: ${JSON.stringify(effective.app_name)},
  packageId: ${JSON.stringify(ios.bundle_id)},
  bundleId: ${JSON.stringify(ios.bundle_id)},
  websiteUrl: ${JSON.stringify(effective.website_url)},
  versionName: ${JSON.stringify(effective.version_name)},
  versionCode: ${effective.version_code},
  primaryColor: ${JSON.stringify(design.theme.primary_color)},
  backgroundColor: ${JSON.stringify(design.theme.background_color)},
  splashBackgroundColor: ${JSON.stringify(design.theme.background_color)},
  statusBarColor: ${JSON.stringify(design.theme.status_bar_color)},
  navigationBarColor: ${JSON.stringify(design.theme.navigation_bar_color)},
  orientation: ${JSON.stringify(effective.orientation)},
  allowExternalLinks: ${effective.allow_external_links},
  enableStatusBar: ${effective.enable_status_bar},
  features: ${JSON.stringify(features)},
  design: ${JSON.stringify(design)}
};
`;

  for (const out of [path.join(projectDir, 'src', 'app-config.js'), path.join(projectDir, 'public', 'app-config.js')]) {
    if (fs.existsSync(path.dirname(out))) writeFile(out, appConfigJs);
  }
  for (const out of [path.join(projectDir, 'src', 'design.json'), path.join(projectDir, 'public', 'design.json')]) {
    if (fs.existsSync(path.dirname(out))) writeFile(out, JSON.stringify(design, null, 2));
  }
  for (const out of [path.join(projectDir, 'src', 'features.json'), path.join(projectDir, 'public', 'features.json')]) {
    if (fs.existsSync(path.dirname(out))) writeFile(out, JSON.stringify(features, null, 2));
  }

  for (const htmlPath of [path.join(projectDir, 'src', 'index.html'), path.join(projectDir, 'public', 'index.html')]) {
    if (!fs.existsSync(htmlPath)) continue;
    let html = fs.readFileSync(htmlPath, 'utf8');
    html = html.replace(/<title>[^<]*<\/title>/, `<title>${escapeHtml(effective.app_name)}</title>`);
    html = html.replace(/(<h1 id="app-title"[^>]*>)[^<]*(<\/h1>)/, `$1${escapeHtml(effective.app_name)}$2`);
    fs.writeFileSync(htmlPath, html, 'utf8');
  }

  applyInfoPlist(appDir, effective, design, features, ios);
  writeFile(path.join(appDir, 'App.entitlements'), buildEntitlements(effective, design, features, ios));
  updatePbxproj(appDir, effective, ios);

  const assets = await writeIosAssets(projectDir, { ...effective, design }, design, options);

  return { config: effective, design, features, ios, assets };
}

module.exports = {
  prepareIosApp,
  writeIosAssets,
  buildEntitlements
};
