# Android Code Signing Guide

This guide details how Android code signing works in the **Bizgrowth360 Android Build Engine**, how to generate a secure release keystore, and how to configure GitHub Secrets.

---

## 1. Why Code Signing is Critical

On Android:
- Every APK and Android App Bundle (AAB) must be digitally signed before it can be installed on an Android device or published to Google Play.
- The cryptographic certificate identifies the app developer and guarantees that app updates come from the authentic author.
- **Permanent Identity**: Once an app is installed on a device or uploaded to the Google Play Store, all future updates **MUST** be signed with the same key (or use Google Play App Signing). If you lose your signing key, you cannot update your app on existing user devices!

---

## 2. Generating a Production Release Keystore

You can generate a production Java Keystore (`.jks`) using the `keytool` utility bundled with any Java JDK.

### On Windows (PowerShell):
```powershell
& "C:\Program Files\Android\Android Studio\jbr\bin\keytool.exe" `
  -genkeypair -v `
  -keystore release.jks `
  -alias bizgrowth360-key `
  -keyalg RSA `
  -keysize 2048 `
  -validity 10000 `
  -storetype JKS
```

### On macOS / Linux:
```bash
keytool -genkeypair -v \
  -keystore release.jks \
  -alias bizgrowth360-key \
  -keyalg RSA \
  -keysize 2048 \
  -validity 10000 \
  -storetype JKS
```

### Key Parameter Explanations:
- `-keystore release.jks`: Output keystore file name.
- `-alias bizgrowth360-key`: A unique alias identifier for this specific key pair.
- `-keyalg RSA`: Strong RSA encryption algorithm.
- `-keysize 2048`: Recommended 2048-bit key length.
- `-validity 10000`: Certificate validity in days (~27 years). Google Play requires at least 25 years.

You will be prompted to enter:
1. Keystore password (choose a strong password and remember it).
2. Developer information (First and Last Name, Organization, City, Country Code).
3. Confirmation (`yes`).

---

## 3. Base64 Encoding the Keystore

Because GitHub Secrets only accept text strings, the binary `.jks` file must be converted to a Base64 string.

### On Windows (PowerShell):
```powershell
$bytes = [System.IO.File]::ReadAllBytes("release.jks")
$base64 = [Convert]::ToBase64String($bytes)
$base64 | Out-File -FilePath "keystore-base64.txt" -Encoding ascii
Write-Host "Base64 string written to keystore-base64.txt"
```

### On Linux / macOS:
```bash
base64 -w 0 release.jks > keystore-base64.txt
# (On macOS, use: base64 -i release.jks -o keystore-base64.txt)
```

Copy the entire single-line text from `keystore-base64.txt`.

---

## 4. Configuring GitHub Repository Secrets

In your GitHub repository:
1. Click **Settings** > **Secrets and variables** > **Actions**.
2. Click **New repository secret** and add the following 4 secrets:

| Secret Name | Value |
| :--- | :--- |
| `ANDROID_KEYSTORE_BASE64` | The complete contents of `keystore-base64.txt` |
| `ANDROID_KEYSTORE_PASSWORD` | The password you entered when creating the keystore |
| `ANDROID_KEY_ALIAS` | The alias you specified (e.g. `bizgrowth360-key`) |
| `ANDROID_KEY_PASSWORD` | The password for the alias (often same as keystore password) |

---

## 5. Security & Isolation in the Build Pipeline

The Bizgrowth360 build workflow implements stringent security guarantees:
- **No Keystore in Git**: Keystore files are ignored by `.gitignore` and are never committed to version control.
- **Dynamic In-Memory/Runner Decode**: The keystore is decoded into a temporary directory inside `$RUNNER_TEMP` only during the build execution.
- **Log Masking**: Keystore passwords and aliases are explicitly registered with `::add-mask::` so they are never printed in GitHub Actions console logs.
- **Immediate Cleanup**: An `always()` post-build hook erases `$RUNNER_TEMP/keystore` immediately after compilation finishes.

---

## 6. Safe Backup Protocol

> [!IMPORTANT]
> Keep multiple secure offline backups of your `release.jks` file and passwords (e.g., in an encrypted password manager or secure vault). **Never delete this key.**
