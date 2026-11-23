# App Icon & Splash Asset Handling

GitHub Actions manual inputs cannot upload binary files. The build engine therefore uses three safe mechanisms. All customer assets are untrusted input.

## 1. HTTPS URL inputs

Workflow inputs `icon_url` and `splash_url` accept an HTTPS URL.

The downloader enforces:

- HTTPS only (also for every redirect; maximum 3 redirects)
- No embedded credentials
- 30 second timeout
- Maximum 10 MB
- PNG / JPEG / WebP only (detected from file bytes, not the extension)
- Single still image (animated images rejected)

The file is validated with `sharp`, then re-encoded to a clean PNG. No uploaded file is executed.

## 2. Repository-relative assets

Set `app_icon` and/or `splash_image` in a JSON configuration:

```json
{
  "app_icon": "assets/my-icon.png",
  "splash_image": "assets/my-splash.png"
}
```

Rules:

- The path must stay inside the Capacitor project. `../` traversal and absolute paths are rejected.
- SVG uploads are not accepted (SVG can reference external/local resources).
- Missing or invalid custom assets fail the build; they are never silently ignored.

## 3. Defaults

If `app_icon` is absent/null and no `icon_url` is supplied, the engine generates a branded icon from the app name and primary color.

If `splash_image` is absent/null and no `splash_url` is supplied, the engine generates a branded splash using the app name, primary color and splash background color.

## Validation limits

| Asset | Formats | Minimum | Maximum | Max size | Notes |
| :--- | :--- | :--- | :--- | :--- | :--- |
| Icon | PNG, JPEG, WebP | 192×192 | 4096×4096 | 10 MB | Non-square images are center-cropped for square launchers; adaptive foreground is padded inside the 66% safe zone. |
| Splash | PNG, JPEG, WebP | 320×320 | 8192×8192 | 10 MB | Rendered with `contain` + configured background color, preserving aspect ratio. |

## Generated Android resources

- `mipmap-mdpi` … `mipmap-xxxhdpi` standard, round and adaptive foreground icons
- `mipmap-anydpi-v26/ic_launcher.xml` and `ic_launcher_round.xml`
- `values/ic_launcher_background.xml`
- `drawable` and `drawable-port/land-*` splash screens
- `public/icon.png`, `public/splash.png`
