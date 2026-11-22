/**
 * prepare-app.js
 * Prepares and configures the Capacitor Android project for Bizgrowth360 Android Builder.
 * Synchronizes dynamic configuration into Capacitor, Android resources, Gradle, and AndroidManifest.
 */

const fs = require('fs');
const path = require('path');
const { validateConfig } = require('./validate-config');

/**
 * Utility to darken hex color by a given factor (e.g. 0.2 for 20% darker)
 */
function darkenColor(hex, factor = 0.2) {
  let cleanHex = hex.replace('#', '');
  if (cleanHex.length === 3) {
    cleanHex = cleanHex.split('').map(c => c + c).join('');
  }
  const num = parseInt(cleanHex, 16);
  let r = (num >> 16);
  let g = ((num >> 8) & 0x00FF);
  let b = (num & 0x0000FF);

  r = Math.max(0, Math.min(255, Math.floor(r * (1 - factor))));
  g = Math.max(0, Math.min(255, Math.floor(g * (1 - factor))));
  b = Math.max(0, Math.min(255, Math.floor(b * (1 - factor))));

  return `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1).toUpperCase()}`;
}

/**
 * Recursively find and remove empty parent directories
 */
function cleanEmptyDirs(dir, stopDir) {
  if (dir === stopDir || !dir.startsWith(stopDir)) return;
  try {
    const files = fs.readdirSync(dir);
    if (files.length === 0) {
      fs.rmdirSync(dir);
      cleanEmptyDirs(path.dirname(dir), stopDir);
    }
  } catch (e) {
    // ignore
  }
}

/**
 * Main prepare function
 */
function prepareApp(options = {}) {
  const rootDir = options.rootDir || path.resolve(__dirname, '..');
  const appTemplateDir = path.join(rootDir, 'app-template');
  const androidDir = path.join(appTemplateDir, 'android');
  const appDir = path.join(androidDir, 'app');

  // 1. Resolve base configuration
  const configPath = options.configPath || path.join(rootDir, 'config', 'default-app.json');
  let baseConfig = {};
  if (fs.existsSync(configPath)) {
    try {
      baseConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    } catch (e) {
      console.error(`Error reading config file ${configPath}: ${e.message}`);
      process.exit(1);
    }
  }

  // 2. Merge overrides from options / env
  const merged = {
    ...baseConfig,
    ...(options.appName ? { app_name: options.appName } : {}),
    ...(options.packageId ? { package_id: options.packageId } : {}),
    ...(options.websiteUrl ? { website_url: options.websiteUrl } : {}),
    ...(options.versionName ? { version_name: options.versionName } : {}),
    ...(options.versionCode !== undefined ? { version_code: options.versionCode } : {}),
    ...(options.primaryColor ? { primary_color: options.primaryColor } : {}),
    ...(options.backgroundColor ? { background_color: options.backgroundColor } : {}),
    ...(options.statusBarColor ? { status_bar_color: options.statusBarColor } : {}),
    ...(options.navigationBarColor ? { navigation_bar_color: options.navigationBarColor } : {}),
    ...(options.orientation ? { orientation: options.orientation } : {}),
    ...(options.allowExternalLinks !== undefined ? { allow_external_links: options.allowExternalLinks } : {})
  };

  // 3. Validate merged configuration
  const validation = validateConfig(merged);
  if (!validation.valid) {
    console.error('\n[FATAL] Configuration validation failed before prepare:');
    validation.errors.forEach(err => console.error(`  - ${err}`));
    process.exit(1);
  }

  const config = validation.normalizedConfig;
  console.log('\n=== Preparing Android App Configuration ===');
  console.log(`App Name:       ${config.app_name}`);
  console.log(`Package ID:     ${config.package_id}`);
  console.log(`Website URL:    ${config.website_url}`);
  console.log(`Version Name:   ${config.version_name}`);
  console.log(`Version Code:   ${config.version_code}`);
  console.log(`Primary Color:  ${config.primary_color}`);
  console.log(`Orientation:    ${config.orientation}`);
  console.log(`External Links: ${config.allow_external_links}`);

  // 4. Update capacitor.config.json & capacitor.config.ts
  const capConfigJsonPath = path.join(appTemplateDir, 'capacitor.config.json');
  const capConfigTsPath = path.join(appTemplateDir, 'capacitor.config.ts');

  const capConfigObj = {
    appId: config.package_id,
    appName: config.app_name,
    webDir: 'public',
    server: {
      androidScheme: 'https',
      cleartext: false
    },
    plugins: {
      SplashScreen: {
        launchShowDuration: 1500,
        launchAutoHide: true,
        backgroundColor: config.background_color,
        androidSplashResourceName: 'splash',
        androidScaleType: 'CENTER_CROP',
        showSpinner: true,
        splashFullScreen: true,
        splashImmersive: true
      },
      StatusBar: {
        style: 'DARK',
        backgroundColor: config.status_bar_color
      }
    }
  };

  fs.writeFileSync(capConfigJsonPath, JSON.stringify(capConfigObj, null, 2), 'utf8');
  console.log(`[OK] Updated capacitor.config.json`);

  const capConfigTsContent = `import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = ${JSON.stringify(capConfigObj, null, 2)};

export default config;
`;
  fs.writeFileSync(capConfigTsPath, capConfigTsContent, 'utf8');
  console.log(`[OK] Updated capacitor.config.ts`);

  // 5. Update runtime app-config.js in src/ and public/
  const appConfigJsContent = `// Dynamic runtime configuration generated by prepare-app.js
window.APP_CONFIG = {
  appName: ${JSON.stringify(config.app_name)},
  packageId: ${JSON.stringify(config.package_id)},
  websiteUrl: ${JSON.stringify(config.website_url)},
  primaryColor: ${JSON.stringify(config.primary_color)},
  backgroundColor: ${JSON.stringify(config.background_color)},
  statusBarColor: ${JSON.stringify(config.status_bar_color)},
  navigationBarColor: ${JSON.stringify(config.navigation_bar_color)},
  orientation: ${JSON.stringify(config.orientation)},
  allowExternalLinks: ${config.allow_external_links}
};
`;

  const srcConfigPath = path.join(appTemplateDir, 'src', 'app-config.js');
  const publicConfigPath = path.join(appTemplateDir, 'public', 'app-config.js');
  if (fs.existsSync(path.dirname(srcConfigPath))) {
    fs.writeFileSync(srcConfigPath, appConfigJsContent, 'utf8');
  }
  if (fs.existsSync(path.dirname(publicConfigPath))) {
    fs.writeFileSync(publicConfigPath, appConfigJsContent, 'utf8');
  }
  console.log(`[OK] Injected runtime configuration into src/ & public/`);

  // 6. Update strings.xml
  const stringsPath = path.join(appDir, 'src', 'main', 'res', 'values', 'strings.xml');
  const stringsXml = `<?xml version='1.0' encoding='utf-8'?>
<resources>
    <string name="app_name">${escapeXml(config.app_name)}</string>
    <string name="title_activity_main">${escapeXml(config.app_name)}</string>
    <string name="package_name">${config.package_id}</string>
    <string name="custom_url">${escapeXml(config.website_url)}</string>
</resources>
`;
  ensureDirForFile(stringsPath);
  fs.writeFileSync(stringsPath, stringsXml, 'utf8');
  console.log(`[OK] Updated res/values/strings.xml`);

  // 7. Update colors.xml
  const colorsPath = path.join(appDir, 'src', 'main', 'res', 'values', 'colors.xml');
  const primaryDark = darkenColor(config.primary_color, 0.2);
  const colorsXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="colorPrimary">${config.primary_color}</color>
    <color name="colorPrimaryDark">${primaryDark}</color>
    <color name="colorAccent">${config.primary_color}</color>
    <color name="statusBarColor">${config.status_bar_color}</color>
    <color name="navigationBarColor">${config.navigation_bar_color}</color>
</resources>
`;
  ensureDirForFile(colorsPath);
  fs.writeFileSync(colorsPath, colorsXml, 'utf8');
  console.log(`[OK] Updated res/values/colors.xml`);

  // 8. Update styles.xml to apply status & navigation bar colors
  const stylesPath = path.join(appDir, 'src', 'main', 'res', 'values', 'styles.xml');
  if (fs.existsSync(stylesPath)) {
    const stylesXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <style name="AppTheme" parent="Theme.AppCompat.Light.DarkActionBar">
        <item name="colorPrimary">@color/colorPrimary</item>
        <item name="colorPrimaryDark">@color/colorPrimaryDark</item>
        <item name="colorAccent">@color/colorAccent</item>
    </style>

    <style name="AppTheme.NoActionBar" parent="Theme.AppCompat.DayNight.NoActionBar">
        <item name="windowActionBar">false</item>
        <item name="windowNoTitle">true</item>
        <item name="android:background">@null</item>
        <item name="android:statusBarColor">@color/statusBarColor</item>
        <item name="android:navigationBarColor">@color/navigationBarColor</item>
    </style>

    <style name="AppTheme.NoActionBarLaunch" parent="Theme.SplashScreen">
        <item name="android:background">@drawable/splash</item>
    </style>
</resources>
`;
    fs.writeFileSync(stylesPath, stylesXml, 'utf8');
    console.log(`[OK] Updated res/values/styles.xml with branding colors`);
  }

  // 9. Update AndroidManifest.xml (Orientation, FileProvider, etc.)
  const manifestPath = path.join(appDir, 'src', 'main', 'AndroidManifest.xml');
  if (fs.existsSync(manifestPath)) {
    let manifest = fs.readFileSync(manifestPath, 'utf8');

    let orientationAttr = 'android:screenOrientation="unspecified"';
    if (config.orientation === 'portrait') {
      orientationAttr = 'android:screenOrientation="portrait"';
    } else if (config.orientation === 'landscape') {
      orientationAttr = 'android:screenOrientation="landscape"';
    } else if (config.orientation === 'sensor') {
      orientationAttr = 'android:screenOrientation="sensor"';
    }

    if (manifest.includes('android:screenOrientation=')) {
      manifest = manifest.replace(/android:screenOrientation="[^"]*"/g, orientationAttr);
    } else {
      manifest = manifest.replace(/<activity([^>]*)android:name="\.MainActivity"/, `<activity$1android:name=".MainActivity"\n            ${orientationAttr}`);
    }

    // Add usesCleartextTraffic="false" for security
    if (!manifest.includes('android:usesCleartextTraffic')) {
      manifest = manifest.replace('<application', '<application\n        android:usesCleartextTraffic="false"');
    }

    fs.writeFileSync(manifestPath, manifest, 'utf8');
    console.log(`[OK] Updated AndroidManifest.xml (Orientation: ${config.orientation})`);
  }

  // 10. Update app/build.gradle with package ID, version, and signing config
  const buildGradlePath = path.join(appDir, 'build.gradle');
  if (fs.existsSync(buildGradlePath)) {
    let gradle = fs.readFileSync(buildGradlePath, 'utf8');

    // Update namespace
    gradle = gradle.replace(/namespace\s+["'][^"']+["']/, `namespace "${config.package_id}"`);

    // Update applicationId
    gradle = gradle.replace(/applicationId\s+["'][^"']+["']/, `applicationId "${config.package_id}"`);

    // Update versionCode
    gradle = gradle.replace(/versionCode\s+\d+/, `versionCode ${config.version_code}`);

    // Update versionName
    gradle = gradle.replace(/versionName\s+["'][^"']+["']/, `versionName "${config.version_name}"`);

    // Build.gradle already contains the secure signingConfigs and buildTypes blocks.
    fs.writeFileSync(buildGradlePath, gradle, 'utf8');
    console.log(`[OK] Updated app/build.gradle (ApplicationId: ${config.package_id}, v${config.version_name} build ${config.version_code})`);
  }

  // 11. Relocate and update MainActivity.java to match package_id
  const javaBaseDir = path.join(appDir, 'src', 'main', 'java');
  const targetPackageSubpath = config.package_id.replace(/\./g, path.sep);
  const targetJavaDir = path.join(javaBaseDir, targetPackageSubpath);
  const targetMainActivityPath = path.join(targetJavaDir, 'MainActivity.java');

  // Search existing MainActivity.java in java directory
  let existingMainActivityPath = null;
  function findMainActivity(dir) {
    if (!fs.existsSync(dir)) return;
    const items = fs.readdirSync(dir);
    for (const item of items) {
      const fullPath = path.join(dir, item);
      const stat = fs.statSync(fullPath);
      if (stat.isDirectory()) {
        findMainActivity(fullPath);
      } else if (item === 'MainActivity.java') {
        existingMainActivityPath = fullPath;
        return;
      }
    }
  }
  findMainActivity(javaBaseDir);

  const mainActivityJavaCode = `package ${config.package_id};

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.widget.Toast;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.WebViewListener;

public class MainActivity extends BridgeActivity {

    private long backPressedTime = 0;
    private Toast backToast;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (getBridge() != null) {
            WebView webView = getBridge().getWebView();
            if (webView != null) {
                WebSettings settings = webView.getSettings();
                settings.setDomStorageEnabled(true);
                settings.setDatabaseEnabled(true);
                settings.setJavaScriptEnabled(true);
                settings.setAllowFileAccess(true);
                settings.setCacheMode(WebSettings.LOAD_DEFAULT);
                settings.setSupportZoom(false);
                settings.setBuiltInZoomControls(false);
            }

            // Hook into webview lifecycle for offline error fallback
            getBridge().addWebViewListener(new WebViewListener() {
                @Override
                public void onReceivedError(WebView webView) {
                    if (webView != null) {
                        webView.post(() -> {
                            webView.loadUrl("file:///android_asset/public/index.html?state=offline");
                        });
                    }
                }
            });
        }
    }

    /**
     * Handles Android hardware back button:
     * - Navigates backward within WebView history if pages exist.
     * - Prevents accidental app exit at root page with a 2-second confirmation toast.
     */
    @Override
    public void onBackPressed() {
        WebView webView = (getBridge() != null) ? getBridge().getWebView() : null;
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            if (backPressedTime + 2000 > System.currentTimeMillis()) {
                if (backToast != null) {
                    backToast.cancel();
                }
                super.onBackPressed();
            } else {
                backToast = Toast.makeText(this, "Press back again to exit", Toast.LENGTH_SHORT);
                backToast.show();
                backPressedTime = System.currentTimeMillis();
            }
        }
    }
}
`;

  ensureDirForFile(targetMainActivityPath);
  fs.writeFileSync(targetMainActivityPath, mainActivityJavaCode, 'utf8');

  // If old MainActivity existed in a different path, delete it and clean up
  if (existingMainActivityPath && path.resolve(existingMainActivityPath) !== path.resolve(targetMainActivityPath)) {
    fs.unlinkSync(existingMainActivityPath);
    cleanEmptyDirs(path.dirname(existingMainActivityPath), javaBaseDir);
    console.log(`[OK] Relocated MainActivity.java to package: ${config.package_id}`);
  } else {
    console.log(`[OK] Updated MainActivity.java for package: ${config.package_id}`);
  }

  console.log(`=== Android App Preparation Completed Successfully ===\n`);
  return config;
}

function ensureDirForFile(filePath) {
  const dir = path.dirname(filePath);
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

function escapeXml(unsafe) {
  if (!unsafe) return '';
  return unsafe
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

// CLI entrypoint
if (require.main === module) {
  const args = process.argv.slice(2);
  const options = {};

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg.startsWith('--')) {
      const nextArg = (i + 1 < args.length && !args[i + 1].startsWith('--')) ? args[++i] : null;
      if (!nextArg || !nextArg.trim()) continue;

      if (arg === '--config') options.configPath = nextArg.trim();
      else if (arg === '--app-name') options.appName = nextArg.trim();
      else if (arg === '--package-id') options.packageId = nextArg.trim();
      else if (arg === '--website-url') options.websiteUrl = nextArg.trim();
      else if (arg === '--version-name') options.versionName = nextArg.trim();
      else if (arg === '--version-code') options.versionCode = parseInt(nextArg.trim(), 10);
      else if (arg === '--primary-color') options.primaryColor = nextArg.trim();
      else if (arg === '--background-color') options.backgroundColor = nextArg.trim();
      else if (arg === '--status-bar-color') options.statusBarColor = nextArg.trim();
      else if (arg === '--navigation-bar-color') options.navigationBarColor = nextArg.trim();
      else if (arg === '--orientation') options.orientation = nextArg.trim();
      else if (arg === '--allow-external-links') options.allowExternalLinks = nextArg.trim() === 'true';
    }
  }

  // Also support ENV vars
  if (process.env.APP_NAME) options.appName = process.env.APP_NAME;
  if (process.env.PACKAGE_ID) options.packageId = process.env.PACKAGE_ID;
  if (process.env.WEBSITE_URL) options.websiteUrl = process.env.WEBSITE_URL;
  if (process.env.VERSION_NAME) options.versionName = process.env.VERSION_NAME;
  if (process.env.VERSION_CODE) options.versionCode = parseInt(process.env.VERSION_CODE, 10);

  prepareApp(options);
}

module.exports = {
  prepareApp,
  darkenColor
};
