/**
 * Bizgrowth360 Native Feature Bridge v1.1.0
 *
 * Provides a standardized, secure, versioned JavaScript API connecting website
 * pages running inside the Android WebView or iOS WKWebView to native hardware
 * and OS capabilities.
 */

(function (window) {
  'use strict';

  var BRIDGE_VERSION = '1.1.0';
  var listeners = {};

  var config = window.APP_CONFIG || {};

  function detectPlatform() {
    try {
      if (window.Capacitor && typeof window.Capacitor.getPlatform === 'function') {
        var platform = window.Capacitor.getPlatform();
        if (platform) return platform;
      }
    } catch (e) {
      // ignore
    }
    return config.platform || 'web';
  }

  function isNativePlatform() {
    try {
      if (window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function') {
        return window.Capacitor.isNativePlatform();
      }
    } catch (e) {
      // ignore
    }
    return typeof window.Capacitor !== 'undefined';
  }

  function capacitorPlugin(name) {
    return window.Capacitor && window.Capacitor.Plugins ? window.Capacitor.Plugins[name] : null;
  }

  var Bridge = {
    version: BRIDGE_VERSION,
    platform: detectPlatform(),
    isNative: isNativePlatform(),

    /**
     * Return active application metadata.
     */
    getAppInfo: function () {
      return {
        appName: config.appName || 'Bizgrowth360 App',
        packageId: config.packageId || 'in.bizgrowth360.app',
        bundleId: config.bundleId || config.packageId || 'in.bizgrowth360.app',
        platform: this.platform,
        versionName: config.versionName || '1.0.0',
        versionCode: config.versionCode || 1,
        bridgeVersion: BRIDGE_VERSION,
        isNative: this.isNative
      };
    },

    /**
     * Report which native features are available on this platform/build.
     *
     * @returns {Object}
     */
    getCapabilities: function () {
      return {
        platform: this.platform,
        native: this.isNative,
        share: !!capacitorPlugin('Share') || !!navigator.share,
        camera: !!capacitorPlugin('Camera'),
        geolocation: !!capacitorPlugin('Geolocation') || !!navigator.geolocation,
        pushNotifications: !!capacitorPlugin('PushNotifications'),
        deepLinks: !!capacitorPlugin('App'),
        fileUpload: true,
        offlineDetection: typeof navigator.onLine === 'boolean',
        inAppUpdates: true
      };
    },

    /**
     * Native Share sheet (Android and iOS).
     *
     * @param {Object} options { title, text, url, dialogTitle }
     * @returns {Promise<Object>}
     */
    share: function (options) {
      options = options || {};
      var shareData = {
        title: options.title || document.title,
        text: options.text || '',
        url: options.url || window.location.href,
        dialogTitle: options.dialogTitle || 'Share via'
      };

      var sharePlugin = capacitorPlugin('Share');
      if (sharePlugin) {
        return sharePlugin.share(shareData);
      }

      if (navigator.share) {
        return navigator.share(shareData);
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(shareData.url).then(function () {
          alert('Link copied to clipboard: ' + shareData.url);
          return { value: 'copied' };
        });
      }

      window.prompt('Copy link to share:', shareData.url);
      return Promise.resolve({ value: 'prompt' });
    },

    /**
     * Check current network connectivity status.
     *
     * @returns {Object} { online: boolean, type: string }
     */
    getConnectivity: function () {
      var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      return {
        online: navigator.onLine,
        type: conn && conn.effectiveType ? conn.effectiveType : 'unknown'
      };
    },

    /**
     * Request a geolocation fix. Uses the Capacitor Geolocation plugin on
     * Android/iOS (which triggers the correct native permission prompt) and
     * falls back to the browser API on the web.
     *
     * @param {Object} options Capacitor/browser geolocation options
     * @returns {Promise<Object>}
     */
    getLocation: function (options) {
      options = options || { enableHighAccuracy: true, timeout: 15000 };
      var geo = capacitorPlugin('Geolocation');

      if (geo) {
        var permission = geo.requestPermissions ? geo.requestPermissions() : Promise.resolve({ location: 'granted' });
        return permission.then(function (state) {
          if (state && state.location && state.location !== 'granted' && state.location !== 'prompt') {
            throw new Error('Location permission was not granted.');
          }
          return geo.getCurrentPosition(options);
        });
      }

      return new Promise(function (resolve, reject) {
        if (!navigator.geolocation) {
          reject(new Error('Geolocation is not available on this device.'));
          return;
        }
        navigator.geolocation.getCurrentPosition(resolve, reject, options);
      });
    },

    /**
     * Query app updates from the WordPress REST API endpoint.
     *
     * @returns {Promise<Object>}
     */
    checkUpdate: function () {
      var self = this;
      var info = self.getAppInfo();
      var baseUrl = config.websiteUrl || '';
      if (!baseUrl) {
        return Promise.reject(new Error('Website URL is not configured.'));
      }

      var updateEndpoint = baseUrl.replace(/\/+$/, '') + '/wp-json/bizgrowth360/v1/app-update'
        + '?package_id=' + encodeURIComponent(info.bundleId)
        + '&platform=' + encodeURIComponent(info.platform)
        + '&version_code=' + encodeURIComponent(info.versionCode);

      return fetch(updateEndpoint)
        .then(function (res) {
          if (!res.ok) throw new Error('Update check failed with status ' + res.status);
          return res.json();
        })
        .then(function (data) {
          self.emit('updateChecked', data);
          return data;
        });
    },

    /**
     * Open the correct store/download destination for an update payload.
     * iOS uses the App Store URL; Android uses Play Store or a direct APK.
     *
     * @param {Object} updateData
     * @returns {boolean}
     */
    openUpdate: function (updateData) {
      updateData = updateData || {};
      var target;
      if (this.platform === 'ios') {
        target = updateData.app_store_url || updateData.play_store_url;
      } else {
        target = updateData.direct_apk_url || updateData.play_store_url || updateData.app_store_url;
      }
      if (!target) return false;
      window.open(target, '_system');
      return true;
    },

    /**
     * Register a push notification device token with WordPress.
     *
     * Android supplies an FCM registration token. iOS supplies an APNs token;
     * it is stored with platform=ios and must be delivered by an APNs provider.
     *
     * @param {string} token
     * @param {string} [deviceId]
     * @returns {Promise<Object>}
     */
    registerPushToken: function (token, deviceId) {
      var info = this.getAppInfo();
      var baseUrl = config.websiteUrl || '';
      if (!baseUrl) {
        return Promise.reject(new Error('Website URL is not configured.'));
      }

      var endpoint = baseUrl.replace(/\/+$/, '') + '/wp-json/bizgrowth360/v1/register-device';
      var payload = {
        token: token,
        device_id: deviceId || ('device_' + Math.random().toString(36).substr(2, 9)),
        platform: info.platform,
        app_version: info.versionName,
        package_id: info.bundleId
      };

      return fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (res) {
        return res.json();
      });
    },

    /**
     * Ask for notification permission and register with APNs/FCM.
     * On iOS this must run after the user has interacted with the app.
     *
     * @returns {Promise<Object>}
     */
    requestPushPermission: function () {
      var push = capacitorPlugin('PushNotifications');
      if (!push) {
        return Promise.resolve({ status: 'unsupported' });
      }

      var check = push.checkPermissions ? push.checkPermissions() : Promise.resolve({ receive: 'prompt' });
      return check.then(function (state) {
        if (state && state.receive === 'granted') {
          return push.register().then(function () { return { status: 'granted' }; });
        }
        return push.requestPermissions().then(function (result) {
          if (result && result.receive === 'granted') {
            return push.register().then(function () { return { status: 'granted' }; });
          }
          return { status: 'denied' };
        });
      });
    },

    /**
     * Event Listener registration.
     */
    on: function (event, callback) {
      if (!listeners[event]) {
        listeners[event] = [];
      }
      listeners[event].push(callback);
    },

    off: function (event, callback) {
      if (!listeners[event]) return;
      listeners[event] = listeners[event].filter(function (cb) {
        return cb !== callback;
      });
    },

    emit: function (event, data) {
      if (!listeners[event]) return;
      listeners[event].forEach(function (cb) {
        try {
          cb(data);
        } catch (e) {
          console.error('[Bizgrowth360Bridge] Event listener error:', e);
        }
      });
    }
  };

  // Wire automatic connectivity event propagation
  window.addEventListener('online', function () {
    Bridge.emit('online', Bridge.getConnectivity());
  });
  window.addEventListener('offline', function () {
    Bridge.emit('offline', Bridge.getConnectivity());
  });

  // Wire Capacitor App Deep-linking if available
  var appPlugin = capacitorPlugin('App');
  if (appPlugin && appPlugin.addListener) {
    appPlugin.addListener('appUrlOpen', function (data) {
      Bridge.emit('deepLink', data);
    });
  }

  // Wire Capacitor Push Notification events if available
  var pushPlugin = capacitorPlugin('PushNotifications');
  if (pushPlugin && pushPlugin.addListener) {
    pushPlugin.addListener('registration', function (token) {
      Bridge.emit('pushToken', token);
      Bridge.registerPushToken(token.value);
    });
    pushPlugin.addListener('registrationError', function (error) {
      Bridge.emit('pushRegistrationError', error);
    });
    pushPlugin.addListener('pushNotificationReceived', function (notification) {
      Bridge.emit('pushNotification', notification);
    });
    pushPlugin.addListener('pushNotificationActionPerformed', function (action) {
      Bridge.emit('pushNotificationAction', action);
      if (action.notification && action.notification.data && action.notification.data.deep_link) {
        window.location.href = action.notification.data.deep_link;
      }
    });
  }

  // Expose to global window
  window.Bizgrowth360Bridge = Bridge;

})(window);
