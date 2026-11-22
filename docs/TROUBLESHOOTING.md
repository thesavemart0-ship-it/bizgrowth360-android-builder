# Bizgrowth360 Troubleshooting Guide

This guide documents common errors encountered during Android automated builds and provides solutions.

---

## 1. Configuration Errors

### `package_id must contain at least two segments`
- **Cause**: Android package names require at least two dot-separated parts (e.g. `com.example`). A single word like `myapp` is invalid.
- **Solution**: Use reverse-domain notation, such as `in.bizgrowth360.demoapp` or `com.company.store`.

### `Segment "switch" is a reserved Java keyword`
- **Cause**: One of the segments in your `package_id` is a Java keyword (`default`, `switch`, `class`, `native`, `int`, etc.).
- **Solution**: Change the segment to an unreserved word (e.g. `com.myswitch.app` instead of `com.switch.app`).

### `website_url must use the secure "https://" protocol`
- **Cause**: The build engine enforces HTTPS by default for security and compliance with modern Android cleartext network security policies.
- **Solution**: Ensure your URL starts with `https://`.

---

## 2. Signing & Keystore Errors

### `No ANDROID_KEYSTORE_BASE64 secret found`
- **Cause**: The GitHub repository secrets have not been configured.
- **Effect**: The pipeline will automatically fall back to debug signing for testing. The build will succeed, but the APK will not be suitable for Google Play upload.
- **Solution**: Follow [SIGNING.md](SIGNING.md) to generate a keystore and add the 4 required secrets to GitHub.

### `Keystore was tampered with, or password was incorrect`
- **Cause**: The `ANDROID_KEYSTORE_PASSWORD` or `ANDROID_KEY_PASSWORD` does not match the password specified during keystore generation.
- **Solution**: Verify your passwords. You can test your keystore locally using:
  ```bash
  keytool -list -v -keystore release.jks -alias your-alias
  ```

---

## 3. Gradle & Android SDK Errors

### `SDK location not found. Define a valid SDK location with an ANDROID_HOME environment variable`
- **Cause**: In local development, Android SDK path is not configured.
- **Solution**: Create `app-template/android/local.properties` with:
  ```properties
  sdk.dir=C\:\\Users\\<YourUsername>\\AppData\\Local\\Android\\Sdk
  # On macOS/Linux:
  # sdk.dir=/Users/<YourUsername>/Library/Android/sdk
  ```
  In GitHub Actions, the runner provides `ANDROID_HOME=/usr/local/lib/android/sdk` automatically.

### `Unsupported class file major version` / Java Version Mismatch
- **Cause**: Gradle 8.11 requires Java 17 or Java 21. Running on Java 8 or Java 11 causes compilation errors.
- **Solution**: Ensure Java 21 (`actions/setup-java@v4` with `java-version: '21'`) is active.

---

## 4. Runtime & In-App Issues

### App displays "No Internet Connection" screen immediately
- **Cause**: The mobile device does not have an active data or Wi-Fi connection, or the configured `website_url` is inaccessible / down.
- **Solution**: Check network connection on device. Tap the **Retry Connection** button once connectivity is restored.

### External links like WhatsApp or Tel do not open
- **Cause**: URL schemes need intent handling.
- **Solution**: The included `MainActivity.java` automatically handles `tel:`, `mailto:`, `sms:`, and `whatsapp:` schemes and launches native Android handler apps.
