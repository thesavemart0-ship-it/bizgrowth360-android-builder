/**
 * generate-icons.js
 * Generates Android launcher icons (standard, round, adaptive) and splash
 * screens for Bizgrowth360 Android Builder using sharp.
 *
 * All customer-supplied image files are validated and re-encoded to PNG before
 * being written into Android resources. SVG uploads are intentionally rejected
 * because they can contain external references and are not needed for app
 * icons.
 */

const fs = require('fs');
const path = require('path');
const { validateConfig, escapeXml } = require('./validate-config');

let sharp;
try {
  sharp = require('sharp');
} catch (e) {
  try {
    sharp = require(path.join(__dirname, '..', 'app-template', 'node_modules', 'sharp'));
  } catch (err) {
    console.error('Error: sharp library is required for icon generation. Please run npm install.');
    process.exit(1);
  }
}

// Android Launcher Icon Sizes
const ICON_SIZES = [
  { folder: 'mipmap-mdpi', size: 48, fgSize: 108 },
  { folder: 'mipmap-hdpi', size: 72, fgSize: 162 },
  { folder: 'mipmap-xhdpi', size: 96, fgSize: 216 },
  { folder: 'mipmap-xxhdpi', size: 144, fgSize: 324 },
  { folder: 'mipmap-xxxhdpi', size: 192, fgSize: 432 }
];

// Android Splash Sizes
const SPLASH_SIZES = [
  { folder: 'drawable', width: 480, height: 800 },
  { folder: 'drawable-port-mdpi', width: 320, height: 480 },
  { folder: 'drawable-port-hdpi', width: 480, height: 800 },
  { folder: 'drawable-port-xhdpi', width: 720, height: 1280 },
  { folder: 'drawable-port-xxhdpi', width: 960, height: 1600 },
  { folder: 'drawable-port-xxxhdpi', width: 1280, height: 1920 },
  { folder: 'drawable-land-mdpi', width: 480, height: 320 },
  { folder: 'drawable-land-hdpi', width: 800, height: 480 },
  { folder: 'drawable-land-xhdpi', width: 1280, height: 720 },
  { folder: 'drawable-land-xxhdpi', width: 1600, height: 960 },
  { folder: 'drawable-land-xxxhdpi', width: 1920, height: 1280 }
];

const ALLOWED_IMAGE_FORMATS = new Set(['png', 'jpeg', 'webp']);
const ALLOWED_IMAGE_EXTENSIONS = new Set(['.png', '.jpg', '.jpeg', '.webp']);
const MAX_ASSET_BYTES = 10 * 1024 * 1024;

/**
 * Creates an SVG buffer for the default branded icon.
 */
function createDefaultIconSvg(appName = 'Bizgrowth360', primaryColor = '#2563EB') {
  const initial = (appName.trim().charAt(0) || 'B').toUpperCase();
  return Buffer.from(`
    <svg width="1024" height="1024" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="${primaryColor}" />
          <stop offset="100%" stop-color="#1E40AF" />
        </linearGradient>
        <filter id="shadow" x="-10%" y="-10%" width="130%" height="130%">
          <feDropShadow dx="0" dy="16" stdDeviation="24" flood-opacity="0.3" />
        </filter>
      </defs>
      <rect width="1024" height="1024" rx="224" fill="url(#bgGrad)" />

      <g filter="url(#shadow)">
        <polygon points="512,192 780,348 780,660 512,816 244,660 244,348" fill="#FFFFFF" fill-opacity="0.18" />
        <polygon points="512,240 736,370 736,630 512,760 288,630 288,370" fill="#FFFFFF" fill-opacity="0.25" />
      </g>

      <path d="M350 674 L512 350 L674 674 L512 560 Z" fill="#FFFFFF" />
      <circle cx="512" cy="512" r="64" fill="${primaryColor}" />

      <text x="512" y="534" font-family="-apple-system, Roboto, sans-serif" font-size="60" font-weight="bold" fill="#FFFFFF" text-anchor="middle" dominant-baseline="central">${escapeXml(initial)}</text>
    </svg>
  `);
}

/**
 * Creates an SVG buffer for the adaptive foreground (inside the 66% safe zone).
 */
function createAdaptiveForegroundSvg(appName = 'Bizgrowth360', primaryColor = '#2563EB') {
  const initial = (appName.trim().charAt(0) || 'B').toUpperCase();
  return Buffer.from(`
    <svg width="1024" height="1024" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
      <g transform="translate(512, 512) scale(0.68) translate(-512, -512)">
        <rect x="192" y="192" width="640" height="640" rx="160" fill="${primaryColor}" />
        <path d="M380 650 L512 370 L644 650 L512 540 Z" fill="#FFFFFF" />
        <circle cx="512" cy="512" r="54" fill="${primaryColor}" />
        <text x="512" y="530" font-family="-apple-system, Roboto, sans-serif" font-size="52" font-weight="bold" fill="#FFFFFF" text-anchor="middle" dominant-baseline="central">${escapeXml(initial)}</text>
      </g>
    </svg>
  `);
}

/**
 * Creates an SVG buffer for the default splash screen.
 */
function createDefaultSplashSvg(width, height, appName = 'Bizgrowth360', primaryColor = '#2563EB', bgColor = '#FFFFFF') {
  const initial = (appName.trim().charAt(0) || 'B').toUpperCase();
  return Buffer.from(`
    <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
      <rect width="${width}" height="${height}" fill="${bgColor}" />
      <g transform="translate(${width / 2}, ${height / 2})">
        <g transform="translate(-80, -110)">
          <rect width="160" height="160" rx="36" fill="${primaryColor}" />
          <path d="M50 115 L80 45 L110 115 L80 90 Z" fill="#FFFFFF" />
          <circle cx="80" cy="80" r="14" fill="${primaryColor}" />
          <text x="80" y="85" font-family="-apple-system, Roboto, sans-serif" font-size="14" font-weight="bold" fill="#FFFFFF" text-anchor="middle">${escapeXml(initial)}</text>
        </g>
        <text x="0" y="90" font-family="-apple-system, Roboto, sans-serif" font-size="24" font-weight="700" fill="${primaryColor}" text-anchor="middle">${escapeXml(appName)}</text>
      </g>
    </svg>
  `);
}

/**
 * Generates a circular mask buffer.
 */
function createCircleMask(size) {
  return Buffer.from(`
    <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
      <circle cx="${size / 2}" cy="${size / 2}" r="${size / 2}" fill="#000000" />
    </svg>
  `);
}

/**
 * Validates a customer-supplied image before it is decoded by sharp.
 *
 * @param {string} filePath
 * @param {object} options
 * @param {'icon'|'splash'} options.kind
 * @param {number} options.minWidth
 * @param {number} options.minHeight
 * @param {number} options.maxDimension
 * @returns {Promise<{valid: boolean, errors: string[], metadata?: object}>}
 */
async function validateImageAsset(filePath, options = {}) {
  const errors = [];
  const kind = options.kind || 'icon';
  const minWidth = options.minWidth || 192;
  const minHeight = options.minHeight || 192;
  const maxDimension = options.maxDimension || 8192;

  if (typeof filePath !== 'string' || !filePath) {
    return { valid: false, errors: [`${kind} asset path is required.`] };
  }
  if (!fs.existsSync(filePath)) {
    return { valid: false, errors: [`${kind} asset not found at: ${filePath}`] };
  }

  const stat = fs.statSync(filePath);
  if (!stat.isFile()) {
    return { valid: false, errors: [`${kind} asset is not a regular file: ${filePath}`] };
  }
  if (stat.size <= 0) {
    return { valid: false, errors: [`${kind} asset is empty: ${filePath}`] };
  }
  if (stat.size > MAX_ASSET_BYTES) {
    return {
      valid: false,
      errors: [`${kind} asset is too large (${(stat.size / 1024 / 1024).toFixed(1)} MB). Maximum is ${MAX_ASSET_BYTES / 1024 / 1024} MB.`]
    };
  }

  const ext = path.extname(filePath).toLowerCase();
  if (!ALLOWED_IMAGE_EXTENSIONS.has(ext)) {
    errors.push(`${kind} asset must be PNG, JPEG or WebP (got "${ext || 'no extension'}"). SVG uploads are not accepted.`);
    return { valid: false, errors };
  }

  let metadata;
  try {
    metadata = await sharp(filePath, { limitInputPixels: 40 * 1000 * 1000 }).metadata();
  } catch (err) {
    return { valid: false, errors: [`${kind} asset could not be decoded: ${err.message}`] };
  }

  if (!ALLOWED_IMAGE_FORMATS.has(metadata.format)) {
    errors.push(`${kind} asset format "${metadata.format}" is not allowed. Use PNG, JPEG or WebP.`);
  }
  if (!metadata.width || !metadata.height) {
    errors.push(`${kind} asset has no readable dimensions.`);
  } else {
    if (metadata.width < minWidth || metadata.height < minHeight) {
      errors.push(
        `${kind} asset is too small (${metadata.width}x${metadata.height}). ` +
        `Minimum is ${minWidth}x${minHeight}.`
      );
    }
    if (metadata.width > maxDimension || metadata.height > maxDimension) {
      errors.push(
        `${kind} asset is too large (${metadata.width}x${metadata.height}). ` +
        `Maximum dimension is ${maxDimension}px.`
      );
    }
    if (metadata.width * metadata.height > 40 * 1000 * 1000) {
      errors.push(`${kind} asset has too many pixels.`);
    }
  }
  if (metadata.pages && metadata.pages > 1) {
    errors.push(`${kind} asset must be a single still image (animated images are not allowed).`);
  }

  return { valid: errors.length === 0, errors, metadata, sizeBytes: stat.size };
}

/**
 * Resolves a configured asset path inside the project directory and prevents
 * path traversal / absolute paths outside the project.
 */
function resolveAssetPath(projectDir, configuredPath) {
  if (!configuredPath) return null;
  if (path.isAbsolute(configuredPath)) {
    throw new Error(`Asset path must be relative to the project: ${configuredPath}`);
  }
  const resolved = path.resolve(projectDir, configuredPath);
  const relative = path.relative(projectDir, resolved);
  if (relative.startsWith('..') || path.isAbsolute(relative)) {
    throw new Error(`Asset path escapes the project directory: ${configuredPath}`);
  }
  return resolved;
}

/**
 * Main icon & splash generation function.
 */
async function generateIcons(options = {}) {
  const rootDir = options.rootDir || path.resolve(__dirname, '..');
  const projectDir = options.projectDir || path.join(rootDir, 'app-template');
  const resDir = path.join(projectDir, 'android', 'app', 'src', 'main', 'res');
  const publicDir = path.join(projectDir, 'public');

  const configPath = options.configPath || path.join(rootDir, 'config', 'default-app.json');
  let baseConfig = {};
  if (options.config && typeof options.config === 'object') {
    baseConfig = options.config;
  } else if (fs.existsSync(configPath)) {
    try {
      baseConfig = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    } catch (e) {
      throw new Error(`Could not parse config at ${configPath}: ${e.message}`);
    }
  }

  const merged = {
    ...baseConfig,
    ...(options.appName ? { app_name: options.appName } : {}),
    ...(options.primaryColor ? { primary_color: options.primaryColor } : {}),
    ...(options.backgroundColor ? { background_color: options.backgroundColor } : {}),
    ...(options.splashBackgroundColor ? { splash_background_color: options.splashBackgroundColor } : {})
  };

  const validation = validateConfig(merged);
  if (!validation.valid) {
    throw new Error(`Invalid branding configuration:\n  - ${validation.errors.join('\n  - ')}`);
  }

  const config = validation.normalizedConfig;
  const appName = config.app_name;
  const primaryColor = config.primary_color;
  const backgroundColor = config.background_color;
  const splashBackgroundColor = config.splash_background_color;

  console.log(`\n=== Generating Android Branding Assets ===`);
  console.log(`App Name: ${appName}`);
  console.log(`Primary Color: ${primaryColor}`);
  console.log(`Background Color: ${backgroundColor}`);
  console.log(`Splash Background Color: ${splashBackgroundColor}`);

  [publicDir, resDir].forEach(d => {
    if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
  });

  // ---------------------------------------------------------------------
  // Icon
  // ---------------------------------------------------------------------
  const customIconPath = options.iconPath || resolveAssetPath(projectDir, config.app_icon);
  let iconSourceBuffer;
  let fgSourceBuffer;
  let iconSource = 'default';
  let iconReport = null;

  if (customIconPath) {
    console.log(`Validating custom icon: ${customIconPath}`);
    const iconValidation = await validateImageAsset(customIconPath, {
      kind: 'icon',
      minWidth: 192,
      minHeight: 192,
      maxDimension: 4096
    });
    if (!iconValidation.valid) {
      throw new Error(`Custom icon is invalid:\n  - ${iconValidation.errors.join('\n  - ')}`);
    }
    console.log(
      `[OK] Custom icon accepted: ${iconValidation.metadata.format}, ` +
      `${iconValidation.metadata.width}x${iconValidation.metadata.height}, ` +
      `${(iconValidation.sizeBytes / 1024).toFixed(0)} KB`
    );
    // Re-encode to a clean PNG. This strips metadata and guarantees that only
    // raster image data reaches the Android resource pipeline.
    iconSourceBuffer = await sharp(customIconPath, { limitInputPixels: 40 * 1000 * 1000 })
      .rotate()
      .png()
      .toBuffer();
    fgSourceBuffer = iconSourceBuffer;
    iconSource = 'custom';
    iconReport = {
      source: 'custom',
      format: iconValidation.metadata.format,
      width: iconValidation.metadata.width,
      height: iconValidation.metadata.height
    };
  } else {
    console.log('No custom icon supplied. Generating branded default icon from SVG vector...');
    iconSourceBuffer = await sharp(createDefaultIconSvg(appName, primaryColor)).png().toBuffer();
    fgSourceBuffer = await sharp(createAdaptiveForegroundSvg(appName, primaryColor)).png().toBuffer();
    iconReport = { source: 'default' };
  }

  await sharp(iconSourceBuffer).resize(1024, 1024, { fit: 'cover' }).png().toFile(path.join(publicDir, 'icon.png'));
  console.log('[OK] Saved master icon to public/icon.png');

  for (const item of ICON_SIZES) {
    const targetFolder = path.join(resDir, item.folder);
    if (!fs.existsSync(targetFolder)) fs.mkdirSync(targetFolder, { recursive: true });

    await sharp(iconSourceBuffer)
      .resize(item.size, item.size, { fit: 'cover' })
      .png()
      .toFile(path.join(targetFolder, 'ic_launcher.png'));

    const circleMask = createCircleMask(item.size);
    await sharp(iconSourceBuffer)
      .resize(item.size, item.size, { fit: 'cover' })
      .composite([{ input: circleMask, blend: 'dest-in' }])
      .png()
      .toFile(path.join(targetFolder, 'ic_launcher_round.png'));

    let foregroundBuffer;
    if (iconSource === 'custom') {
      // Adaptive foreground safe zone: keep the logo inside ~66% of the
      // 108dp canvas so launcher masks never clip meaningful content.
      const innerSize = Math.max(1, Math.round(item.fgSize * 0.64));
      const inner = await sharp(fgSourceBuffer)
        .resize(innerSize, innerSize, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .png()
        .toBuffer();
      const left = Math.round((item.fgSize - innerSize) / 2);
      foregroundBuffer = await sharp({
        create: {
          width: item.fgSize,
          height: item.fgSize,
          channels: 4,
          background: { r: 0, g: 0, b: 0, alpha: 0 }
        }
      })
        .composite([{ input: inner, left, top: left }])
        .png()
        .toBuffer();
    } else {
      foregroundBuffer = await sharp(fgSourceBuffer)
        .resize(item.fgSize, item.fgSize, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
        .png()
        .toBuffer();
    }

    await sharp(foregroundBuffer)
      .png()
      .toFile(path.join(targetFolder, 'ic_launcher_foreground.png'));

    console.log(`[OK] Generated icons for ${item.folder} (${item.size}x${item.size}, fg: ${item.fgSize}x${item.fgSize})`);
  }

  const anydpiFolder = path.join(resDir, 'mipmap-anydpi-v26');
  if (!fs.existsSync(anydpiFolder)) fs.mkdirSync(anydpiFolder, { recursive: true });

  const icLauncherXml = `<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>
`;
  fs.writeFileSync(path.join(anydpiFolder, 'ic_launcher.xml'), icLauncherXml, 'utf8');
  fs.writeFileSync(path.join(anydpiFolder, 'ic_launcher_round.xml'), icLauncherXml, 'utf8');

  const valuesFolder = path.join(resDir, 'values');
  if (!fs.existsSync(valuesFolder)) fs.mkdirSync(valuesFolder, { recursive: true });

  const icBgColorXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">${primaryColor}</color>
</resources>
`;
  fs.writeFileSync(path.join(valuesFolder, 'ic_launcher_background.xml'), icBgColorXml, 'utf8');
  console.log('[OK] Generated mipmap-anydpi-v26 adaptive icon XML definitions');

  // ---------------------------------------------------------------------
  // Splash
  // ---------------------------------------------------------------------
  console.log('\nGenerating Splash Screen assets...');
  const customSplashPath = options.splashPath || resolveAssetPath(projectDir, config.splash_image);
  let splashSource = 'default';
  let splashReport = null;
  let splashInput = null;

  if (customSplashPath) {
    console.log(`Validating custom splash: ${customSplashPath}`);
    const splashValidation = await validateImageAsset(customSplashPath, {
      kind: 'splash',
      minWidth: 320,
      minHeight: 320,
      maxDimension: 8192
    });
    if (!splashValidation.valid) {
      throw new Error(`Custom splash image is invalid:\n  - ${splashValidation.errors.join('\n  - ')}`);
    }
    console.log(
      `[OK] Custom splash accepted: ${splashValidation.metadata.format}, ` +
      `${splashValidation.metadata.width}x${splashValidation.metadata.height}, ` +
      `${(splashValidation.sizeBytes / 1024).toFixed(0)} KB`
    );
    splashInput = await sharp(customSplashPath, { limitInputPixels: 40 * 1000 * 1000 })
      .rotate()
      .png()
      .toBuffer();
    splashSource = 'custom';
    splashReport = {
      source: 'custom',
      format: splashValidation.metadata.format,
      width: splashValidation.metadata.width,
      height: splashValidation.metadata.height
    };
  } else {
    console.log('No custom splash supplied. Generating branded default splash screens...');
    splashReport = { source: 'default' };
  }

  for (const splash of SPLASH_SIZES) {
    const splashFolder = path.join(resDir, splash.folder);
    if (!fs.existsSync(splashFolder)) fs.mkdirSync(splashFolder, { recursive: true });

    const outPath = path.join(splashFolder, 'splash.png');
    if (splashInput) {
      // `contain` preserves the original aspect ratio and letterboxes with the
      // configured background color instead of stretching/cropping the image.
      await sharp(splashInput)
        .resize(splash.width, splash.height, {
          fit: 'contain',
          background: splashBackgroundColor
        })
        .flatten({ background: splashBackgroundColor })
        .png()
        .toFile(outPath);
    } else {
      const splashSvg = createDefaultSplashSvg(
        splash.width,
        splash.height,
        appName,
        primaryColor,
        splashBackgroundColor
      );
      await sharp(splashSvg).png().toFile(outPath);
    }
  }

  if (splashInput) {
    await sharp(splashInput)
      .resize(1280, 1920, { fit: 'contain', background: splashBackgroundColor })
      .flatten({ background: splashBackgroundColor })
      .png()
      .toFile(path.join(publicDir, 'splash.png'));
  } else {
    const publicSplashSvg = createDefaultSplashSvg(1280, 1920, appName, primaryColor, splashBackgroundColor);
    await sharp(publicSplashSvg).png().toFile(path.join(publicDir, 'splash.png'));
  }

  console.log('[OK] Generated all splash screen drawables and public/splash.png');
  console.log('=== Android Assets Generated Successfully ===\n');

  return {
    icon: iconReport,
    splash: splashReport,
    densities: ICON_SIZES.map(i => i.folder),
    splashDensities: SPLASH_SIZES.map(s => s.folder)
  };
}

if (require.main === module) {
  const args = process.argv.slice(2);
  const options = {};
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg.startsWith('--')) {
      const nextArg = (i + 1 < args.length && !args[i + 1].startsWith('--')) ? args[++i] : null;
      if (!nextArg || !nextArg.trim()) continue;

      if (arg === '--config') options.configPath = nextArg.trim();
      else if (arg === '--project-dir') options.projectDir = nextArg.trim();
      else if (arg === '--icon') options.iconPath = nextArg.trim();
      else if (arg === '--splash') options.splashPath = nextArg.trim();
      else if (arg === '--app-name') options.appName = nextArg.trim();
      else if (arg === '--primary-color') options.primaryColor = nextArg.trim();
      else if (arg === '--background-color') options.backgroundColor = nextArg.trim();
      else if (arg === '--splash-background-color') options.splashBackgroundColor = nextArg.trim();
    }
  }
  generateIcons(options)
    .then(() => process.exit(0))
    .catch(err => {
      console.error('Error generating assets:', err.message || err);
      process.exit(1);
    });
}

module.exports = {
  generateIcons,
  validateImageAsset,
  resolveAssetPath,
  createDefaultIconSvg,
  createAdaptiveForegroundSvg,
  createDefaultSplashSvg,
  ICON_SIZES,
  SPLASH_SIZES
};
