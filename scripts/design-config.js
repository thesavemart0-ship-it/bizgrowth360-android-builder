/**
 * design-config.js
 * Build-time validation and defaulting for the App Design Studio schema.
 *
 * Server-side WordPress sanitization is the primary validation layer. This
 * module is the defense-in-depth layer for direct JSON payloads and guarantees
 * that only safe, bounded values reach the Android web shell.
 */

const SCHEMA_VERSION = 1;

const COLOR_FIELDS = [
  'primary_color', 'secondary_color', 'accent_color', 'background_color',
  'card_color', 'text_color', 'heading_color', 'button_color',
  'button_text_color', 'border_color', 'status_bar_color', 'navigation_bar_color'
];

const FONTS = ['system', 'arial', 'georgia', 'verdana', 'trebuchet', 'tahoma', 'times', 'courier'];
const ICONS = [
  'home', 'menu', 'search', 'bell', 'cart', 'user', 'grid', 'link', 'star',
  'heart', 'info', 'phone', 'mail', 'whatsapp', 'settings', 'image',
  'chevron-right', 'more', 'website', 'shop', 'bag', 'calendar', 'location', 'play'
];
const MENU_TYPES = ['home', 'website', 'page', 'category', 'external', 'custom', 'contact', 'whatsapp', 'account', 'cart'];
const NAV_TYPES = ['none', 'bottom', 'drawer', 'top'];
const NAV_STYLES = ['solid', 'transparent', 'floating'];
const SECTION_TYPES = [
  'hero', 'slider', 'welcome', 'featured_links', 'quick_actions', 'categories',
  'products', 'featured_content', 'latest_posts', 'promo', 'image_cards',
  'custom_html', 'contact'
];
const COMPONENT_PRESETS = ['modern', 'minimal', 'rounded', 'material', 'compact', 'premium'];
const SECTION_LAYOUTS = ['default', 'grid', 'list', 'carousel', 'split', 'overlay'];
const IMAGE_RATIOS = ['1:1', '4:3', '16:9', '3:2'];
const SHADOWS = ['none', 'soft', 'medium', 'strong'];
const CARD_STYLES = ['flat', 'outlined', 'elevated'];
const BUTTON_STYLES = ['solid', 'outline', 'pill'];
const ICON_STYLES = ['rounded', 'outline', 'filled'];

function defaultDesign(appConfig = {}) {
  const primary = color(appConfig.primary_color, '#2563EB');
  const background = color(appConfig.background_color, '#FFFFFF');
  const status = color(appConfig.status_bar_color, primary);
  const navigation = color(appConfig.navigation_bar_color, background);

  return {
    schema_version: SCHEMA_VERSION,
    theme: {
      mode: 'light',
      primary_color: primary,
      secondary_color: '#0F172A',
      accent_color: '#F59E0B',
      background_color: background,
      card_color: '#F8FAFC',
      text_color: '#111827',
      heading_color: '#0F172A',
      button_color: primary,
      button_text_color: '#FFFFFF',
      border_color: '#E2E8F0',
      status_bar_color: status,
      navigation_bar_color: navigation,
      dark: {
        primary_color: '#60A5FA',
        secondary_color: '#E2E8F0',
        accent_color: '#FBBF24',
        background_color: '#0B1220',
        card_color: '#111827',
        text_color: '#E5E7EB',
        heading_color: '#F9FAFB',
        button_color: '#3B82F6',
        button_text_color: '#FFFFFF',
        border_color: '#1F2937',
        status_bar_color: '#0B1220',
        navigation_bar_color: '#0B1220'
      }
    },
    header: {
      enabled: true,
      show_logo: true,
      logo_url: '',
      title: '',
      background_color: primary,
      text_color: '#FFFFFF',
      height: 56,
      sticky: true,
      show_search: true,
      show_notifications: false,
      show_cart: false,
      show_profile: false,
      search_url: '',
      notification_url: '',
      cart_url: '',
      profile_url: '',
      actions: []
    },
    navigation: { type: 'none', style: 'solid', more_label: 'More', items: [] },
    menu: { groups: [{ id: 'main', label: 'Menu' }], items: [] },
    homepage: { enabled: false, sections: [] },
    components: {
      style_preset: 'modern',
      card_radius: 12,
      button_radius: 10,
      card_style: 'elevated',
      button_style: 'solid',
      image_ratio: '16:9',
      shadow: 'soft',
      card_spacing: 12,
      section_spacing: 20,
      content_width: 100,
      icon_style: 'rounded',
      show_dividers: false
    },
    typography: {
      font_family: 'system',
      heading_font: 'system',
      body_font: 'system',
      base_size: 14,
      heading_scale: 1.25,
      button_weight: 600,
      line_height: 1.5,
      letter_spacing: 0
    },
    layout: {
      page_padding: 16,
      section_gap: 20,
      max_width: 600,
      safe_area: true,
      bottom_nav_height: 64
    }
  };
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function deepMerge(base, extra) {
  if (Array.isArray(base) || Array.isArray(extra)) {
    return extra === undefined ? base : extra;
  }
  if (isObject(base) && isObject(extra)) {
    const out = clone(base);
    for (const key of Object.keys(extra)) {
      out[key] = deepMerge(base[key], extra[key]);
    }
    return out;
  }
  return extra === undefined ? base : extra;
}

function color(value, fallback) {
  if (typeof value !== 'string' || !/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value.trim())) {
    return fallback;
  }
  let hex = value.trim().toUpperCase();
  if (hex.length === 4) {
    hex = `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`;
  }
  return hex;
}

function text(value, max) {
  return String(value === undefined || value === null ? '' : value)
    .replace(/[\u0000-\u001F\u007F]/g, ' ')
    .trim()
    .slice(0, max);
}

function bool(value, fallback) {
  return value === undefined || value === null ? !!fallback : !!value;
}

function intRange(value, min, max, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(min, Math.min(max, Math.round(number)));
}

function floatRange(value, min, max, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(min, Math.min(max, Math.round(number * 100) / 100));
}

function pick(value, allowed, fallback) {
  return allowed.includes(value) ? value : fallback;
}

function siteOrigin(appConfig) {
  try {
    return new URL(appConfig.website_url || 'https://example.com').origin;
  } catch (err) {
    return 'https://example.com';
  }
}

function url(value, appConfig, imagesOnly = false) {
  const raw = String(value === undefined || value === null ? '' : value).trim();
  if (!raw) return '';
  const origin = siteOrigin(appConfig);
  let normalized = raw;
  if (raw.startsWith('//')) normalized = `https:${raw}`;
  else if (raw.startsWith('/')) normalized = origin + raw;
  else if (!/^[a-z][a-z0-9+.-]*:/i.test(raw)) normalized = `https://${raw}`;

  let parsed;
  try {
    parsed = new URL(normalized);
  } catch (err) {
    return '';
  }
  const allowed = imagesOnly
    ? ['http:', 'https:']
    : ['http:', 'https:', 'mailto:', 'tel:', 'sms:', 'whatsapp:', 'app:'];
  if (!allowed.includes(parsed.protocol)) return '';
  if (!imagesOnly && parsed.protocol === 'app:') {
    return ['app:home', 'app:menu', 'app:website'].includes(normalized) ? normalized : '';
  }
  if (!imagesOnly && parsed.protocol === 'http:' && appConfig.website_url && appConfig.website_url.startsWith('https://')) {
    return normalized;
  }
  return normalized;
}

function id(value) {
  const cleaned = String(value || '').toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 48);
  return cleaned || `bg360_${Math.random().toString(36).slice(2, 10)}`;
}

function sanitizeHtml(value) {
  return String(value || '')
    .slice(0, 5000)
    .replace(/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|link|meta)[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
    .replace(/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|link|meta)[^>]*\/?>/gi, '')
    .replace(/<\s*\/\s*script/gi, '&lt;/script')
    .replace(/<!--/g, '&lt;!--')
    .replace(/<\s*\/\s*style/gi, '&lt;/style')
    .replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
    .replace(/(href|src)\s*=\s*(["'])\s*javascript:[^"']*\2/gi, '$1="#"');
}

function sanitizeTheme(theme, fallback) {
  theme = isObject(theme) ? theme : {};
  const out = { mode: pick(theme.mode, ['light', 'dark', 'auto'], fallback.mode) };
  for (const field of COLOR_FIELDS) out[field] = color(theme[field], fallback[field]);
  const dark = isObject(theme.dark) ? theme.dark : {};
  out.dark = {};
  for (const field of COLOR_FIELDS) out.dark[field] = color(dark[field], fallback.dark[field]);
  return out;
}

function sanitizeHeader(header, fallback, appConfig) {
  header = isObject(header) ? header : {};
  const actions = Array.isArray(header.actions) ? header.actions.slice(0, 4) : [];
  return {
    enabled: bool(header.enabled, fallback.enabled),
    show_logo: bool(header.show_logo, fallback.show_logo),
    logo_url: url(header.logo_url, appConfig, true),
    title: text(header.title, 80),
    background_color: color(header.background_color, fallback.background_color),
    text_color: color(header.text_color, fallback.text_color),
    height: intRange(header.height, 44, 88, fallback.height),
    sticky: bool(header.sticky, fallback.sticky),
    show_search: bool(header.show_search, fallback.show_search),
    show_notifications: bool(header.show_notifications, fallback.show_notifications),
    show_cart: bool(header.show_cart, fallback.show_cart),
    show_profile: bool(header.show_profile, fallback.show_profile),
    search_url: url(header.search_url, appConfig),
    notification_url: url(header.notification_url, appConfig),
    cart_url: url(header.cart_url, appConfig),
    profile_url: url(header.profile_url, appConfig),
    actions: actions.filter(isObject).map(action => ({
      id: id(action.id),
      label: text(action.label, 40),
      icon: pick(action.icon, ICONS, 'link'),
      url: url(action.url, appConfig),
      enabled: bool(action.enabled, true)
    }))
  };
}

function sanitizeNavItem(item, appConfig) {
  return {
    id: id(item.id),
    label: text(item.label, 40) || 'Item',
    icon: pick(item.icon, ICONS, 'link'),
    type: pick(item.type, MENU_TYPES, 'website'),
    url: url(item.url, appConfig),
    visible: bool(item.visible, true)
  };
}

function sanitizeMenu(menu, fallback, appConfig) {
  menu = isObject(menu) ? menu : {};
  const groups = Array.isArray(menu.groups) ? menu.groups.slice(0, 8) : [];
  const outGroups = [];
  const seen = new Set();
  for (const group of groups) {
    if (!isObject(group)) continue;
    let groupId = id(group.id);
    if (seen.has(groupId)) groupId = id('');
    seen.add(groupId);
    outGroups.push({ id: groupId, label: text(group.label, 40) || 'Menu' });
  }
  if (!outGroups.length) {
    outGroups.push({ id: 'main', label: 'Menu' });
    seen.add('main');
  }
  const items = Array.isArray(menu.items) ? menu.items.slice(0, 40) : [];
  return {
    groups: outGroups,
    items: items.filter(isObject).map(item => {
      let group = id(item.group);
      if (!seen.has(group)) group = outGroups[0].id;
      return {
        id: id(item.id),
        label: text(item.label, 40) || 'Item',
        icon: pick(item.icon, ICONS, 'link'),
        type: pick(item.type, MENU_TYPES, 'website'),
        url: url(item.url, appConfig),
        enabled: bool(item.enabled, true),
        group
      };
    })
  };
}

function sanitizeSection(section, appConfig) {
  const type = pick(section.type, SECTION_TYPES, 'welcome');
  const items = Array.isArray(section.items) ? section.items.slice(0, 20) : [];
  return {
    id: id(section.id),
    type,
    enabled: bool(section.enabled, true),
    title: text(section.title, 120),
    subtitle: text(section.subtitle, 200),
    text: type === 'custom_html' ? sanitizeHtml(section.text) : text(section.text, 2000),
    image_url: url(section.image_url, appConfig, true),
    link_url: url(section.link_url, appConfig),
    button_label: text(section.button_label, 40),
    layout: pick(section.layout, SECTION_LAYOUTS, 'default'),
    items: items.filter(isObject).map(item => ({
      id: id(item.id),
      label: text(item.label, 80),
      title: text(item.title, 120),
      subtitle: text(item.subtitle, 200),
      text: text(item.text, 500),
      url: url(item.url, appConfig),
      image_url: url(item.image_url, appConfig, true),
      icon: pick(item.icon, ICONS, 'link'),
      meta: text(item.meta, 80)
    }))
  };
}

function sanitizeDesign(input, appConfig = {}) {
  const defaults = defaultDesign(appConfig);
  if (!isObject(input)) return defaults;
  const merged = deepMerge(defaults, input);
  const sections = Array.isArray(merged.homepage && merged.homepage.sections)
    ? merged.homepage.sections.slice(0, 20)
    : [];

  return {
    schema_version: SCHEMA_VERSION,
    theme: sanitizeTheme(merged.theme, defaults.theme),
    header: sanitizeHeader(merged.header, defaults.header, appConfig),
    navigation: {
      type: pick(merged.navigation && merged.navigation.type, NAV_TYPES, 'none'),
      style: pick(merged.navigation && merged.navigation.style, NAV_STYLES, 'solid'),
      more_label: text(merged.navigation && merged.navigation.more_label, 20) || 'More',
      items: Array.isArray(merged.navigation && merged.navigation.items)
        ? merged.navigation.items.slice(0, 8).filter(isObject).map(item => sanitizeNavItem(item, appConfig))
        : []
    },
    menu: sanitizeMenu(merged.menu, defaults.menu, appConfig),
    homepage: {
      enabled: bool(merged.homepage && merged.homepage.enabled, false),
      sections: sections.filter(isObject).map(section => sanitizeSection(section, appConfig))
    },
    components: {
      style_preset: pick(merged.components && merged.components.style_preset, COMPONENT_PRESETS, 'modern'),
      card_radius: intRange(merged.components && merged.components.card_radius, 0, 32, 12),
      button_radius: intRange(merged.components && merged.components.button_radius, 0, 32, 10),
      card_style: pick(merged.components && merged.components.card_style, CARD_STYLES, 'elevated'),
      button_style: pick(merged.components && merged.components.button_style, BUTTON_STYLES, 'solid'),
      image_ratio: pick(merged.components && merged.components.image_ratio, IMAGE_RATIOS, '16:9'),
      shadow: pick(merged.components && merged.components.shadow, SHADOWS, 'soft'),
      card_spacing: intRange(merged.components && merged.components.card_spacing, 0, 40, 12),
      section_spacing: intRange(merged.components && merged.components.section_spacing, 0, 48, 20),
      content_width: intRange(merged.components && merged.components.content_width, 70, 100, 100),
      icon_style: pick(merged.components && merged.components.icon_style, ICON_STYLES, 'rounded'),
      show_dividers: bool(merged.components && merged.components.show_dividers, false)
    },
    typography: {
      font_family: pick(merged.typography && merged.typography.font_family, FONTS, 'system'),
      heading_font: pick(merged.typography && merged.typography.heading_font, FONTS, 'system'),
      body_font: pick(merged.typography && merged.typography.body_font, FONTS, 'system'),
      base_size: intRange(merged.typography && merged.typography.base_size, 11, 20, 14),
      heading_scale: floatRange(merged.typography && merged.typography.heading_scale, 1, 1.7, 1.25),
      button_weight: intRange(merged.typography && merged.typography.button_weight, 400, 800, 600),
      line_height: floatRange(merged.typography && merged.typography.line_height, 1.15, 2, 1.5),
      letter_spacing: floatRange(merged.typography && merged.typography.letter_spacing, -1, 2, 0)
    },
    layout: {
      page_padding: intRange(merged.layout && merged.layout.page_padding, 8, 32, 16),
      section_gap: intRange(merged.layout && merged.layout.section_gap, 8, 48, 20),
      max_width: intRange(merged.layout && merged.layout.max_width, 320, 800, 600),
      safe_area: bool(merged.layout && merged.layout.safe_area, true),
      bottom_nav_height: intRange(merged.layout && merged.layout.bottom_nav_height, 48, 88, 64)
    }
  };
}

/**
 * Validate and normalize a design payload. Returns a sanitized design even when
 * non-critical values were clamped.
 */
function validateDesign(payload, appConfig = {}) {
  const errors = [];
  const warnings = [];
  let input = payload;

  if (typeof input === 'string') {
    if (!input.trim()) {
      input = null;
    } else if (input.length > 200000) {
      errors.push('Design configuration is too large.');
      return { valid: false, errors, warnings, design: defaultDesign(appConfig) };
    } else {
      try {
        input = JSON.parse(input);
      } catch (err) {
        errors.push(`Design JSON is invalid: ${err.message}`);
        return { valid: false, errors, warnings, design: defaultDesign(appConfig) };
      }
    }
  }

  if (input !== undefined && input !== null && !isObject(input)) {
    errors.push('Design configuration must be a JSON object.');
    return { valid: false, errors, warnings, design: defaultDesign(appConfig) };
  }

  const design = sanitizeDesign(input || {}, appConfig);
  const sectionCount = design.homepage.sections.length;
  const navCount = design.navigation.items.length;
  const menuCount = design.menu.items.length;

  if (sectionCount >= 20) warnings.push('Homepage section limit reached (20).');
  if (navCount >= 8) warnings.push('Navigation item limit reached (8).');
  if (menuCount >= 40) warnings.push('Menu item limit reached (40).');
  if (design.homepage.enabled && sectionCount === 0) {
    warnings.push('Custom homepage is enabled but has no sections; the app will show a welcome fallback.');
  }

  return { valid: errors.length === 0, errors, warnings, design };
}

/**
 * Merge the design palette into the core app config used by Android resources.
 * Light palette is used for compile-time resources; the runtime shell selects
 * dark/auto palette based on the device setting.
 */
function applyDesignToConfig(config, design) {
  const palette = design.theme.mode === 'dark' ? design.theme.dark : design.theme;
  return {
    ...config,
    primary_color: palette.primary_color,
    background_color: palette.background_color,
    status_bar_color: palette.status_bar_color,
    navigation_bar_color: palette.navigation_bar_color,
    splash_background_color: design.theme.background_color
  };
}

function designSummary(design) {
  return {
    schema_version: design.schema_version,
    theme_mode: design.theme.mode,
    header_enabled: design.header.enabled,
    navigation_type: design.navigation.type,
    navigation_items: design.navigation.items.length,
    menu_items: design.menu.items.length,
    menu_groups: design.menu.groups.length,
    homepage_enabled: design.homepage.enabled,
    homepage_sections: design.homepage.sections.length,
    component_preset: design.components.style_preset,
    typography_font: design.typography.body_font,
    features: {
      header: design.header.enabled,
      navigation: design.navigation.type !== 'none' && design.navigation.items.length > 0,
      menu: design.menu.items.length > 0,
      homepage: design.homepage.enabled || design.homepage.sections.length > 0,
      custom_html: design.homepage.sections.some(section => section.type === 'custom_html')
    }
  };
}

module.exports = {
  SCHEMA_VERSION,
  COLOR_FIELDS,
  FONTS,
  ICONS,
  MENU_TYPES,
  NAV_TYPES,
  SECTION_TYPES,
  defaultDesign,
  sanitizeDesign,
  validateDesign,
  applyDesignToConfig,
  designSummary,
  sanitizeHtml
};
