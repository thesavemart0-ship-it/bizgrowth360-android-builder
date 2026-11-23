# Hostinger Plugin Deployment Record

Date: 2026-11-23  
Site: `https://wordpress.townconnectai.com`  
FTP root: `/public_html`  
Remote plugin: `/public_html/wp-content/plugins/bizgrowth360-app-builder`  
Protocol: FTPS (explicit TLS, port 21)

No credentials are stored in this document or in committed scripts.

## Deployment result

- Plugin files uploaded: 8
- Every file downloaded back from Hostinger and verified by SHA-256: **8/8 PASS**
- Pre-deployment backup: `dist/hostinger-backup-*/bizgrowth360-app-builder/` (local, gitignored)
- Remote plugin folder preserved in place; no site-wide files were overwritten.
- WordPress front page and `wp-login.php` returned HTTP 200 after deployment.

Deployed files and SHA-256:

```text
448815cfe6d4ec74d06d912de6b3c004cbe86cf5ba86a4776689e14a77a05905  bizgrowth360-app-builder.php
e14b3350bb5149109a0016b63bcd2e51be0520a5f8b5467b18d20eaead332c71  assets/css/admin.css
7da4756af76b487e42f44abf54529e07df2de311dd3eaa4729b39fe488c6182e  assets/js/admin.js
5db3ca754ec3bffa72b457a3f11673e4c564eb9eb2c75acf8d373d10086dac8d  includes/class-admin-page.php
2c7270a1e1798202d7a65906693a073c3787bea0d5f1de9f8cd4b697dae8e503  includes/class-ajax-handler.php
b1fc24873261376d995b5368024941b47642bd5dba1975a154f811da06409e6c  includes/class-config.php
b96159161399b11dcee10c287d46a47855f152640fe3c09f101583d6810a5c6e  includes/class-github-client.php
6b77b62b19dc59231d3c80ef4122a2a3d6b8ea8efacc99f0828eec768d9dd5c3  includes/class-settings.php
```

## Security cleanup performed

Removed two unauthenticated helper scripts from the web root after backing them up locally:

- `/public_html/check_admin_user.php` — unauthenticated administrator user/email enumeration helper
- `/public_html/activate_new.php` — unauthenticated plugin activation helper

Both now return HTTP 404. The rest of the WordPress installation was not modified.

## Required configuration

The plugin reads its GitHub token from `wp-config.php`:

```php
define( 'BIZGROWTH360_GITHUB_TOKEN', 'github_pat_...' );
```

The constant is already present and the token has repository access (read-only verification returned HTTP 200 with admin/push permissions). **Rotate this token** as part of normal secret hygiene and update the constant after rotation. Never place it in plugin source or database options.

The plugin also requires the WordPress admin to activate it if it is not already active:

**WP Admin → Plugins → Bizgrowth360 Android App Builder → Activate**

Activation could not be verified without WordPress admin credentials. If the plugin was already active, the new files are live immediately.

## Workflow compatibility

The plugin sends the Phase 3 contract:

- `build_type`: `apk | aab | both`
- `build_variant`: `release | debug`
- orientation, colors, icon/splash URLs, external-link and status-bar options

A single HTTP 422 fallback also supports the older Phase 2 workflow inputs during migration. Once `main` contains the Phase 3 workflow, the fallback is not used.

## Critical follow-up

The following credentials were exposed in chat and one (the FTP password) exists in public Git history:

- Rotate the Hostinger FTP password immediately.
- Rotate the MySQL password if it is the same/shared.
- Do not commit real credentials. Use environment variables or hosting-panel secret storage.

The old deployment scripts were updated to require `FTP_HOST`, `FTP_USER` and `FTP_PASSWORD` from the environment and to use FTPS. Historical commits still contain the old password, so rotation is mandatory.
