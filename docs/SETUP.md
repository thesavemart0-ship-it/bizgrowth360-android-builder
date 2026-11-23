# Bizgrowth360 Android Setup Guide

This guide explains how to set up the **Bizgrowth360 Android Automated Build Engine** in your GitHub repository and local development environment.

---

## 1. Prerequisites

### For Cloud Builds (GitHub Actions)
- A GitHub repository (public or private).
- GitHub Actions enabled in repository settings.
- Repository Secrets configured for release signing (optional for debug builds, required for signed production releases).

### For Local Builds & Testing (Optional)
If you wish to test builds or scripts locally on your machine:
- **Node.js**: v20+ LTS or v22 LTS (`node -v`).
- **npm**: v10+ (`npm -v`).
- **Java Development Kit (JDK)**: OpenJDK 21 or 17 (`java -version`).
- **Android SDK**: Android API Platform 35 and Build-Tools 35.0.0 installed with `ANDROID_HOME` configured.

---

## 2. Repository Configuration

1. **Push the repository to GitHub**:
   ```bash
   git add .
   git commit -m "feat: complete Bizgrowth360 Android build engine"
   git remote add origin https://github.com/<your-org-or-username>/bizgrowth360-android-builder.git
   git push -u origin main
   ```

2. **Verify GitHub Actions Permissions**:
   - Navigate to **Settings** > **Actions** > **General** in your GitHub repository.
   - Under **Workflow permissions**, ensure **Read repository contents and packages permissions** is selected.
   - Click **Save**.

---

## 3. Configuring Repository Secrets (For Release Signing)

To generate cryptographically signed Release APKs and Google Play-ready AAB bundles, configure the following secrets in GitHub:

1. In your GitHub repository, click **Settings** > **Secrets and variables** > **Actions**.
2. Click **New repository secret** and add each of the following:

| Secret Name | Description | Example / Format |
| :--- | :--- | :--- |
| `ANDROID_KEYSTORE_BASE64` | Base64-encoded string of your `.jks` or `.keystore` file | `MIIKqwIBAzCCCm8GCSqGSIb3...` |
| `ANDROID_KEYSTORE_PASSWORD` | The master password for the keystore file | `YourKeystorePassword123` |
| `ANDROID_KEY_ALIAS` | The alias identifier for your signing key | `bizgrowth360-key` |
| `ANDROID_KEY_PASSWORD` | The specific password for the key alias | `YourKeyPassword123` |

> [!NOTE]
> If these secrets are omitted, the build engine will still run successfully, producing debug-signed or test APKs for rapid validation. The workflow will explicitly label these artifacts as `Unsigned / Debug Signed`.

For detailed instructions on generating a keystore and encoding it to Base64, see [SIGNING.md](SIGNING.md).

---

## 4. Running Your First Build

1. Go to the **Actions** tab in your GitHub repository.
2. Under **Workflows** in the left sidebar, click **Bizgrowth360 Android Dynamic Build**.
3. Click the **Run workflow** dropdown on the right side.
4. Fill in or accept the parameters:
   - **App Name**: e.g., `My Business App`
   - **Package ID**: e.g., `com.mycompany.app`
   - **Website URL**: e.g., `https://mybusiness.com`
   - **Version Name**: e.g., `1.0.0`
   - **Version Code**: e.g., `1`
   - **Build Type**: `both` (APK + AAB), `apk`, or `aab`
   - **Build Variant**: `release` or `debug`
   - **Orientation**, **Primary Color**, **Background Color**
   - Optional **Icon URL** / **Splash URL** (HTTPS, see [ASSETS.md](ASSETS.md))
5. Click the green **Run workflow** button.
6. Download the dynamically named artifacts, e.g. `My-Business-App-1.0.0-APK` and `My-Business-App-1.0.0-AAB`.

Every run uses a unique temporary workspace. The base template is never modified, so concurrent builds are isolated.

The previous Phase 2 workflow is preserved as **Bizgrowth360 Legacy Static Build (Regression)** for manual regression checks only.

---

## 5. Local Script Execution

```bash
# 1. Validate a configuration file
node scripts/validate-config.js config/default-app.json

# 2. Run the unit + isolation test suite
node --test tests/unit.test.js

# 3. Dry-run preparation (validation, workspace, assets)
node scripts/build-app.js --config config/examples/mahankali-store.json --dry-run

# 4. Build APK/AAB from an isolated workspace
node scripts/build-app.js --config config/examples/mahankali-store.json

# 5. Validate artifacts manually
node scripts/validate-output.js --strict --config config/examples/mahankali-store.json \
  --apk dist/builds/<build-id>/apk/<file>.apk \
  --aab dist/builds/<build-id>/aab/<file>.aab
```

Local requirements: `ANDROID_HOME` must point to an Android SDK with Platform 35 and Build-Tools installed. The build script writes the isolated workspace's `local.properties` automatically.
