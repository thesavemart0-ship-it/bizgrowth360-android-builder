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
- **Solution**: The generated `MainActivity.java` handles `tel:`, `mailto:`, `sms:` and `whatsapp:` schemes and launches native handlers. If `allow_external_links` is `false`, all external links (including other websites) are intentionally blocked.

### Custom icon or splash rejected
- **Cause**: The file is missing, SVG, animated, larger than 10 MB, or below the minimum dimensions (icon 192×192, splash 320×320).
- **Solution**: Use a PNG/JPEG/WebP still image within the limits. The build intentionally fails instead of silently substituting an image. Omit `app_icon`/`splash_image` (or the URL inputs) to use the generated defaults. See [ASSETS.md](ASSETS.md).

### `APK is unsigned and cannot be installed`
- **Cause**: The Gradle wrapper did not apply a signing config.
- **Solution**: The template falls back to the debug key for release builds. Run `validateSigningRelease`/`validateSigningDebug`; ensure the workspace's `local.properties`/`ANDROID_HOME` and JDK are valid. Configure production secrets for signed release artifacts.

### `Package ID mismatch` or `App label mismatch`
- **Cause**: The APK/AAB was not rebuilt after a configuration change, or an artifact from a previous run was uploaded.
- **Solution**: Use the build's own `dist/builds/<build-id>/` output. Each run uses a unique build ID; never reuse another run's artifact directory.

### Concurrent builds overwrite each other
- **Cause**: Running a legacy Phase 2 workflow (which modifies `app-template/`) at the same time as the dynamic workflow.
- **Solution**: Use **Bizgrowth360 Android Dynamic Build**. It copies the template into `os.tmpdir()/bizgrowth360-builds/<build-id>` and never writes to the base template. The legacy workflow is manual-only for regression testing.

### Build summary is missing
- **Cause**: The build failed before the orchestrator created its output directory (for example, an invalid inline JSON payload).
- **Solution**: Check the job log and the automatically uploaded `build-logs-*` artifact. The workflow also writes a fallback summary row.
