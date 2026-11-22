/**
 * generate-icons.js
 * Generates Android launcher icons (standard, round, adaptive) and splash screens
 * for Bizgrowth360 Android Builder using sharp.
 */

const fs = require('fs');
const path = require('path');
let sharp;
try {
  sharp = require('sharp');
} catch (e) {
  console.warn('Warning: sharp not installed in root, attempting to require from app-template...');
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

/**
 * Creates an SVG buffer for the default branded icon
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
      <!-- Base Rounded Rect -->
      <rect width="1024" height="1024" rx="224" fill="url(#bgGrad)" />
      
      <!-- Growth Hexagon Pattern -->
      <g filter="url(#shadow)">
        <polygon points="512,192 780,348 780,660 512,816 244,660 244,348" fill="#FFFFFF" fill-opacity="0.18" />
        <polygon points="512,240 736,370 736,630 512,760 288,630 288,370" fill="#FFFFFF" fill-opacity="0.25" />
      </g>
      
      <!-- Growth Arrow Shapes -->
      <path d="M350 674 L512 350 L674 674 L512 560 Z" fill="#FFFFFF" />
      <circle cx="512" cy="512" r="64" fill="${primaryColor}" />
      
      <!-- Brand Initial -->
      <text x="512" y="534" font-family="-apple-system, Roboto, sans-serif" font-size="60" font-weight="bold" fill="#FFFFFF" text-anchor="middle" dominant-baseline="central">${initial}</text>
    </svg>
  `);
}

/**
 * Creates an SVG buffer for the adaptive foreground
 */
function createAdaptiveForegroundSvg(appName = 'Bizgrowth360', primaryColor = '#2563EB') {
  const initial = (appName.trim().charAt(0) || 'B').toUpperCase();
  // Safe zone for adaptive icons is the central 66% (circle of radius 33% of canvas)
  return Buffer.from(`
    <svg width="1024" height="1024" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
      <!-- Center Circle / Emblem inside 66% safe zone -->
      <g transform="translate(512, 512) scale(0.68) translate(-512, -512)">
        <rect x="192" y="192" width="640" height="640" rx="160" fill="${primaryColor}" />
        <path d="M380 650 L512 370 L644 650 L512 540 Z" fill="#FFFFFF" />
        <circle cx="512" cy="512" r="54" fill="${primaryColor}" />
        <text x="512" y="530" font-family="-apple-system, Roboto, sans-serif" font-size="52" font-weight="bold" fill="#FFFFFF" text-anchor="middle" dominant-baseline="central">${initial}</text>
      </g>
    </svg>
  `);
}

/**
 * Creates an SVG buffer for the default splash screen
 */
function createDefaultSplashSvg(width, height, appName = 'Bizgrowth360', primaryColor = '#2563EB', bgColor = '#FFFFFF') {
  const initial = (appName.trim().charAt(0) || 'B').toUpperCase();
  return Buffer.from(`
    <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
      <rect width="${width}" height="${height}" fill="${bgColor}" />
      <g transform="translate(${width / 2}, ${height / 2})">
        <!-- Centered Logo -->
        <g transform="translate(-80, -110)">
          <rect width="160" height="160" rx="36" fill="${primaryColor}" />
          <path d="M50 115 L80 45 L110 115 L80 90 Z" fill="#FFFFFF" />
          <circle cx="80" cy="80" r="14" fill="${primaryColor}" />
          <text x="80" y="85" font-family="-apple-system, Roboto, sans-serif" font-size="14" font-weight="bold" fill="#FFFFFF" text-anchor="middle">${initial}</text>
        </g>
        <!-- App Title -->
        <text x="0" y="90" font-family="-apple-system, Roboto, sans-serif" font-size="24" font-weight="700" fill="${primaryColor}" text-anchor="middle">${appName}</text>
      </g>
    </svg>
  `);
}

/**
 * Generates round mask buffer
 */
function createCircleMask(size) {
  return Buffer.from(`
    <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
      <circle cx="${size / 2}" cy="${size / 2}" r="${size / 2}" fill="#000000" />
    </svg>
  `);
}

/**
 * Main icon & splash generation function
 */
async function generateIcons(options = {}) {
  const rootDir = options.rootDir || path.resolve(__dirname, '..');
  const appTemplateDir = path.join(rootDir, 'app-template');
  const resDir = path.join(appTemplateDir, 'android', 'app', 'src', 'main', 'res');
  const publicDir = path.join(appTemplateDir, 'public');

  const configPath = options.configPath || path.join(rootDir, 'config', 'default-app.json');
  let config = {};
  if (fs.existsSync(configPath)) {
    try {
      config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    } catch (e) {
      console.warn(`Could not parse config at ${configPath}, using defaults.`);
    }
  }

  const appName = options.appName || config.app_name || 'Demo Website App';
  const primaryColor = options.primaryColor || config.primary_color || '#2563EB';
  const backgroundColor = options.backgroundColor || config.background_color || '#FFFFFF';

  console.log(`\n=== Generating Android Branding Assets ===`);
  console.log(`App Name: ${appName}`);
  console.log(`Primary Color: ${primaryColor}`);
  console.log(`Background Color: ${backgroundColor}`);

  // Ensure directories exist
  [publicDir, resDir].forEach(d => {
    if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
  });

  // Source icon buffer
  let iconSourceBuffer;
  let fgSourceBuffer;
  const customIconPath = options.iconPath || (config.app_icon ? path.resolve(rootDir, config.app_icon) : null);

  if (customIconPath && fs.existsSync(customIconPath)) {
    console.log(`Using custom icon: ${customIconPath}`);
    try {
      const metadata = await sharp(customIconPath).metadata();
      console.log(`Custom icon format: ${metadata.format}, dimensions: ${metadata.width}x${metadata.height}`);
      iconSourceBuffer = await sharp(customIconPath).png().toBuffer();
      fgSourceBuffer = iconSourceBuffer;
    } catch (err) {
      console.warn(`Failed reading custom icon (${err.message}). Falling back to branded default icon.`);
      iconSourceBuffer = await sharp(createDefaultIconSvg(appName, primaryColor)).png().toBuffer();
      fgSourceBuffer = await sharp(createAdaptiveForegroundSvg(appName, primaryColor)).png().toBuffer();
    }
  } else {
    console.log(`Generating branded default icon from SVG vector...`);
    iconSourceBuffer = await sharp(createDefaultIconSvg(appName, primaryColor)).png().toBuffer();
    fgSourceBuffer = await sharp(createAdaptiveForegroundSvg(appName, primaryColor)).png().toBuffer();
  }

  // Save master icon to public/icon.png
  await sharp(iconSourceBuffer)
    .resize(512, 512)
    .toFile(path.join(publicDir, 'icon.png'));
  console.log(`[OK] Saved master icon to public/icon.png`);

  // Generate Mipmap Launcher Icons
  for (const item of ICON_SIZES) {
    const targetFolder = path.join(resDir, item.folder);
    if (!fs.existsSync(targetFolder)) fs.mkdirSync(targetFolder, { recursive: true });

    // Standard square/squircle launcher
    const launcherPath = path.join(targetFolder, 'ic_launcher.png');
    await sharp(iconSourceBuffer)
      .resize(item.size, item.size)
      .png()
      .toFile(launcherPath);

    // Round launcher with circular mask
    const roundPath = path.join(targetFolder, 'ic_launcher_round.png');
    const circleMask = createCircleMask(item.size);
    await sharp(iconSourceBuffer)
      .resize(item.size, item.size)
      .composite([{ input: circleMask, blend: 'dest-in' }])
      .png()
      .toFile(roundPath);

    // Adaptive icon foreground
    const fgPath = path.join(targetFolder, 'ic_launcher_foreground.png');
    await sharp(fgSourceBuffer)
      .resize(item.fgSize, item.fgSize)
      .png()
      .toFile(fgPath);

    console.log(`[OK] Generated icons for ${item.folder} (${item.size}x${item.size}, fg: ${item.fgSize}x${item.fgSize})`);
  }

  // Ensure mipmap-anydpi-v26 exists for adaptive icons
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

  // Values folder for ic_launcher_background color
  const valuesFolder = path.join(resDir, 'values');
  if (!fs.existsSync(valuesFolder)) fs.mkdirSync(valuesFolder, { recursive: true });

  const icBgColorXml = `<?xml version="1.0" encoding="utf-8"?>
<resources>
    <color name="ic_launcher_background">${primaryColor}</color>
</resources>
`;
  fs.writeFileSync(path.join(valuesFolder, 'ic_launcher_background.xml'), icBgColorXml, 'utf8');
  console.log(`[OK] Generated mipmap-anydpi-v26 adaptive icon XML definitions`);

  // Generate Splash Screens
  console.log(`\nGenerating Splash Screen assets...`);
  const customSplashPath = options.splashPath || (config.splash_image ? path.resolve(rootDir, config.splash_image) : null);
  let splashSource = null;

  if (customSplashPath && fs.existsSync(customSplashPath)) {
    try {
      console.log(`Using custom splash: ${customSplashPath}`);
      splashSource = customSplashPath;
    } catch (e) {
      console.warn(`Failed using custom splash: ${e.message}`);
    }
  }

  for (const splash of SPLASH_SIZES) {
    const splashFolder = path.join(resDir, splash.folder);
    if (!fs.existsSync(splashFolder)) fs.mkdirSync(splashFolder, { recursive: true });

    const outPath = path.join(splashFolder, 'splash.png');
    if (splashSource) {
      await sharp(splashSource)
        .resize(splash.width, splash.height, { fit: 'cover' })
        .png()
        .toFile(outPath);
    } else {
      const splashSvg = createDefaultSplashSvg(splash.width, splash.height, appName, primaryColor, backgroundColor);
      await sharp(splashSvg)
        .png()
        .toFile(outPath);
    }
  }

  // Also save a master splash to public/splash.png
  const publicSplashSvg = createDefaultSplashSvg(1280, 1920, appName, primaryColor, backgroundColor);
  await sharp(publicSplashSvg).png().toFile(path.join(publicDir, 'splash.png'));

  console.log(`[OK] Generated all splash screen drawables and public/splash.png`);
  console.log(`=== Android Assets Generated Successfully ===\n`);
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
      else if (arg === '--icon') options.iconPath = nextArg.trim();
      else if (arg === '--splash') options.splashPath = nextArg.trim();
      else if (arg === '--app-name') options.appName = nextArg.trim();
      else if (arg === '--primary-color') options.primaryColor = nextArg.trim();
    }
  }
  generateIcons(options)
    .then(() => process.exit(0))
    .catch(err => {
      console.error('Error generating assets:', err);
      process.exit(1);
    });
}

module.exports = {
  generateIcons,
  createDefaultIconSvg,
  createAdaptiveForegroundSvg,
  createDefaultSplashSvg
};
