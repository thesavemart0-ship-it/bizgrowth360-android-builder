/**
 * validate-config.js
 * Validates the Android build configuration for Bizgrowth360 Android Builder.
 * Reusable both as a CLI tool and as an imported module.
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

const HEX_COLOR_REGEX = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;
const SEMVER_OR_VERSION_REGEX = /^[0-9]+(\.[0-9]+)*(-[a-zA-Z0-9.]+)?$/;

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
 * Validates Website URL
 * Must be a valid HTTPS URL by default
 */
function validateWebsiteUrl(websiteUrl, allowHttp = false) {
  const errors = [];
  if (typeof websiteUrl !== 'string' || !websiteUrl.trim()) {
    errors.push('website_url is required and must be a non-empty string.');
    return { valid: false, errors };
  }

  const trimmed = websiteUrl.trim();
  let parsedUrl;
  try {
    parsedUrl = new URL(trimmed);
  } catch (err) {
    errors.push(`website_url "${trimmed}" is not a valid URL: ${err.message}`);
    return { valid: false, errors };
  }

  if (!allowHttp && parsedUrl.protocol !== 'https:') {
    errors.push(`website_url must use the secure "https://" protocol. Received: "${parsedUrl.protocol}"`);
  } else if (allowHttp && parsedUrl.protocol !== 'https:' && parsedUrl.protocol !== 'http:') {
    errors.push(`website_url must use "https://" or "http://". Received: "${parsedUrl.protocol}"`);
  }

  if (!parsedUrl.hostname || parsedUrl.hostname.length < 3) {
    errors.push(`website_url has an invalid hostname: "${parsedUrl.hostname}"`);
  }

  return { valid: errors.length === 0, errors };
}

/**
 * Validates full configuration object
 */
function validateConfig(config, options = {}) {
  const errors = [];
  const warnings = [];

  if (!config || typeof config !== 'object') {
    return {
      valid: false,
      errors: ['Configuration must be a non-empty JSON object.'],
      warnings: []
    };
  }

  // 1. app_name
  if (typeof config.app_name !== 'string' || !config.app_name.trim()) {
    errors.push('app_name is required and must be a non-empty string.');
  } else if (config.app_name.trim().length > 50) {
    warnings.push(`app_name "${config.app_name}" is longer than 50 characters; it may be truncated on Android launcher screens.`);
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
  } else if (!SEMVER_OR_VERSION_REGEX.test(config.version_name.trim())) {
    errors.push(`version_name "${config.version_name}" is invalid. Expected format like "1.0.0" or "2.1.4-beta".`);
  }

  // 5. version_code
  if (config.version_code === undefined || config.version_code === null) {
    errors.push('version_code is required (positive integer >= 1).');
  } else {
    const code = Number(config.version_code);
    if (!Number.isInteger(code) || code < 1) {
      errors.push(`version_code must be a positive integer >= 1. Received: ${config.version_code}`);
    } else if (code > 2100000000) {
      errors.push(`version_code ${code} exceeds Google Play's maximum allowed limit of 2,100,000,000.`);
    }
  }

  // 6. Colors (optional with defaults or format checked)
  const colorFields = ['primary_color', 'background_color', 'status_bar_color', 'navigation_bar_color'];
  for (const field of colorFields) {
    if (config[field]) {
      if (typeof config[field] !== 'string' || !HEX_COLOR_REGEX.test(config[field])) {
        errors.push(`${field} must be a valid hex color string (e.g. #2563EB or #FFFFFF). Received: "${config[field]}"`);
      }
    }
  }

  // 7. orientation
  if (config.orientation) {
    if (typeof config.orientation !== 'string' || !ALLOWED_ORIENTATIONS.has(config.orientation.toLowerCase())) {
      errors.push(`orientation must be one of: ${Array.from(ALLOWED_ORIENTATIONS).join(', ')}. Received: "${config.orientation}"`);
    }
  }

  // 8. allow_external_links
  if (config.allow_external_links !== undefined && typeof config.allow_external_links !== 'boolean') {
    errors.push(`allow_external_links must be a boolean (true or false). Received: ${config.allow_external_links}`);
  }

  return {
    valid: errors.length === 0,
    errors,
    warnings,
    normalizedConfig: {
      app_name: config.app_name ? config.app_name.trim() : '',
      package_id: config.package_id ? config.package_id.trim() : '',
      website_url: config.website_url ? config.website_url.trim() : '',
      version_name: config.version_name ? config.version_name.trim() : '1.0.0',
      version_code: Number(config.version_code) || 1,
      app_icon: config.app_icon || 'public/icon.png',
      splash_image: config.splash_image || 'public/splash.png',
      primary_color: config.primary_color || '#2563EB',
      background_color: config.background_color || '#FFFFFF',
      status_bar_color: config.status_bar_color || config.primary_color || '#2563EB',
      navigation_bar_color: config.navigation_bar_color || '#FFFFFF',
      orientation: config.orientation ? config.orientation.toLowerCase() : 'portrait',
      allow_external_links: config.allow_external_links !== false
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

  // Test 1: Valid config
  const validResult = validateConfig({
    app_name: 'Bizgrowth360 App',
    package_id: 'in.bizgrowth360.demoapp',
    website_url: 'https://example.com',
    version_name: '1.0.0',
    version_code: 1,
    primary_color: '#2563EB',
    background_color: '#FFFFFF'
  });
  assert(validResult.valid === true, 'Valid config passes validation');

  // Test 2: Invalid package ID (reserved keyword)
  const kwResult = validatePackageId('com.switch.app');
  assert(kwResult.valid === false && kwResult.errors.some(e => e.includes('reserved Java keyword')),
    'Rejects Java reserved keyword in package segment ("switch")');

  // Test 3: Invalid package ID (starts with digit)
  const digitResult = validatePackageId('com.123test.app');
  assert(digitResult.valid === false && digitResult.errors.some(e => e.includes('must start with an ASCII letter')),
    'Rejects segment starting with a number');

  // Test 4: Single segment package ID
  const singleResult = validatePackageId('bizgrowth360');
  assert(singleResult.valid === false && singleResult.errors.some(e => e.includes('must contain at least two segments')),
    'Rejects single-segment package ID');

  // Test 5: Insecure HTTP URL
  const httpResult = validateWebsiteUrl('http://insecure-site.com');
  assert(httpResult.valid === false && httpResult.errors.some(e => e.includes('https://')),
    'Rejects plain http website URL');

  // Test 6: Malformed URL
  const malformedUrl = validateWebsiteUrl('not-a-url');
  assert(malformedUrl.valid === false, 'Rejects malformed URL');

  // Test 7: Invalid Hex color
  const colorResult = validateConfig({
    app_name: 'Test',
    package_id: 'com.test.app',
    website_url: 'https://test.com',
    version_name: '1.0.0',
    version_code: 1,
    primary_color: 'blue'
  });
  assert(colorResult.valid === false && colorResult.errors.some(e => e.includes('primary_color must be a valid hex color')),
    'Rejects non-hex color name "blue"');

  // Test 8: Invalid version code
  const vcResult = validateConfig({
    app_name: 'Test',
    package_id: 'com.test.app',
    website_url: 'https://test.com',
    version_name: '1.0.0',
    version_code: -5
  });
  assert(vcResult.valid === false && vcResult.errors.some(e => e.includes('version_code must be a positive integer')),
    'Rejects negative version code');

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
  RESERVED_JAVA_KEYWORDS
};
