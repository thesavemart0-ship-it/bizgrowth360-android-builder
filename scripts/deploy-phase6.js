/**
 * deploy-phase6.js
 * Deploy Phase 6 Native Features Engine updates to live WordPress site via FTPS
 * and run live verification test suite.
 */

const ftp = require('basic-ftp');
const path = require('path');
const https = require('https');
const fs = require('fs');

const FTP_CONFIG = {
    host: process.env.FTP_HOST || '217.21.90.172',
    user: process.env.FTP_USER || 'u686056608.wordpress.townconnectai.com',
    password: process.env.FTP_PASSWORD,
    port: parseInt(process.env.FTP_PORT || '21', 10),
    secure: true,
    secureOptions: { rejectUnauthorized: false }
};

const SITE_URL = 'https://wordpress.townconnectai.com';

function httpsGet(url) {
    return new Promise((resolve, reject) => {
        https.get(url, { rejectUnauthorized: false }, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                resolve({ statusCode: res.statusCode, headers: res.headers, body: data });
            });
        }).on('error', reject);
    });
}

function httpsPost(url, payload) {
    return new Promise((resolve, reject) => {
        const postData = JSON.stringify(payload);
        const urlObj = new URL(url);
        const options = {
            hostname: urlObj.hostname,
            port: 443,
            path: urlObj.pathname + urlObj.search,
            method: 'POST',
            rejectUnauthorized: false,
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData)
            }
        };

        const req = https.request(options, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                resolve({ statusCode: res.statusCode, headers: res.headers, body: data });
            });
        });

        req.on('error', reject);
        req.write(postData);
        req.end();
    });
}

async function deployPhase6() {
    if (!FTP_CONFIG.password) {
        throw new Error('FTP_PASSWORD environment variable is required.');
    }
    const client = new ftp.Client();
    client.ftp.verbose = false;

    console.log('📡 Connecting to Hostinger FTPS (217.21.90.172:21)...');
    await client.access(FTP_CONFIG);
    console.log('✅ FTP Connected successfully!');

    await client.cd('/public_html');

    // 1. Upload updated plugin files
    const localPluginDir = path.resolve(__dirname, '../wordpress-plugin/bizgrowth360-app-builder');
    const remoteTarget = 'wp-content/plugins/bizgrowth360-app-builder';

    console.log(`🚀 Uploading Phase 6 plugin files to ${remoteTarget}...`);
    await client.uploadFromDir(localPluginDir, remoteTarget);
    console.log('✅ Plugin files uploaded successfully!');

    // 2. Upload verification runner
    const runnerCode = `<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

header('Content-Type: application/json');

global $wpdb;
$results = [];

// ==========================================
// TEST 1: Device Tokens Database Table
// ==========================================
\\Bizgrowth360\\AppBuilder\\DeviceTokensDb::install_table();
$table_name   = \\Bizgrowth360\\AppBuilder\\DeviceTokensDb::get_table_name();
$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
$columns      = $table_exists ? $wpdb->get_col("DESCRIBE {$table_name}") : [];

$results['test_1_tokens_table'] = [
    'table_exists' => $table_exists,
    'table_name'   => $table_name,
    'has_device_id'=> in_array('device_id', $columns),
    'has_token'    => in_array('token', $columns),
    'has_platform' => in_array('platform', $columns),
    'has_package'  => in_array('package_id', $columns),
    'has_version'  => in_array('app_version', $columns),
];

// ==========================================
// TEST 2: Device Token Registration & Query
// ==========================================
$test_device_id = 'test_device_' . wp_generate_password(8, false);
$reg_ok = \\Bizgrowth360\\AppBuilder\\DeviceTokensDb::register_token([
    'device_id'   => $test_device_id,
    'token'       => 'fcm_fake_token_for_verification_test',
    'platform'    => 'android',
    'app_version' => '1.0.0',
    'package_id'  => 'in.bizgrowth360.demoapp',
]);

$token_row = \\Bizgrowth360\\AppBuilder\\DeviceTokensDb::get_token_by_device($test_device_id);
$total_tokens = \\Bizgrowth360\\AppBuilder\\DeviceTokensDb::count_tokens();

// Clean up test token
\\Bizgrowth360\\AppBuilder\\DeviceTokensDb::unregister_token($test_device_id);

$results['test_2_token_registration'] = [
    'register_success' => $reg_ok,
    'retrieved_ok'     => !empty($token_row) && $token_row->device_id === $test_device_id,
    'count_positive'   => $total_tokens >= 1,
];

// ==========================================
// TEST 3: Native Features Configuration & Defaults
// ==========================================
$defaults = \\Bizgrowth360\\AppBuilder\\NativeFeatures::get_defaults();
$features = \\Bizgrowth360\\AppBuilder\\NativeFeatures::get_features();
$statuses = \\Bizgrowth360\\AppBuilder\\NativeFeatures::get_features_with_status();
$summary  = \\Bizgrowth360\\AppBuilder\\NativeFeatures::get_status_summary();

$results['test_3_native_features_config'] = [
    'has_push'        => isset($features['push_notifications']),
    'has_deep_link'   => isset($features['deep_linking']),
    'has_share'       => isset($features['native_share']),
    'has_camera'      => isset($features['camera_upload']),
    'has_offline'     => isset($features['offline_engine']),
    'has_permissions' => isset($features['permission_manager']),
    'has_updates'     => isset($features['app_updates']),
    'statuses_count'  => count($statuses),
    'summary'         => $summary,
];

// ==========================================
// TEST 4: Digital Asset Links Generation
// ==========================================
$test_sha = '14:6D:E9:D6:0F:4B:B7:21:3E:9A:86:14:04:95:C2:59:7A:DE:52:99:38:62:3A:42:3B:56:59:84:72:42:12:44';
$assetlinks = \\Bizgrowth360\\AppBuilder\\NativeFeatures::generate_assetlinks_json('', $test_sha);
$parsed_al = is_array($assetlinks) ? $assetlinks : json_decode($assetlinks, true);

$results['test_4_assetlinks'] = [
    'is_valid_json'    => is_array($parsed_al),
    'has_package_name' => !empty($parsed_al[0]['target']['package_name']),
    'has_sha256'       => in_array($test_sha, $parsed_al[0]['target']['sha256_cert_fingerprints'] ?? []),
    'relation_correct' => ($parsed_al[0]['relation'][0] ?? '') === 'delegate_permission/common.handle_all_urls',
];

// Flush rewrite rules for .well-known rewrite
flush_rewrite_rules(false);

echo json_encode([
    'success' => true,
    'results' => $results,
    'timestamp' => current_time('mysql'),
], JSON_PRETTY_PRINT);
`;

    const runnerFile = path.resolve(__dirname, '../_phase6_test_runner.php');
    fs.writeFileSync(runnerFile, runnerCode);

    console.log('📤 Uploading live verification test runner...');
    await client.uploadFrom(runnerFile, '_phase6_test_runner.php');
    fs.unlinkSync(runnerFile);
    console.log('✅ Test runner uploaded!');

    // 3. Execute verification suite
    console.log('🧪 Executing server-side Phase 6 verification tests...');
    const runnerRes = await httpsGet(`${SITE_URL}/_phase6_test_runner.php`);
    console.log(`HTTP ${runnerRes.statusCode}`);

    let suiteData = null;
    try {
        suiteData = JSON.parse(runnerRes.body);
    } catch (e) {
        console.error('Could not parse test runner JSON output:', runnerRes.body.slice(0, 500));
    }

    if (suiteData && suiteData.results) {
        console.log('\n📊 === Server-Side Verification Results ===');
        console.log(JSON.stringify(suiteData.results, null, 2));
    }

    // 4. Test live REST API endpoints
    console.log('\n🌐 === Testing Live REST API Endpoints ===');

    // Endpoint 1: GET /wp-json/bizgrowth360/v1/app-update
    console.log('Testing GET /wp-json/bizgrowth360/v1/app-update...');
    const updateRes = await httpsGet(`${SITE_URL}/wp-json/bizgrowth360/v1/app-update?version_code=1`);
    console.log(`Status: ${updateRes.statusCode}`);
    let updateJson = null;
    try {
        updateJson = JSON.parse(updateRes.body);
        console.log('App Update Response:', JSON.stringify(updateJson, null, 2));
    } catch (e) {
        console.log('App Update Body:', updateRes.body.slice(0, 200));
    }

    // Endpoint 2: POST /wp-json/bizgrowth360/v1/register-device
    console.log('\nTesting POST /wp-json/bizgrowth360/v1/register-device...');
    const regPayload = {
        device_id: 'live_test_device_' + Date.now(),
        token: 'fcm_sample_token_xyz_123',
        platform: 'android',
        app_version: '1.0.0',
        package_id: 'in.bizgrowth360.demoapp'
    };
    const regRes = await httpsPost(`${SITE_URL}/wp-json/bizgrowth360/v1/register-device`, regPayload);
    console.log(`Status: ${regRes.statusCode}`);
    try {
        console.log('Device Register Response:', JSON.parse(regRes.body));
    } catch (e) {
        console.log('Device Register Body:', regRes.body.slice(0, 200));
    }

    // Endpoint 3: GET /.well-known/assetlinks.json
    console.log('\nTesting GET /.well-known/assetlinks.json (Android App Links)...');
    const assetlinksRes = await httpsGet(`${SITE_URL}/.well-known/assetlinks.json`);
    console.log(`Status: ${assetlinksRes.statusCode}`);
    console.log(`Content-Type: ${assetlinksRes.headers['content-type']}`);
    try {
        const alJson = JSON.parse(assetlinksRes.body);
        console.log('Digital Asset Links:', JSON.stringify(alJson, null, 2));
    } catch (e) {
        console.log('Assetlinks body:', assetlinksRes.body.slice(0, 200));
    }

    // 5. Cleanup test runner from live server
    console.log('\n🧹 Cleaning up test runner from server...');
    try {
        await client.remove('_phase6_test_runner.php');
        console.log('✅ Test runner removed.');
    } catch (e) {
        console.warn('Could not remove test runner:', e.message);
    }

    client.close();
    console.log('\n🎉 Phase 6 Deployment and Live Verification Complete!');
}

deployPhase6().catch(err => {
    console.error('❌ Phase 6 Deployment failed:', err);
    process.exit(1);
});
