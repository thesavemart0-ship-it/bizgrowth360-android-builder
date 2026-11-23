# Bizgrowth360 Android Build Engine (Phase 3)

A configuration-driven Android build engine that turns one reusable **Capacitor + Gradle** template into independent, signed Android applications. It runs locally or in **GitHub Actions** and produces real **APK** and **AAB** packages.

This phase extends the existing Phase 2 engine. It does **not** rebuild the template, does not touch signing, and does not implement WordPress integration yet.

---

## What is dynamic now

Each build supplies its own:

- App display name
- Android package ID (`applicationId` + `namespace` + MainActivity package + Capacitor appId)
- Website URL (HTTPS only)
- Version name and version code
- Primary, background, splash, status-bar and navigation-bar colors
- Orientation
- Custom icon and splash image (HTTPS URL or repo-relative path)
- APK / AAB / both, release or debug
- External-link policy and status-bar visibility

Every build:

1. Validates the configuration and the template.
2. Copies the template into a **unique temporary workspace** (the base template is never modified).
3. Applies branding and native configuration in the workspace.
4. Downloads/validates optional images and generates all Android densities.
5. Runs Capacitor sync and Gradle.
6. Verifies the actual APK/AAB metadata, package ID, version and bundled config.
7. Writes dynamic artifacts, SHA-256 hashes and a build summary.
8. Deletes the temporary workspace.

Concurrent builds therefore cannot overwrite each other's files or configuration.

---

## Quick start

### GitHub Actions

**Actions** → **Bizgrowth360 Android Dynamic Build** → **Run workflow**.

Key inputs: `app_name`, `package_id`, `website_url`, `version_name`, `version_code`, `build_type` (`apk|aab|both`), `build_variant` (`release|debug`), `orientation`, colors, and optional `icon_url` / `splash_url`.

Artifacts are named dynamically, for example:

- `Mahankali-Store-1.0.0-APK`
- `Mahankali-Store-1.0.0-AAB`

See [docs/BUILD.md](docs/BUILD.md) and [docs/ASSETS.md](docs/ASSETS.md).

### Local

```bash
npm ci
cd app-template && npm ci && cd ..

export ANDROID_HOME=/path/to/Android/Sdk   # Windows: set ANDROID_HOME
node scripts/build-app.js --config config/examples/mahankali-store.json
```

Requirements: Node 22+, JDK 21, Android SDK Platform 35, Build-Tools, `ANDROID_HOME`.

Inputs can also be supplied through a JSON file, an inline `BUILD_CONFIG_JSON` payload, CLI flags or environment variables. This keeps the engine ready for the future WordPress dispatch API.

---

## Configuration schema

`config/app-config.schema.json` is the machine-readable contract. Example:

```json
{
  "app_name": "Mahankali Store",
  "package_id": "in.bizgrowth360.mahankalistore",
  "website_url": "https://mahankalistore.example.com",
  "version_name": "1.0.0",
  "version_code": 1,
  "app_icon": null,
  "splash_image": null,
  "primary_color": "#7C3AED",
  "background_color": "#FFFFFF",
  "splash_background_color": "#F5F3FF",
  "status_bar_color": "#7C3AED",
  "navigation_bar_color": "#FFFFFF",
  "orientation": "portrait",
  "build_type": "both",
  "build_variant": "release",
  "allow_external_links": true,
  "enable_status_bar": true
}
```

### Validation rules

| Field | Rule |
| :--- | :--- |
| `app_name` | 1–60 chars, no control characters; XML/AAPT escaped |
| `package_id` | ≥2 dot segments; each starts with a letter; alphanumeric/underscore; no Java keywords; ≤255 chars |
| `website_url` | HTTPS only, valid host, no credentials, no dangerous schemes |
| `version_name` | `1.0.0` / `2.1.4-beta` style, ≤64 chars |
| `version_code` | integer 1–2,100,000,000 |
| colors | opaque `#RGB` or `#RRGGBB` |
| `orientation` | `portrait`, `landscape`, `auto`, `sensor`, `unspecified` |
| `build_type` | `apk`, `aab`, `both` |
| `build_variant` | `release`, `debug` |
| icon / splash | PNG/JPEG/WebP, max 10 MB, single still image |

---

## How the app opens the website

The bundled Capacitor shell shows a branded loader/offline screen, then navigates to the configured HTTPS URL. The website host is added to Capacitor `server.allowNavigation`, so it stays **inside the app**.

- Same site and subdomains → loaded in the app WebView.
- Other HTTP/HTTPS hosts → opened in the system browser when `allow_external_links=true`, blocked when `false`.
- `tel:`, `mailto:`, `sms:`, `whatsapp:` → handled by native intents.
- Offline / load failure → bundled branded error page with retry.
- Hardware back button → WebView history, double-tap to exit.
- Status bar → colored, light/dark icons chosen automatically, optional hide.

---

## Repository layout

```text
.
├── .github/workflows/
│   ├── android-build.yml             # Phase 3 dynamic isolated build
│   └── android-build-legacy.yml      # Preserved Phase 2 regression workflow
├── app-template/                     # Reusable Capacitor + Android template
│   ├── public/                       # App shell (loader, offline, retry)
│   └── android/                      # Gradle project
├── config/
│   ├── default-app.json
│   ├── app-config.schema.json
│   └── examples/
├── scripts/
│   ├── build-app.js                  # Orchestrator (isolated build)
│   ├── validate-config.js            # Schema + security validation
│   ├── prepare-app.js                # Applies config to workspace
│   ├── generate-icons.js             # Icons, adaptive icons, splash
│   └── validate-output.js            # APK/AAB + aapt2 verification
├── tests/
│   ├── unit.test.js                  # 28 unit/isolation/concurrency tests
│   └── integration.test.js           # Verifies two real builds
├── docs/
└── README.md
```

---

## Tests

```bash
node --test tests/unit.test.js

# After two real builds:
BUILD_SUMMARY_A=dist/integration-app-a/build-summary.json \
BUILD_SUMMARY_B=dist/integration-app-b/build-summary.json \
node --test tests/integration.test.js
```

The integration test opens the actual APK/AAB archives, re-runs strict validation, checks package IDs, labels, versions, URLs, signatures and bundled runtime config, and confirms the two builds are different and their workspaces were cleaned.

---

## Signing

The existing signing mechanism is unchanged. Configure these repository secrets:

| Secret | Purpose |
| :--- | :--- |
| `ANDROID_KEYSTORE_BASE64` | Base64 `.jks` |
| `ANDROID_KEYSTORE_PASSWORD` | Keystore password |
| `ANDROID_KEY_ALIAS` | Key alias |
| `ANDROID_KEY_PASSWORD` | Key password |

If absent, release builds are **debug-signed** and the summary says so. Keystore files are decoded only into `$RUNNER_TEMP`, permission-restricted, masked in logs and deleted in an `always()` step. Secrets are never written to the repository, summaries or artifacts.

See [docs/SIGNING.md](docs/SIGNING.md).

---

## Future WordPress integration (not implemented here)

The engine is ready to receive a dispatch payload from the future WordPress plugin:

- `POST /repos/{owner}/{repo}/actions/workflows/android-build.yml/dispatches`
- Inputs: app name, URL, package ID, icon/splash URL, colors, version, build type.
- The same payload can be passed locally as `BUILD_CONFIG_JSON` or `--config-json`.
- `build-summary.json` provides machine-readable results for polling/callbacks.

No WordPress API is implemented in Phase 3.

---

## More documentation

- [docs/BUILD.md](docs/BUILD.md) — build modes, workflow inputs, artifacts, local builds
- [docs/ASSETS.md](docs/ASSETS.md) — safe icon/splash input and validation
- [docs/SETUP.md](docs/SETUP.md) — repository, secrets and local setup
- [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) — common errors
- [docs/SIGNING.md](docs/SIGNING.md) — keystore generation
- [docs/VERIFICATION.md](docs/VERIFICATION.md) — Phase 3 test results and remaining verification
- [docs/HOSTINGER_DEPLOYMENT.md](docs/HOSTINGER_DEPLOYMENT.md) — Hostinger plugin deployment record
