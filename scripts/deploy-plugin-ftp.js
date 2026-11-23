const ftp = require('basic-ftp');
const path = require('path');
const https = require('https');
const fs = require('fs');

async function deploy() {
    const client = new ftp.Client();
    client.ftp.verbose = false;

    console.log('📡 Connecting to FTP server 217.21.90.172...');
    try {
        await client.access({
            host: process.env.FTP_HOST || '217.21.90.172',
            user: process.env.FTP_USER || 'u686056608.wordpress.townconnectai.com',
            password: process.env.FTP_PASSWORD || 'Wordpress@2026,',
            port: parseInt(process.env.FTP_PORT || '21', 10),
            secure: false
        });
        console.log('✅ FTP Connected successfully!');

        await client.cd('/public_html');
        console.log('📂 Working directory:', await client.pwd());

        const localPluginDir = path.resolve(__dirname, '../wordpress-plugin/bizgrowth360-app-builder');
        const remoteTarget = 'wp-content/plugins/bizgrowth360-app-builder';

        console.log(`🚀 Uploading plugin files from: ${localPluginDir} to: ${remoteTarget}`);
        await client.uploadFromDir(localPluginDir, remoteTarget);
        console.log('✅ Plugin files uploaded successfully!');

        // Verify remote files
        await client.cd('/public_html/' + remoteTarget);
        const pluginFiles = await client.list();
        console.log('📋 Files in remote plugin folder:', pluginFiles.map(f => f.name));

        // Create temporary activator in WordPress root
        await client.cd('/public_html');
        const activatorCode = `<?php
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin_file = 'bizgrowth360-app-builder/bizgrowth360-app-builder.php';
$result = activate_plugin($plugin_file);

header('Content-Type: application/json');
echo json_encode([
    'active' => is_plugin_active($plugin_file),
    'is_wp_error' => is_wp_error($result) ? $result->get_error_message() : null,
    'site_url' => get_bloginfo('url'),
    'site_name' => get_bloginfo('name'),
    'options' => get_option('bizgrowth360_app_builder_options')
]);
`;
        const localActivatorPath = path.resolve(__dirname, 'temp-activator.php');
        fs.writeFileSync(localActivatorPath, activatorCode);

        console.log('⚡ Uploading temporary activation script to /public_html/bg360-activate-temp.php...');
        await client.uploadFrom(localActivatorPath, 'bg360-activate-temp.php');

        console.log('🌐 Invoking activation endpoint via HTTPS...');
        const activationResponse = await new Promise((resolve, reject) => {
            https.get('https://wordpress.townconnectai.com/bg360-activate-temp.php', (res) => {
                let data = '';
                res.on('data', chunk => data += chunk);
                res.on('end', () => resolve(data));
            }).on('error', reject);
        });

        console.log('🎉 Activation Response:\n', activationResponse);

        // Remove temporary activator on remote
        console.log('🧹 Cleaning up temporary activator on server...');
        await client.remove('bg360-activate-temp.php');
        fs.unlinkSync(localActivatorPath);
        console.log('✅ Remote server cleaned. Activation complete!');

    } catch (err) {
        console.error('❌ Deployment error:', err);
    } finally {
        client.close();
    }
}

deploy();
