/**
 * app.js
 * Client-side logic for the Bizgrowth360 Capacitor Android App Shell.
 * Handles online/offline detection, website redirection, retry mechanism,
 * and seamless fallback.
 */

(function () {
  'use strict';

  // Config defaults (overridden by app-config.js if present)
  var config = window.APP_CONFIG || {
    appName: 'Demo Website App',
    websiteUrl: 'https://example.com',
    primaryColor: '#2563EB',
    backgroundColor: '#FFFFFF',
    allowExternalLinks: true
  };

  // DOM Elements
  var appTitleEl = document.getElementById('app-title');
  var targetUrlEl = document.getElementById('target-url-label');
  var loadingStateEl = document.getElementById('loading-state');
  var errorStateEl = document.getElementById('error-state');
  var retryBtn = document.getElementById('retry-btn');
  var btnText = retryBtn ? retryBtn.querySelector('.btn-text') : null;
  var btnSpinner = retryBtn ? retryBtn.querySelector('.btn-spinner') : null;
  var loadingStatusEl = document.getElementById('loading-status');

  // Apply dynamic colors and text
  if (config.appName && appTitleEl) {
    appTitleEl.textContent = config.appName;
    document.title = config.appName;
  }
  if (config.primaryColor) {
    document.documentElement.style.setProperty('--primary-color', config.primaryColor);
  }
  if (config.backgroundColor) {
    document.documentElement.style.setProperty('--bg-color', config.backgroundColor);
  }
  if (config.websiteUrl && targetUrlEl) {
    targetUrlEl.textContent = config.websiteUrl;
  }

  var isRetrying = false;
  var targetUrl = config.websiteUrl || 'https://example.com';

  function showLoading(message) {
    if (loadingStatusEl && message) {
      loadingStatusEl.textContent = message;
    }
    if (loadingStateEl) {
      loadingStateEl.classList.remove('hidden');
      loadingStateEl.classList.add('visible');
    }
    if (errorStateEl) {
      errorStateEl.classList.add('hidden');
      errorStateEl.classList.remove('visible');
    }
  }

  function showError() {
    if (loadingStateEl) {
      loadingStateEl.classList.add('hidden');
      loadingStateEl.classList.remove('visible');
    }
    if (errorStateEl) {
      errorStateEl.classList.remove('hidden');
      errorStateEl.classList.add('visible');
    }
  }

  /**
   * Attempts connection check to verify if network is truly accessible
   */
  function checkConnectivity(callback) {
    if (!navigator.onLine) {
      return callback(false);
    }

    // Ping check with cache buster and timeout
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var signal = controller ? controller.signal : null;
    var timeoutId = setTimeout(function () {
      if (controller) controller.abort();
      callback(false);
    }, 6000);

    fetch(targetUrl, {
      method: 'HEAD',
      mode: 'no-cors',
      cache: 'no-cache',
      signal: signal
    })
      .then(function () {
        clearTimeout(timeoutId);
        callback(true);
      })
      .catch(function () {
        clearTimeout(timeoutId);
        // Fallback test to a reliable endpoint if target website blocks no-cors HEAD
        fetch('https://www.google.com/generate_204', {
          method: 'GET',
          mode: 'no-cors',
          cache: 'no-cache'
        })
          .then(function () {
            callback(true);
          })
          .catch(function () {
            callback(false);
          });
      });
  }

  /**
   * Main navigation trigger
   */
  function navigateToWebsite() {
    showLoading('Connecting to ' + (config.appName || 'website') + '...');

    checkConnectivity(function (isOnline) {
      if (isOnline) {
        showLoading('Loading website...');
        // Small delay for smooth visual transition
        setTimeout(function () {
          window.location.replace(targetUrl);
        }, 350);
      } else {
        showError();
      }
    });
  }

  /**
   * Retry button handler
   */
  function handleRetry() {
    if (isRetrying) return;
    isRetrying = true;

    if (retryBtn) retryBtn.disabled = true;
    if (btnText) btnText.textContent = 'Checking...';
    if (btnSpinner) btnSpinner.classList.remove('hidden');

    checkConnectivity(function (isOnline) {
      setTimeout(function () {
        isRetrying = false;
        if (retryBtn) retryBtn.disabled = false;
        if (btnText) btnText.textContent = 'Retry Connection';
        if (btnSpinner) btnSpinner.classList.add('hidden');

        if (isOnline) {
          showLoading('Connected! Loading...');
          setTimeout(function () {
            window.location.replace(targetUrl);
          }, 300);
        } else {
          showError();
        }
      }, 600);
    });
  }

  if (retryBtn) {
    retryBtn.addEventListener('click', handleRetry);
  }

  // Auto-retry when network comes back online
  window.addEventListener('online', function () {
    if (errorStateEl && errorStateEl.classList.contains('visible')) {
      handleRetry();
    }
  });

  window.addEventListener('offline', function () {
    showError();
  });

  // Handle Capacitor native back button if Capacitor bridge is present
  if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) {
    window.Capacitor.Plugins.App.addListener('backButton', function (status) {
      if (status && status.canGoBack) {
        window.history.back();
      } else {
        // At root page
        window.Capacitor.Plugins.App.exitApp();
      }
    });
  }

  // Initial check on page load
  document.addEventListener('DOMContentLoaded', function () {
    // Check URL query parameters for explicit error triggers (e.g. from Android native onReceivedError)
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('state') === 'offline') {
      showError();
    } else {
      navigateToWebsite();
    }
  });
})();
