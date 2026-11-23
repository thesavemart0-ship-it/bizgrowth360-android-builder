/**
 * Integration verification for two real, concurrently produced builds.
 *
 * Usage (after running build-app.js twice):
 *   BUILD_SUMMARY_A=dist/integration-app-a/build-summary.json \
 *   BUILD_SUMMARY_B=dist/integration-app-b/build-summary.json \
 *   node --test tests/integration.test.js
 *
 * This test validates the actual APK/AAB files, not mock data.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const { validateApk, validateAab, inspectZipArchive, readZipEntry } = require('../scripts/validate-output');

const summaryPathA = process.env.BUILD_SUMMARY_A;
const summaryPathB = process.env.BUILD_SUMMARY_B;

function loadSummary(envValue, label) {
  assert.ok(envValue, `${label} summary path is required (set BUILD_SUMMARY_${label})`);
  assert.ok(fs.existsSync(envValue), `Summary not found: ${envValue}`);
  return JSON.parse(fs.readFileSync(envValue, 'utf8'));
}

function readBundledConfig(artifactPath) {
  const zip = inspectZipArchive(artifactPath);
  const entry = readZipEntry(zip, 'assets/public/app-config.js')
    || readZipEntry(zip, 'base/assets/public/app-config.js');
  assert.ok(entry, `app-config.js missing from ${artifactPath}`);
  const text = entry.toString('utf8');
  const readString = (key) => {
    const match = text.match(new RegExp(`${key}\\s*:\\s*("(?:[^"\\\\]|\\\\.)*")`));
    assert.ok(match, `${key} missing from bundled app-config.js`);
    return JSON.parse(match[1]);
  };
  return {
    appName: readString('appName'),
    packageId: readString('packageId'),
    websiteUrl: readString('websiteUrl')
  };
}

test('two concurrent builds produce independent, valid Android packages', () => {
  const a = loadSummary(summaryPathA, 'A');
  const b = loadSummary(summaryPathB, 'B');

  assert.equal(a.status, 'success', `Build A failed: ${a.error || 'unknown'}`);
  assert.equal(b.status, 'success', `Build B failed: ${b.error || 'unknown'}`);

  // Configuration differences
  assert.notEqual(a.config.package_id, b.config.package_id);
  assert.notEqual(a.config.app_name, b.config.app_name);
  assert.notEqual(a.config.website_url, b.config.website_url);
  assert.notEqual(a.buildId, b.buildId);

  // Build modes
  assert.equal(a.build.type, 'both');
  assert.equal(a.build.variant, 'release');
  assert.ok(a.artifacts.apk, 'Build A should produce an APK');
  assert.ok(a.artifacts.aab, 'Build A should produce an AAB');
  assert.equal(b.build.type, 'apk');
  assert.equal(b.build.variant, 'debug');
  assert.ok(b.artifacts.apk, 'Build B should produce an APK');
  assert.equal(b.artifacts.aab, null, 'Build B should not produce an AAB');

  // Artifact names are dynamic and safe
  assert.match(a.artifacts.apk.artifactName, /-APK$/);
  assert.match(a.artifacts.aab.artifactName, /-AAB$/);
  assert.match(b.artifacts.apk.artifactName, /-APK$/);
  assert.ok(!a.artifacts.apk.artifactName.includes('/'));
  assert.ok(!a.artifacts.apk.artifactName.includes('..'));

  // APK A
  const apkA = validateApk(a.artifacts.apk.path, a.config, { strict: true });
  assert.equal(apkA.valid, true, apkA.errors.join('; '));
  assert.equal(apkA.metadata.packageName, a.config.package_id);
  assert.equal(apkA.metadata.versionName, a.config.version_name);
  assert.equal(Number(apkA.metadata.versionCode), a.config.version_code);
  assert.equal(apkA.metadata.applicationLabel, a.config.app_name);

  // AAB A
  const aabA = validateAab(a.artifacts.aab.path, a.config, { strict: true });
  assert.equal(aabA.valid, true, aabA.errors.join('; '));

  // APK B
  const apkB = validateApk(b.artifacts.apk.path, b.config, { strict: true });
  assert.equal(apkB.valid, true, apkB.errors.join('; '));
  assert.equal(apkB.metadata.packageName, b.config.package_id);
  assert.equal(apkB.metadata.versionName, b.config.version_name);
  assert.equal(Number(apkB.metadata.versionCode), b.config.version_code);
  assert.equal(apkB.metadata.applicationLabel, b.config.app_name);

  // No configuration leakage between packages
  const bundledA = readBundledConfig(a.artifacts.apk.path);
  const bundledB = readBundledConfig(b.artifacts.apk.path);
  assert.equal(bundledA.packageId, a.config.package_id);
  assert.equal(bundledA.appName, a.config.app_name);
  assert.equal(bundledA.websiteUrl, a.config.website_url);
  assert.equal(bundledB.packageId, b.config.package_id);
  assert.equal(bundledB.appName, b.config.app_name);
  assert.equal(bundledB.websiteUrl, b.config.website_url);
  assert.notEqual(bundledA.packageId, bundledB.packageId);
  assert.notEqual(bundledA.websiteUrl, bundledB.websiteUrl);

  // Distinct output directories and cleaned workspaces
  assert.notEqual(a.outputDir, b.outputDir);
  assert.equal(a.workspaceRetained, false);
  assert.equal(b.workspaceRetained, false);
  assert.equal(fs.existsSync(a.workspaceDir), false, 'Build A workspace was not cleaned');
  assert.equal(fs.existsSync(b.workspaceDir), false, 'Build B workspace was not cleaned');

  // Validation summaries contain no failures
  const failedChecks = [...a.validation.checks, ...b.validation.checks].filter(c => !c.passed);
  assert.deepEqual(failedChecks, []);
});
