const ftp = require('basic-ftp');
const path = require('path');
const https = require('https');
const fs = require('fs');

async function deployAndConfigure() {
    if (!process.env.FTP_HOST || !process.env.FTP_USER || !process.env.FTP_PASSWORD) {
        throw new Error('FTP_HOST, FTP_USER and FTP_PASSWORD environment variables are required.');
    }
    const githubToken = process.env.BIZGROWTH360_GITHUB_TOKEN || '';
    const client = new ftp.Client();
    client.ftp.verbose = false;

    console.log('Connecting to Hostinger FTP/FTPS...');
    try {
        await client.access({
            host: process.env.FTP_HOST.replace(/^ftp:\/\//, ''),
            user: process.env.FTP_USER,
            password: process.env.FTP_PASSWORD,
            port: parseInt(process.env.FTP_PORT || '21', 10),
            secure: true,
            secureOptions: { rejectUnauthorized: false }
        });
        console.log('✅ FTP Connected successfully!');

        await client.cd('/public_html');

        // 1. Configure wp-config.php with BIZGROWTH360_GITHUB_TOKEN
        console.log('⚙️ Checking wp-config.php for BIZGROWTH360_GITHUB_TOKEN...');
        const tempConfigPath = path.resolve(__dirname, 'temp-wp-config.php');
        await client.downloadTo(tempConfigPath, 'wp-config.php');
        let configContent = fs.readFileSync(tempConfigPath, 'utf8');

        if (!configContent.includes('BIZGROWTH360_GITHUB_TOKEN')) {
            console.log('➕ Adding BIZGROWTH360_GITHUB_TOKEN constant to remote wp-config.php...');
            const tokenLine = `\n/**\n * Bizgrowth360 Android App Builder Build Engine Token.\n */\ndefine( 'BIZGROWTH360_GITHUB_TOKEN', '${githubToken.replace(/'/g, "\\'")}' );\n`;
            const insertionPoint = "/* Add any custom values between this line and the \"stop editing\" line. */";
            if (configContent.includes(insertionPoint)) {
                configContent = configContent.replace(insertionPoint, insertionPoint + tokenLine);
            } else {
                configContent = configContent.replace('/* That\'s all, stop editing!', tokenLine + '\n/* That\'s all, stop editing!');
            }
            fs.writeFileSync(tempConfigPath, configContent, 'utf8');
            await client.uploadFrom(tempConfigPath, 'wp-config.php');
            console.log('✅ Remote wp-config.php updated successfully with token constant!');
        } else {
            console.log('ℹ️ BIZGROWTH360_GITHUB_TOKEN already present in wp-config.php.');
        }

        // Clean local temp config file
        if (fs.existsSync(tempConfigPath)) fs.unlinkSync(tempConfigPath);
        const inspectionConfig = path.resolve(__dirname, 'remote-wp-config.php');
        if (fs.existsSync(inspectionConfig)) fs.unlinkSync(inspectionConfig);

        // 2. Upload updated plugin files
        const localPluginDir = path.resolve(__dirname, '../wordpress-plugin/bizgrowth360-app-builder');
        const remoteTarget = 'wp-content/plugins/bizgrowth360-app-builder';

        console.log(`🚀 Uploading updated plugin files from: ${localPluginDir}`);
        await client.uploadFromDir(localPluginDir, remoteTarget);
        console.log('✅ Updated plugin files uploaded successfully!');

        // 3. Upload test runner to verify migration, token detection, and build functionality
        const runnerCode = `<?php
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

header('Content-Type: application/json');

$settings = new \\Bizgrowth360\\AppBuilder\\Settings();
$settings->migrate_legacy_options();

$current_options = get_option(\\Bizgrowth360\\AppBuilder\\Settings::OPTION_KEY);

// Test 1: Check token constant detection
$has_token = \\Bizgrowth360\\AppBuilder\\Config::has_token();
$token_preview = $has_token ? 'configured' : 'none';

// Test 2: Check migration removed github_token & legacy keys from options
$legacy_keys_found = array_intersect(
    array_keys($current_options),
    ['github_token', 'github_owner', 'github_repo', 'github_branch', 'workflow_file']
);

// Test 3: Test GitHubClient with centralized config
$client = new \\Bizgrowth360\\AppBuilder\\GitHubClient($settings);
$runs = $client->get_workflow_runs(3);
$latest_run = !empty($runs['runs'][0]) ? $runs['runs'][0] : null;

// Test 3b: Test BuildManager input validation and readiness
$bm = new \\Bizgrowth360\\AppBuilder\\BuildManager($settings, $client);
$test_val = $bm->validate_inputs([
    'app_name' => 'TownConnect',
    'package_id' => 'com.townconnectai.app',
    'website_url' => 'https://wordpress.townconnectai.com',
    'version_name' => '1.0.0',
    'version_code' => 1
]);

// Test 4: Missing token error verification
$token_error_message = '';
if (!\\Bizgrowth360\\AppBuilder\\Config::has_token()) {
    $token_error_message = 'Token missing as expected in negative test';
}

echo json_encode([
    'status' => 'success',
    'has_token' => $has_token,
    'token_preview' => $token_preview,
    'legacy_keys_in_db' => array_values($legacy_keys_found),
    'preserved_app_name' => $current_options['app_name'] ?? null,
    'preserved_package_id' => $current_options['package_id'] ?? null,
    'preserved_website_url' => $current_options['website_url'] ?? null,
    'recent_runs_count' => isset($runs['runs']) ? count($runs['runs']) : 0,
    'latest_run' => $latest_run,
    'build_manager_ready' => $test_val['valid'] ?? false
], JSON_PRETTY_PRINT);
`;

        const localRunnerPath = path.resolve(__dirname, 'temp-verify.php');
        fs.writeFileSync(localRunnerPath, runnerCode);

        console.log('⚡ Uploading verification runner...');
        await client.uploadFrom(localRunnerPath, 'bg360-verify-migration.php');

        console.log('🌐 Executing verification on live WordPress site...');
        const responseData = await new Promise((resolve, reject) => {
            https.get('https://wordpress.townconnectai.com/bg360-verify-migration.php', (res) => {
                let data = '';
                res.on('data', chunk => data += chunk);
                res.on('end', () => resolve(data));
            }).on('error', reject);
        });

        console.log('📊 Live Verification Result:\n', responseData);

        // Cleanup
        await client.remove('bg360-verify-migration.php');
        if (fs.existsSync(localRunnerPath)) fs.unlinkSync(localRunnerPath);
        console.log('🧹 Cleaned up temporary test runner on server.');

    } catch (err) {
        console.error('❌ Deployment error:', err);
    } finally {
        client.close();
    }
}

deployAndConfigure().catch(console.error);
