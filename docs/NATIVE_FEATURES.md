# Bizgrowth360 App Builder: Native Features Engine Documentation

**Phase 6 Specification & Architecture Guide**  
**Version:** 1.0.0  
**Target Platform:** Android (Capacitor 7 + Android SDK 34 / Java 21)

---

## 1. Executive Overview

The **Bizgrowth360 Native Features Engine** transforms basic website WebView wrappers into full-featured, hardware-integrated Android applications. It bridges web-based WordPress applications with native Android capabilities including:

- **Push Notifications** via Firebase Cloud Messaging (FCM) with Android 13+ runtime permissions and channel support.
- **Deep Linking & Verified App Links** with dynamic `/.well-known/assetlinks.json` domain ownership verification.
- **Native Share Sheet** invoking the Android OS system share modal with progressive browser fallbacks.
- **Camera & File Uploads** supporting `<input type="file">` forms, photo capture, and gallery selection.
- **Offline Recovery Engine** with branded network-failure screens and automatic reconnect-to-page reloading.
- **Native Permission Manager** requesting sensitive permissions (e.g. Geolocation) on-demand with user rationales.
- **In-App Update Engine** delivering real-time version checks with recommended and mandatory update policies.

---

## 2. JavaScript Native Bridge (`window.Bizgrowth360Bridge`)

The runtime bridge is loaded before page scripts and provides a versioned, secure, asynchronous interface between web pages and the native Android OS.

### Bridge API Reference

```javascript
// Check bridge availability
if (window.Bizgrowth360Bridge) {
    console.log("Bridge initialized! Native mode:", window.Bizgrowth360Bridge.isNative);
}
```

#### Methods

| Method | Parameters | Returns | Description |
|---|---|---|---|
| `getAppInfo()` | None | `Object` | Returns `{ appName, packageId, versionName, versionCode, bridgeVersion, isNative }`. |
| `share(options)` | `{ title, text, url, dialogTitle }` | `Promise<Object>` | Opens native Android share sheet. Falls back to Web Share API, clipboard copy, or URL prompt. |
| `getConnectivity()` | None | `{ online: boolean, type: string }` | Returns real-time network connectivity and connection speed info. |
| `checkUpdate()` | None | `Promise<Object>` | Queries WordPress REST API endpoint to determine if an update is available or mandatory. |
| `registerPushToken(token, deviceId)` | `token: string, deviceId?: string` | `Promise<Object>` | Registers FCM token with WordPress database for targeted or broadcast push delivery. |
| `on(event, callback)` | `event: string, callback: function` | `void` | Registers an event listener for native events. |
| `off(event, callback)` | `event: string, callback: function` | `void` | Removes a registered event listener. |
| `emit(event, data)` | `event: string, data: any` | `void` | Dispatches an event to registered listeners. |

#### Event Lifecycle

```javascript
// Listen for connection changes
window.Bizgrowth360Bridge.on('online', function(conn) {
    console.log('App back online! Reloading live data...');
});

window.Bizgrowth360Bridge.on('offline', function(conn) {
    console.warn('App went offline.');
});

// Deep link opened
window.Bizgrowth360Bridge.on('deepLink', function(data) {
    console.log('Deep link opened with URL:', data.url);
});

// Push notification received
window.Bizgrowth360Bridge.on('pushNotification', function(notification) {
    console.log('Push received:', notification.title, notification.body);
});

// Push notification tapped
window.Bizgrowth360Bridge.on('pushNotificationAction', function(action) {
    console.log('Push action tapped:', action);
    if (action.notification?.data?.deep_link) {
        window.location.href = action.notification.data.deep_link;
    }
});
```

---

## 3. Push Notifications Architecture (Firebase Cloud Messaging)

### Setup & Credentials
1. Create a project in [Firebase Console](https://console.firebase.google.com/).
2. Add an Android app with your configured **Package ID** (e.g. `in.bizgrowth360.demoapp`).
3. Download `google-services.json` and paste its contents into **App Builder > Native Features > Push Notifications**.
4. Retrieve your **Firebase Cloud Messaging Server Key** (or service account credential) and save it in the admin panel.

### Android 13+ (API 33) Runtime Permissions
Push notifications require explicit user opt-in on Android 13+. When enabled:
- The build orchestrator injects `<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />` into `AndroidManifest.xml`.
- The notification channel metadata is injected:
  ```xml
  <meta-data
      android:name="com.google.firebase.messaging.default_notification_channel_id"
      android:value="default_channel" />
  ```
- The bridge registers listeners with `@capacitor/push-notifications` to capture the device token on initial launch and send it to WordPress via `POST /wp-json/bizgrowth360/v1/register-device`.

### Dispatching Push Notifications
Administrators can send test notifications from the WordPress Admin or trigger notifications programmatically:
```php
$rest = new \Bizgrowth360\AppBuilder\RestApi();
$rest->send_fcm_notification($server_key, $device_tokens, 'Order #1024 Shipped', 'Your package is on the way!', [
    'url' => home_url('/my-account/orders/1024/')
]);
```

---

## 4. Deep Links & Android App Links

### Android App Links (`https://domain.com/...`)
Android App Links route web URLs directly to the installed app without showing the Android system disambiguation dialog ("Open with Chrome or App?").

1. **Manifest Configuration**: When Deep Linking is enabled in WordPress, the build engine automatically injects:
   ```xml
   <intent-filter android:autoVerify="true">
       <action android:name="android.intent.action.VIEW" />
       <category android:name="android.intent.category.DEFAULT" />
       <category android:name="android.intent.category.BROWSABLE" />
       <data android:scheme="https" android:host="wordpress.townconnectai.com" />
   </intent-filter>
   ```
2. **Digital Asset Links**: Android verifies domain association by fetching:
   `https://<domain>/.well-known/assetlinks.json`
   - Served dynamically with `Content-Type: application/json; charset=utf-8` and HTTP 200 (no redirects).
   - Generates exact JSON payload:
     ```json
     [
       {
         "relation": ["delegate_permission/common.handle_all_urls"],
         "target": {
           "namespace": "android_app",
           "package_name": "in.bizgrowth360.demoapp",
           "sha256_cert_fingerprints": [
             "14:6D:E9:D6:0F:4B:B7:21:3E:9A:86:14:04:95:C2:59:7A:DE:52:99:38:62:3A:42:3B:56:59:84:72:42:12:44"
           ]
         }
       }
     ]
     ```

### Custom URL Schemes (`bizgrowth360://...`)
A custom scheme filter is also generated for direct app-to-app linking:
```xml
<intent-filter>
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="bizgrowth360" />
</intent-filter>
```

---

## 5. Camera & File Upload Engine

HTML `<input type="file">` elements inside standard WebViews do not work out-of-the-box on Android without proper file chooser intents and permissions.

### Build Engine Injection
- **Camera Capture (`camera_enabled = true`)**:
  - Injects `android.permission.CAMERA`
  - Injects `<uses-feature android:name="android.hardware.camera" android:required="false" />`
  - Injects `androidx.core.content.FileProvider` and `@xml/file_paths` for safe content URI handling.
- **Gallery Selection (`gallery_enabled = true`)**:
  - Injects `android.permission.READ_MEDIA_IMAGES` (Android 13+)
  - Injects `android.permission.READ_EXTERNAL_STORAGE` (`android:maxSdkVersion="32"`)

---

## 6. Offline Recovery Engine

When a mobile device loses internet connectivity, standard WebViews display a generic browser network error (`net::ERR_INTERNET_DISCONNECTED`).

### Offline Behavior
1. In `MainActivity.java`, WebView error listener captures connection drops:
   ```java
   getBridge().addWebViewListener(new WebViewListener() {
       @Override
       public void onReceivedError(WebView view) {
           view.post(() -> view.loadUrl("https://localhost/index.html?state=offline"));
       }
   });
   ```
2. The bundled local app shell displays an offline screen matching the app's brand colors.
3. The bridge continuously monitors `window.addEventListener('online')` and automatically reloads the website when internet connectivity is re-established.

---

## 7. In-App Update Engine

Enforces versioning policies and alerts users when updates are published.

### REST Endpoint: `GET /wp-json/bizgrowth360/v1/app-update`
Query Parameters:
- `version_code`: The integer version code of the installed app.
- `package_id`: (Optional) The Android package ID.

Example Response:
```json
{
  "update_available": true,
  "mandatory": false,
  "current_version": 1,
  "latest_version_code": 2,
  "latest_version_name": "1.1.0",
  "min_version_code": 1,
  "changelog": "Added push notifications and faster checkout.",
  "play_store_url": "https://play.google.com/store/apps/details?id=in.bizgrowth360.demoapp",
  "direct_apk_url": "https://example.com/downloads/app-v1.1.0.apk",
  "app_store_url": "https://apps.apple.com/app/id0000000000",
  "platform": "ios"
}
```

The endpoint also accepts `platform=android|ios`. iOS clients use
`app_store_url`; Android clients use `play_store_url` or `direct_apk_url`.

### Update Policies
- **Recommended**: Displays a non-blocking banner or dialog that the user can dismiss to continue browsing.
- **Mandatory**: When `current_version < min_version_code` or `mode = 'mandatory'`, displays a non-dismissible modal directing the user to download the update.

---

## 8. Implementation & Verification Status Matrix

| Component | Status | Verification Method |
|---|---|---|
| Device Tokens Database (`wp_bg360_device_tokens`) | **Verified** | Live Hostinger MySQL schema verified; token registration & cleanup tested. |
| REST API `/register-device` | **Verified** | Live `POST` test returned HTTP 200 with device token stored. |
| REST API `/app-update` | **Verified** | Live `GET` test returned HTTP 200 with version policy JSON. |
| Digital Asset Links (`/.well-known/assetlinks.json`) | **Verified** | Live HTTP 200 verified (canonical redirect suppressed). |
| Native JavaScript Bridge (`bizgrowth360-bridge.js`) | **Verified** | Injected in `public/` and `src/` app templates; verified with automated unit tests. |
| Dynamic Manifest Permissions (`prepare-app.js`) | **Verified** | 40 Node.js unit tests passing with dynamic permissions, intent-filters, and metadata. |
| WordPress Admin Dashboard UI | **Verified** | Live deployed to `wordpress.townconnectai.com` with real-time status chips and action modals. |
| Real FCM Push Delivery | **Ready** | Server-side dispatcher implemented; requires external Firebase Server Key and live Android device to receive. |
| Real Android Keystore Signing | **Ready** | Secrets-driven CI/CD architecture verified; requires user's Google Play keystore. |

---

## 9. iOS Compatibility (Phase 7)

The same Native Features configuration is applied to the generated iOS project:

- **Push notifications** — `@capacitor/push-notifications`; `aps-environment`
  entitlement and `remote-notification` background mode. The generated
  AppDelegate forwards APNs registration callbacks to Capacitor. Requires the
  Push Notifications capability in the provisioning profile. APNs tokens are
  stored with `platform=ios` and are not sent through FCM.
- **Deep links** — custom URL schemes are written to `CFBundleURLTypes`; Universal
  Links use the Associated Domains entitlement plus the dynamic
  `/.well-known/apple-app-site-association` endpoint.
- **Native share** — `@capacitor/share` on iOS.
- **Camera / gallery / file upload** — `NSCameraUsageDescription`,
  `NSPhotoLibraryUsageDescription` and `NSPhotoLibraryAddUsageDescription`.
- **Geolocation** — `@capacitor/geolocation` plus
  `NSLocationWhenInUseUsageDescription`.
- **Offline handling** — bridge online/offline events and the branded offline
  screen; no iOS plist entry required.
- **In-app updates** — the bridge opens `app_updates.app_store_url` on iOS.
- **Permissions** — Capacitor plugins trigger the native iOS permission prompts.

See [IOS_BUILD.md](IOS_BUILD.md) for signing, IPA export, TestFlight and
troubleshooting.
