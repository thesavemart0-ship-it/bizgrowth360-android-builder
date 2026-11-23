# Phase 3 Verification Record

Executed locally on Windows with:

- Node.js v24.18.0
- OpenJDK 21
- Android SDK: `C:\Users\PERSONAL\AppData\Local\Android\Sdk`
- Build-Tools 35.0.0 (`aapt2`)
- Gradle wrapper 8.11.1, AGP 8.7.2, compileSdk 35

The base `app-template/` was not modified by these builds. All builds used isolated workspaces.

---

## 1. Unit / isolation test suite

Command:

```bash
node --test tests/unit.test.js
```

Result: **28 passed, 0 failed** (7.0s).

Covers: configuration validation, invalid package IDs, duplicate package IDs, invalid URLs, dangerous schemes, invalid colors, invalid versions, missing/invalid/default icons, missing/default splash, non-square aspect handling, safe artifact naming, XML/AAPT escaping, build-type task mapping, sequential isolation, concurrent isolation, path traversal, private/loopback asset URLs, inline JSON payloads, and output validator failure handling.

---

## 2. Real concurrent builds

Two builds were launched simultaneously:

| | Build A | Build B |
| :--- | :--- | :--- |
| App name | Mahankali Store | TownConnect |
| Package ID | `in.bizgrowth360.mahankalistore` | `in.bizgrowth360.townconnect` |
| Website | `https://mahankalistore.example.com` | `https://wordpress.townconnectai.com` |
| Version | 1.0.0 (1) | 1.0.1 (2) |
| Mode | both / release | apk / debug |
| Result | SUCCESS | SUCCESS |

Artifacts:

| Artifact | File | Size | SHA-256 |
| :--- | :--- | :--- | :--- |
| Mahankali-Store-1.0.0-APK | `Mahankali-Store-1.0.0-release.apk` | 3.20 MB | `74e12fed12fa9b8aef0ea91fa79c5a07719cc84c458964366c55a9315aabe358` |
| Mahankali-Store-1.0.0-AAB | `Mahankali-Store-1.0.0-release.aab` | 2.97 MB | `97abaf8b04735eb7c9370d372654d04d5b7f074d93b7a9e979f4f9f92bdf33cd` |
| TownConnect-1.0.1-APK | `TownConnect-1.0.1-debug.apk` | 4.07 MB | `b0f06b5bc0c27c10e26c545e52f4e2d23b3be117767ff40b78869a499df3f4f9` |

Both workspaces were removed automatically.

---

## 3. Cross-build integration test

Command:

```bash
BUILD_SUMMARY_A=dist/final-app-a/build-summary.json \
BUILD_SUMMARY_B=dist/final-app-b/build-summary.json \
node --test tests/integration.test.js
```

Result: **1 passed, 0 failed**.

Verified:

- Different package IDs, app names, URLs and build IDs.
- APK package ID, version name, version code and launcher label via `aapt2`.
- AAB package ID, version name and version code decoded from the protobuf manifest.
- Bundled `app-config.js` contains the correct per-build app name, package ID and URL.
- No configuration leakage between the two packages.
- APK/AAB signatures present.
- Requested artifact set matches the build mode (A: APK+AAB, B: APK only).
- Both temporary workspaces were cleaned.

---

## 4. Special-character app name build

Config: `config/examples/special-characters.json`

- App name: `Mahankali's <Store> & Co`
- Package: `in.bizgrowth360.specialchars`
- Mode: apk / debug
- Version: 1.0.0 (3)

Result: **SUCCESS** after AAPT resource compilation.

- Launcher label read back by `aapt2`: `Mahankali's <Store> & Co`
- Artifact: `Mahankali-s-Store-Co-1.0.0-debug.apk` (4.06 MB)
- SHA-256: `6647d8bffb56d590bc16e2726c1f2a205fcb090f414c84a95991ca5cd6b24495`

This confirms XML/AAPT escaping does not truncate or inject resource values.

---

## 5. Asset URL pipeline

Dry run with real HTTPS assets:

- Icon: `https://raw.githubusercontent.com/github/explore/main/topics/android/android.png` (PNG 288×288)
- Splash: `https://picsum.photos/800/1200` (JPEG 800×1200 after one HTTPS redirect)

Result: **SUCCESS** (download, validation, re-encode, all densities generated). Temporary workspace cleaned.

Also verified rejection of HTTP URLs and loopback/private/link-local asset hosts.

---

## 6. GitHub Actions end-to-end run

Workflow: **Bizgrowth360 Android Dynamic Build**  
Run: `45109616293`  
Commit: `8887de0`  
Result: **SUCCESS**

The first run (`45109354007`) failed before the build because `android-actions/setup-android@v3` could not install the obsolete `tools` SDK package. The step was removed; the runner image already provides the Android SDK used by the previous successful builds. The rerun passed every step.

Downloaded artifacts were extracted and re-validated locally with strict mode:

| Artifact | File | Size | SHA-256 |
| :--- | :--- | :--- | :--- |
| `Demo-Website-App-1.0.0-APK` | `Demo-Website-App-1.0.0-release.apk` | 3.27 MB | `1999c8db3f549a1b1ff90acb25496092aac45fea22f920159b941b6591a5bb54` |
| `Demo-Website-App-1.0.0-AAB` | `Demo-Website-App-1.0.0-release.aab` | 3.06 MB | `c44254ceaeb9f7110bafeeaccf52115dcfdc008f59f94e42057bd9be1401974b` |

Verified after download:

- APK package `in.bizgrowth360.demoapp`, version `1.0.0 (1)`, label `Demo Website App`.
- AAB manifest package/version decoded from protobuf.
- APK/AAB signatures present.
- Bundled runtime config contains the configured app name, package ID and URL.
- Artifact download endpoint returned the exact uploaded archive sizes.

---

## 7. WordPress plugin / Phase 3 dispatch contract

The exact Phase 3 input contract used by the deployed WordPress plugin was dispatched through the GitHub API:

- App: `Mahankali Store`
- Package: `in.bizgrowth360.mahankalistore`
- URL: `https://mahankalistore.example.com`
- Mode: `apk / debug`
- Version: `1.0.0 (1)`

Result: **SUCCESS** — run `45109935270`.

Downloaded artifact:

| Artifact | File | Size | SHA-256 |
| :--- | :--- | :--- | :--- |
| `Mahankali-Store-1.0.0-APK` | `Mahankali-Store-1.0.0-debug.apk` | 4.16 MB | `3f2de978418e664b2d4f47dbd65fab727b0a4c0c01b1498ee09e4caa6ccd578e` |

Strict validation after download confirmed `aapt2` package ID, launcher label `Mahankali Store`, version `1.0.0 (1)`, website URL, bundled runtime config and APK signature. This is the same contract the plugin sends from WordPress; the plugin UI itself was not clicked because WordPress admin credentials were not available.

---

## 8. Phase 5 — App Design Studio verification

Executed on the local WordPress test site (`bg360-test`, PHP 8.2) and on GitHub Actions.

### Validation tests

| Test | Result |
| :--- | :--- |
| `node --test tests/unit.test.js` | **34 passed, 0 failed** |
| `php tests/php/design-config-test.php` | **35 passed, 0 failed** |
| PHP lint, all plugin files | **no syntax errors** |
| Local design APK build (`config/examples/design-studio.json`) | **SUCCESS** |
| Local WordPress plugin activation | **SUCCESS** |

### Local WordPress UI tests

The real plugin was activated on a local WordPress installation and the Design Studio was driven through Chromium:

- All 10 Design Studio sections rendered.
- Theme preset selection changed config + live preview.
- Header/nav/card/typography variables appeared in the preview.
- Navigation item and homepage section add/edit operations worked.
- Menu group and item CRUD worked.
- Save returned success and wrote the versioned array to `wp_options`.
- Browser reload restored the saved design (primary color, navigation item, menu items, homepage sections).
- Autosave fired after a debounced edit and persisted to the database.
- Discard reverted unsaved changes.
- Section reset and full reset worked with confirmation.
- Live Preview Home/Menu/Navigation modes worked.
- Size selector changed the preview device size.
- A crafted `custom_html` payload containing `<script>`, `onclick` and `javascript:` was sanitized server-side by the real plugin.

### GitHub Actions design build

| Item | Result |
| :--- | :--- |
| Regression push run `45114907777` (default config, APK + AAB) | **SUCCESS** |
| Design dispatch run `45114928159` (Design Studio payload, APK/debug) | **SUCCESS** |
| Artifact | `Design-Studio-Test-1.0.0-APK` |
| Downloaded APK | `Design-Studio-Test-1.0.0-debug.apk` |
| APK size | 4.18 MB |
| APK SHA-256 | `b1af9a0990245c786d80fcd4817585a3c4e3a0917af634d14a2d8934d51f41bf` |
| Embedded `design.json` | 8,079 bytes, schema v1 |
| Embedded shell config | 6 navigation items, 4 homepage sections, 5 menu items |
| Strict APK validation | package, version, label, URL, signature, bundled runtime config all PASS |

The APK was downloaded from GitHub, its web assets were extracted, and the resulting shell was loaded in Chromium: header, bottom navigation, menu drawer, hero, quick actions, products and safe custom HTML all rendered with the configured colors/typography.

### Not executed

- Installing the APK on a physical Android device or emulator (visual/device runtime behavior).
- Logging into the production Hostinger WordPress admin to click the live Design Studio UI. The identical plugin code and assets were verified locally; production files were deployed and SHA-256 verified.
- Production keystore signing (builds remain debug-signed without repository secrets).
