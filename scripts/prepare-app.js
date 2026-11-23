/**
 * prepare-app.js
 * Prepares and configures a Capacitor Android project for Bizgrowth360.
 *
 * The function accepts an explicit `projectDir` so the build orchestrator can
 * apply customer configuration to an isolated copy of the template instead of
 * mutating the base template. The CLI keeps working against app-template for
 * backwards compatibility.
 */

const fs = require('fs');
const path = require('path');
const {
  validateConfig,
  escapeXml,
  escapeAndroidString,
  escapeHtml
} = require('./validate-config');

/**
 * Darken a hex color by a given factor (e.g. 0.2 for 20% darker).
 */
function darkenColor(hex, factor = 0.2) {
  let cleanHex = String(hex).replace('#', '');
  if (cleanHex.length === 3) {
    cleanHex = cleanHex.split('').map(c => c + c).join('');
  }
  const num = parseInt(cleanHex.slice(0, 6), 16);
  let r = (num >> 16);
  let g = ((num >> 8) & 0x00FF);
  let b = (num & 0x0000FF);

  r = Math.max(0, Math.min(255, Math.floor(r * (1 - factor))));
  g = Math.max(0, Math.min(255, Math.floor(g * (1 - factor))));
  b = Math.max(0, Math.min(255, Math.floor(b * (1 - factor))));

  return `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1).toUpperCase()}`;
}

/**
 * Returns true when a color is light enough to require dark system-bar icons.
 */
function isLightColor(hex) {
  let cleanHex = String(hex).replace('#', '');
  if (cleanHex.length === 3) {
    cleanHex = cleanHex.split('').map(c => c + c).join('');
  }
  const num = parseInt(cleanHex.slice(0, 6), 16);
  const r = (num >> 16) & 0xFF;
  const g = (num >> 8) & 0xFF;
  const b = num & 0xFF;
  // Relative luminance approximation (sRGB)
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.6;
}

function cleanEmptyDirs(dir, stopDir) {
  const resolvedStop = path.resolve(stopDir);
  let current = path.resolve(dir);
  while (current.startsWith(resolvedStop) && current !== resolvedStop) {
    try {
      if (fs.readdirSync(current).length === 0) {
        fs.rmdirSync(current);
        current = path.dirname(current);
      } else {
        break;
      }
    } catch (e) {
      break;
    }
  }
}

function ensureDirForFile(filePath) {
  const dir = path.dirname(filePath);
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true });
  }
}

function readJsonFile(filePath) {
  if (!filePath || !fs.existsSync(filePath)) return {};
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (e) {
    throw new Error(`Error reading config file ${filePath}: ${e.message}`);
  }
}

/**
 * Main prepare function.
 *
 * options.projectDir   - Capacitor project root to modify (isolated workspace)
 * options.config       - already-loaded config object (wins over configPath)
 * options.configPath   - JSON config path (defaults to config/default-app.json)
 * plus appName/packageId/websiteUrl/... overrides.
 */
function prepareApp(options = {}) {
  const rootDir = options.rootDir || path.resolve(__dirname, '..');
  const projectDir = options.projectDir
    ? path.resolve(options.projectDir)
    : path.join(rootDir, 'app-template');
  const androidDir = path.join(projectDir, 'android');
  const appDir = path.join(androidDir, 'app');

  if (!fs.existsSync(projectDir) || !fs.existsSync(appDir)) {
    throw new Error(`Android project is incomplete. Missing: ${appDir}`);
  }

  const configPath = options.configPath || path.join(rootDir, 'config', 'default-app.json');
  const baseConfig = options.config && typeof options.config === 'object'
    ? options.config
    : readJsonFile(configPath);

  const merged = {
    ...baseConfig,
    ...(options.appName ? { app_name: options.appName } : {}),
    ...(options.packageId ? { package_id: options.packageId } : {}),
    ...(options.websiteUrl ? { website_url: options.websiteUrl } : {}),
    ...(options.versionName ? { version_name: options.versionName } : {}),
    ...(options.versionCode !== undefined ? { version_code: options.versionCode } : {}),
    ...(options.primaryColor ? { primary_color: options.primaryColor } : {}),
    ...(options.backgroundColor ? { background_color: options.backgroundColor } : {}),
    ...(options.splashBackgroundColor ? { splash_background_color: options.splashBackgroundColor } : {}),
    ...(options.statusBarColor ? { status_bar_color: options.statusBarColor } : {}),
    ...(options.navigationBarColor ? { navigation_bar_color: options.navigationBarColor } : {}),
    ...(options.orientation ? { orientation: options.orientation } : {}),
    ...(options.allowExternalLinks !== undefined ? { allow_external_links: options.allowExternalLinks } : {}),
    ...(options.enableStatusBar !== undefined ? { enable_status_bar: options.enableStatusBar } : {})
  };

  const validation = validateConfig(merged);
  if (!validation.valid) {
    throw new Error(`Configuration validation failed before prepare:\n  - ${validation.errors.join('\n  - ')}`);
  }

  const config = validation.normalizedConfig;
  const website = new URL(config.website_url);
  const websiteHost = website.hostname;

  console.log('\n=== Preparing Android App Configuration ===');
  console.log(`App Name:       ${config.app_name}`);
  console.log(`Package ID:     ${config.package_id}`);
  console.log(`Website URL:    ${config.website_url}`);
  console.log(`Version Name:   ${config.version_name}`);
  console.log(`Version Code:   ${config.version_code}`);
  console.log(`Primary Color:  ${config.primary_color}`);
  console.log(`Orientation:    ${config.orientation}`);
  console.log(`External Links: ${config.allow_external_links}`);
  console.log(`Status Bar:     ${config.enable_status_bar}`);

  // -------------------------------------------------------------------
  // Capacitor configuration
  // -------------------------------------------------------------------
  const serverAllowNavigation = [websiteHost];
  if (!/^\d+\.\d+\.\d+\.\d+$/.test(websiteHost) && !websiteHost.includes(':')) {
    serverAllowNavigation.push(`*.${websiteHost}`);
  }

  const capConfigObj = {
    appId: config.package_id,
    appName: config.app_name,
    webDir: 'public',
    backgroundColor: config.background_color,
    server: {
      androidScheme: 'https',
      cleartext: false,
      allowNavigation: serverAllowNavigation
    },
    plugins: {
      SplashScreen: {
        launchShowDuration: 1500,
        launchAutoHide: true,
        backgroundColor: config.splash_background_color,
        androidSplashResourceName: 'splash',
        androidScaleType: 'CENTER_CROP',
        showSpinner: true,
        splashFullScreen: true,
        splashImmersive: true
      },
      StatusBar: {
        style: isLightColor(config.status_bar_color) ? 'DARK' : 'LIGHT',
        backgroundColor: config.status_bar_color
      }
    }
  };

  const capConfigJsonPath = path.join(projectDir, 'capacitor.config.json');
  const capConfigTsPath = path.join(projectDir, 'capacitor.config.ts');
  fs.writeFileSync(capConfigJsonPath, JSON.stringify(capConfigObj, null, 2), 'utf8');
  fs.writeFileSync(capConfigTsPath, `import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = ${JSON.stringify(capConfigObj, null, 2)};

export default config;
`, 'utf8');
  console.log('[OK] Updated capacitor.config.json & capacitor.config.ts');

  // -------------------------------------------------------------------
  // Runtime web configuration
  // -------------------------------------------------------------------
  const appConfigJsContent = `// Dynamic runtime configuration generated by prepare-app.js
window.APP_CONFIG = {
  appName: ${JSON.stringify(config.app_name)},
  packageId: ${JSON.stringify(config.package_id)},
  websiteUrl: ${JSON.stringify(config.website_url)},
  primaryColor: ${JSON.stringify(config.primary_color)},
  backgroundColor: ${JSON.stringify(config.background_color)},
  splashBackgroundColor: ${JSON.stringify(config.splash_background_color)},
  statusBarColor: ${JSON.stringify(config.status_bar_color)},
  navigationBarColor: ${JSON.stringify(config.navigation_bar_color)},
  orientation: ${JSON.stringify(config.orientation)},
  allowExternalLinks: ${config.allow_external_links},
  enableStatusBar: ${config.enable_status_bar}
};
`;

  for (const configPathOut of [
    path.join(projectDir, 'src', 'app-config.js'),
    path.join(projectDir, 'public', 'app-config.js')
  ]) {
    if (fs.existsSync(path.dirname(configPathOut))) {
      fs.writeFileSync(configPathOut, appConfigJsContent, 'utf8');
    }
  }
  console.log('[OK] Injected runtime configuration into src/ & public/');

  // Update initial HTML fallbacks so the correct app name and URL are visible
  // even if JavaScript has not executed yet.
  for (const htmlPath of [
    path.join(projectDir, 'src', 'index.html'),
    path.join(projectDir, 'public', 'index.html')
  ]) {
    if (!fs.existsSync(htmlPath)) continue;
    let html = fs.readFileSync(htmlPath, 'utf8');
    html = html.replace(/<title>[^<]*<\/title>/, `<title>${escapeHtml(config.app_name)}</title>`);
    html = html.replace(
      /(<h1 id="app-title"[^>]*>)[^<]*(<\/h1>)/,
      `$1${escapeHtml(config.app_name)}$2`
    );
    html = html.replace(
      /(<span class="meta-url" id="target-url-label"[^>]*>)[^<]*(<\/span>)/,
      `$1${escapeHtml(config.website_url)}$2`
    );
    fs.writeFileSync(htmlPath, html, 'utf8');
  }
  console.log('[OK] Updated HTML fallback title and target URL');

  // -------------------------------------------------------------------
  // Android string resources
  // -------------------------------------------------------------------
  const stringsPath = path.join(appDir, 'src', 'main', 'res', 'values', 'strings.xml');
  const stringsXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <string name="app_name" formatted="false">${escapeAndroidString(config.app_name)}</string>
    <string name="title_activity_main" formatted="false">${escapeAndroidString(config.app_name)}</string>
    <string name="package_name">${escapeAndroidString(config.package_id)}</string>
    <string name="custom_url" formatted="false">${escapeAndroidString(config.website_url)}</string>
    <string name="custom_website_host" formatted="false">${escapeAndroidString(websiteHost)}</string>
</resources>
`;
  ensureDirForFile(stringsPath);
  fs.writeFileSync(stringsPath, stringsXml, 'utf8');
  console.log('[OK] Updated res/values/strings.xml');

  // Boolean resources consumed by MainActivity
  const boolsPath = path.join(appDir, 'src', 'main', 'res', 'values', 'bools.xml');
  const boolsXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <bool name="allow_external_links">${config.allow_external_links ? 'true' : 'false'}</bool>
    <bool name="enable_status_bar">${config.enable_status_bar ? 'true' : 'false'}</bool>
</resources>
`;
  fs.writeFileSync(boolsPath, boolsXml, 'utf8');
  console.log('[OK] Updated res/values/bools.xml');

  // -------------------------------------------------------------------
  // Colors and theme
  // -------------------------------------------------------------------
  const colorsPath = path.join(appDir, 'src', 'main', 'res', 'values', 'colors.xml');
  const primaryDark = darkenColor(config.primary_color, 0.2);
  const colorsXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="colorPrimary">${config.primary_color}</color>
    <color name="colorPrimaryDark">${primaryDark}</color>
    <color name="colorAccent">${config.primary_color}</color>
    <color name="backgroundColor">${config.background_color}</color>
    <color name="splashBackgroundColor">${config.splash_background_color}</color>
    <color name="statusBarColor">${config.status_bar_color}</color>
    <color name="navigationBarColor">${config.navigation_bar_color}</color>
</resources>
`;
  ensureDirForFile(colorsPath);
  fs.writeFileSync(colorsPath, colorsXml, 'utf8');
  console.log('[OK] Updated res/values/colors.xml');

  const stylesPath = path.join(appDir, 'src', 'main', 'res', 'values', 'styles.xml');
  const lightStatusBar = isLightColor(config.status_bar_color) ? 'true' : 'false';
  const lightNavigationBar = isLightColor(config.navigation_bar_color) ? 'true' : 'false';
  const stylesXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <style name="AppTheme" parent="Theme.AppCompat.Light.DarkActionBar">
        <item name="colorPrimary">@color/colorPrimary</item>
        <item name="colorPrimaryDark">@color/colorPrimaryDark</item>
        <item name="colorAccent">@color/colorAccent</item>
        <item name="android:statusBarColor">@color/statusBarColor</item>
        <item name="android:navigationBarColor">@color/navigationBarColor</item>
    </style>

    <style name="AppTheme.NoActionBar" parent="Theme.AppCompat.DayNight.NoActionBar">
        <item name="windowActionBar">false</item>
        <item name="windowNoTitle">true</item>
        <item name="android:background">@color/backgroundColor</item>
        <item name="android:statusBarColor">@color/statusBarColor</item>
        <item name="android:navigationBarColor">@color/navigationBarColor</item>
        <item name="android:windowLightStatusBar">${lightStatusBar}</item>
        <item name="android:windowLightNavigationBar">${lightNavigationBar}</item>
    </style>

    <style name="AppTheme.NoActionBarLaunch" parent="Theme.SplashScreen">
        <item name="android:background">@drawable/splash</item>
        <item name="windowSplashScreenBackground">@color/splashBackgroundColor</item>
    </style>
</resources>
`;
  fs.writeFileSync(stylesPath, stylesXml, 'utf8');
  console.log('[OK] Updated res/values/styles.xml with branding colors');

  // -------------------------------------------------------------------
  // AndroidManifest.xml
  // -------------------------------------------------------------------
  const manifestPath = path.join(appDir, 'src', 'main', 'AndroidManifest.xml');
  if (!fs.existsSync(manifestPath)) {
    throw new Error('AndroidManifest.xml is missing from the template.');
  }
  let manifest = fs.readFileSync(manifestPath, 'utf8');

  let orientationValue = 'unspecified';
  if (config.orientation === 'portrait') orientationValue = 'portrait';
  else if (config.orientation === 'landscape') orientationValue = 'landscape';
  else if (config.orientation === 'sensor') orientationValue = 'sensor';
  const orientationAttr = `android:screenOrientation="${orientationValue}"`;

  if (/android:screenOrientation="[^"]*"/.test(manifest)) {
    manifest = manifest.replace(/android:screenOrientation="[^"]*"/g, orientationAttr);
  } else {
    manifest = manifest.replace(
      /<activity([^>]*)android:name="\.MainActivity"/,
      `<activity$1android:name=".MainActivity"\n            ${orientationAttr}`
    );
  }

  if (!manifest.includes('android:usesCleartextTraffic')) {
    manifest = manifest.replace('<application', '<application\n        android:usesCleartextTraffic="false"');
  }

  if (!manifest.includes('android.permission.ACCESS_NETWORK_STATE')) {
    manifest = manifest.replace(
      /(<uses-permission android:name="android\.permission\.INTERNET" \/>)/,
      `$1\n    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />`
    );
  }

  fs.writeFileSync(manifestPath, manifest, 'utf8');
  console.log(`[OK] Updated AndroidManifest.xml (Orientation: ${config.orientation})`);

  // -------------------------------------------------------------------
  // Gradle configuration
  // -------------------------------------------------------------------
  const buildGradlePath = path.join(appDir, 'build.gradle');
  if (!fs.existsSync(buildGradlePath)) {
    throw new Error('app/build.gradle is missing from the template.');
  }
  let gradle = fs.readFileSync(buildGradlePath, 'utf8');

  if (!/namespace\s+["'][^"']+["']/.test(gradle)) {
    throw new Error('app/build.gradle does not contain a namespace declaration.');
  }
  if (!/applicationId\s+["'][^"']+["']/.test(gradle)) {
    throw new Error('app/build.gradle does not contain an applicationId declaration.');
  }

  gradle = gradle.replace(/namespace\s+["'][^"']+["']/, `namespace "${config.package_id}"`);
  gradle = gradle.replace(/applicationId\s+["'][^"']+["']/, `applicationId "${config.package_id}"`);
  gradle = gradle.replace(/versionCode\s+\d+/, `versionCode ${config.version_code}`);
  gradle = gradle.replace(/versionName\s+["'][^"']+["']/, `versionName "${config.version_name}"`);

  fs.writeFileSync(buildGradlePath, gradle, 'utf8');
  console.log(`[OK] Updated app/build.gradle (ApplicationId: ${config.package_id}, v${config.version_name} build ${config.version_code})`);

  // -------------------------------------------------------------------
  // MainActivity relocation + native behavior
  // -------------------------------------------------------------------
  const javaBaseDir = path.join(appDir, 'src', 'main', 'java');
  const targetPackageSubpath = config.package_id.replace(/\./g, path.sep);
  const targetJavaDir = path.join(javaBaseDir, targetPackageSubpath);
  const targetMainActivityPath = path.join(targetJavaDir, 'MainActivity.java');

  let existingMainActivityPath = null;
  function findMainActivity(dir) {
    if (!fs.existsSync(dir)) return;
    for (const item of fs.readdirSync(dir)) {
      const fullPath = path.join(dir, item);
      const stat = fs.statSync(fullPath);
      if (stat.isDirectory()) {
        findMainActivity(fullPath);
      } else if (item === 'MainActivity.java' && !existingMainActivityPath) {
        existingMainActivityPath = fullPath;
      }
    }
  }
  findMainActivity(javaBaseDir);

  const mainActivityJavaCode = `package ${config.package_id};

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.WindowManager;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.widget.Toast;
import androidx.core.content.ContextCompat;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;
import com.getcapacitor.WebViewListener;

public class MainActivity extends BridgeActivity {

    private long backPressedTime = 0;
    private Toast backToast;
    private boolean allowExternalLinks = true;
    private String websiteHost = "";
    private boolean offlinePageShown = false;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        allowExternalLinks = getResources().getBoolean(R.bool.allow_external_links);
        websiteHost = getString(R.string.custom_website_host);
        if (!getResources().getBoolean(R.bool.enable_status_bar)) {
            getWindow().setFlags(
                WindowManager.LayoutParams.FLAG_FULLSCREEN,
                WindowManager.LayoutParams.FLAG_FULLSCREEN
            );
        }

        if (getBridge() == null) {
            return;
        }

        WebView webView = getBridge().getWebView();
        if (webView != null) {
            webView.setBackgroundColor(ContextCompat.getColor(this, R.color.backgroundColor));
            WebSettings settings = webView.getSettings();
            settings.setDomStorageEnabled(true);
            settings.setDatabaseEnabled(true);
            settings.setJavaScriptEnabled(true);
            settings.setAllowFileAccess(true);
            settings.setAllowContentAccess(false);
            settings.setCacheMode(WebSettings.LOAD_DEFAULT);
            settings.setSupportZoom(false);
            settings.setBuiltInZoomControls(false);
            settings.setDisplayZoomControls(false);
        }

        // Keep the configured website (and its subdomains) inside the app.
        // Other hosts are either opened in the system browser or blocked,
        // depending on allow_external_links.
        getBridge().setWebViewClient(new BridgeWebViewClient(getBridge()) {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri url = request.getUrl();
                if (isSpecialScheme(url)) {
                    return false;
                }
                if (shouldStayInApp(url)) {
                    return false;
                }
                return handleExternalNavigation(url);
            }

            @SuppressWarnings("deprecation")
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                Uri uri = Uri.parse(url);
                if (isSpecialScheme(uri)) {
                    return false;
                }
                if (shouldStayInApp(uri)) {
                    return false;
                }
                return handleExternalNavigation(uri);
            }
        });

        // Offline / failed main-frame fallback to the bundled branded error page.
        getBridge().addWebViewListener(new WebViewListener() {
            @Override
            public void onReceivedError(WebView view) {
                if (view == null || offlinePageShown) {
                    return;
                }
                offlinePageShown = true;
                String appUrl = getBridge().getAppUrl();
                String errorUrl = (appUrl == null ? "https://localhost" : appUrl)
                    + "/index.html?state=offline";
                view.post(() -> view.loadUrl(errorUrl));
            }
        });
    }

    private boolean isSpecialScheme(Uri url) {
        if (url == null || url.getScheme() == null) {
            return true;
        }
        String scheme = url.getScheme().toLowerCase();
        return "about".equals(scheme) || "data".equals(scheme) || "blob".equals(scheme);
    }

    private boolean shouldStayInApp(Uri url) {
        if (url == null || url.getScheme() == null) {
            return false;
        }
        String scheme = url.getScheme().toLowerCase();
        if ("http".equals(scheme) || "https".equals(scheme)) {
            String host = url.getHost() == null ? "" : url.getHost().toLowerCase();
            if (isAppHost(host)) {
                offlinePageShown = false;
                return true;
            }
        }
        return false;
    }

    private boolean handleExternalNavigation(Uri url) {
        if (url == null) {
            return true;
        }
        String scheme = url.getScheme() == null ? "" : url.getScheme().toLowerCase();

        if ("javascript".equals(scheme) || "file".equals(scheme)) {
            return true;
        }

        if (!allowExternalLinks) {
            Toast.makeText(this, "External links are disabled in this app", Toast.LENGTH_SHORT).show();
            return true;
        }

        if ("http".equals(scheme) || "https".equals(scheme)) {
            return getBridge().launchIntent(url);
        }

        // tel:, mailto:, sms:, whatsapp:, market:, upi:, intent:, etc.
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, url));
        } catch (ActivityNotFoundException ignored) {
            Toast.makeText(this, "No app can handle this link", Toast.LENGTH_SHORT).show();
        }
        return true;
    }

    private boolean isAppHost(String host) {
        if (host == null || host.isEmpty()) {
            return false;
        }
        String configured = websiteHost == null ? "" : websiteHost.toLowerCase();
        return host.equals(configured) || host.equals("localhost") || host.equals("127.0.0.1")
            || (!configured.isEmpty() && host.endsWith("." + configured));
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

  if (existingMainActivityPath && path.resolve(existingMainActivityPath) !== path.resolve(targetMainActivityPath)) {
    fs.unlinkSync(existingMainActivityPath);
    cleanEmptyDirs(path.dirname(existingMainActivityPath), javaBaseDir);
    console.log(`[OK] Relocated MainActivity.java to package: ${config.package_id}`);
  } else {
    console.log(`[OK] Updated MainActivity.java for package: ${config.package_id}`);
  }

  console.log('=== Android App Preparation Completed Successfully ===\n');
  return config;
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
      else if (arg === '--project-dir') options.projectDir = nextArg.trim();
      else if (arg === '--app-name') options.appName = nextArg.trim();
      else if (arg === '--package-id') options.packageId = nextArg.trim();
      else if (arg === '--website-url') options.websiteUrl = nextArg.trim();
      else if (arg === '--version-name') options.versionName = nextArg.trim();
      else if (arg === '--version-code') options.versionCode = parseInt(nextArg.trim(), 10);
      else if (arg === '--primary-color') options.primaryColor = nextArg.trim();
      else if (arg === '--background-color') options.backgroundColor = nextArg.trim();
      else if (arg === '--splash-background-color') options.splashBackgroundColor = nextArg.trim();
      else if (arg === '--status-bar-color') options.statusBarColor = nextArg.trim();
      else if (arg === '--navigation-bar-color') options.navigationBarColor = nextArg.trim();
      else if (arg === '--orientation') options.orientation = nextArg.trim();
      else if (arg === '--allow-external-links') options.allowExternalLinks = nextArg.trim() === 'true';
      else if (arg === '--enable-status-bar') options.enableStatusBar = nextArg.trim() === 'true';
    }
  }

  if (process.env.APP_NAME) options.appName = process.env.APP_NAME;
  if (process.env.PACKAGE_ID) options.packageId = process.env.PACKAGE_ID;
  if (process.env.WEBSITE_URL) options.websiteUrl = process.env.WEBSITE_URL;
  if (process.env.VERSION_NAME) options.versionName = process.env.VERSION_NAME;
  if (process.env.VERSION_CODE) options.versionCode = parseInt(process.env.VERSION_CODE, 10);

  try {
    prepareApp(options);
  } catch (err) {
    console.error(`\n[FATAL] ${err.message}`);
    process.exit(1);
  }
}

module.exports = {
  prepareApp,
  darkenColor,
  isLightColor
};
