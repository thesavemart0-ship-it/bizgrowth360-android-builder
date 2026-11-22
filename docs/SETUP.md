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
2. Under **Workflows** in the left sidebar, click **Bizgrowth360 Android Build Engine**.
3. Click the **Run workflow** dropdown on the right side.
4. Fill in or accept the parameters:
   - **App Display Name**: e.g., `My Business App`
   - **Android Package ID**: e.g., `com.mycompany.app`
   - **Website URL**: e.g., `https://mybusiness.com`
   - **Version Name**: e.g., `1.0.0`
   - **Version Code**: e.g., `1`
   - **Build Type**: `both` (builds both Debug and Release)
   - **Build Target**: `both` (builds both APK and AAB)
5. Click the green **Run workflow** button.
6. Once the build completes (typically 3-5 minutes), download the generated packages from the **Artifacts** section on the workflow run page.

---

## 5. Local Script Execution

You can run the build engine's scripts locally:

```bash
# 1. Validate configuration
npm run validate

# 2. Run unit tests
npm test

# 3. Apply configuration to app template
npm run prepare

# 4. Generate app icons and splash screens
npm run generate-icons

# 5. Validate generated APK/AAB packages
npm run validate-output
```
