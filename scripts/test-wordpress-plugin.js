const ftp = require('basic-ftp');
const path = require('path');
const https = require('https');
const fs = require('fs');

async function testRemotePlugin() {
    const client = new ftp.Client();
    client.ftp.verbose = false;

    console.log('📡 Connecting to FTP...');
    await client.access({
        host: process.env.FTP_HOST || '217.21.90.172',
        user: process.env.FTP_USER || 'u686056608.wordpress.townconnectai.com',
        password: process.env.FTP_PASSWORD || 'Wordpress@2026,',
        port: parseInt(process.env.FTP_PORT || '21', 10),
        secure: false
    });

    await client.cd('/public_html');

    // Runner PHP script that exercises the plugin classes on the live server
    const runnerCode = `<?php
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

header('Content-Type: application/json');

// Check plugin loaded
if (!class_exists('\\\\Bizgrowth360\\\\AppBuilder\\\\Settings')) {
    echo json_encode(['error' => 'Plugin class Settings not loaded']);
    exit;
}

$settings = new \\Bizgrowth360\\AppBuilder\\Settings();
$github = new \\Bizgrowth360\\AppBuilder\\GitHubClient($settings);

// Test 1: GitHub Connection
$test_conn = $github->test_connection();

// Test 2: Fetch recent runs
$runs = $github->get_workflow_runs(3);

// Test 3: Check artifacts on latest run
$artifacts = [];
if (!empty($runs['runs'][0]['id'])) {
    $latest_run_id = $runs['runs'][0]['id'];
    $artifacts_res = $github->get_run_artifacts($latest_run_id);
    if (!empty($artifacts_res['artifacts'])) {
        $artifacts = $artifacts_res['artifacts'];
        // Also test direct signed download url generation
        $first_art_id = $artifacts[0]['id'];
        $direct_download = $github->get_artifact_direct_url($first_art_id);
        $artifacts[0]['direct_signed_url_sample'] = $direct_download ? substr($direct_download, 0, 80) . '...' : null;
    }
}

echo json_encode([
    'plugin_active' => is_plugin_active('bizgrowth360-app-builder/bizgrowth360-app-builder.php'),
    'test_connection' => $test_conn,
    'recent_runs_count' => isset($runs['runs']) ? count($runs['runs']) : 0,
    'latest_run' => isset($runs['runs'][0]) ? $runs['runs'][0] : null,
    'artifacts' => $artifacts
], JSON_PRETTY_PRINT);
`;

    const localRunnerPath = path.resolve(__dirname, 'temp-runner.php');
    fs.writeFileSync(localRunnerPath, runnerCode);

    console.log('⚡ Uploading verification runner...');
    await client.uploadFrom(localRunnerPath, 'bg360-test-runner.php');

    console.log('🌐 Executing verification runner on live WordPress site...');
    const result = await new Promise((resolve, reject) => {
        https.get('https://wordpress.townconnectai.com/bg360-test-runner.php', (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(data));
        }).on('error', reject);
    });

    console.log('📊 Verification Result:\n', result);

    console.log('🧹 Cleaning up remote runner...');
    await client.remove('bg360-test-runner.php');
    fs.unlinkSync(localRunnerPath);
    console.log('✅ Remote server cleaned.');

    client.close();
}

testRemotePlugin().catch(console.error);
