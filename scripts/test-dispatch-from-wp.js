const ftp = require('basic-ftp');
const path = require('path');
const https = require('https');
const fs = require('fs');

async function triggerBuildFromWordPress() {
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

    // Runner PHP script that triggers dispatch_build through the WordPress plugin
    const runnerCode = `<?php
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

header('Content-Type: application/json');

$settings = new \\Bizgrowth360\\AppBuilder\\Settings();
$github = new \\Bizgrowth360\\AppBuilder\\GitHubClient($settings);

$inputs = [
    'app_name' => 'TownConnect Mobile',
    'package_id' => 'com.townconnectai.mobile',
    'website_url' => 'https://wordpress.townconnectai.com',
    'version_name' => '1.0.2',
    'version_code' => 2,
    'build_type' => 'both',
    'build_target' => 'both'
];

$dispatch_result = $github->dispatch_build($inputs);

echo json_encode([
    'timestamp' => date('c'),
    'dispatch' => $dispatch_result
], JSON_PRETTY_PRINT);
`;

    const localRunnerPath = path.resolve(__dirname, 'temp-dispatch.php');
    fs.writeFileSync(localRunnerPath, runnerCode);

    console.log('⚡ Uploading build dispatch runner to WordPress...');
    await client.uploadFrom(localRunnerPath, 'bg360-test-dispatch.php');

    console.log('🌐 Triggering build from live WordPress site...');
    const result = await new Promise((resolve, reject) => {
        https.get('https://wordpress.townconnectai.com/bg360-test-dispatch.php', (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(data));
        }).on('error', reject);
    });

    console.log('📊 Dispatch Result from WordPress:\n', result);

    console.log('🧹 Cleaning up remote runner...');
    await client.remove('bg360-test-dispatch.php');
    fs.unlinkSync(localRunnerPath);
    console.log('✅ Remote server cleaned.');

    client.close();
}

triggerBuildFromWordPress().catch(console.error);
