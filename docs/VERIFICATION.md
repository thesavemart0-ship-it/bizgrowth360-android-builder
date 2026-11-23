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

## 7. Not executed in this environment

These require a physical/emulated device and are **not claimed as passing**:

- Installing the APK on an emulator/device and visually confirming the website, splash transition, back button and status bar.
- Signing with a real production keystore (local and GitHub builds used the debug key fallback).
- AAB-only build mode (AAB production was exercised through `both` mode).

The legacy Phase 2 workflow file is preserved as a manual regression workflow.
