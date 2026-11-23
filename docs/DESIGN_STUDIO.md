# App Design Studio (Phase 5)

The Design Studio is part of the existing **Bizgrowth360 App Builder** plugin. It does not create a second plugin and does not replace the Android build pipeline.

## Architecture

- **Storage**: `bizgrowth360_app_builder_design` WordPress option, server-sanitized.
- **Schema**: versioned `schema_version: 1`; contract in `config/design-config.schema.json`.
- **Editor**: `assets/js/design-studio.js` + `assets/css/design-studio.css`, loaded only on the App Builder admin page.
- **Save API**: `wp_ajax_bizgrowth360_save_design` and `wp_ajax_bizgrowth360_reset_design`, capability + nonce protected.
- **Build transfer**: `BuildManager` snapshots the design with the build, `GitHubClient` sends compact `design_json`, the workflow passes `DESIGN_JSON` to `scripts/build-app.js`.
- **Build validation**: `scripts/design-config.js` sanitizes the payload again at build time.
- **Android shell**: `app-template/public/app.js` + `app.css` render the design; `prepare-app.js` embeds it in `app-config.js` and `design.json`.
- **Native branding**: the design palette overrides Android primary/background/status/nav resources for the compiled app.

## Modules

| Module | What works |
| :--- | :--- |
| App Theme | 12 colors, light/dark/auto mode, editable dark palette, 6 presets |
| App Header | visibility, logo, title, colors, height, sticky, search/notification/cart/profile, up to 4 custom actions |
| Navigation | none/bottom/drawer/top, solid/transparent/floating, up to 8 items, visible state, icons, URLs, app-internal destinations |
| Menu Builder | groups + items, CRUD, enable/disable, ordering, icons, URL and type validation |
| Homepage Builder | 13 section types, add/remove/edit/enable/reorder, section items, safe custom HTML |
| App Components | modern/minimal/rounded/material/compact/premium presets, radius, shadow, spacing, width, icon style, dividers |
| Typography | device-safe font stacks, base/heading/body font, size, scale, weight, line height, letter spacing |
| Layout | page padding, section gap, max width, safe area, bottom nav height |
| Live Preview | interactive phone frame, Home/Menu/Navigation modes, 4 sizes, light/dark toggle, unsaved state |
| Reset & Save | manual save, autosave, dirty indicator, discard, section/full reset with confirmation |

## Android behavior

The native app shell renders the header, bottom/top navigation, drawer and configured homepage sections. Website pages opened from the shell remain in the WebView when they belong to the configured site; external links follow the existing external-link policy.

**Important limitation:** arbitrary remote websites cannot have the native app header/navigation injected into their HTML. Website pages keep the website’s own layout. The configured header, navigation, drawer and homepage are part of the native app shell, and this is stated in the editor.

Product/category/post sections use the items configured in the studio; the engine does not scrape the WordPress website automatically.

Custom HTML is limited to safe formatting tags and attributes. Scripts, styles, iframes, forms, PHP, event handlers and `javascript:` URLs are removed server-side and again at build time.

## Security

- `manage_options` capability checks.
- Nonce verification on save/reset.
- Recursive server-side sanitization and bounded arrays/strings.
- Allowlisted colors, URLs, icons, fonts, menu types and section types.
- Safe URL schemes only (`https`, `http`, `mailto`, `tel`, `sms`, `whatsapp`, internal `app:home|menu|website`).
- No arbitrary PHP/JavaScript execution.
- Design assets are URLs; the build-time icon/splash downloader retains its HTTPS, size, image and SSRF protections.

## Testing

```bash
node --test tests/unit.test.js
php tests/php/design-config-test.php
php -l wordpress-plugin/bizgrowth360-app-builder/includes/class-design-config.php
```

The Phase 5 test build and GitHub Actions artifact verification are recorded in `docs/VERIFICATION.md`.
