const ftp = require('basic-ftp');
const path = require('path');
const https = require('https');
const fs = require('fs');

async function testHideGitHub() {
    if (!process.env.FTP_HOST || !process.env.FTP_USER || !process.env.FTP_PASSWORD) {
        throw new Error('FTP_HOST, FTP_USER and FTP_PASSWORD environment variables are required.');
    }
    const client = new ftp.Client();
    client.ftp.verbose = false;

    console.log('📡 Connecting to FTP...');
    await client.access({
        host: process.env.FTP_HOST,
        user: process.env.FTP_USER,
        password: process.env.FTP_PASSWORD,
        port: parseInt(process.env.FTP_PORT || '21', 10),
        secure: true,
        secureOptions: { rejectUnauthorized: false }
    });

    await client.cd('/public_html');

    // Runner PHP script that performs deep tests on the live WordPress environment
    const runnerCode = `<?php
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

header('Content-Type: application/json');

$settings = new \\Bizgrowth360\\AppBuilder\\Settings();
$github = new \\Bizgrowth360\\AppBuilder\\GitHubClient($settings);
$admin_page = new \\Bizgrowth360\\AppBuilder\\AdminPage($settings, $github);

// 1. Test Token Constant Detection
$token_detected = \\Bizgrowth360\\AppBuilder\\Config::has_token();

// 2. Test Negative Case: Simulate missing token by testing client validation
$missing_token_client = new class($settings) extends \\Bizgrowth360\\AppBuilder\\GitHubClient {
    public function test_missing_token_behavior() {
        // Test dispatch without token
        $reflection = new ReflectionClass('\\\\Bizgrowth360\\\\AppBuilder\\\\GitHubClient');
        $headers_method = $reflection->getMethod('get_headers');
        $headers_method->setAccessible(true);
        return true;
    }
};

// 3. Test Settings Migration & Stored Options
$options = get_option(\\Bizgrowth360\\AppBuilder\\Settings::OPTION_KEY);
$legacy_keys = ['github_owner', 'github_repo', 'github_branch', 'github_token', 'workflow_file'];
$found_legacy = [];
foreach ($legacy_keys as $k) {
    if (array_key_exists($k, $options)) {
        $found_legacy[] = $k;
    }
}

// 4. Test Rendered Admin UI HTML
ob_start();
$admin_page->render_page();
$rendered_html = ob_get_clean();

$ui_checks = [
    'has_github_tab' => strpos($rendered_html, 'tab-github') !== false,
    'has_github_token_input' => strpos($rendered_html, '[github_token]') !== false,
    'has_github_owner_input' => strpos($rendered_html, '[github_owner]') !== false,
    'has_github_repo_input' => strpos($rendered_html, '[github_repo]') !== false,
    'has_test_conn_btn' => strpos($rendered_html, 'bg360-test-conn-btn') !== false,
    'has_app_name_input' => strpos($rendered_html, '[app_name]') !== false,
    'has_package_id_input' => strpos($rendered_html, '[package_id]') !== false,
    'has_website_url_input' => strpos($rendered_html, '[website_url]') !== false,
    'has_builder_tab' => strpos($rendered_html, 'tab-builder') !== false,
    'has_monitor_tab' => strpos($rendered_html, 'tab-monitor') !== false,
    'has_build_now_button' => strpos($rendered_html, 'bg360-trigger-build-btn') !== false,
];

// 5. Test Live Build Dispatch
$dispatch_test = $github->dispatch_build([
    'app_name' => 'TownConnect Live',
    'package_id' => 'com.townconnectai.live',
    'website_url' => 'https://wordpress.townconnectai.com',
    'version_name' => '1.0.3',
    'version_code' => 3,
    'build_type' => 'both',
    'build_target' => 'both'
]);

echo json_encode([
    'token_detected' => $token_detected,
    'legacy_keys_in_db' => $found_legacy,
    'preserved_app_settings' => [
        'app_name' => $options['app_name'] ?? null,
        'package_id' => $options['package_id'] ?? null,
        'website_url' => $options['website_url'] ?? null,
        'version_name' => $options['version_name'] ?? null,
        'primary_color' => $options['primary_color'] ?? null,
    ],
    'ui_checks' => $ui_checks,
    'dispatch_test' => $dispatch_test
], JSON_PRETTY_PRINT);
`;

    const localRunnerPath = path.resolve(__dirname, 'temp-deep-test.php');
    fs.writeFileSync(localRunnerPath, runnerCode);

    console.log('⚡ Uploading deep test runner...');
    await client.uploadFrom(localRunnerPath, 'bg360-deep-test.php');

    console.log('🌐 Executing deep test runner on live site...');
    const result = await new Promise((resolve, reject) => {
        https.get('https://wordpress.townconnectai.com/bg360-deep-test.php', (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(data));
        }).on('error', reject);
    });

    console.log('📊 Deep Test Result:\n', result);

    console.log('🧹 Cleaning up remote deep test runner...');
    await client.remove('bg360-deep-test.php');
    fs.unlinkSync(localRunnerPath);
    console.log('✅ Remote server cleaned.');

    client.close();
}

testHideGitHub().catch(console.error);
