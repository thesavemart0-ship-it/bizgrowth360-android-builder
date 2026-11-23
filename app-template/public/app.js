/**
 * Bizgrowth360 Android App Shell
 *
 * Renders the native app home shell from the validated Design Studio config.
 * When no design is configured, preserves the original website-first behavior.
 */
(function () {
  'use strict';

  var config = window.APP_CONFIG || {};
  var design = config.design || {};
  var shell = document.getElementById('bg360-app-shell');
  var legacy = document.getElementById('bg360-legacy-shell');
  var appTitleEl = document.getElementById('app-title');
  var targetUrlEl = document.getElementById('target-url-label');
  var loadingStateEl = document.getElementById('loading-state');
  var errorStateEl = document.getElementById('error-state');
  var retryBtn = document.getElementById('retry-btn');
  var btnText = retryBtn ? retryBtn.querySelector('.btn-text') : null;
  var btnSpinner = retryBtn ? retryBtn.querySelector('.btn-spinner') : null;
  var loadingStatusEl = document.getElementById('loading-status');
  var state = { active: 'home', drawer: false, search: false, pendingUrl: '' };
  var retrying = false;

  var ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>',
    menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
    cart: '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12h11L21 7H6"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    link: '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
    star: '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9Z"/>',
    heart: '<path d="M20.8 5.6a5.5 5.5 0 0 0-7.8 0L12 6.6l-1-1a5.5 5.5 0 0 0-7.8 7.8L12 22l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    whatsapp: '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.5L3 21l1.9-5.4A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 9c1 3 3 5 6 6l1.5-2 2.5 1.2c-.3 2-1.7 2.8-3 2.8-4 0-8-4-8-8 0-1.3.8-2.7 2.8-3L11 8.5 9 10"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m21 15-5-5L5 21"/>',
    'chevron-right': '<path d="m9 18 6-6-6-6"/>',
    more: '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
    website: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
    shop: '<path d="M4 7h16l-1.5 14h-13L4 7Z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/>',
    bag: '<rect x="4" y="7" width="16" height="14" rx="2"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
    location: '<path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    play: '<path d="m8 5 11 7-11 7V5Z"/>'
  };

  var FONT_STACKS = {
    system: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    arial: 'Arial, Helvetica, sans-serif',
    georgia: 'Georgia, "Times New Roman", serif',
    verdana: 'Verdana, Geneva, sans-serif',
    trebuchet: '"Trebuchet MS", Helvetica, sans-serif',
    tahoma: 'Tahoma, Geneva, sans-serif',
    times: '"Times New Roman", Times, serif',
    courier: '"Courier New", Courier, monospace'
  };

  function esc(value) {
    return String(value === undefined || value === null ? '' : value).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
    });
  }

  function attr(value) {
    return esc(value);
  }

  function safeUrl(value) {
    value = String(value || '').trim();
    if (!value || value === '#') return '';
    if (/^(https?:|mailto:|tel:|sms:|whatsapp:|app:)/i.test(value)) return value;
    if (/^\//.test(value)) {
      try {
        return new URL(value, config.websiteUrl || 'https://example.com').toString();
      } catch (e) {
        return '';
      }
    }
    return '';
  }

  function safeHtml(value) {
    try {
      var doc = new DOMParser().parseFromString(String(value || ''), 'text/html');
      doc.querySelectorAll('script,style,iframe,object,embed,form,input,button,textarea,select,link,meta').forEach(function (node) {
        node.remove();
      });
      doc.querySelectorAll('*').forEach(function (node) {
        Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
          if (/^on/i.test(attribute.name)) node.removeAttribute(attribute.name);
          if ((attribute.name === 'href' || attribute.name === 'src') && /^\s*javascript:/i.test(attribute.value)) {
            node.removeAttribute(attribute.name);
          }
        });
      });
      return doc.body.innerHTML;
    } catch (e) {
      return esc(value);
    }
  }

  function icon(name, cls) {
    return '<svg class="bg360-icon ' + attr(cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || ICONS.link) + '</svg>';
  }

  function contrast(hex) {
    var value = String(hex || '#FFFFFF').replace('#', '');
    if (value.length === 3) value = value.charAt(0) + value.charAt(0) + value.charAt(1) + value.charAt(1) + value.charAt(2) + value.charAt(2);
    var r = parseInt(value.substring(0, 2), 16) || 0;
    var g = parseInt(value.substring(2, 4), 16) || 0;
    var b = parseInt(value.substring(4, 6), 16) || 0;
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.62 ? '#111827' : '#FFFFFF';
  }

  function currentMode() {
    var mode = (design.theme && design.theme.mode) || 'light';
    if (mode === 'auto') {
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    return mode === 'dark' ? 'dark' : 'light';
  }

  function palette() {
    var theme = design.theme || {};
    if (currentMode() === 'dark' && theme.dark) return theme.dark;
    return theme;
  }

  function applyTheme() {
    var theme = design.theme || {};
    var p = palette();
    var components = design.components || {};
    var typography = design.typography || {};
    var layout = design.layout || {};
    var header = design.header || {};
    var root = document.documentElement;
    var vars = {
      '--bg360-primary': p.primary_color || config.primaryColor || '#2563EB',
      '--bg360-secondary': p.secondary_color || '#0F172A',
      '--bg360-accent': p.accent_color || '#F59E0B',
      '--bg360-bg': p.background_color || config.backgroundColor || '#FFFFFF',
      '--bg360-card': p.card_color || '#F8FAFC',
      '--bg360-text': p.text_color || '#111827',
      '--bg360-heading': p.heading_color || '#0F172A',
      '--bg360-button': p.button_color || p.primary_color || '#2563EB',
      '--bg360-button-text': p.button_text_color || '#FFFFFF',
      '--bg360-border': p.border_color || '#E2E8F0',
      '--bg360-status': p.status_bar_color || config.statusBarColor || '#2563EB',
      '--bg360-navbar': p.navigation_bar_color || config.navigationBarColor || '#FFFFFF',
      '--bg360-card-radius': (components.card_radius || 12) + 'px',
      '--bg360-button-radius': (components.button_radius || 10) + 'px',
      '--bg360-card-spacing': (components.card_spacing || 12) + 'px',
      '--bg360-section-spacing': (components.section_spacing || 20) + 'px',
      '--bg360-content-width': (components.content_width || 100) + '%',
      '--bg360-header-height': (header.height || 56) + 'px',
      '--bg360-nav-height': (layout.bottom_nav_height || 64) + 'px',
      '--bg360-page-padding': (layout.page_padding || 16) + 'px',
      '--bg360-section-gap': (layout.section_gap || 20) + 'px',
      '--bg360-base-size': (typography.base_size || 14) + 'px',
      '--bg360-line-height': String(typography.line_height || 1.5),
      '--bg360-heading-scale': String(typography.heading_scale || 1.25),
      '--bg360-button-weight': String(typography.button_weight || 600),
      '--bg360-letter-spacing': String(typography.letter_spacing || 0) + 'px',
      '--bg360-body-font': FONT_STACKS[typography.body_font] || FONT_STACKS[typography.font_family] || FONT_STACKS.system,
      '--bg360-heading-font': FONT_STACKS[typography.heading_font] || FONT_STACKS[typography.font_family] || FONT_STACKS.system,
      '--bg360-header-bg': header.background_color || p.primary_color || '#2563EB',
      '--bg360-header-text': header.text_color || '#FFFFFF',
      '--bg360-shadow': ({ none: 'none', soft: '0 2px 8px rgba(15,23,42,.08)', medium: '0 8px 20px rgba(15,23,42,.12)', strong: '0 14px 34px rgba(15,23,42,.18)' })[components.shadow] || '0 2px 8px rgba(15,23,42,.08)',
      '--bg360-card-border': components.card_style === 'outlined' ? '1px solid var(--bg360-border)' : 'none',
      '--bg360-card-bg': components.card_style === 'flat' ? 'transparent' : 'var(--bg360-card)',
      '--bg360-image-ratio': ({ '1:1': '1 / 1', '4:3': '4 / 3', '16:9': '16 / 9', '3:2': '3 / 2' })[components.image_ratio] || '16 / 9',
      '--primary-color': p.primary_color || '#2563EB',
      '--bg-color': p.background_color || '#FFFFFF',
      '--text-color': p.text_color || '#111827',
      '--text-muted': p.text_color || '#6B7280'
    };
    Object.keys(vars).forEach(function (key) { root.style.setProperty(key, vars[key]); });
    document.body.classList.toggle('bg360-dark', currentMode() === 'dark');
    document.body.classList.toggle('bg360-show-dividers', !!components.show_dividers);
    document.body.classList.toggle('bg360-icons-outline', components.icon_style === 'outline');
    document.body.classList.toggle('bg360-icons-filled', components.icon_style === 'filled');
    document.body.classList.toggle('bg360-buttons-outline', components.button_style === 'outline');
    document.body.classList.toggle('bg360-buttons-pill', components.button_style === 'pill');
    document.body.classList.toggle('bg360-nonsticky', header.sticky === false);
    document.body.classList.toggle('bg360-no-safe-area', layout.safe_area === false);
    document.body.classList.toggle('bg360-nav-transparent', (design.navigation || {}).style === 'transparent');
    document.body.classList.toggle('bg360-nav-floating', (design.navigation || {}).style === 'floating');
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', p.status_bar_color || p.primary_color || '#2563EB');
    if (config.appName) document.title = config.appName;
  }

  function applyNativeChrome() {
    var capacitor = window.Capacitor;
    if (!capacitor || !capacitor.Plugins || !capacitor.Plugins.StatusBar) return;
    var statusBar = capacitor.Plugins.StatusBar;
    var p = palette();
    var color = p.status_bar_color || config.statusBarColor || p.primary_color || '#2563EB';
    try {
      if (config.enableStatusBar === false) {
        statusBar.hide();
        return;
      }
      if (statusBar.setBackgroundColor) statusBar.setBackgroundColor({ color: color });
      if (statusBar.setStyle) statusBar.setStyle({ style: contrast(color) === '#111827' ? 'DARK' : 'LIGHT' });
    } catch (e) {
      // best effort
    }
  }

  function hasDesignShell() {
    var homepage = design.homepage || {};
    var navigation = design.navigation || {};
    var menu = design.menu || {};
    return !!(
      homepage.enabled ||
      (homepage.sections || []).length ||
      ((navigation.type || 'none') !== 'none' && (navigation.items || []).length) ||
      (menu.items || []).length
    );
  }

  function headerTitle() {
    var header = design.header || {};
    return header.title || config.appName || 'Your App';
  }

  function headerActions() {
    var header = design.header || {};
    var actions = [];
    if (header.show_search) actions.push({ action: 'search', icon: 'search', label: 'Search' });
    if (header.show_notifications && header.notification_url) actions.push({ action: 'notifications', icon: 'bell', label: 'Notifications' });
    if (header.show_cart) actions.push({ action: 'cart', icon: 'cart', label: 'Cart' });
    if (header.show_profile) actions.push({ action: 'profile', icon: 'user', label: 'Profile' });
    (header.actions || []).forEach(function (action, index) {
      if (action.enabled && action.url) actions.push({ action: 'custom', customIndex: index, icon: action.icon, label: action.label || 'Action' });
    });
    return actions;
  }

  function renderHeader() {
    var header = design.header || {};
    if (header.enabled === false) return '';
    var nav = design.navigation || {};
    var menu = design.menu || {};
    var showMenu = nav.type === 'drawer' || (menu.items || []).length > 0;
    var actions = headerActions();
    var logo = '';
    if (header.show_logo) {
      logo = header.logo_url
        ? '<img class="bg360-logo" src="' + attr(safeUrl(header.logo_url)) + '" alt="" />'
        : '<span class="bg360-logo bg360-logo-letter">' + esc((config.appName || 'A').charAt(0).toUpperCase()) + '</span>';
    }
    return '<header class="bg360-header">' +
      (showMenu ? '<button type="button" class="bg360-icon-btn" data-app-action="menu" aria-label="Open menu">' + icon('menu') + '</button>' : '') +
      logo +
      '<span class="bg360-title">' + esc(headerTitle()) + '</span>' +
      '<span class="bg360-header-actions">' + actions.map(function (action) {
        return '<button type="button" class="bg360-icon-btn" data-app-action="' + attr(action.action) + '"' + (action.customIndex !== undefined ? ' data-custom-index="' + action.customIndex + '"' : '') + ' aria-label="' + attr(action.label) + '">' + icon(action.icon) + '</button>';
      }).join('') + '</span></header>';
  }

  function renderMain() {
    var homepage = design.homepage || {};
    var sections = (homepage.sections || []).filter(function (section) { return section.enabled; });
    if (!sections.length) {
      return '<main class="bg360-main"><div class="bg360-empty-home">' +
        '<div class="bg360-empty-logo">' + icon('home') + '</div>' +
        '<h2>' + esc(config.appName || 'Your App') + '</h2>' +
        '<p>Stay connected with everything we offer.</p>' +
        '<button type="button" class="bg360-button" data-app-action="website">Open Website</button>' +
        '</div></main>';
    }
    return '<main class="bg360-main">' + sections.map(renderSection).join('') + '</main>';
  }

  function renderSection(section) {
    var type = section.type;
    var layout = section.layout || 'default';
    var title = section.title ? '<h2>' + esc(section.title) + '</h2>' : '';
    var subtitle = section.subtitle ? '<p class="bg360-subtitle">' + esc(section.subtitle) + '</p>' : '';
    var text = section.text && type !== 'custom_html' ? '<p class="bg360-text">' + esc(section.text) + '</p>' : '';
    var button = section.button_label ? '<button type="button" class="bg360-button" data-app-url="' + attr(safeUrl(section.link_url) || config.websiteUrl) + '" data-app-type="website">' + esc(section.button_label) + '</button>' : '';
    var image = section.image_url ? '<div class="bg360-section-image" style="background-image:url(&quot;' + attr(safeUrl(section.image_url)) + '&quot;)"></div>' : '';
    var items = section.items || [];

    if (type === 'hero') {
      return '<section class="bg360-section bg360-hero">' + image + '<div class="bg360-hero-copy">' + title + subtitle + text + button + '</div></section>';
    }
    if (type === 'promo') {
      return '<section class="bg360-section bg360-promo">' + image + '<div class="bg360-promo-copy">' + title + text + button + '</div></section>';
    }
    if (type === 'welcome') {
      return '<section class="bg360-section bg360-panel-section">' + title + subtitle + text + button + '</section>';
    }
    if (type === 'custom_html') {
      return '<section class="bg360-section bg360-panel-section bg360-html">' + safeHtml(section.text) + '</section>';
    }
    if (type === 'contact') {
      return '<section class="bg360-section bg360-panel-section bg360-contact">' + title + text + button + '</section>';
    }
    if (type === 'slider') {
      return '<section class="bg360-section bg360-layout-' + attr(layout) + '">' + title + '<div class="bg360-slider">' + items.map(function (item) {
        return '<button type="button" class="bg360-slide" data-app-url="' + attr(safeUrl(item.url)) + '">' + imageHtml(item.image_url) + '<strong>' + esc(item.title || item.label) + '</strong><span>' + esc(item.subtitle) + '</span></button>';
      }).join('') + '</div></section>';
    }
    if (type === 'image_cards' || type === 'categories') {
      return '<section class="bg360-section bg360-layout-' + attr(layout) + '">' + title + '<div class="bg360-grid">' + items.map(function (item) {
        return '<button type="button" class="bg360-image-card" data-app-url="' + attr(safeUrl(item.url)) + '">' + imageHtml(item.image_url) + '<span>' + esc(item.title || item.label) + '</span></button>';
      }).join('') + '</div></section>';
    }
    if (type === 'products') {
      return '<section class="bg360-section bg360-layout-' + attr(layout) + '">' + title + '<div class="bg360-grid">' + items.map(function (item) {
        return '<button type="button" class="bg360-product" data-app-url="' + attr(safeUrl(item.url)) + '">' + imageHtml(item.image_url) + '<strong>' + esc(item.title || item.label) + '</strong><span>' + esc(item.meta || item.subtitle) + '</span></button>';
      }).join('') + '</div></section>';
    }
    if (type === 'latest_posts' || type === 'featured_content') {
      return '<section class="bg360-section bg360-layout-' + attr(layout) + '">' + title + '<div class="bg360-list">' + items.map(function (item) {
        return '<button type="button" class="bg360-list-item" data-app-url="' + attr(safeUrl(item.url)) + '">' + imageHtml(item.image_url, 'bg360-thumb') + '<span class="bg360-list-copy"><strong>' + esc(item.title || item.label) + '</strong><small>' + esc(item.subtitle || item.text) + '</small></span>' + icon('chevron-right') + '</button>';
      }).join('') + '</div></section>';
    }
    if (type === 'featured_links' || type === 'quick_actions') {
      return '<section class="bg360-section bg360-layout-' + attr(layout) + '">' + title + '<div class="bg360-quick-grid">' + items.map(function (item) {
        return '<button type="button" class="bg360-quick-item" data-app-url="' + attr(safeUrl(item.url)) + '"><span class="bg360-quick-icon">' + icon(item.icon || 'link') + '</span><span>' + esc(item.label || item.title) + '</span></button>';
      }).join('') + '</div></section>';
    }
    return '<section class="bg360-section bg360-panel-section bg360-layout-' + attr(layout) + '">' + title + subtitle + text + button + '</section>';
  }

  function imageHtml(url, cls) {
    if (!url) return '<span class="bg360-image ' + attr(cls || '') + ' bg360-image-placeholder">' + icon('image') + '</span>';
    return '<span class="bg360-image ' + attr(cls || '') + '" style="background-image:url(&quot;' + attr(safeUrl(url)) + '&quot;)"></span>';
  }

  function renderNavigation() {
    var nav = design.navigation || {};
    if (nav.type === 'none') return '';
    var items = (nav.items || []).filter(function (item) { return item.visible; });
    if (!items.length) return '';
    if (nav.type === 'bottom') {
      var visible = items.length > 5 ? items.slice(0, 4) : items.slice(0, 5);
      return '<nav class="bg360-bottom-nav">' + visible.map(function (item, index) {
        return '<button type="button" class="' + (state.active === 'home' && index === 0 ? 'is-active' : '') + '" data-app-nav="' + index + '">' + icon(item.icon) + '<span>' + esc(item.label) + '</span></button>';
      }).join('') + (items.length > 5 ? '<button type="button" data-app-action="menu">' + icon('more') + '<span>' + esc(nav.more_label || 'More') + '</span></button>' : '') + '</nav>';
    }
    if (nav.type === 'top') {
      return '<nav class="bg360-top-nav">' + items.slice(0, 6).map(function (item, index) {
        return '<button type="button" class="' + (state.active === 'home' && index === 0 ? 'is-active' : '') + '" data-app-nav="' + index + '">' + esc(item.label) + '</button>';
      }).join('') + '</nav>';
    }
    return '';
  }

  function renderDrawer() {
    var menu = design.menu || {};
    var groups = menu.groups || [];
    var items = (menu.items || []).filter(function (item) { return item.enabled; });
    var header = design.header || {};
    var body = '';
    if (!items.length) {
      body = '<p class="bg360-drawer-empty">No menu items configured.</p>';
    } else {
      groups.forEach(function (group) {
        var groupItems = items.filter(function (item) { return item.group === group.id; });
        if (!groupItems.length) return;
        body += '<span class="bg360-drawer-group">' + esc(group.label) + '</span>';
        groupItems.forEach(function (item) {
          body += '<button type="button" class="bg360-drawer-item" data-app-url="' + attr(safeUrl(item.url)) + '" data-app-type="' + attr(item.type) + '">' + icon(item.icon) + '<span>' + esc(item.label) + '</span></button>';
        });
      });
    }
    return '<div class="bg360-drawer ' + (state.drawer ? 'is-open' : '') + '" aria-hidden="' + (state.drawer ? 'false' : 'true') + '">' +
      '<div class="bg360-drawer-overlay" data-app-action="close-drawer"></div>' +
      '<aside class="bg360-drawer-panel"><div class="bg360-drawer-head">' +
      '<span class="bg360-logo bg360-logo-letter">' + esc((config.appName || 'A').charAt(0).toUpperCase()) + '</span>' +
      '<strong>' + esc(header.title || config.appName || 'Menu') + '</strong>' +
      '<button type="button" class="bg360-icon-btn" data-app-action="close-drawer" aria-label="Close menu">' + icon('chevron-right') + '</button>' +
      '</div><div class="bg360-drawer-body">' + body + '</div></aside></div>';
  }

  function renderSearch() {
    return '<div class="bg360-search ' + (state.search ? 'is-open' : '') + '" aria-hidden="' + (state.search ? 'false' : 'true') + '">' +
      '<div class="bg360-search-panel"><div class="bg360-search-head"><strong>Search</strong><button type="button" class="bg360-icon-btn" data-app-action="close-search" aria-label="Close search">' + icon('chevron-right') + '</button></div>' +
      '<form class="bg360-search-form"><input type="search" class="bg360-search-input" placeholder="Search the website..." autocomplete="off" /><button type="submit" class="bg360-button">Search</button></form></div></div>';
  }

  function renderShell() {
    if (!shell) return;
    applyTheme();
    document.body.classList.add('bg360-shell-active');
    if (legacy) legacy.hidden = true;
    shell.hidden = false;
    shell.innerHTML = renderHeader() + renderMain() + renderNavigation() + renderDrawer() + renderSearch();
    applyNativeChrome();
    if (state.search) {
      var input = shell.querySelector('.bg360-search-input');
      if (input) setTimeout(function () { input.focus(); }, 60);
    }
  }

  function navItems() {
    return ((design.navigation || {}).items || []).filter(function (item) { return item.visible; });
  }

  function goHome() {
    state.active = 'home';
    state.drawer = false;
    state.search = false;
    renderShell();
  }

  function openDrawer() {
    state.drawer = true;
    var drawer = shell.querySelector('.bg360-drawer');
    if (drawer) {
      drawer.classList.add('is-open');
      drawer.setAttribute('aria-hidden', 'false');
    }
  }

  function closeDrawer() {
    state.drawer = false;
    var drawer = shell.querySelector('.bg360-drawer');
    if (drawer) {
      drawer.classList.remove('is-open');
      drawer.setAttribute('aria-hidden', 'true');
    }
  }

  function openSearch() {
    state.search = true;
    var search = shell.querySelector('.bg360-search');
    if (search) {
      search.classList.add('is-open');
      search.setAttribute('aria-hidden', 'false');
      var input = search.querySelector('.bg360-search-input');
      if (input) setTimeout(function () { input.focus(); }, 60);
    }
  }

  function closeSearch() {
    state.search = false;
    var search = shell.querySelector('.bg360-search');
    if (search) {
      search.classList.remove('is-open');
      search.setAttribute('aria-hidden', 'true');
    }
  }

  function isNetworkUrl(url) {
    return /^https?:/i.test(url);
  }

  function openUrl(url, type) {
    url = safeUrl(url);
    if (!url) {
      url = safeUrl(config.websiteUrl);
    }
    if (!url) return;
    if (url.indexOf('app:') === 0) {
      if (url === 'app:home') return goHome();
      if (url === 'app:menu') return openDrawer();
      if (url === 'app:website') url = safeUrl(config.websiteUrl);
    }
    if (isNetworkUrl(url) && !navigator.onLine) {
      showOffline(url);
      return;
    }
    window.location.href = url;
  }

  function navigateItem(item) {
    if (!item) return;
    var type = item.type || 'website';
    if (type === 'home') return goHome();
    var url = item.url;
    if (type === 'website' && !url) url = config.websiteUrl;
    if (type === 'cart' && !url) url = config.websiteUrl;
    if (type === 'account' && !url) url = config.websiteUrl;
    if (!url) return;
    openUrl(url, type);
  }

  function handleShellClick(event) {
    var actionEl = event.target.closest('[data-app-action]');
    if (actionEl) {
      event.preventDefault();
      var action = actionEl.getAttribute('data-app-action');
      var header = design.header || {};
      if (action === 'menu') {
        if (state.drawer) closeDrawer(); else openDrawer();
      } else if (action === 'close-drawer') {
        closeDrawer();
      } else if (action === 'search') {
        openSearch();
      } else if (action === 'close-search') {
        closeSearch();
      } else if (action === 'website') {
        openUrl(config.websiteUrl, 'website');
      } else if (action === 'notifications') {
        openUrl(header.notification_url || config.websiteUrl, 'notifications');
      } else if (action === 'cart') {
        openUrl(header.cart_url || config.websiteUrl, 'cart');
      } else if (action === 'profile') {
        openUrl(header.profile_url || config.websiteUrl, 'account');
      } else if (action === 'custom') {
        var index = parseInt(actionEl.getAttribute('data-custom-index'), 10);
        var custom = (header.actions || [])[index];
        if (custom && custom.url) openUrl(custom.url, 'custom');
      }
      return;
    }

    var navEl = event.target.closest('[data-app-nav]');
    if (navEl) {
      event.preventDefault();
      var navIndex = parseInt(navEl.getAttribute('data-app-nav'), 10);
      navigateItem(navItems()[navIndex]);
      return;
    }

    var urlEl = event.target.closest('[data-app-url]');
    if (urlEl) {
      event.preventDefault();
      var url = urlEl.getAttribute('data-app-url');
      var type = urlEl.getAttribute('data-app-type') || 'website';
      if (!url) return;
      if (url.indexOf('app:') === 0) return openUrl(url, type);
      navigateItem({ type: type, url: url });
      return;
    }
  }

  function handleShellSubmit(event) {
    if (!event.target.closest('.bg360-search-form')) return;
    event.preventDefault();
    var input = event.target.querySelector('.bg360-search-input');
    var query = input ? input.value.trim() : '';
    if (!query) return;
    var header = design.header || {};
    var base = header.search_url || config.websiteUrl || '';
    var url = '';
    if (base.indexOf('{query}') !== -1) {
      url = base.replace('{query}', encodeURIComponent(query));
    } else {
      try {
        var parsed = new URL(base, config.websiteUrl || 'https://example.com');
        parsed.searchParams.set('s', query);
        url = parsed.toString();
      } catch (e) {
        url = (config.websiteUrl || '') + '?s=' + encodeURIComponent(query);
      }
    }
    openUrl(url, 'search');
  }

  function showLoading(message) {
    if (loadingStatusEl && message) loadingStatusEl.textContent = message;
    if (loadingStateEl) {
      loadingStateEl.classList.remove('hidden');
      loadingStateEl.classList.add('visible');
    }
    if (errorStateEl) {
      errorStateEl.classList.add('hidden');
      errorStateEl.classList.remove('visible');
    }
  }

  function showErrorScreen(url) {
    state.pendingUrl = url || config.websiteUrl || '';
    document.body.classList.remove('bg360-shell-active');
    if (shell) shell.hidden = true;
    if (legacy) legacy.hidden = false;
    if (loadingStateEl) {
      loadingStateEl.classList.add('hidden');
      loadingStateEl.classList.remove('visible');
    }
    if (errorStateEl) {
      errorStateEl.classList.remove('hidden');
      errorStateEl.classList.add('visible');
    }
    if (targetUrlEl) targetUrlEl.textContent = state.pendingUrl;
  }

  function showOffline(url) {
    showErrorScreen(url);
  }

  function retryNavigation() {
    if (retrying) return;
    retrying = true;
    if (retryBtn) retryBtn.disabled = true;
    if (btnText) btnText.textContent = 'Checking...';
    if (btnSpinner) btnSpinner.classList.remove('hidden');
    setTimeout(function () {
      retrying = false;
      if (retryBtn) retryBtn.disabled = false;
      if (btnText) btnText.textContent = 'Retry Connection';
      if (btnSpinner) btnSpinner.classList.add('hidden');
      if (navigator.onLine) {
        window.location.href = state.pendingUrl || config.websiteUrl || '';
      } else {
        showErrorScreen(state.pendingUrl);
      }
    }, 350);
  }

  function initLegacyWebsite() {
    if (targetUrlEl) targetUrlEl.textContent = config.websiteUrl || 'https://example.com';
    showLoading('Connecting to ' + (config.appName || 'website') + '...');
    if (navigator.onLine) {
      setTimeout(function () {
        showLoading('Loading website...');
        window.location.replace(config.websiteUrl || 'https://example.com');
      }, 300);
    } else {
      showErrorScreen(config.websiteUrl);
    }
  }

  function init() {
    applyTheme();
    applyNativeChrome();

    if (appTitleEl) appTitleEl.textContent = config.appName || 'Your App';
    document.title = config.appName || document.title;

    if (retryBtn) retryBtn.addEventListener('click', retryNavigation);
    window.addEventListener('online', function () {
      if (errorStateEl && errorStateEl.classList.contains('visible')) retryNavigation();
    });
    window.addEventListener('offline', function () {
      if (!hasDesignShell()) showErrorScreen(config.websiteUrl);
    });

    if (shell) {
      shell.addEventListener('click', handleShellClick);
      shell.addEventListener('submit', handleShellSubmit);
    }

    if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) {
      window.Capacitor.Plugins.App.addListener('backButton', function (status) {
        if (state.drawer) return closeDrawer();
        if (state.search) return closeSearch();
        if (status && status.canGoBack) window.history.back();
        else window.Capacitor.Plugins.App.exitApp();
      });
    }

    var params = new URLSearchParams(window.location.search);
    if (params.get('state') === 'offline') {
      showErrorScreen(config.websiteUrl);
      return;
    }

    if (hasDesignShell()) {
      renderShell();
    } else {
      initLegacyWebsite();
    }

    if (window.matchMedia) {
      var media = window.matchMedia('(prefers-color-scheme: dark)');
      var handler = function () {
        if ((design.theme || {}).mode === 'auto') {
          applyTheme();
          if (hasDesignShell()) renderShell();
          applyNativeChrome();
        }
      };
      if (media.addEventListener) media.addEventListener('change', handler);
      else if (media.addListener) media.addListener(handler);
    }
  }

  document.addEventListener('DOMContentLoaded', init);
  if (document.readyState !== 'loading') init();
})();
