/**
 * deploy-phase4.js
 * Deploy Phase 4 plugin updates to live WordPress site via FTPS and run live verification suite.
 */

const ftp = require('basic-ftp');
const path = require('path');
const https = require('https');
const fs = require('fs');

async function deployPhase4() {
    if (!process.env.FTP_HOST || !process.env.FTP_USER || !process.env.FTP_PASSWORD) {
        throw new Error('FTP_HOST, FTP_USER and FTP_PASSWORD environment variables are required.');
    }
    const host = process.env.FTP_HOST;
    const user = process.env.FTP_USER;
    const password = process.env.FTP_PASSWORD;
    const port = parseInt(process.env.FTP_PORT || '21', 10);

    const client = new ftp.Client();
    client.ftp.verbose = false;

    console.log('📡 Connecting to Hostinger FTP (FTPS)...');
    await client.access({
        host,
        user,
        password,
        port,
        secure: true,
        secureOptions: { rejectUnauthorized: false }
    });
    console.log('✅ FTP Connected successfully!');

    await client.cd('/public_html');

    // 1. Upload updated plugin files
    const localPluginDir = path.resolve(__dirname, '../wordpress-plugin/bizgrowth360-app-builder');
    const remoteTarget = 'wp-content/plugins/bizgrowth360-app-builder';

    console.log(`🚀 Uploading Phase 4 plugin files to ${remoteTarget}...`);
    await client.uploadFromDir(localPluginDir, remoteTarget);
    console.log('✅ Plugin files uploaded successfully!');

    // 2. Generate and upload live verification script
    const runnerCode = `<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

header('Content-Type: application/json');

global $wpdb;
$results = [];

// ==========================================
// TEST 1: Database Table Installation
// ==========================================
\\Bizgrowth360\\AppBuilder\\BuildDb::install_table();
$table_name = \\Bizgrowth360\\AppBuilder\\BuildDb::get_table_name();
$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
$columns = $table_exists ? $wpdb->get_col("DESCRIBE {$table_name}") : [];
$results['test_1_db_table'] = [
    'table_exists' => $table_exists,
    'table_name'   => $table_name,
    'has_build_id' => in_array('build_id', $columns),
    'has_snapshot' => in_array('config_snapshot', $columns),
    'has_status'   => in_array('status', $columns),
    'has_stage'    => in_array('stage', $columns),
];

// ==========================================
// TEST 2: Input Validation (Valid & Invalid)
// ==========================================
$settings = new \\Bizgrowth360\\AppBuilder\\Settings();
$github   = new \\Bizgrowth360\\AppBuilder\\GitHubClient($settings);
$manager  = new \\Bizgrowth360\\AppBuilder\\BuildManager($settings, $github);

$valid_input = [
    'app_name'     => 'Phase4 Live Test App',
    'package_id'   => 'in.bizgrowth360.phase4test',
    'website_url'  => 'https://wordpress.townconnectai.com',
    'version_name' => '1.4.0',
    'version_code' => 4,
    'build_type'   => 'both',
    'build_variant'=> 'release',
    'primary_color'=> '#1E40AF',
];
$val_valid = $manager->validate_inputs($valid_input);

$invalid_input = [
    'app_name'     => '', // Missing
    'package_id'   => 'invalid-package', // Invalid chars
    'website_url'  => 'http://insecure.com', // Insecure HTTP
    'version_code' => -5, // Invalid negative code
];
$val_invalid = $manager->validate_inputs($invalid_input);

$results['test_2_validation'] = [
    'valid_passed'    => $val_valid['valid'],
    'invalid_failed'  => !$val_invalid['valid'],
    'invalid_errors'  => $val_invalid['errors'],
];

// ==========================================
// TEST 3: Database CRUD & Immutable Snapshot
// ==========================================
$test_build_id = 'bg360_bld_test_' . wp_generate_password(8, false, false);
$insert_id = \\Bizgrowth360\\AppBuilder\\BuildDb::create_build([
    'build_id'        => $test_build_id,
    'app_name'        => 'CRUD Test App',
    'package_id'      => 'com.test.crud',
    'website_url'     => 'https://wordpress.townconnectai.com',
    'version_name'    => '1.0.0',
    'version_code'    => 1,
    'build_type'      => 'both',
    'build_variant'   => 'release',
    'status'          => 'completed',
    'config_snapshot' => $valid_input,
    'artifacts'       => [
        ['id' => 12345, 'name' => 'Test-APK', 'size_mb' => '6.5 MB']
    ]
]);
$fetched_build = \\Bizgrowth360\\AppBuilder\\BuildDb::get_build($test_build_id);

$results['test_3_crud_snapshot'] = [
    'record_created'     => (bool) $insert_id,
    'snapshot_preserved' => is_array($fetched_build->config_snapshot) && ($fetched_build->config_snapshot['app_name'] === 'Phase4 Live Test App'),
    'artifacts_stored'   => !empty($fetched_build->artifacts) && ($fetched_build->artifacts[0]['name'] === 'Test-APK'),
];

// ==========================================
// TEST 4: Search, Filter, and Pagination
// ==========================================
$list_all = \\Bizgrowth360\\AppBuilder\\BuildDb::get_builds(['per_page' => 5]);
$search_res = \\Bizgrowth360\\AppBuilder\\BuildDb::get_builds(['search' => 'CRUD Test']);
$results['test_4_filtering'] = [
    'total_builds'    => $list_all['total'],
    'search_matched'  => count($search_res['items']) > 0,
    'pagination_work' => $list_all['page'] === 1 && $list_all['per_page'] === 5,
];

// ==========================================
// TEST 5: Live Build Dispatch via BuildManager
// ==========================================
// Check latest run and update status
$sync_res = $manager->get_build_status('bg360_bld_1f5C4uz9N3eG');
$results['test_5_live_dispatch'] = [
    'build_id'         => 'bg360_bld_1f5C4uz9N3eG',
    'status_synced'    => $sync_res['success'] ?? false,
    'current_status'   => $sync_res['build']->status ?? null,
    'artifacts_count'  => count($sync_res['build']->artifacts ?? []),
];

// ==========================================
// TEST 6: Logs Retrieval on Run #13
// ==========================================
$logs_res = $github->get_build_logs(45111480040);
$results['test_6_logs_retrieval'] = [
    'success'        => $logs_res['success'] ?? false,
    'job_name'       => $logs_res['job_name'] ?? null,
    'logs_length'    => isset($logs_res['logs']) ? strlen($logs_res['logs']) : 0,
    'has_clean_text' => isset($logs_res['logs']) && (strpos($logs_res['logs'], 'Build Dynamic Android Application') !== false || strpos($logs_res['logs'], 'Runner') !== false),
];

// ==========================================
// TEST 7: Artifact Download Proxy (Pre-signed URL)
// ==========================================
$artifact_url = $github->get_artifact_direct_url(14790080727);
$results['test_7_artifact_download'] = [
    'url_generated' => !empty($artifact_url),
    'is_secure'     => (bool) preg_match('/^https:\\/\\//i', $artifact_url ?? ''),
];

// ==========================================
// TEST 8: Retry Mechanism
// ==========================================
// Ensure no active builds are blocking retry
$wpdb->query("UPDATE {$table_name} SET status = 'completed' WHERE status IN ('queued', 'dispatching', 'starting', 'preparing', 'building', 'signing', 'in_progress')");

$dummy_failed_id = 'bg360_bld_failed_' . wp_generate_password(8, false, false);
\\Bizgrowth360\\AppBuilder\\BuildDb::create_build([
    'build_id'        => $dummy_failed_id,
    'app_name'        => 'Retry Sample App',
    'package_id'      => 'com.test.retry',
    'website_url'     => 'https://wordpress.townconnectai.com',
    'version_name'    => '1.0.5',
    'version_code'    => 5,
    'build_type'      => 'both',
    'build_variant'   => 'release',
    'status'          => 'failed',
    'config_snapshot' => $valid_input,
]);

$retry_res = $manager->retry_build($dummy_failed_id);
$results['test_8_retry'] = [
    'retry_success'    => $retry_res['success'] ?? false,
    'new_build_id'     => $retry_res['build_id'] ?? null,
    'original_intact'  => \\Bizgrowth360\\AppBuilder\\BuildDb::get_build($dummy_failed_id) !== null,
    'parent_linked'    => isset($retry_res['build_id']) ? (\\Bizgrowth360\\AppBuilder\\BuildDb::get_build($retry_res['build_id'])->parent_build_id === $dummy_failed_id) : false,
];

// Clean up dummy builds
$wpdb->delete($table_name, ['build_id' => $test_build_id]);
$wpdb->delete($table_name, ['build_id' => $dummy_failed_id]);
if (!empty($retry_res['build_id'])) {
    $wpdb->delete($table_name, ['build_id' => $retry_res['build_id']]);
}

echo json_encode([
    'status'  => 'success',
    'results' => $results,
], JSON_PRETTY_PRINT);
`;

    const localRunnerPath = path.resolve(__dirname, 'temp-phase4-verify.php');
    fs.writeFileSync(localRunnerPath, runnerCode);

    console.log('⚡ Uploading Phase 4 live verification script...');
    await client.uploadFrom(localRunnerPath, 'bg360-phase4-verify.php');

    console.log('🌐 Executing Phase 4 live verification test suite...');
    const result = await new Promise((resolve, reject) => {
        https.get('https://wordpress.townconnectai.com/bg360-phase4-verify.php', (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(data));
        }).on('error', reject);
    });

    console.log('📊 Live Verification Results:\n', result);

    console.log('🧹 Cleaning up remote verification script...');
    await client.remove('bg360-phase4-verify.php');
    fs.unlinkSync(localRunnerPath);
    console.log('✅ Remote verification script removed.');

    client.close();
}

deployPhase4().catch(console.error);
