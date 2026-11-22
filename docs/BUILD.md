# Android Build Execution Guide

This document describes how builds are orchestrated in the **Bizgrowth360 Android Build Engine**, the supported build targets, and how to verify and test the resulting packages.

---

## 1. Supported Build Types & Targets

The pipeline supports three operational build configurations:

### 1.1 Debug APK (`debug`)
- **Use Case**: Internal testing, development, and rapid validation.
- **Output**: `Bizgrowth360-<AppName>-<Version>-debug.apk`
- **Signing**: Signed with standard Android debug key. Installs on any Android device with *Developer options* and *Install unknown apps* enabled.
- **Logs**: Contains full web inspector debugging capability (`chrome://inspect`).

### 1.2 Release APK (`release`)
- **Use Case**: Distribution via direct download, client testing, and enterprise sideloading.
- **Output**: `Bizgrowth360-<AppName>-<Version>-release.apk`
- **Signing**: Signed with your production keystore (if secrets configured). Fully optimized.

### 1.3 Play Store Release Bundle (`aab`)
- **Use Case**: Publishing to the **Google Play Store**.
- **Output**: `Bizgrowth360-<AppName>-<Version>-release.aab`
- **Signing**: Signed with your upload key.
- **Format**: Android App Bundle format (`.aab`) containing optimized split resources for user device architectures. Ready for Google Play Console upload.

---

## 2. Triggering a Build via GitHub Actions

### Method 1: GitHub Web Interface (`workflow_dispatch`)
1. Go to the **Actions** tab of your repository.
2. Select **Bizgrowth360 Android Build Engine**.
3. Click **Run workflow**.
4. Configure the parameters:
   - `app_name`: Display name on home screen (e.g. `Apex Fitness`)
   - `package_id`: Android package ID (e.g. `com.apexfitness.app`)
   - `website_url`: HTTPS website URL (e.g. `https://apexfitness.example.com`)
   - `version_name`: Semantic version string (e.g. `1.0.0`)
   - `version_code`: Incremental integer (e.g. `1`)
   - `build_type`: `both`, `release`, or `debug`
   - `build_target`: `both`, `apk`, or `aab`
   - `config_file`: Optional path to custom JSON config (defaults to `config/default-app.json`)
5. Click **Run workflow**.

### Method 2: Git Push
Pushing changes to the `main` branch automatically triggers a build using the settings in `config/default-app.json`.

---

## 3. Downloading Build Artifacts

Once the workflow finishes:
1. Click on the completed workflow run.
2. Scroll to the **Artifacts** section at the bottom of the summary page.
3. You will see individual ZIP downloads:
   - `Bizgrowth360-<AppName>-<Version>-APK` (contains the APK files)
   - `Bizgrowth360-<AppName>-<Version>-AAB` (contains the `.aab` bundle)
4. Unzip the downloaded archive to extract the raw `.apk` or `.aab`.

---

## 4. Installing and Testing on a Physical Android Device

### Method A: Direct Transfer / Download
1. Send the `release.apk` or `debug.apk` to your phone via USB cable, Google Drive, WhatsApp, or email.
2. On your Android phone, tap the file in your Files / Downloads app.
3. If prompted, toggle **Allow from this source** to permit installing unknown apps.
4. Tap **Install**.
5. Launch the app!

### Method B: Via Android Debug Bridge (ADB)
Connect your Android phone via USB with USB Debugging enabled:
```bash
adb install -r dist/apk/Bizgrowth360-Demo_Website_App-1.0.0-debug.apk
```

---

## 5. Build Artifact Verification

Before artifacts are published, the pipeline automatically runs `scripts/validate-output.js`:
- Verifies file existence and non-zero size.
- Confirms ZIP format validity.
- For APK: checks for `AndroidManifest.xml`, `classes.dex`, `resources.arsc`.
- For AAB: checks for `BundleConfig.pb`, `base/manifest/AndroidManifest.xml`, `base/dex/classes.dex`.
- Validates signing signatures.
- Computes SHA-256 cryptographic hashes for integrity verification.
