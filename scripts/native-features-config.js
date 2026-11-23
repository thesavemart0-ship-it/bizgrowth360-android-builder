/**
 * native-features-config.js
 * Build-time validation, defaulting, and manifest transformation for Bizgrowth360 Native Features.
 *
 * Provides defense-in-depth validation for feature configuration payloads
 * and injects dynamic Android permissions, intent filters, and metadata.
 */

const fs = require('fs');
const path = require('path');

const SCHEMA_VERSION = 1;

/**
 * Default features configuration matching WordPress NativeFeatures::get_defaults().
 *
 * @param {Object} appConfig
 * @returns {Object}
 */
function defaultFeatures(appConfig = {}) {
  let host = 'example.com';
  if (appConfig.website_url) {
    try {
      host = new URL(appConfig.website_url).hostname || 'example.com';
    } catch (e) {
      // fallback
    }
  }

  return {
    schema_version: SCHEMA_VERSION,
    push_notifications: {
      enabled: false,
      channel_id: 'default_channel',
      channel_name: 'General Notifications',
      firebase_project_id: '',
      firebase_server_key: '',
      google_services_json: ''
    },
    deep_linking: {
      enabled: false,
      domain: host,
      scheme: 'bizgrowth360',
      sha256_fingerprint: '',
      auto_verify: true
    },
    native_share: {
      enabled: true,
      default_title: appConfig.app_name || 'Bizgrowth360 App',
      default_text: `Check this out on ${appConfig.app_name || 'Bizgrowth360 App'}`
    },
    camera_upload: {
      camera_enabled: true,
      gallery_enabled: true,
      max_file_size_mb: 25,
      allowed_mime_types: 'image/*,application/pdf'
    },
    offline_engine: {
      enabled: true,
      title: 'No Internet Connection',
      message: 'Please check your connection and tap retry to reload the page.',
      auto_reconnect: true
    },
    permission_manager: {
      enabled: true,
      location_enabled: false,
      rationale_title: 'Permission Required',
      rationale_message: 'This permission is needed to access native device features.'
    },
    app_updates: {
      enabled: true,
      min_version_code: 1,
      latest_version_code: appConfig.version_code || 1,
      latest_version_name: appConfig.version_name || '1.0.0',
      mode: 'recommended',
      changelog: 'Performance improvements and bug fixes.',
      play_store_url: '',
      direct_apk_url: ''
    }
  };
}

/**
 * Validate and normalize features configuration object.
 *
 * @param {Object|string|null} raw
 * @param {Object} appConfig
 * @returns {Object} { valid: boolean, errors: Array<string>, normalizedFeatures: Object }
 */
function validateFeatures(raw, appConfig = {}) {
  const errors = [];
  const defaults = defaultFeatures(appConfig);

  let input = raw;
  if (typeof input === 'string') {
    const trimmed = input.trim();
    if (trimmed === '') {
      input = {};
    } else {
      try {
        input = JSON.parse(trimmed);
      } catch (err) {
        return {
          valid: false,
          errors: [`Invalid features JSON: ${err.message}`],
          normalizedFeatures: defaults
        };
      }
    }
  }

  if (!input || typeof input !== 'object' || Array.isArray(input)) {
    input = {};
  }

  const normalized = {
    schema_version: SCHEMA_VERSION,
    push_notifications: { ...defaults.push_notifications },
    deep_linking: { ...defaults.deep_linking },
    native_share: { ...defaults.native_share },
    camera_upload: { ...defaults.camera_upload },
    offline_engine: { ...defaults.offline_engine },
    permission_manager: { ...defaults.permission_manager },
    app_updates: { ...defaults.app_updates }
  };

  // 1. Push Notifications
  if (input.push_notifications && typeof input.push_notifications === 'object') {
    const p = input.push_notifications;
    normalized.push_notifications.enabled = Boolean(p.enabled);
    if (typeof p.channel_id === 'string' && p.channel_id.trim()) {
      normalized.push_notifications.channel_id = p.channel_id.trim().replace(/[^a-zA-Z0-9_-]/g, '_');
    }
    if (typeof p.channel_name === 'string' && p.channel_name.trim()) {
      normalized.push_notifications.channel_name = p.channel_name.trim().slice(0, 100);
    }
    if (typeof p.firebase_project_id === 'string') {
      normalized.push_notifications.firebase_project_id = p.firebase_project_id.trim();
    }
    if (typeof p.firebase_server_key === 'string') {
      normalized.push_notifications.firebase_server_key = p.firebase_server_key.trim();
    }
    if (typeof p.google_services_json === 'string') {
      normalized.push_notifications.google_services_json = p.google_services_json.trim();
    }
  }

  // 2. Deep Linking
  if (input.deep_linking && typeof input.deep_linking === 'object') {
    const d = input.deep_linking;
    normalized.deep_linking.enabled = Boolean(d.enabled);
    if (typeof d.domain === 'string' && d.domain.trim()) {
      normalized.deep_linking.domain = d.domain.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
    }
    if (typeof d.scheme === 'string' && d.scheme.trim()) {
      const scheme = d.scheme.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
      normalized.deep_linking.scheme = scheme || 'bizgrowth360';
    }
    if (typeof d.sha256_fingerprint === 'string') {
      normalized.deep_linking.sha256_fingerprint = d.sha256_fingerprint.trim().toUpperCase();
    }
    normalized.deep_linking.auto_verify = d.auto_verify !== false;
  }

  // 3. Native Share
  if (input.native_share && typeof input.native_share === 'object') {
    const s = input.native_share;
    normalized.native_share.enabled = Boolean(s.enabled);
    if (typeof s.default_title === 'string' && s.default_title.trim()) {
      normalized.native_share.default_title = s.default_title.trim().slice(0, 150);
    }
    if (typeof s.default_text === 'string' && s.default_text.trim()) {
      normalized.native_share.default_text = s.default_text.trim().slice(0, 500);
    }
  }

  // 4. Camera & Uploads
  if (input.camera_upload && typeof input.camera_upload === 'object') {
    const c = input.camera_upload;
    normalized.camera_upload.camera_enabled = Boolean(c.camera_enabled);
    normalized.camera_upload.gallery_enabled = Boolean(c.gallery_enabled);
    if (c.max_file_size_mb !== undefined && !Number.isNaN(Number(c.max_file_size_mb))) {
      normalized.camera_upload.max_file_size_mb = Math.max(1, Math.min(200, Number(c.max_file_size_mb)));
    }
    if (typeof c.allowed_mime_types === 'string' && c.allowed_mime_types.trim()) {
      normalized.camera_upload.allowed_mime_types = c.allowed_mime_types.trim().slice(0, 200);
    }
  }

  // 5. Offline Engine
  if (input.offline_engine && typeof input.offline_engine === 'object') {
    const o = input.offline_engine;
    normalized.offline_engine.enabled = Boolean(o.enabled);
    if (typeof o.title === 'string' && o.title.trim()) {
      normalized.offline_engine.title = o.title.trim().slice(0, 100);
    }
    if (typeof o.message === 'string' && o.message.trim()) {
      normalized.offline_engine.message = o.message.trim().slice(0, 300);
    }
    normalized.offline_engine.auto_reconnect = o.auto_reconnect !== false;
  }

  // 6. Permission Manager
  if (input.permission_manager && typeof input.permission_manager === 'object') {
    const p = input.permission_manager;
    normalized.permission_manager.enabled = Boolean(p.enabled);
    normalized.permission_manager.location_enabled = Boolean(p.location_enabled);
    if (typeof p.rationale_title === 'string' && p.rationale_title.trim()) {
      normalized.permission_manager.rationale_title = p.rationale_title.trim().slice(0, 100);
    }
    if (typeof p.rationale_message === 'string' && p.rationale_message.trim()) {
      normalized.permission_manager.rationale_message = p.rationale_message.trim().slice(0, 300);
    }
  }

  // 7. App Updates
  if (input.app_updates && typeof input.app_updates === 'object') {
    const u = input.app_updates;
    normalized.app_updates.enabled = Boolean(u.enabled);
    if (u.min_version_code !== undefined && !Number.isNaN(Number(u.min_version_code))) {
      normalized.app_updates.min_version_code = Math.max(1, Math.floor(Number(u.min_version_code)));
    }
    if (u.latest_version_code !== undefined && !Number.isNaN(Number(u.latest_version_code))) {
      normalized.app_updates.latest_version_code = Math.max(1, Math.floor(Number(u.latest_version_code)));
    }
    if (typeof u.latest_version_name === 'string' && u.latest_version_name.trim()) {
      normalized.app_updates.latest_version_name = u.latest_version_name.trim().slice(0, 50);
    }
    if (u.mode === 'mandatory' || u.mode === 'recommended') {
      normalized.app_updates.mode = u.mode;
    }
    if (typeof u.changelog === 'string') {
      normalized.app_updates.changelog = u.changelog.trim().slice(0, 1000);
    }
    if (typeof u.play_store_url === 'string') {
      normalized.app_updates.play_store_url = u.play_store_url.trim();
    }
    if (typeof u.direct_apk_url === 'string') {
      normalized.app_updates.direct_apk_url = u.direct_apk_url.trim();
    }
  }

  return {
    valid: errors.length === 0,
    errors,
    normalizedFeatures: normalized
  };
}

/**
 * Returns a human-readable summary of enabled native features for logs and reports.
 *
 * @param {Object} features
 * @returns {Array<string>}
 */
function featuresSummary(features) {
  if (!features) return ['Default native features'];
  const summary = [];

  summary.push(`Push Notifications: ${features.push_notifications?.enabled ? 'ENABLED' : 'DISABLED'}`);
  if (features.push_notifications?.enabled) {
    summary.push(`  - Channel: ${features.push_notifications.channel_id} ("${features.push_notifications.channel_name}")`);
  }

  summary.push(`Deep Linking: ${features.deep_linking?.enabled ? 'ENABLED' : 'DISABLED'}`);
  if (features.deep_linking?.enabled) {
    summary.push(`  - Domain: https://${features.deep_linking.domain} (autoVerify=${features.deep_linking.auto_verify})`);
    summary.push(`  - Scheme: ${features.deep_linking.scheme}://`);
  }

  summary.push(`Native Share: ${features.native_share?.enabled ? 'ENABLED' : 'DISABLED'}`);
  summary.push(`Camera & Uploads: ${features.camera_upload?.camera_enabled ? 'Camera=ON' : 'Camera=OFF'}, ${features.camera_upload?.gallery_enabled ? 'Gallery=ON' : 'Gallery=OFF'}`);
  summary.push(`Offline Engine: ${features.offline_engine?.enabled ? 'ENABLED' : 'DISABLED'} (auto_reconnect=${features.offline_engine?.auto_reconnect})`);
  summary.push(`Location Permission: ${features.permission_manager?.location_enabled ? 'REQUESTED' : 'NONE'}`);
  summary.push(`In-App Updates: ${features.app_updates?.enabled ? `ENABLED (${features.app_updates.mode}, min=${features.app_updates.min_version_code}, latest=${features.app_updates.latest_version_code})` : 'DISABLED'}`);

  return summary;
}

module.exports = {
  SCHEMA_VERSION,
  defaultFeatures,
  validateFeatures,
  featuresSummary
};
