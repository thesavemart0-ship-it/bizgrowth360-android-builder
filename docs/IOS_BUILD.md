# Bizgrowth360 iOS Build Engine

Phase 7 extends the existing Bizgrowth360 App Builder with real iOS project
generation and IPA builds. Android APK/AAB builds, Design Studio, Native
Features, build history and downloads continue to use the same shared
configuration.

## Architecture

```
WordPress App Builder (Settings + Build Center)
        │  workflow_dispatch (non-sensitive inputs only)
        ├── android-build.yml  ── ubuntu-latest ── Gradle ── APK / AAB
        └── ios-build.yml      ── macOS runner  ── Xcode ── signed IPA
                                              │
                                              └── optional TestFlight upload

scripts/build-ios.js
  ├── copyTemplate()          isolated workspace; shared template untouched
  ├── prepare-ios.js          Info.plist, entitlements, pbxproj, icons, splash
  ├── capacitor sync ios      web assets + native plugins + CocoaPods
  ├── xcodebuild archive      Release archive with manual signing
  ├── xcodebuild -exportArchive
  └── validate-ipa.js         ZIP structure, metadata, codesign (macOS)
```

The iOS workflow is `workflow_dispatch` only. It never runs on push, which
prevents accidental macOS runner spending.

## Prerequisites

1. An Apple Developer Program membership.
2. An App ID / bundle identifier registered in the Apple Developer portal.
3. A distribution certificate (`.p12`) and a provisioning profile
   (`.mobileprovision`) that includes the capabilities used by the app:
   Push Notifications, Associated Domains, and any other enabled feature.
4. An App Store Connect API key (`.p8`) if TestFlight upload is required.
5. A GitHub repository where Actions is enabled for the build workflows.

The app's Apple bundle identifier is **separate** from the Android package ID.
Underscores are not valid in Apple bundle identifiers, so the plugin derives a
default by converting `_` to `-`. Always verify the bundle ID matches the App ID
registered in the Apple Developer portal.

## GitHub Actions secrets

Configure these under **Repository → Settings → Secrets and variables →
Actions**. Never store them in WordPress options, workflow files, source code,
build logs or artifacts.

| Secret | Required | Purpose |
| :--- | :--- | :--- |
| `IOS_CERTIFICATE_BASE64` | Signed IPA | Base64 of the `.p12` signing certificate |
| `IOS_CERTIFICATE_PASSWORD` | Signed IPA | Password for the `.p12` |
| `IOS_PROVISIONING_PROFILE_BASE64` | Signed IPA | Base64 of the `.mobileprovision` |
| `IOS_KEYCHAIN_PASSWORD` | Optional | Temporary keychain password (generated if absent) |
| `IOS_TEAM_ID` | Optional | Apple Team ID when not stored in plugin settings |
| `APPSTORE_CONNECT_KEY_ID` | TestFlight | App Store Connect API key ID |
| `APPSTORE_CONNECT_ISSUER_ID` | TestFlight | App Store Connect issuer ID |
| `APPSTORE_CONNECT_PRIVATE_KEY` | TestFlight | `.p8` key contents (raw PEM or base64) |

Create the base64 values on a trusted machine:

```bash
base64 -w 0 signing-certificate.p12 > cert.b64        # Linux
base64 -i signing-certificate.p12 -o cert.b64         # macOS
base64 -w 0 profile.mobileprovision > profile.b64
```

The workflow imports the certificate into a temporary keychain, installs the
provisioning profile into the runner's profile directory, and deletes both in an
`always()` cleanup step. It never prints the certificate or key.

## Signing and export methods

| Export method | Use case | Identity |
| :--- | :--- | :--- |
| `development` | Development devices | Apple Development |
| `ad-hoc` | Registered devices / QA | Apple Distribution |
| `app-store` | TestFlight and App Store distribution | Apple Distribution |

The generated `ExportOptions.plist` uses `signingStyle: manual` and maps the
bundle ID to the imported provisioning profile name. If the profile does not
include the app's entitlements (for example Associated Domains or Push
Notifications), signing fails and the build is reported as failed. This is
intentional: the plugin never claims a signed IPA when signing failed.

## Running a build

### From WordPress

1. Open **App Builder → App Configuration**.
2. Select **Target Platform** = iOS or Android + iOS and fill in the iOS bundle
   ID, Team ID (optional), export method, build mode and runner.
3. Save the configuration.
4. Use **Build iOS IPA** or **Build All Platforms** in the header.
5. Review the confirmation modal. For App Store builds with TestFlight enabled,
   tick the per-build TestFlight confirmation checkbox.
6. Track stages in **Build Center**: Queued → Preparing → Building → Signing →
   Exporting → Uploading → Completed/Failed/Cancelled.

### From GitHub Actions

Run **Bizgrowth360 iOS Dynamic Build** with `workflow_dispatch` and set
`ios_mode` to `ipa` for a signed IPA or `validation` for an unsigned simulator
compile. Validation mode never produces or claims an IPA.

## IPA export and validation

`scripts/build-ios.js` only marks a build successful after:

1. `xcodebuild archive` succeeds.
2. `xcodebuild -exportArchive` succeeds.
3. An `.ipa` file exists in the export directory.
4. `scripts/validate-ipa.js` accepts the archive:
   - `Payload/<App>.app/Info.plist` exists.
   - The app executable exists.
   - `_CodeSignature/CodeResources` exists.
   - On macOS, `codesign --verify --deep --strict` passes.
   - Bundle ID, version and build number match the requested configuration.
5. The SHA-256 is computed and recorded in `build-summary.json`.

If only an Xcode project or archive was generated, the build is not successful
and no IPA is reported.

## TestFlight / App Store Connect

TestFlight upload requires **all** of the following:

- Build mode `ipa`.
- Export method `app-store`.
- Plugin setting “Allow a TestFlight upload option” enabled.
- Per-build “Upload this signed IPA to TestFlight” confirmation checked.
- App Store Connect API key, key ID and issuer ID configured as secrets.

The workflow validates the IPA with `altool --validate-app` and then uploads it
with `altool --upload-app`. It never runs `--submit-app` and never submits an app
for App Store review or release. App Store submission and release must be done
manually by the account owner in App Store Connect.

## Cost and runner management

- iOS builds use `macos-14` or `macos-15` GitHub-hosted runners.
- macOS minutes are billed at a higher rate than Linux minutes.
- The workflow has a 45-minute timeout and a concurrency group per branch/app
  name to avoid duplicate runs.
- WordPress prevents a new build while another build is active and uses a short
  dispatch lock to reduce duplicate requests.
- Prefer `validation` mode while iterating on project configuration; use `ipa`
  mode only when signing is configured.

## iOS native feature compatibility

| Feature | iOS implementation | Configuration |
| :--- | :--- | :--- |
| Push notifications | `@capacitor/push-notifications` + APNs | `aps-environment` entitlement, `UIBackgroundModes=remote-notification`, Push Notifications capability in the provisioning profile |
| Deep links | `@capacitor/app` `appUrlOpen` + Universal Links | `CFBundleURLSchemes` for custom schemes, Associated Domains entitlement, AASA endpoint |
| Native share | `@capacitor/share` | No plist entry required |
| Camera | `@capacitor/camera` / WKWebView file input | `NSCameraUsageDescription` |
| Gallery / file upload | WKWebView file input + `@capacitor/camera` | `NSPhotoLibraryUsageDescription`, `NSPhotoLibraryAddUsageDescription` |
| Geolocation | `@capacitor/geolocation` with browser fallback | `NSLocationWhenInUseUsageDescription` |
| Offline handling | Bridge online/offline events, branded offline screen | No plist entry |
| In-app updates | WordPress REST `app-update` endpoint + App Store URL | `app_updates.app_store_url`; iOS opens the App Store |
| Permissions | Capacitor plugins request native permissions | Usage descriptions above |

iOS push tokens are APNs tokens. The WordPress plugin stores them with
`platform=ios` and deliberately does **not** send them through the legacy FCM
endpoint. Production iOS push delivery requires an APNs provider (token-based
APNs or Firebase iOS messaging) in addition to this plugin.

Universal Links require the AASA file at:

```
https://your-site.example/.well-known/apple-app-site-association
```

The plugin serves it dynamically with `Content-Type: application/json`. The
`appID` is `<AppleTeamID>.<BundleID>`, so the Team ID must be configured. The
signed app must include the Associated Domains entitlement and the provisioning
profile must include the Associated Domains capability.

## Troubleshooting

| Symptom | Likely cause | Fix |
| :--- | :--- | :--- |
| `IOS_CERTIFICATE_BASE64 is required` | Missing signing secret | Add the certificate secret or use `validation` mode |
| `No valid Apple code-signing identity` | Wrong `.p12` password or certificate | Re-export the certificate and password |
| `No profiles for ... were found` | Bundle ID/profile mismatch | Register the exact bundle ID and regenerate the profile |
| `requires a provisioning profile` | Missing profile secret | Add `IOS_PROVISIONING_PROFILE_BASE64` |
| `Provisioning profile ... doesn't include ...` | Missing capability | Enable Push/Associated Domains on the App ID and profile |
| `altool` not found | Xcode toolchain change | Update the workflow to the current Apple upload tool |
| `App.xcworkspace was not generated` | CocoaPods failure | Check the CocoaPods install step and `pod install` log |
| Build succeeds but no IPA | Export failed | Check the `xcodebuild -exportArchive` log; the build should be failed, not completed |
| AASA 404 | Rewrite rules not flushed | Re-activate the plugin or flush permalinks; confirm the endpoint in a browser |

## Verification status

At the time this document was written:

- iOS project generation, Info.plist/entitlements generation, icon/splash
  generation, bundle ID validation, IPA structural validation and workflow
  security checks were tested locally.
- A real signed IPA was **not** built because no Apple Developer certificate,
  provisioning profile or macOS/Xcode environment was available.
- A TestFlight upload was **not** performed because no App Store Connect API key
  was available.
- Android unit tests and existing build tests were run to check for regressions.

Do not treat the iOS build as production-verified until a real macOS run
produces a signed IPA and (optionally) a TestFlight upload with your credentials.
