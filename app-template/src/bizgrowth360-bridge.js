/**
 * Bizgrowth360 Native Feature Bridge v1.0.0
 *
 * Provides a standardized, secure, versioned JavaScript API connecting website
 * pages running inside the Android WebView to native Android hardware and OS capabilities.
 */

(function (window) {
  'use strict';

  var BRIDGE_VERSION = '1.0.0';
  var listeners = {};

  var config = window.APP_CONFIG || {};

  var Bridge = {
    version: BRIDGE_VERSION,
    platform: 'android',
    isNative: typeof window.Capacitor !== 'undefined',

    /**
     * Return active application metadata.
     */
    getAppInfo: function () {
      return {
        appName: config.appName || 'Bizgrowth360 App',
        packageId: config.packageId || 'in.bizgrowth360.app',
        versionName: config.versionName || '1.0.0',
        versionCode: config.versionCode || 1,
        bridgeVersion: BRIDGE_VERSION,
        isNative: this.isNative
      };
    },

    /**
     * Native Android Share sheet.
     * Shares URL, title and text. Falls back gracefully on regular web browsers.
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

      // 1. Try Capacitor Native Share
      if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Share) {
        return window.Capacitor.Plugins.Share.share(shareData);
      }

      // 2. Try Standard Web Share API
      if (navigator.share) {
        return navigator.share(shareData);
      }

      // 3. Fallback: Copy to clipboard
      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(shareData.url).then(function () {
          alert('Link copied to clipboard: ' + shareData.url);
          return { value: 'copied' };
        });
      }

      // Prompt fallback
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
        + '?package_id=' + encodeURIComponent(info.packageId)
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
     * Register a push notification device token with WordPress.
     *
     * @param {string} token FCM device token
     * @param {string} [deviceId] Optional unique device UUID
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
        platform: 'android',
        app_version: info.versionName,
        package_id: info.packageId
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
  if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) {
    window.Capacitor.Plugins.App.addListener('appUrlOpen', function (data) {
      Bridge.emit('deepLink', data);
    });
  }

  // Wire Capacitor Push Notification events if available
  if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications) {
    window.Capacitor.Plugins.PushNotifications.addListener('registration', function (token) {
      Bridge.emit('pushToken', token);
      Bridge.registerPushToken(token.value);
    });
    window.Capacitor.Plugins.PushNotifications.addListener('pushNotificationReceived', function (notification) {
      Bridge.emit('pushNotification', notification);
    });
    window.Capacitor.Plugins.PushNotifications.addListener('pushNotificationActionPerformed', function (action) {
      Bridge.emit('pushNotificationAction', action);
      if (action.notification && action.notification.data && action.notification.data.deep_link) {
        window.location.href = action.notification.data.deep_link;
      }
    });
  }

  // Expose to global window
  window.Bizgrowth360Bridge = Bridge;

})(window);
