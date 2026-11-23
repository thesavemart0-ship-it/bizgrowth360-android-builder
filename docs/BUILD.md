# Android Build Execution Guide (Phase 3 — Dynamic Builds)

This document describes how the **Bizgrowth360 Android Dynamic Build Engine** produces independent Android apps from one reusable Capacitor template.

Every build runs in its own temporary workspace. The base `app-template/` is never modified, so concurrent builds cannot leak configuration into each other.

---

## 1. Build Modes

| Setting | Values | Meaning |
| :--- | :--- | :--- |
| `build_type` | `apk` | Installable APK only |
| | `aab` | Google Play App Bundle only |
| | `both` | APK and AAB in one Gradle invocation |
| `build_variant` | `release` | Release build (signed with production keystore when configured) |
| | `debug` | Debug build (debug keystore, WebView inspection enabled) |

Gradle tasks are selected automatically:

- `apk/release` → `assembleRelease`
- `aab/release` → `bundleRelease`
- `both/release` → `assembleRelease bundleRelease` (single preparation pass)
- debug equivalents use `assembleDebug` / `bundleDebug`.

---

## 2. Triggering a Build in GitHub Actions

1. Open **Actions** → **Bizgrowth360 Android Dynamic Build**.
2. Click **Run workflow** and fill in:

| Input | Example | Notes |
| :--- | :--- | :--- |
| App Name | `Mahankali Store` | Escaped safely for Android resources. |
| Package ID | `in.bizgrowth360.mahankalistore` | Validated; applied to `namespace`, `applicationId`, MainActivity package and Capacitor appId. |
| Website URL | `https://mahankalistore.example.com` | HTTPS only. Dangerous schemes are rejected. |
| Version Name | `1.0.0` | Semantic-style validation. |
| Version Code | `1` | Positive integer, max 2,100,000,000. Never silently reset. |
| Build Type | `both` | `apk`, `aab`, or `both`. |
| Build Variant | `release` | `release` or `debug`. |
| Orientation | `portrait` | `portrait`, `landscape`, `auto`, `sensor`. |
| Primary Color | `#7C3AED` | Opaque `#RGB` or `#RRGGBB`. |
| Background Color | `#FFFFFF` | Also used for the native WebView background. |
| Splash Background Color | `#F5F3FF` | Optional; defaults to background color. |
| Icon URL | `https://cdn.example.com/icon.png` | Optional HTTPS asset. |
| Splash URL | `https://cdn.example.com/splash.png` | Optional HTTPS asset. |
| Allow External Links | `true` | `true` opens other sites in the system browser. `false` blocks them. |
| Enable Status Bar | `true` | Hides the system status bar when `false`. |
| Config File | `config/default-app.json` | Optional JSON baseline. Manual inputs override it. |

3. Click **Run workflow**.

Pushing to `main` also builds `config/default-app.json` automatically.

### Asset uploads

GitHub `workflow_dispatch` cannot accept file uploads. Use one of these safe mechanisms:

1. **HTTPS URL inputs** (`icon_url`, `splash_url`) — the engine downloads with HTTPS enforcement, redirect limits, 30s timeout and a 10 MB cap, then validates and re-encodes the image.
2. **Committed repository asset** — place the file under `app-template/assets/` (or anywhere inside the template) and set `app_icon` / `splash_image` in a JSON config file.
3. **Future WordPress payload** — send `icon_url` / `splash_url` in the dispatch JSON.

See [ASSETS.md](ASSETS.md) for accepted formats and dimensions.

---

## 3. Downloading Artifacts

After the run:

1. Open the workflow run.
2. Scroll to **Artifacts**.
3. Download the dynamically named ZIPs, for example:
   - `Mahankali-Store-1.0.0-APK`
   - `Mahankali-Store-1.0.0-AAB`
4. Inside each ZIP the file is named `${SafeAppName}-${Version}-${Variant}.apk|aab`.

Names are sanitized: path separators, traversal sequences, control characters and Windows reserved device names are removed.

---

## 4. Installing and Testing

### Direct transfer
1. Send the APK to an Android device.
2. Tap the file and allow installation from that source when prompted.
3. Launch the app; it opens the configured HTTPS website inside the app.

### ADB
```bash
adb install -r "dist/integration-app-a/apk/Mahankali-Store-1.0.0-release.apk"
```

---

## 5. Local Dynamic Build

Requirements: Node 22+, JDK 21, Android SDK Platform 35 + Build-Tools, `ANDROID_HOME` set.

```bash
# From the repository root
npm ci
cd app-template && npm ci && cd ..

# Build one app from a JSON configuration
node scripts/build-app.js --config config/examples/mahankali-store.json

# Override values / run a debug APK
node scripts/build-app.js \
  --config config/examples/townconnect.json \
  --build-type apk \
  --build-variant debug

# Validate without running Gradle
node scripts/build-app.js --config config/examples/mahankali-store.json --dry-run

# Keep the temporary workspace for inspection
node scripts/build-app.js --config config/examples/mahankali-store.json --keep-workspace
```

Outputs are written to `dist/builds/<build-id>/` (or `--output-dir`):

```text
dist/builds/<build-id>/
├── apk/<App>-<Version>-<variant>.apk
├── aab/<App>-<Version>-<variant>.aab
├── build-summary.json
├── build-summary.md
└── build.log
```

---

## 6. Automated Verification

Each build performs pre-build and post-build checks:

**Before Gradle**
- Configuration schema, package ID, URL, version, color, orientation and booleans.
- Template completeness (20 required files).
- Isolated workspace creation and node_modules linking.
- Icon/splash download and validation (format, dimensions, size, still image).
- Package ID / namespace / version injection into Gradle.
- App name, URL, orientation resources in `strings.xml`, `app-config.js` and the manifest.

**After Gradle**
- APK/AAB file exists and is above the minimum size.
- ZIP structure contains the required Android entries.
- `aapt2 dump badging` verifies package ID, version name, version code and launcher label.
- AAB manifest and resource table are decompressed and checked.
- Bundled `app-config.js` contains the exact app name, package ID and website URL.
- APK signature presence.
- `output-metadata.json` matches the requested package/version.
- SHA-256 hashes are recorded in the summary.

Any failed check fails the build with a meaningful message. Unsigned APKs are rejected in strict mode.

---

## 7. Build Summary

`build-summary.md` is appended to the GitHub Actions summary and contains:

- app name, website, package ID, version name/code, build type/variant, orientation
- signing status
- APK/AAB generated status
- every validation check with PASS/FAIL and detail
- artifact names, file sizes and SHA-256 hashes
- configuration source

`build-summary.json` is machine-readable for the future WordPress plugin callback.
