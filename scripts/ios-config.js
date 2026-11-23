/**
 * ios-config.js
 * Validation and normalization for Apple/iOS build configuration.
 */

const IOS_EXPORT_METHODS = ['development', 'ad-hoc', 'app-store'];
const IOS_MODES = ['ipa', 'validation'];
const IOS_ORIENTATIONS = ['portrait', 'landscape', 'auto', 'sensor'];

/**
 * Validate an Apple bundle identifier.
 *
 * Apple accepts alphanumeric characters, hyphens and periods. Each segment
 * must not start or end with a hyphen. We additionally require at least two
 * dot-separated segments so the value is a proper reverse-DNS identifier.
 *
 * @param {string} bundleId
 * @returns {{valid: boolean, errors: string[], value?: string}}
 */
function validateBundleId(bundleId) {
  const errors = [];
  if (typeof bundleId !== 'string' || !bundleId.trim()) {
    errors.push('iOS Bundle Identifier is required.');
    return { valid: false, errors };
  }

  const id = bundleId.trim();
  const segment = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';

  if (id.length > 155) {
    errors.push('iOS Bundle Identifier must be 155 characters or fewer.');
  }
  if (!new RegExp(`^${segment}(\\.${segment})+$`).test(id)) {
    errors.push('iOS Bundle Identifier must contain at least two dot-separated segments. Each segment may contain letters, numbers and hyphens, and cannot start or end with a hyphen.');
  }
  if (!/[A-Za-z]/.test(id)) {
    errors.push('iOS Bundle Identifier must contain at least one letter.');
  }
  if (/\.(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i.test(id)) {
    errors.push('iOS Bundle Identifier cannot end in a reserved device name.');
  }

  return { valid: errors.length === 0, errors, value: id };
}

/**
 * Derive a safe default bundle identifier from an Android package ID.
 * Underscores are not allowed in Apple bundle identifiers.
 *
 * @param {string} packageId
 * @returns {string}
 */
function defaultBundleId(packageId) {
  const source = String(packageId || 'in.bizgrowth360.demoapp');
  return source
    .replace(/_/g, '-')
    .replace(/[^A-Za-z0-9.-]/g, '')
    .split('.')
    .filter(Boolean)
    .map(segment => segment.replace(/^-+|-+$/g, ''))
    .join('.') || 'in.bizgrowth360.app';
}

/**
 * Normalize iOS settings from the shared app configuration and CLI options.
 *
 * @param {Object} appConfig
 * @param {Object} options
 * @returns {Object}
 */
function normalizeIosConfig(appConfig = {}, options = {}) {
  const bundleId = String(
    options.bundleId ||
    appConfig.ios_bundle_id ||
    appConfig.bundle_id ||
    defaultBundleId(appConfig.package_id)
  ).trim();

  const exportMethod = String(options.exportMethod || appConfig.ios_export_method || 'app-store').toLowerCase();
  const mode = String(options.mode || appConfig.ios_mode || 'ipa').toLowerCase();
  const teamId = String(options.teamId || appConfig.ios_team_id || process.env.IOS_TEAM_ID || '').trim();
  const orientation = String(appConfig.orientation || 'portrait').toLowerCase();

  return {
    bundle_id: bundleId,
    export_method: IOS_EXPORT_METHODS.includes(exportMethod) ? exportMethod : 'app-store',
    team_id: teamId,
    orientation: IOS_ORIENTATIONS.includes(orientation) ? orientation : 'portrait',
    version_name: String(appConfig.version_name || '1.0.0'),
    build_number: Math.max(1, Math.floor(Number(appConfig.version_code || 1))),
    app_name: String(appConfig.app_name || 'Bizgrowth360 App'),
    website_url: String(appConfig.website_url || 'https://example.com'),
    upload_to_testflight: options.uploadToTestflight === true || appConfig.ios_upload_testflight === true,
    confirm_app_store_upload: options.confirmAppStoreUpload === true,
    mode: IOS_MODES.includes(mode) ? mode : 'ipa'
  };
}

/**
 * Validate normalized iOS configuration.
 *
 * @param {Object} appConfig
 * @param {Object} options
 * @returns {{valid: boolean, errors: string[], warnings: string[], ios: Object}}
 */
function validateIosConfig(appConfig = {}, options = {}) {
  const errors = [];
  const warnings = [];
  const ios = normalizeIosConfig(appConfig, options);

  const bundle = validateBundleId(ios.bundle_id);
  if (!bundle.valid) errors.push(...bundle.errors);

  if (!/^[0-9]+(\.[0-9]+){0,2}$/.test(ios.version_name)) {
    errors.push(`iOS CFBundleShortVersionString must be 1-3 numeric components (e.g. 1.0.0). Received: ${ios.version_name}`);
  }
  if (!Number.isInteger(ios.build_number) || ios.build_number < 1) {
    errors.push('iOS build number (CFBundleVersion) must be a positive integer.');
  }
  if (ios.version_name.length > 18) {
    warnings.push('iOS version name is unusually long.');
  }
  if (ios.team_id && !/^[A-Z0-9]{10}$/i.test(ios.team_id)) {
    warnings.push('Apple Team ID is usually a 10-character alphanumeric value.');
  }
  if (ios.mode === 'ipa') {
    if (!ios.team_id) {
      warnings.push('Apple Team ID was not supplied. It can also come from the IOS_TEAM_ID GitHub secret.');
    }
    if (ios.upload_to_testflight && !ios.confirm_app_store_upload) {
      warnings.push('TestFlight upload requires explicit confirmation; the upload will be skipped.');
    }
    if (ios.upload_to_testflight && ios.export_method !== 'app-store') {
      errors.push('TestFlight uploads require the app-store export method.');
    }
  } else if (ios.upload_to_testflight) {
    warnings.push('TestFlight upload is ignored in validation mode.');
  }

  return { valid: errors.length === 0, errors, warnings, ios };
}

module.exports = {
  IOS_EXPORT_METHODS,
  IOS_MODES,
  IOS_ORIENTATIONS,
  validateBundleId,
  defaultBundleId,
  normalizeIosConfig,
  validateIosConfig
};
