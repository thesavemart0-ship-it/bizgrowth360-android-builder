/**
 * validate-config.js
 * Validates the Android build configuration for Bizgrowth360 Android Builder.
 * Reusable both as a CLI tool and as an imported module.
 *
 * This module is intentionally dependency-free so it can also be reused by a
 * future WordPress plugin / API dispatcher.
 */

const fs = require('fs');
const path = require('path');

// Android Java & Kotlin reserved keywords that cannot be used as package segments
const RESERVED_JAVA_KEYWORDS = new Set([
  'abstract', 'assert', 'boolean', 'break', 'byte', 'case', 'catch', 'char',
  'class', 'const', 'continue', 'default', 'do', 'double', 'else', 'enum',
  'extends', 'final', 'finally', 'float', 'for', 'goto', 'if', 'implements',
  'import', 'instanceof', 'int', 'interface', 'long', 'native', 'new', 'package',
  'private', 'protected', 'public', 'return', 'short', 'static', 'strictfp',
  'super', 'switch', 'synchronized', 'this', 'throw', 'throws', 'transient',
  'try', 'void', 'volatile', 'while', 'true', 'false', 'null'
]);

const ALLOWED_ORIENTATIONS = new Set([
  'portrait', 'landscape', 'auto', 'sensor', 'unspecified'
]);

const ALLOWED_BUILD_TYPES = new Set(['apk', 'aab', 'both']);
const ALLOWED_BUILD_VARIANTS = new Set(['release', 'debug']);

// Android accepts #RGB and #RRGGBB. We intentionally reject #AARRGGBB here
// because the same value is also consumed by CSS, where #RRGGBBAA means
// something different. Keeping the schema to opaque colors avoids silent
// branding inconsistencies between Android resources and the web shell.
const HEX_COLOR_REGEX = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;
const SEMVER_OR_VERSION_REGEX = /^[0-9]+(\.[0-9]+)*(-[a-zA-Z0-9.]+)?$/;
const CONTROL_CHARS_REGEX = /[\u0000-\u001F\u007F]/;
const ASSET_URL_FIELDS = ['icon_url', 'splash_url'];

/**
 * Returns true for loopback/private/link-local hosts. Asset URLs are fetched by
 * the build runner, so blocking these avoids SSRF against internal services.
 */
function isDisallowedAssetHost(hostname) {
  if (!hostname) return true;
  const host = String(hostname).toLowerCase().replace(/^\[/, '').replace(/\]$/, '');
  if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local')) return true;
  if (host === '::1' || host === '0:0:0:0:0:0:0:1') return true;

  const v4 = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (v4) {
    const [a, b] = [Number(v4[1]), Number(v4[2])];
    if (a === 0 || a === 10 || a === 127) return true;
    if (a === 169 && b === 254) return true;
    if (a === 192 && b === 168) return true;
    if (a === 172 && b >= 16 && b <= 31) return true;
    if (a === 100 && b >= 64 && b <= 127) return true;
  }

  if (host.includes(':')) {
    if (host.startsWith('fc') || host.startsWith('fd') || host.startsWith('fe80')) return true;
  }
  return false;
}

/**
 * Validates Android Package ID (applicationId)
 * Rules:
 * - Must have at least two segments separated by dot (e.g. com.example)
 * - Each segment must start with a letter (a-zA-Z)
 * - Segments can only contain letters, numbers, and underscores
 * - Segments cannot be reserved Java keywords
 * - Overall length <= 255 chars
 */
function validatePackageId(packageId) {
  const errors = [];
  if (typeof packageId !== 'string' || !packageId.trim()) {
    errors.push('package_id is required and must be a non-empty string.');
    return { valid: false, errors };
  }

  const trimmed = packageId.trim();

  if (trimmed.length > 255) {
    errors.push(`package_id is too long (${trimmed.length} chars). Maximum allowed is 255 characters.`);
  }

  if (/\s/.test(trimmed)) {
    errors.push('package_id cannot contain whitespace.');
  }

  if (trimmed.startsWith('.') || trimmed.endsWith('.')) {
    errors.push('package_id cannot start or end with a dot.');
  }

  if (trimmed.includes('..')) {
    errors.push('package_id cannot contain consecutive dots.');
  }

  const segments = trimmed.split('.');
  if (segments.length < 2) {
    errors.push(`package_id "${trimmed}" must contain at least two segments (e.g., com.example.app).`);
  }

  if (segments.length > 64) {
    errors.push('package_id cannot contain more than 64 dot-separated segments.');
  }

  for (const seg of segments) {
    if (!seg) {
      continue; // Handled by consecutive dots or leading/trailing dot
    }
    if (!/^[a-zA-Z]/.test(seg)) {
      errors.push(`Segment "${seg}" in package_id must start with an ASCII letter [a-zA-Z].`);
    }
    if (!/^[a-zA-Z0-9_]+$/.test(seg)) {
      errors.push(`Segment "${seg}" contains invalid characters. Only alphanumeric characters and underscores are allowed.`);
    }
    if (RESERVED_JAVA_KEYWORDS.has(seg.toLowerCase())) {
      errors.push(`Segment "${seg}" is a reserved Java keyword and cannot be used in a package name.`);
    }
  }

  return { valid: errors.length === 0, errors };
}

/**
 * Validates Website URL.
 * HTTPS is required by default. Dangerous schemes (javascript:, data:, file:,
 * intent:) are rejected because the value is injected into the app shell,
 * Capacitor configuration, and Android resources.
 */
function validateWebsiteUrl(websiteUrl, allowHttp = false) {
  const errors = [];
  if (typeof websiteUrl !== 'string' || !websiteUrl.trim()) {
    errors.push('website_url is required and must be a non-empty string.');
    return { valid: false, errors };
  }

  const trimmed = websiteUrl.trim();

  if (trimmed.length > 2048) {
    errors.push('website_url is too long (maximum 2048 characters).');
  }

  if (CONTROL_CHARS_REGEX.test(trimmed)) {
    errors.push('website_url cannot contain control characters.');
  }

  let parsedUrl;
  try {
    parsedUrl = new URL(trimmed);
  } catch (err) {
    errors.push(`website_url "${trimmed}" is not a valid URL: ${err.message}`);
    return { valid: false, errors };
  }

  const allowedProtocols = allowHttp ? ['https:', 'http:'] : ['https:'];
  if (!allowedProtocols.includes(parsedUrl.protocol)) {
    errors.push(
      `website_url must use ${allowHttp ? '"https://" or "http://"' : 'the secure "https://" protocol'}. ` +
      `Received: "${parsedUrl.protocol}"`
    );
  }

  if (parsedUrl.username || parsedUrl.password) {
    errors.push('website_url must not contain embedded credentials (username/password).');
  }

  if (!parsedUrl.hostname || parsedUrl.hostname.length < 3) {
    errors.push(`website_url has an invalid hostname: "${parsedUrl.hostname}"`);
  }

  if (/[\s<>"'\\]/.test(parsedUrl.hostname)) {
    errors.push(`website_url has an unsafe hostname: "${parsedUrl.hostname}"`);
  }

  return { valid: errors.length === 0, errors, url: parsedUrl };
}

/**
 * Validates an optional HTTPS image asset URL used by the build workflow.
 */
function validateAssetUrl(assetUrl, fieldName) {
  const errors = [];
  if (assetUrl === undefined || assetUrl === null || assetUrl === '') {
    return { valid: true, errors, url: null };
  }
  if (typeof assetUrl !== 'string') {
    errors.push(`${fieldName} must be a string URL.`);
    return { valid: false, errors, url: null };
  }
  const trimmed = assetUrl.trim();
  if (!trimmed) return { valid: true, errors, url: null };

  let parsed;
  try {
    parsed = new URL(trimmed);
  } catch (err) {
    errors.push(`${fieldName} is not a valid URL: ${err.message}`);
    return { valid: false, errors, url: null };
  }

  if (parsed.protocol !== 'https:') {
    errors.push(`${fieldName} must use https://. Received: "${parsed.protocol}"`);
  }
  if (parsed.username || parsed.password) {
    errors.push(`${fieldName} must not contain embedded credentials.`);
  }
  if (!parsed.hostname || parsed.hostname.length < 3) {
    errors.push(`${fieldName} has an invalid hostname.`);
  } else if (isDisallowedAssetHost(parsed.hostname)) {
    errors.push(`${fieldName} must not target a loopback, private or link-local host.`);
  }

  return { valid: errors.length === 0, errors, url: trimmed };
}

/**
 * Validates and normalizes a hex color. Returns { valid, errors, value }.
 */
function validateColor(value, fieldName) {
  const errors = [];
  if (typeof value !== 'string' || !HEX_COLOR_REGEX.test(value.trim())) {
    errors.push(`${fieldName} must be a valid opaque hex color (e.g. #2563EB or #FFF). Received: "${value}"`);
    return { valid: false, errors, value: null };
  }
  let hex = value.trim().toUpperCase();
  if (hex.length === 4) {
    hex = `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`;
  }
  return { valid: true, errors, value: hex };
}

/**
 * Escapes a value for safe inclusion in an XML resource / AndroidManifest.
 */
function escapeXml(unsafe) {
  if (unsafe === undefined || unsafe === null) return '';
  return String(unsafe)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

/**
 * Escapes a value for an Android string resource. AAPT2 requires a backslash
 * before apostrophes, quotes and backslashes. XML metacharacters are escaped
 * so the generated strings.xml can never be broken out of by a customer value.
 */
function escapeAndroidString(unsafe) {
  if (unsafe === undefined || unsafe === null) return '';
  return String(unsafe)
    .replace(/\\/g, '\\\\')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/'/g, "\\'")
    .replace(/"/g, '\\"')
    .replace(/\r\n|\r|\n/g, '\\n')
    .replace(/\t/g, '\\t');
}

/**
 * Escapes a value for safe inclusion in an HTML text node or title.
 */
function escapeHtml(unsafe) {
  if (unsafe === undefined || unsafe === null) return '';
  return String(unsafe)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Converts an arbitrary display string into a safe filename component.
 * Prevents path traversal, reserved Windows device names, control characters
 * and excessively long names.
 */
function sanitizeFileComponent(value, fallback = 'App', maxLength = 80) {
  let name = String(value === undefined || value === null ? '' : value)
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^A-Za-z0-9._-]+/g, '-')
    .replace(/^[-._]+/, '')
    .replace(/[-._]+$/, '');

  if (!name) name = fallback;
  if (/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i.test(name)) {
    name = `app-${name}`;
  }
  if (name.length > maxLength) {
    name = name.slice(0, maxLength).replace(/[-._]+$/, '');
  }
  return name || fallback;
}

/**
 * Builds the dynamic artifact base name, e.g. "Mahankali-Store-1.0.0".
 */
function buildArtifactBaseName(appName, versionName) {
  const safeApp = sanitizeFileComponent(appName, 'App', 60);
  const safeVersion = sanitizeFileComponent(versionName, '1.0.0', 32);
  return `${safeApp}-${safeVersion}`;
}

/**
 * Detects duplicate package IDs in a set of build configurations.
 * Used by the future API dispatcher and by the test suite.
 */
function findDuplicatePackageIds(configs) {
  const seen = new Map();
  const duplicates = [];
  for (const cfg of configs || []) {
    if (!cfg || !cfg.package_id) continue;
    const id = String(cfg.package_id).trim();
    if (seen.has(id)) {
      duplicates.push(id);
    } else {
      seen.set(id, true);
    }
  }
  return Array.from(new Set(duplicates));
}

/**
 * Returns a normalized hex color or the supplied fallback when invalid.
 */
function normalizeColorValue(value, fallback) {
  if (typeof value !== 'string' || !value.trim()) {
    return fallback ? String(fallback).toUpperCase() : '';
  }
  const result = validateColor(value, 'color');
  return result.valid ? result.value : (fallback ? String(fallback).toUpperCase() : '');
}

/**
 * Validates full configuration object.
 *
 * options.allowHttp - allow http website URLs (default false)
 * options.baseDir   - optional base directory used for asset path validation
 */
function validateConfig(config, options = {}) {
  const errors = [];
  const warnings = [];

  if (!config || typeof config !== 'object' || Array.isArray(config)) {
    return {
      valid: false,
      errors: ['Configuration must be a non-empty JSON object.'],
      warnings: []
    };
  }

  // 1. app_name
  if (typeof config.app_name !== 'string' || !config.app_name.trim()) {
    errors.push('app_name is required and must be a non-empty string.');
  } else {
    const name = config.app_name.trim();
    if (name.length > 60) {
      errors.push(`app_name is too long (${name.length} chars). Maximum allowed is 60 characters.`);
    } else if (name.length > 50) {
      warnings.push(`app_name "${name}" is longer than 50 characters; it may be truncated on Android launcher screens.`);
    }
    if (CONTROL_CHARS_REGEX.test(name)) {
      errors.push('app_name cannot contain control characters or newlines.');
    }
    if (!/[\p{L}\p{N}]/u.test(name)) {
      warnings.push('app_name does not contain any letters or numbers.');
    }
  }

  // 2. package_id
  const pkgValidation = validatePackageId(config.package_id);
  if (!pkgValidation.valid) {
    errors.push(...pkgValidation.errors);
  }

  // 3. website_url
  const urlValidation = validateWebsiteUrl(config.website_url, options.allowHttp || false);
  if (!urlValidation.valid) {
    errors.push(...urlValidation.errors);
  }

  // 4. version_name
  if (typeof config.version_name !== 'string' || !config.version_name.trim()) {
    errors.push('version_name is required (e.g. "1.0.0").');
  } else {
    const version = config.version_name.trim();
    if (!SEMVER_OR_VERSION_REGEX.test(version)) {
      errors.push(`version_name "${version}" is invalid. Expected format like "1.0.0" or "2.1.4-beta".`);
    }
    if (version.length > 64) {
      errors.push('version_name cannot exceed 64 characters.');
    }
  }

  // 5. version_code
  if (config.version_code === undefined || config.version_code === null || config.version_code === '') {
    errors.push('version_code is required (positive integer >= 1).');
  } else if (typeof config.version_code !== 'number' && typeof config.version_code !== 'string') {
    errors.push(`version_code must be a number, not ${Array.isArray(config.version_code) ? 'an array' : typeof config.version_code}.`);
  } else if (typeof config.version_code === 'string' && !/^\d+$/.test(config.version_code.trim())) {
    errors.push(`version_code must contain digits only. Received: "${config.version_code}"`);
  } else {
    const code = Number(config.version_code);
    if (!Number.isInteger(code) || code < 1) {
      errors.push(`version_code must be a positive integer >= 1. Received: ${config.version_code}`);
    } else if (code > 2100000000) {
      errors.push(`version_code ${code} exceeds Google Play's maximum allowed limit of 2,100,000,000.`);
    }
  }

  // 6. Colors. status/navigation/splash defaults are derived later.
  const requiredColors = ['primary_color', 'background_color'];
  for (const field of requiredColors) {
    if (config[field] === undefined || config[field] === null || config[field] === '') {
      errors.push(`${field} is required.`);
    } else {
      const result = validateColor(config[field], field);
      if (!result.valid) errors.push(...result.errors);
    }
  }

  const optionalColors = [
    'splash_background_color',
    'status_bar_color',
    'navigation_bar_color'
  ];
  for (const field of optionalColors) {
    if (config[field] !== undefined && config[field] !== null && config[field] !== '') {
      const result = validateColor(config[field], field);
      if (!result.valid) errors.push(...result.errors);
    }
  }

  // 7. orientation
  if (config.orientation !== undefined && config.orientation !== null && config.orientation !== '') {
    if (typeof config.orientation !== 'string' || !ALLOWED_ORIENTATIONS.has(config.orientation.toLowerCase())) {
      errors.push(`orientation must be one of: ${Array.from(ALLOWED_ORIENTATIONS).join(', ')}. Received: "${config.orientation}"`);
    }
  }

  // 8. Booleans
  const booleanFields = ['allow_external_links', 'enable_status_bar'];
  for (const field of booleanFields) {
    if (config[field] !== undefined && typeof config[field] !== 'boolean') {
      errors.push(`${field} must be a boolean (true or false). Received: ${config[field]}`);
    }
  }

  // 9. Build type / variant
  let buildType = 'both';
  let buildVariant = 'release';
  if (config.build_type !== undefined && config.build_type !== null && config.build_type !== '') {
    if (typeof config.build_type !== 'string') {
      errors.push('build_type must be a string.');
    } else {
      const raw = config.build_type.toLowerCase();
      if (raw === 'debug' || raw === 'release') {
        // Backwards compatibility with the Phase 2 workflow inputs where
        // "build_type" meant debug/release and "build_target" meant apk/aab.
        buildVariant = raw;
        warnings.push(`Legacy build_type "${raw}" interpreted as build_variant.`);
        const legacyTarget = String(config.build_target || 'both').toLowerCase();
        buildType = ALLOWED_BUILD_TYPES.has(legacyTarget) ? legacyTarget : 'both';
      } else if (ALLOWED_BUILD_TYPES.has(raw)) {
        buildType = raw;
      } else {
        errors.push(`build_type must be one of: apk, aab, both. Received: "${config.build_type}"`);
      }
    }
  }

  if (config.build_variant !== undefined && config.build_variant !== null && config.build_variant !== '') {
    if (typeof config.build_variant !== 'string' || !ALLOWED_BUILD_VARIANTS.has(config.build_variant.toLowerCase())) {
      errors.push(`build_variant must be one of: release, debug. Received: "${config.build_variant}"`);
    } else {
      buildVariant = config.build_variant.toLowerCase();
    }
  }

  // 10. Optional asset paths
  for (const field of ['app_icon', 'splash_image']) {
    if (config[field] !== undefined && config[field] !== null && config[field] !== '' && typeof config[field] !== 'string') {
      errors.push(`${field} must be a string path.`);
    }
  }

  // 11. Optional remote asset URLs
  for (const field of ASSET_URL_FIELDS) {
    const result = validateAssetUrl(config[field], field);
    if (!result.valid) errors.push(...result.errors);
  }

  const primaryColor = normalizeColorValue(config.primary_color, '#2563EB');
  const backgroundColor = normalizeColorValue(config.background_color, '#FFFFFF');
  const splashBackgroundColor = normalizeColorValue(
    config.splash_background_color || config.background_color,
    backgroundColor
  );
  const statusBarColor = normalizeColorValue(
    config.status_bar_color || config.primary_color,
    primaryColor
  );
  const navigationBarColor = normalizeColorValue(
    config.navigation_bar_color || config.background_color,
    backgroundColor
  );

  return {
    valid: errors.length === 0,
    errors,
    warnings,
    normalizedConfig: {
      app_name: typeof config.app_name === 'string' ? config.app_name.trim() : '',
      package_id: typeof config.package_id === 'string' ? config.package_id.trim() : '',
      website_url: typeof config.website_url === 'string' ? config.website_url.trim() : '',
      version_name: typeof config.version_name === 'string' ? config.version_name.trim() : '1.0.0',
      version_code: Number(config.version_code) || 1,
      app_icon: config.app_icon || null,
      splash_image: config.splash_image || null,
      icon_url: config.icon_url || null,
      splash_url: config.splash_url || null,
      primary_color: primaryColor || '#2563EB',
      background_color: backgroundColor || '#FFFFFF',
      splash_background_color: splashBackgroundColor || '#FFFFFF',
      status_bar_color: statusBarColor || '#2563EB',
      navigation_bar_color: navigationBarColor || '#FFFFFF',
      orientation: typeof config.orientation === 'string' ? config.orientation.toLowerCase() : 'portrait',
      allow_external_links: config.allow_external_links !== false,
      enable_status_bar: config.enable_status_bar !== false,
      build_type: buildType,
      build_variant: buildVariant
    }
  };
}

// Unit test runner when --test is passed
function runSelfTests() {
  console.log('Running validate-config unit test suite...');
  let passed = 0;
  let failed = 0;

  function assert(condition, message) {
    if (condition) {
      passed++;
      console.log(`  [PASS] ${message}`);
    } else {
      failed++;
      console.error(`  [FAIL] ${message}`);
    }
  }

  const base = {
    app_name: 'Bizgrowth360 App',
    package_id: 'in.bizgrowth360.demoapp',
    website_url: 'https://example.com',
    version_name: '1.0.0',
    version_code: 1,
    primary_color: '#2563EB',
    background_color: '#FFFFFF'
  };

  assert(validateConfig(base).valid === true, 'Valid config passes validation');

  const kwResult = validatePackageId('com.switch.app');
  assert(kwResult.valid === false && kwResult.errors.some(e => e.includes('reserved Java keyword')),
    'Rejects Java reserved keyword in package segment ("switch")');

  const digitResult = validatePackageId('com.123test.app');
  assert(digitResult.valid === false && digitResult.errors.some(e => e.includes('must start with an ASCII letter')),
    'Rejects segment starting with a number');

  const hyphenResult = validatePackageId('com.my-app.app');
  assert(hyphenResult.valid === false && hyphenResult.errors.some(e => e.includes('invalid characters')),
    'Rejects hyphens in package ID');

  const singleResult = validatePackageId('bizgrowth360');
  assert(singleResult.valid === false && singleResult.errors.some(e => e.includes('must contain at least two segments')),
    'Rejects single-segment package ID');

  const httpResult = validateWebsiteUrl('http://insecure-site.com');
  assert(httpResult.valid === false && httpResult.errors.some(e => e.includes('https://')),
    'Rejects plain http website URL');

  const schemeResult = validateWebsiteUrl('javascript:alert(1)');
  assert(schemeResult.valid === false && schemeResult.errors.some(e => e.includes('secure')),
    'Rejects javascript scheme');

  const malformedUrl = validateWebsiteUrl('not-a-url');
  assert(malformedUrl.valid === false, 'Rejects malformed URL');

  const colorResult = validateConfig({ ...base, primary_color: 'blue' });
  assert(colorResult.valid === false && colorResult.errors.some(e => e.includes('primary_color must be a valid')),
    'Rejects non-hex color name "blue"');

  const vcResult = validateConfig({ ...base, version_code: -5 });
  assert(vcResult.valid === false && vcResult.errors.some(e => e.includes('version_code must be a positive integer')),
    'Rejects negative version code');

  const escaped = escapeAndroidString("Mahankali's <Store> & Co");
  assert(escaped.includes("\\'") && escaped.includes('&amp;') && escaped.includes('&lt;'),
    'Escapes apostrophes and XML metacharacters for Android string resources');

  const artifact = buildArtifactBaseName('../../Mahankali/Store', '1.0.0');
  assert(artifact === 'Mahankali-Store-1.0.0', 'Generates safe artifact names without path traversal');

  console.log(`\nSelf-test summary: ${passed} passed, ${failed} failed.`);
  if (failed > 0) {
    process.exit(1);
  }
}

// CLI Execution entrypoint
if (require.main === module) {
  const args = process.argv.slice(2);

  if (args.includes('--test')) {
    runSelfTests();
    process.exit(0);
  }

  const configPath = args[0] || path.join(__dirname, '..', 'config', 'default-app.json');
  console.log(`Validating configuration from: ${configPath}`);

  if (!fs.existsSync(configPath)) {
    console.error(`Error: Configuration file not found at: ${configPath}`);
    process.exit(1);
  }

  let raw;
  try {
    raw = fs.readFileSync(configPath, 'utf8');
  } catch (err) {
    console.error(`Error: Unable to read file at ${configPath}: ${err.message}`);
    process.exit(1);
  }

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (err) {
    console.error(`Error: Invalid JSON syntax in ${configPath}: ${err.message}`);
    process.exit(1);
  }

  const result = validateConfig(parsed);

  if (result.warnings.length > 0) {
    console.warn('\nWarnings:');
    result.warnings.forEach(w => console.warn(`  - [WARN] ${w}`));
  }

  if (!result.valid) {
    console.error('\nConfiguration Validation FAILED with errors:');
    result.errors.forEach(e => console.error(`  - [ERROR] ${e}`));
    process.exit(1);
  }

  console.log('\nConfiguration is VALID:');
  console.log(JSON.stringify(result.normalizedConfig, null, 2));
}

module.exports = {
  validateConfig,
  validatePackageId,
  validateWebsiteUrl,
  validateAssetUrl,
  validateColor,
  isDisallowedAssetHost,
  escapeXml,
  escapeAndroidString,
  escapeHtml,
  sanitizeFileComponent,
  buildArtifactBaseName,
  findDuplicatePackageIds,
  RESERVED_JAVA_KEYWORDS,
  ALLOWED_ORIENTATIONS,
  ALLOWED_BUILD_TYPES,
  ALLOWED_BUILD_VARIANTS
};
