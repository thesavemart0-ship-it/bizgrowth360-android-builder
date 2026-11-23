/**
 * Bizgrowth360 App Design Studio
 *
 * Client-side editor for the versioned design configuration. The server
 * sanitizes and persists the payload; this file never stores executable code.
 */
(function () {
	'use strict';

	var DS = window.bizgrowth360DesignStudio;
	if (!DS) {
		return;
	}

	var COLOR_LABELS = {
		primary_color: 'Primary',
		secondary_color: 'Secondary',
		accent_color: 'Accent',
		background_color: 'Background',
		card_color: 'Card background',
		text_color: 'Text',
		heading_color: 'Headings',
		button_color: 'Button',
		button_text_color: 'Button text',
		border_color: 'Border',
		status_bar_color: 'Status bar',
		navigation_bar_color: 'Navigation bar'
	};

	var SECTION_LABELS = {
		hero: 'Hero banner',
		slider: 'Image slider',
		welcome: 'Welcome text',
		featured_links: 'Featured links',
		quick_actions: 'Quick actions',
		categories: 'Categories',
		products: 'Products',
		featured_content: 'Featured content',
		latest_posts: 'Latest posts',
		promo: 'Promotional banner',
		image_cards: 'Image cards',
		custom_html: 'Custom HTML',
		contact: 'Contact section'
	};

	var MENU_LABELS = {
		home: 'Home',
		website: 'Website page',
		page: 'WordPress page',
		category: 'Category',
		external: 'External link',
		custom: 'Custom page',
		contact: 'Contact',
		whatsapp: 'WhatsApp',
		account: 'Account',
		cart: 'Cart'
	};

	var FONT_LABELS = {
		system: 'System default',
		arial: 'Arial',
		georgia: 'Georgia',
		verdana: 'Verdana',
		trebuchet: 'Trebuchet MS',
		tahoma: 'Tahoma',
		times: 'Times New Roman',
		courier: 'Courier New'
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

	var json = JSON.parse(JSON.stringify(DS.design || {}));
	var design = deepMerge(JSON.parse(JSON.stringify(DS.defaults || {})), json);
	var lastSaved = clone(design);
	var dirty = false;
	var saving = false;
	var savePending = false;
	var autosaveTimer = null;
	var previewMode = 'home';
	var previewDark = false;
	var previewSize = 'phone';
	var activePanel = 'theme';
	var idCounter = 0;
	var mediaFrame = null;

	function clone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function deepMerge(base, extra) {
		if (Array.isArray(base) || Array.isArray(extra)) {
			return extra === undefined ? base : extra;
		}
		if (isObject(base) && isObject(extra)) {
			var out = clone(base);
			Object.keys(extra).forEach(function (key) {
				out[key] = deepMerge(base[key], extra[key]);
			});
			return out;
		}
		return extra === undefined ? base : extra;
	}

	function isObject(value) {
		return value !== null && typeof value === 'object' && !Array.isArray(value);
	}

	function getPath(object, path) {
		return String(path).split('.').reduce(function (current, key) {
			return current === undefined || current === null ? undefined : current[key];
		}, object);
	}

	function setPath(object, path, value) {
		var parts = String(path).split('.');
		var current = object;
		for (var i = 0; i < parts.length - 1; i++) {
			var key = parts[i];
			if (current[key] === undefined || current[key] === null || typeof current[key] !== 'object') {
				current[key] = /^\d+$/.test(parts[i + 1]) ? [] : {};
			}
			current = current[key];
		}
		current[parts[parts.length - 1]] = value;
	}

	function esc(value) {
		return String(value === undefined || value === null ? '' : value).replace(/[&<>"']/g, function (char) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
		});
	}

	function attr(value) {
		return esc(value);
	}

	function uid(prefix) {
		idCounter += 1;
		return (prefix || 'item') + '_' + Date.now().toString(36) + '_' + idCounter;
	}

	function safeHex(value, fallback) {
		value = String(value || '').trim();
		return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value) ? value : (fallback || '#2563EB');
	}

	function safeUrl(value) {
		value = String(value || '').trim();
		if (!value) {
			return '#';
		}
		if (/^(https?:|mailto:|tel:|sms:|whatsapp:)/i.test(value)) {
			return value;
		}
		if (/^\//.test(value)) {
			return DS.home_url.replace(/\/$/, '') + value;
		}
		return 'https://' + value;
	}

	function sanitizePreviewHtml(html) {
		try {
			var doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
			doc.querySelectorAll('script,style,iframe,object,embed,form,input,button,textarea,select,link,meta').forEach(function (node) {
				node.remove();
			});
			doc.querySelectorAll('*').forEach(function (node) {
				Array.from(node.attributes).forEach(function (attribute) {
					if (/^on/i.test(attribute.name)) {
						node.removeAttribute(attribute.name);
					}
					if ((attribute.name === 'href' || attribute.name === 'src') && /^\s*javascript:/i.test(attribute.value)) {
						node.removeAttribute(attribute.name);
					}
				});
			});
			return doc.body.innerHTML;
		} catch (e) {
			return esc(html);
		}
	}

	function contrast(hex) {
		var value = safeHex(hex, '#FFFFFF').replace('#', '');
		if (value.length === 3) {
			value = value.charAt(0) + value.charAt(0) + value.charAt(1) + value.charAt(1) + value.charAt(2) + value.charAt(2);
		}
		var r = parseInt(value.substring(0, 2), 16);
		var g = parseInt(value.substring(2, 4), 16);
		var b = parseInt(value.substring(4, 6), 16);
		return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.62 ? '#111827' : '#FFFFFF';
	}

	function icon(name, extraClass) {
		var path = ICONS[name] || ICONS.link;
		return '<svg class="bg360-pv-icon ' + esc(extraClass || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
	}

	function optionList(values, labels) {
		return values.map(function (value) {
			return { value: value, label: labels && labels[value] ? labels[value] : value };
		});
	}

	function field(label, control, help, wide) {
		return '<div class="bg360-field' + (wide ? ' bg360-field-wide' : '') + '">' +
			'<label class="bg360-field-label">' + esc(label) + '</label>' +
			'<div class="bg360-field-control">' + control + '</div>' +
			(help ? '<p class="bg360-field-help">' + esc(help) + '</p>' : '') +
			'</div>';
	}

	function textField(path, label, help, type) {
		var value = getPath(design, path);
		return field(label, '<input type="' + (type || 'text') + '" data-path="' + attr(path) + '" value="' + attr(value) + '" class="regular-text" />', help);
	}

	function textareaField(path, label, help, rows) {
		var value = getPath(design, path);
		return field(label, '<textarea data-path="' + attr(path) + '" rows="' + (rows || 3) + '" class="large-text code">' + esc(value) + '</textarea>', help, true);
	}

	function numberField(path, label, min, max, step, help) {
		var value = getPath(design, path);
		return field(label, '<input type="number" data-path="' + attr(path) + '" data-number="true" min="' + attr(min) + '" max="' + attr(max) + '" step="' + attr(step || 1) + '" value="' + attr(value) + '" class="small-text" />', help);
	}

	function colorField(path, label) {
		var value = safeHex(getPath(design, path), '#FFFFFF');
		return field(label, '<div class="bg360-color-pair"><input type="color" data-path="' + attr(path) + '" data-color-picker="true" value="' + attr(value) + '" /><input type="text" data-path="' + attr(path) + '" data-color-hex="true" value="' + attr(value) + '" maxlength="7" spellcheck="false" /></div>');
	}

	function toggleField(path, label, help) {
		var checked = !!getPath(design, path);
		return '<label class="bg360-toggle-field">' +
			'<input type="checkbox" data-path="' + attr(path) + '" ' + (checked ? 'checked="checked"' : '') + ' />' +
			'<span><strong>' + esc(label) + '</strong>' + (help ? '<small>' + esc(help) + '</small>' : '') + '</span></label>';
	}

	function selectField(path, label, options, help) {
		var value = getPath(design, path);
		var html = options.map(function (option) {
			return '<option value="' + attr(option.value) + '" ' + (String(option.value) === String(value) ? 'selected="selected"' : '') + '>' + esc(option.label) + '</option>';
		}).join('');
		return field(label, '<select data-path="' + attr(path) + '">' + html + '</select>', help);
	}

	function mediaField(path, label, help) {
		var value = getPath(design, path);
		return field(label, '<div class="bg360-media-picker">' +
			'<input type="text" data-path="' + attr(path) + '" value="' + attr(value) + '" class="regular-text" placeholder="https://..." />' +
			'<button type="button" class="button" data-media-path="' + attr(path) + '">Choose image</button>' +
			'</div>' +
			(value ? '<div class="bg360-media-thumb"><img src="' + attr(safeUrl(value)) + '" alt="" /></div>' : ''), help);
	}

	function subheading(text, note) {
		return '<div class="bg360-subheading"><h4>' + esc(text) + '</h4>' + (note ? '<p>' + esc(note) + '</p>' : '') + '</div>';
	}

	function repeatHeader(title, index, listPath, enabledPath, canDelete) {
		return '<div class="bg360-repeat-head">' +
			'<div class="bg360-drag-handle" title="Drag to reorder">' + icon('menu') + '</div>' +
			'<strong>' + esc(title) + '</strong>' +
			(enabledPath ? '<label class="bg360-inline-toggle"><input type="checkbox" data-path="' + attr(enabledPath) + '" ' + (getPath(design, enabledPath) ? 'checked="checked"' : '') + ' /> Enabled</label>' : '') +
			'<div class="bg360-repeat-actions">' +
			'<button type="button" class="button button-small" data-action="move-up" data-list="' + attr(listPath) + '" data-index="' + index + '" title="Move up">↑</button>' +
			'<button type="button" class="button button-small" data-action="move-down" data-list="' + attr(listPath) + '" data-index="' + index + '" title="Move down">↓</button>' +
			(canDelete ? '<button type="button" class="button button-small button-link-delete" data-action="remove" data-list="' + attr(listPath) + '" data-index="' + index + '" title="Remove">×</button>' : '') +
			'</div></div>';
	}

	function repeatCard(title, index, listPath, enabledPath, body, canDrag) {
		return '<div class="bg360-repeat-card" ' + (canDrag ? 'draggable="true" data-drag-list="' + attr(listPath) + '" data-drag-index="' + index + '"' : '') + '>' +
			repeatHeader(title, index, listPath, enabledPath, true) +
			'<div class="bg360-repeat-body">' + body + '</div></div>';
	}

	function emptyMessage(text) {
		return '<p class="bg360-empty-message">' + esc(text) + '</p>';
	}

	/* ---------------------------------------------------------------- panels */

	function themePanel() {
		var light = DesignConfig('theme');
		var dark = light.dark || {};
		var presetHtml = Object.keys(DS.presets).map(function (key) {
			var preset = DS.presets[key];
			var colors = preset.colors || {};
			return '<button type="button" class="bg360-preset-card" data-action="preset" data-preset="' + attr(key) + '">' +
				'<span class="bg360-preset-swatches" style="--swatch-a:' + attr(colors.primary_color || '#2563EB') + ';--swatch-b:' + attr(colors.accent_color || '#F59E0B') + ';--swatch-c:' + attr(colors.background_color || '#FFFFFF') + ';"></span>' +
				'<span><strong>' + esc(preset.label) + '</strong><small>' + esc(preset.mode === 'dark' ? 'Dark preset' : 'Light preset') + '</small></span>' +
				'</button>';
		}).join('');

		return subheading('Preset palettes', 'Selecting a preset updates colors and the live preview immediately.') +
			'<div class="bg360-preset-grid">' + presetHtml + '</div>' +
			'<div class="bg360-field-grid">' +
			selectField('theme.mode', 'Appearance mode', [
				{ value: 'light', label: 'Light' },
				{ value: 'dark', label: 'Dark' },
				{ value: 'auto', label: 'Auto (follow device)' }
			], 'Auto follows the Android device light/dark setting in the app shell.') +
			'</div>' +
			subheading('Light palette', 'Used in light mode and as the base theme.') +
			'<div class="bg360-color-grid">' + Object.keys(COLOR_LABELS).map(function (key) {
				return colorField('theme.' + key, COLOR_LABELS[key]);
			}).join('') + '</div>' +
			'<details class="bg360-details" open="open"><summary>Dark mode palette</summary><div class="bg360-details-body">' +
			'<div class="bg360-color-grid">' + Object.keys(COLOR_LABELS).map(function (key) {
				return colorField('theme.dark.' + key, COLOR_LABELS[key]);
			}).join('') + '</div>' +
			'<button type="button" class="button" data-action="copy-dark">Copy light palette to dark</button>' +
			'</div></details>';
	}

	function DesignConfig(section) {
		return (design && design[section]) || {};
	}

	function headerPanel() {
		return '<div class="bg360-field-grid">' +
			toggleField('header.enabled', 'Show app header', 'Header appears on the native app home shell.') +
			toggleField('header.show_logo', 'Show logo', 'Uses the selected image or an app-name initial.') +
			toggleField('header.sticky', 'Sticky header', 'Keeps the header visible while scrolling the home content.') +
			textField('header.title', 'Header title', 'Leave empty to use the app name.') +
			numberField('header.height', 'Header height (px)', 44, 88, 1) +
			colorField('header.background_color', 'Header background') +
			colorField('header.text_color', 'Header text') +
			'</div>' +
			mediaField('header.logo_url', 'Header logo', 'Square or wide PNG/JPG/WebP. The app will not download it at build time.') +
			subheading('Header action buttons', 'Buttons are only rendered when they have a working destination. Search falls back to the website search.') +
			'<div class="bg360-field-grid">' +
			toggleField('header.show_search', 'Search button', 'Opens a search sheet and submits to the website search URL.') +
			toggleField('header.show_notifications', 'Notification button', 'Requires a notification URL.') +
			toggleField('header.show_cart', 'Cart button', 'Opens the cart URL; uses the website URL if empty.') +
			toggleField('header.show_profile', 'Profile button', 'Opens the account URL; uses the website URL if empty.') +
			textField('header.search_url', 'Search URL', 'Leave empty to use website_url/?s=query.') +
			textField('header.notification_url', 'Notification URL') +
			textField('header.cart_url', 'Cart URL') +
			textField('header.profile_url', 'Profile URL') +
			'</div>' +
			subheading('Custom action icons', 'Up to four extra header actions. Each needs a label and destination to appear in the app.') +
			renderHeaderActions() +
			'<button type="button" class="button" data-action="add-header-action">Add custom action</button>';
	}

	function renderHeaderActions() {
		var actions = DesignConfig('header').actions || [];
		if (!actions.length) {
			return emptyMessage('No custom header actions yet.');
		}
		return actions.map(function (action, index) {
			return repeatCard('Custom action ' + (index + 1), index, 'header.actions', 'header.actions.' + index + '.enabled',
				'<div class="bg360-field-grid">' +
				textField('header.actions.' + index + '.label', 'Label') +
				selectField('header.actions.' + index + '.icon', 'Icon', optionList(DS.icons)) +
				textField('header.actions.' + index + '.url', 'Destination URL') +
				'</div>', true);
		}).join('');
	}

	function navigationPanel() {
		var nav = DesignConfig('navigation');
		var count = (nav.items || []).filter(function (item) { return item.visible; }).length;
		return '<div class="bg360-field-grid">' +
			selectField('navigation.type', 'Navigation style', [
				{ value: 'none', label: 'None' },
				{ value: 'bottom', label: 'Bottom navigation' },
				{ value: 'drawer', label: 'Side drawer' },
				{ value: 'top', label: 'Top navigation' }
			], 'Bottom navigation is recommended for Android phones.') +
			selectField('navigation.style', 'Visual style', [
				{ value: 'solid', label: 'Solid' },
				{ value: 'transparent', label: 'Transparent' },
				{ value: 'floating', label: 'Floating' }
			]) +
			textField('navigation.more_label', 'More label', 'Used when more than five bottom items are configured.') +
			'</div>' +
			'<p class="bg360-field-help">Visible items: ' + esc(count) + '. Bottom navigation shows the first four plus a More button when needed. Maximum 8 items are stored.</p>' +
			subheading('Navigation items', 'Drag to reorder, or use the arrow buttons. Item URLs are validated on save.') +
			renderNavItems() +
			'<button type="button" class="button" data-action="add-nav-item">Add navigation item</button>';
	}

	function renderNavItems() {
		var items = DesignConfig('navigation').items || [];
		if (!items.length) {
			return emptyMessage('No navigation items yet. Add items or keep navigation disabled.');
		}
		return items.map(function (item, index) {
			return repeatCard('Navigation item ' + (index + 1), index, 'navigation.items', 'navigation.items.' + index + '.visible',
				'<div class="bg360-field-grid">' +
				textField('navigation.items.' + index + '.label', 'Label') +
				selectField('navigation.items.' + index + '.icon', 'Icon', optionList(DS.icons)) +
				selectField('navigation.items.' + index + '.type', 'Destination type', optionList(DS.menu_types, MENU_LABELS)) +
				textField('navigation.items.' + index + '.url', 'URL / destination') +
				'</div>', true);
		}).join('');
	}

	function menuPanel() {
		var menu = DesignConfig('menu');
		var groups = menu.groups || [];
		var groupHtml = groups.map(function (group, index) {
			return '<div class="bg360-group-row"><span class="bg360-drag-handle">' + icon('menu') + '</span>' +
				'<input type="text" data-path="menu.groups.' + index + '.label" value="' + attr(group.label) + '" class="regular-text" />' +
				'<button type="button" class="button button-small button-link-delete" data-action="remove-group" data-index="' + index + '" ' + (groups.length < 2 ? 'disabled="disabled"' : '') + '>Remove</button></div>';
		}).join('');
		return subheading('Menu groups', 'Groups appear as headings in the side drawer.') +
			'<div class="bg360-group-list">' + groupHtml + '</div>' +
			'<button type="button" class="button" data-action="add-menu-group">Add group</button>' +
			subheading('Menu items', 'These items populate the side drawer and can be used by navigation items.') +
			renderMenuItems() +
			'<button type="button" class="button" data-action="add-menu-item">Add menu item</button>';
	}

	function renderMenuItems() {
		var menu = DesignConfig('menu');
		var items = menu.items || [];
		var groups = menu.groups || [];
		if (!items.length) {
			return emptyMessage('No menu items yet. Add links for the drawer or More menu.');
		}
		var groupOptions = groups.map(function (group) { return { value: group.id, label: group.label }; });
		return items.map(function (item, index) {
			return repeatCard('Menu item ' + (index + 1), index, 'menu.items', 'menu.items.' + index + '.enabled',
				'<div class="bg360-field-grid">' +
				selectField('menu.items.' + index + '.group', 'Group', groupOptions) +
				textField('menu.items.' + index + '.label', 'Label') +
				selectField('menu.items.' + index + '.icon', 'Icon', optionList(DS.icons)) +
				selectField('menu.items.' + index + '.type', 'Type', optionList(DS.menu_types, MENU_LABELS)) +
				textField('menu.items.' + index + '.url', 'URL / destination') +
				'</div>', true);
		}).join('');
	}

	function homepagePanel() {
		var homepage = DesignConfig('homepage');
		var sections = homepage.sections || [];
		var sectionHtml = sections.map(function (section, index) {
			return renderSection(section, index);
		}).join('');

		return toggleField('homepage.enabled', 'Use a custom app homepage', 'When enabled, the app opens the native home shell. When disabled, it opens your website directly as before.') +
			'<p class="bg360-field-help">Sections are rendered in the native app shell. Website pages opened from a link keep the website’s own layout. Custom HTML is sanitized server-side.</p>' +
			'<div class="bg360-add-row"><select id="bg360-new-section-type">' +
			DS.section_types.map(function (type) {
				return '<option value="' + attr(type) + '">' + esc(SECTION_LABELS[type] || type) + '</option>';
			}).join('') +
			'</select><button type="button" class="button button-primary" data-action="add-section">Add section</button></div>' +
			'<div class="bg360-section-list">' + (sectionHtml || emptyMessage('No homepage sections yet. Add a hero, welcome text or quick actions.')) + '</div>';
	}

	function sectionHasItems(type) {
		return ['slider', 'featured_links', 'quick_actions', 'categories', 'products', 'featured_content', 'latest_posts', 'image_cards'].indexOf(type) !== -1;
	}

	function renderSection(section, index) {
		var type = section.type || 'welcome';
		var body = '<div class="bg360-field-grid">' +
			selectField('homepage.sections.' + index + '.type', 'Section type', optionList(DS.section_types, SECTION_LABELS)) +
			selectField('homepage.sections.' + index + '.layout', 'Layout', optionList(DS.section_layouts)) +
			textField('homepage.sections.' + index + '.title', 'Section title') +
			textField('homepage.sections.' + index + '.subtitle', 'Section subtitle') +
			textareaField('homepage.sections.' + index + '.text', 'Body text', type === 'custom_html' ? 'Allowed tags only: headings, paragraphs, lists, links, images, strong/em. Scripts, styles, iframes and event handlers are stripped.' : '', 4) +
			mediaField('homepage.sections.' + index + '.image_url', 'Section image / background') +
			textField('homepage.sections.' + index + '.link_url', 'Section button URL') +
			textField('homepage.sections.' + index + '.button_label', 'Button label') +
			'</div>';

		if (sectionHasItems(type)) {
			body += '<div class="bg360-subheading"><h4>Items</h4><p>Cards, slides or links for this section.</p></div>' + renderSectionItems(section.items || [], index, type) +
				'<button type="button" class="button" data-action="add-section-item" data-section="' + index + '">Add item</button>';
		}

		return '<div class="bg360-section-card" draggable="true" data-drag-list="homepage.sections" data-drag-index="' + index + '">' +
			'<div class="bg360-repeat-head"><div class="bg360-drag-handle">' + icon('menu') + '</div>' +
			'<strong>' + esc(SECTION_LABELS[type] || type) + (section.title ? ': ' + esc(section.title) : '') + '</strong>' +
			'<label class="bg360-inline-toggle"><input type="checkbox" data-path="homepage.sections.' + index + '.enabled" ' + (section.enabled ? 'checked="checked"' : '') + ' /> Enabled</label>' +
			'<div class="bg360-repeat-actions">' +
			'<button type="button" class="button button-small" data-action="move-up" data-list="homepage.sections" data-index="' + index + '">↑</button>' +
			'<button type="button" class="button button-small" data-action="move-down" data-list="homepage.sections" data-index="' + index + '">↓</button>' +
			'<button type="button" class="button button-small button-link-delete" data-action="remove" data-list="homepage.sections" data-index="' + index + '">×</button>' +
			'</div></div><div class="bg360-repeat-body">' + body + '</div></div>';
	}

	function renderSectionItems(items, sectionIndex, type) {
		if (!items.length) {
			return emptyMessage('No items yet. Add at least one item for this section to appear in the app.');
		}
		return '<div class="bg360-item-list">' + items.map(function (item, itemIndex) {
			var path = 'homepage.sections.' + sectionIndex + '.items.' + itemIndex;
			return '<div class="bg360-item-card" draggable="true" data-drag-list="homepage.sections.' + sectionIndex + '.items" data-drag-index="' + itemIndex + '">' +
				'<div class="bg360-item-head"><span class="bg360-drag-handle">' + icon('menu') + '</span><strong>Item ' + (itemIndex + 1) + '</strong>' +
				'<div class="bg360-repeat-actions"><button type="button" class="button button-small" data-action="move-up" data-list="homepage.sections.' + sectionIndex + '.items" data-index="' + itemIndex + '">↑</button>' +
				'<button type="button" class="button button-small" data-action="move-down" data-list="homepage.sections.' + sectionIndex + '.items" data-index="' + itemIndex + '">↓</button>' +
				'<button type="button" class="button button-small button-link-delete" data-action="remove" data-list="homepage.sections.' + sectionIndex + '.items" data-index="' + itemIndex + '">×</button></div></div>' +
				'<div class="bg360-field-grid">' +
				textField(path + '.label', 'Label') +
				textField(path + '.title', 'Title') +
				textField(path + '.subtitle', 'Subtitle') +
				textField(path + '.meta', type === 'products' ? 'Price / meta' : 'Meta text') +
				textField(path + '.url', 'URL') +
				mediaField(path + '.image_url', 'Image') +
				selectField(path + '.icon', 'Icon', optionList(DS.icons)) +
				'</div></div>';
		}).join('') + '</div>';
	}

	function componentsPanel() {
		return selectField('components.style_preset', 'Component preset', optionList(DS.component_presets, {
			modern: 'Modern', minimal: 'Minimal', rounded: 'Rounded', material: 'Material-inspired', compact: 'Compact', premium: 'Premium'
		}), 'Changing the preset updates the component values below. You can fine-tune them afterwards.') +
			'<div class="bg360-field-grid">' +
			numberField('components.card_radius', 'Card radius (px)', 0, 32, 1) +
			numberField('components.button_radius', 'Button radius (px)', 0, 32, 1) +
			selectField('components.card_style', 'Card style', optionList(DS.card_styles)) +
			selectField('components.button_style', 'Button style', optionList(DS.button_styles)) +
			selectField('components.image_ratio', 'Image aspect ratio', optionList(DS.image_ratios)) +
			selectField('components.shadow', 'Shadow', optionList(DS.shadows)) +
			numberField('components.card_spacing', 'Card spacing (px)', 0, 40, 1) +
			numberField('components.section_spacing', 'Section spacing (px)', 0, 48, 1) +
			numberField('components.content_width', 'Content width (%)', 70, 100, 1) +
			selectField('components.icon_style', 'Icon style', optionList(DS.icon_styles)) +
			toggleField('components.show_dividers', 'Show dividers between list items') +
			'</div>';
	}

	function typographyPanel() {
		return '<div class="bg360-field-grid">' +
			selectField('typography.font_family', 'Base font', optionList(DS.fonts, FONT_LABELS), 'Only device-safe fonts are used. No external font downloads are required.') +
			selectField('typography.heading_font', 'Heading font', optionList(DS.fonts, FONT_LABELS)) +
			selectField('typography.body_font', 'Body font', optionList(DS.fonts, FONT_LABELS)) +
			numberField('typography.base_size', 'Base font size (px)', 11, 20, 1) +
			numberField('typography.heading_scale', 'Heading scale', 1, 1.7, 0.05) +
			numberField('typography.button_weight', 'Button font weight', 400, 800, 100) +
			numberField('typography.line_height', 'Line height', 1.15, 2, 0.05) +
			numberField('typography.letter_spacing', 'Letter spacing (px)', -1, 2, 0.1) +
			'</div>' +
			'<p class="bg360-field-help">Typography is applied to the native app shell only. Website WebView content keeps the website’s typography.</p>';
	}

	function layoutPanel() {
		return '<div class="bg360-field-grid">' +
			numberField('layout.page_padding', 'Page padding (px)', 8, 32, 1) +
			numberField('layout.section_gap', 'Section gap (px)', 8, 48, 1) +
			numberField('layout.max_width', 'Max content width (px)', 320, 800, 1) +
			numberField('layout.bottom_nav_height', 'Bottom nav height (px)', 48, 88, 1) +
			toggleField('layout.safe_area', 'Respect Android safe areas', 'Keeps content away from notches and system bars.') +
			'</div>';
	}

	function previewPanel() {
		var sections = (DesignConfig('homepage').sections || []).filter(function (section) { return section.enabled; }).length;
		var navItems = (DesignConfig('navigation').items || []).filter(function (item) { return item.visible; }).length;
		var menuItems = (DesignConfig('menu').items || []).filter(function (item) { return item.enabled; }).length;
		return '<div class="bg360-preview-summary">' +
			'<div><strong>' + esc(sections) + '</strong><span>Homepage sections</span></div>' +
			'<div><strong>' + esc(navItems) + '</strong><span>Navigation items</span></div>' +
			'<div><strong>' + esc(menuItems) + '</strong><span>Menu items</span></div>' +
			'</div>' +
			'<div class="bg360-preview-controls">' +
			'<div class="bg360-preview-modes"><button type="button" class="button ' + (previewMode === 'home' ? 'is-active' : '') + '" data-preview-mode="home">Home</button><button type="button" class="button ' + (previewMode === 'menu' ? 'is-active' : '') + '" data-preview-mode="menu">Menu</button><button type="button" class="button ' + (previewMode === 'navigation' ? 'is-active' : '') + '" data-preview-mode="navigation">Navigation</button></div>' +
			'<select id="bg360-preview-size-panel"><option value="small" ' + (previewSize === 'small' ? 'selected="selected"' : '') + '>Small phone</option><option value="phone" ' + (previewSize === 'phone' ? 'selected="selected"' : '') + '>Phone</option><option value="large" ' + (previewSize === 'large' ? 'selected="selected"' : '') + '>Large phone</option><option value="tablet" ' + (previewSize === 'tablet' ? 'selected="selected"' : '') + '>Tablet</option></select>' +
			'<button type="button" class="button" data-action="toggle-preview-theme">' + icon('image') + ' Toggle light/dark</button>' +
			'</div>' +
			'<p class="bg360-field-help">The preview is interactive for editing only; links are disabled. Open the Home, Menu and Navigation modes to inspect the native shell.</p>' +
			'<div class="bg360-limitations"><h4>Supported in the Android app</h4><ul>' +
			'<li>Native home shell, header, menu drawer, bottom/top navigation.</li>' +
			'<li>Theme colors, typography, cards, buttons, spacing and section layouts.</li>' +
			'<li>Website pages and external links open in the WebView/browser.</li>' +
			'</ul><h4>Limitations</h4><ul>' +
			'<li>Remote website pages keep their own layout; the app header/navigation cannot be injected into arbitrary websites.</li>' +
			'<li>Product/category/post sections use the items you configure; they do not scrape the website automatically.</li>' +
			'<li>Custom HTML allows safe formatting tags only, never PHP or JavaScript.</li>' +
			'</ul></div>';
	}

	function resetPanel() {
		var saved = DS.saved_at ? new Date(DS.saved_at * 1000).toLocaleString() : 'Not saved yet';
		return '<div class="bg360-save-summary">' +
			'<div><strong>Schema version</strong><span>' + esc(DS.schema_version) + '</span></div>' +
			'<div><strong>Last saved</strong><span id="bg360-design-last-saved">' + esc(saved) + '</span></div>' +
			'<div><strong>Storage</strong><span>WordPress option (server sanitized)</span></div>' +
			'</div>' +
			subheading('Save and restore', 'Autosave runs after a short delay when enabled. The Save button writes immediately.') +
			'<div class="bg360-button-row"><button type="button" class="button button-primary" id="bg360-design-save-btn-2">Save Design</button>' +
			'<button type="button" class="button" data-action="discard">Discard unsaved changes</button></div>' +
			subheading('Reset', 'Reset is destructive and requires confirmation.') +
			'<div class="bg360-reset-row"><select id="bg360-reset-section"><option value="">Entire design</option><option value="theme">App Theme</option><option value="header">App Header</option><option value="navigation">Navigation</option><option value="menu">Menu Builder</option><option value="homepage">Homepage Builder</option><option value="components">App Components</option><option value="typography">Typography</option><option value="layout">Layout & Spacing</option></select>' +
			'<button type="button" class="button button-link-delete" data-action="reset-section">Reset selected</button>' +
			'<button type="button" class="button button-link-delete" data-action="reset-all">Reset entire design</button></div>';
	}

	function renderPanels() {
		var map = {
			theme: themePanel,
			header: headerPanel,
			navigation: navigationPanel,
			menu: menuPanel,
			homepage: homepagePanel,
			components: componentsPanel,
			typography: typographyPanel,
			layout: layoutPanel,
			preview: previewPanel,
			reset: resetPanel
		};
		Object.keys(map).forEach(function (key) {
			var target = document.getElementById('bg360-panel-' + key);
			if (target) {
				target.innerHTML = map[key]();
			}
		});
	}

	/* --------------------------------------------------------------- preview */

	function currentPalette() {
		var theme = design.theme || {};
		var mode = theme.mode || 'light';
		if (mode === 'auto') {
			mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
		}
		if (previewDark) {
			mode = mode === 'dark' ? 'light' : 'dark';
		}
		return mode === 'dark' ? (theme.dark || theme) : theme;
	}

	function applyPreviewVars() {
		var device = document.getElementById('bg360-studio-device');
		if (!device) {
			return;
		}
		var palette = currentPalette();
		var components = design.components || {};
		var typography = design.typography || {};
		var layout = design.layout || {};
		var header = design.header || {};
		var vars = {
			'--pv-primary': safeHex(palette.primary_color, '#2563EB'),
			'--pv-secondary': safeHex(palette.secondary_color, '#0F172A'),
			'--pv-accent': safeHex(palette.accent_color, '#F59E0B'),
			'--pv-bg': safeHex(palette.background_color, '#FFFFFF'),
			'--pv-card': safeHex(palette.card_color, '#F8FAFC'),
			'--pv-text': safeHex(palette.text_color, '#111827'),
			'--pv-heading': safeHex(palette.heading_color, '#0F172A'),
			'--pv-button': safeHex(palette.button_color, '#2563EB'),
			'--pv-button-text': safeHex(palette.button_text_color, '#FFFFFF'),
			'--pv-border': safeHex(palette.border_color, '#E2E8F0'),
			'--pv-status': safeHex(palette.status_bar_color, '#2563EB'),
			'--pv-navbar': safeHex(palette.navigation_bar_color, '#FFFFFF'),
			'--pv-card-radius': String(components.card_radius || 12) + 'px',
			'--pv-button-radius': String(components.button_radius || 10) + 'px',
			'--pv-card-spacing': String(components.card_spacing || 12) + 'px',
			'--pv-section-spacing': String(components.section_spacing || 20) + 'px',
			'--pv-content-width': String(components.content_width || 100) + '%',
			'--pv-header-height': String(header.height || 56) + 'px',
			'--pv-nav-height': String(layout.bottom_nav_height || 64) + 'px',
			'--pv-page-padding': String(layout.page_padding || 16) + 'px',
			'--pv-section-gap': String(layout.section_gap || 20) + 'px',
			'--pv-base-size': String(typography.base_size || 14) + 'px',
			'--pv-line-height': String(typography.line_height || 1.5),
			'--pv-heading-scale': String(typography.heading_scale || 1.25),
			'--pv-button-weight': String(typography.button_weight || 600),
			'--pv-letter-spacing': String(typography.letter_spacing || 0) + 'px',
			'--pv-heading-font': FONT_STACKS[typography.heading_font] || FONT_STACKS[typography.font_family] || FONT_STACKS.system,
			'--pv-body-font': FONT_STACKS[typography.body_font] || FONT_STACKS[typography.font_family] || FONT_STACKS.system,
			'--pv-header-bg': safeHex(header.background_color, palette.primary_color),
			'--pv-header-text': safeHex(header.text_color, '#FFFFFF'),
			'--pv-shadow': { none: 'none', soft: '0 2px 8px rgba(15,23,42,.08)', medium: '0 8px 20px rgba(15,23,42,.12)', strong: '0 14px 34px rgba(15,23,42,.18)' }[components.shadow] || '0 2px 8px rgba(15,23,42,.08)',
			'--pv-card-border': components.card_style === 'outlined' ? '1px solid var(--pv-border)' : 'none',
			'--pv-card-bg': components.card_style === 'flat' ? 'transparent' : 'var(--pv-card)'
		};
		Object.keys(vars).forEach(function (key) {
			device.style.setProperty(key, vars[key]);
		});
		device.classList.toggle('is-dark', currentPalette() === (design.theme || {}).dark && (design.theme || {}).mode !== 'light');
		device.setAttribute('data-size', previewSize);
	}

	function renderPreview() {
		var app = document.getElementById('bg360-preview-app');
		var drawer = document.getElementById('bg360-preview-drawer');
		if (!app || !drawer) {
			return;
		}
		applyPreviewVars();
		var palette = currentPalette();
		var header = renderPreviewHeader(palette);
		var content = renderPreviewHome(palette);
		var nav = renderPreviewNavigation(palette);
		app.innerHTML = '<div class="bg360-pv-statusbar" style="background:' + attr(palette.status_bar_color) + ';color:' + attr(contrast(palette.status_bar_color)) + '"><span>10:45</span><span class="bg360-pv-status-icons">' + icon('website') + icon('star') + '</span></div>' +
			header + '<main class="bg360-pv-main">' + content + '</main>' + nav;
		drawer.innerHTML = renderPreviewDrawer(palette);
		drawer.classList.toggle('is-open', previewMode === 'menu');
		app.classList.toggle('is-nav-focus', previewMode === 'navigation');
		var screen = document.getElementById('bg360-preview-screen');
		if (screen) {
			screen.scrollTop = 0;
		}
	}

	function renderPreviewHeader(palette) {
		var header = design.header || {};
		if (header.enabled === false) {
			return '';
		}
		var actions = [];
		if (header.show_search) actions.push('<button type="button" class="bg360-pv-icon-btn" data-preview-link="1" title="Search">' + icon('search') + '</button>');
		if (header.show_notifications && header.notification_url) actions.push('<button type="button" class="bg360-pv-icon-btn" data-preview-link="1" title="Notifications">' + icon('bell') + '</button>');
		if (header.show_cart) actions.push('<button type="button" class="bg360-pv-icon-btn" data-preview-link="1" title="Cart">' + icon('cart') + '</button>');
		if (header.show_profile) actions.push('<button type="button" class="bg360-pv-icon-btn" data-preview-link="1" title="Profile">' + icon('user') + '</button>');
		(header.actions || []).forEach(function (action) {
			if (action.enabled && action.url) {
				actions.push('<button type="button" class="bg360-pv-icon-btn" data-preview-link="1" title="' + attr(action.label) + '">' + icon(action.icon) + '</button>');
			}
		});
		var menuButton = ((design.navigation || {}).type === 'drawer' || ((design.menu || {}).items || []).length) ? '<button type="button" class="bg360-pv-icon-btn" data-preview-menu="1" title="Menu">' + icon('menu') + '</button>' : '';
		var logo = header.show_logo && header.logo_url ? '<img class="bg360-pv-logo" src="' + attr(safeUrl(header.logo_url)) + '" alt="" />' : '<span class="bg360-pv-logo bg360-pv-logo-letter">' + esc((DS.app_name || 'App').charAt(0).toUpperCase()) + '</span>';
		return '<header class="bg360-pv-header">' + menuButton + logo + '<span class="bg360-pv-title">' + esc(header.title || DS.app_name || 'Your App') + '</span><div class="bg360-pv-actions">' + actions.join('') + '</div></header>';
	}

	function renderPreviewHome(palette) {
		var homepage = design.homepage || {};
		var sections = (homepage.sections || []).filter(function (section) { return section.enabled; });
		if (!homepage.enabled) {
			return '<div class="bg360-pv-empty"><div class="bg360-pv-logo bg360-pv-logo-letter">' + icon('website') + '</div><h2>' + esc(DS.app_name || 'Your App') + '</h2><p>Enable the custom homepage in Homepage Builder to replace this website-first experience.</p><button type="button" class="bg360-pv-button" data-preview-link="1">Open Website</button></div>';
		}
		if (!sections.length) {
			return '<div class="bg360-pv-empty"><div class="bg360-pv-logo bg360-pv-logo-letter">' + icon('home') + '</div><h2>Welcome to ' + esc(DS.app_name || 'your app') + '</h2><p>Add a hero, welcome text or quick actions in Homepage Builder.</p><button type="button" class="bg360-pv-button" data-preview-link="1">Open Website</button></div>';
		}
		return sections.map(function (section) { return renderPreviewSection(section, palette); }).join('');
	}

	function renderPreviewSection(section, palette) {
		var type = section.type;
		var title = section.title ? '<h2>' + esc(section.title) + '</h2>' : '';
		var subtitle = section.subtitle ? '<p class="bg360-pv-subtitle">' + esc(section.subtitle) + '</p>' : '';
		var text = section.text && type !== 'custom_html' ? '<p class="bg360-pv-text">' + esc(section.text) + '</p>' : '';
		var button = section.button_label ? '<button type="button" class="bg360-pv-button" data-preview-link="1">' + esc(section.button_label) + '</button>' : '';
		var image = section.image_url ? '<div class="bg360-pv-section-image" style="background-image:url(&quot;' + attr(safeUrl(section.image_url)) + '&quot;)"></div>' : '';
		var items = section.items || [];

		if (type === 'hero') {
			return '<section class="bg360-pv-section bg360-pv-hero">' + image + '<div class="bg360-pv-hero-copy">' + title + subtitle + text + button + '</div></section>';
		}
		if (type === 'promo') {
			return '<section class="bg360-pv-section bg360-pv-promo">' + image + '<div class="bg360-pv-promo-copy">' + title + text + button + '</div></section>';
		}
		if (type === 'welcome') {
			return '<section class="bg360-pv-section bg360-pv-welcome">' + title + subtitle + text + button + '</section>';
		}
		if (type === 'custom_html') {
			return '<section class="bg360-pv-section bg360-pv-html">' + sanitizePreviewHtml(section.text) + '</section>';
		}
		if (type === 'contact') {
			return '<section class="bg360-pv-section bg360-pv-contact">' + title + text + button + '</section>';
		}
		if (type === 'slider') {
			return '<section class="bg360-pv-section">' + title + '<div class="bg360-pv-slider">' + items.map(function (item) {
				return '<div class="bg360-pv-slide" data-preview-link="1">' + previewImage(item.image_url) + '<strong>' + esc(item.title || item.label) + '</strong><span>' + esc(item.subtitle) + '</span></div>';
			}).join('') + '</div></section>';
		}
		if (type === 'image_cards' || type === 'categories') {
			return '<section class="bg360-pv-section">' + title + '<div class="bg360-pv-grid">' + items.map(function (item) {
				return '<button type="button" class="bg360-pv-image-card" data-preview-link="1">' + previewImage(item.image_url) + '<span>' + esc(item.title || item.label) + '</span></button>';
			}).join('') + '</div></section>';
		}
		if (type === 'products') {
			return '<section class="bg360-pv-section">' + title + '<div class="bg360-pv-grid">' + items.map(function (item) {
				return '<button type="button" class="bg360-pv-product" data-preview-link="1">' + previewImage(item.image_url) + '<strong>' + esc(item.title || item.label) + '</strong><span>' + esc(item.meta || item.subtitle) + '</span></button>';
			}).join('') + '</div></section>';
		}
		if (type === 'latest_posts' || type === 'featured_content') {
			return '<section class="bg360-pv-section">' + title + '<div class="bg360-pv-list">' + items.map(function (item) {
				return '<button type="button" class="bg360-pv-list-item" data-preview-link="1">' + previewImage(item.image_url) + '<span><strong>' + esc(item.title || item.label) + '</strong><small>' + esc(item.subtitle || item.text) + '</small></span>' + icon('chevron-right') + '</button>';
			}).join('') + '</div></section>';
		}
		if (type === 'featured_links' || type === 'quick_actions') {
			return '<section class="bg360-pv-section">' + title + '<div class="bg360-pv-quick">' + items.map(function (item) {
				return '<button type="button" class="bg360-pv-quick-item" data-preview-link="1"><span class="bg360-pv-quick-icon">' + icon(item.icon || 'link') + '</span><span>' + esc(item.label || item.title) + '</span></button>';
			}).join('') + '</div></section>';
		}
		return '<section class="bg360-pv-section">' + title + subtitle + text + button + '</section>';
	}

	function previewImage(url) {
		return url ? '<span class="bg360-pv-image" style="background-image:url(&quot;' + attr(safeUrl(url)) + '&quot;)"></span>' : '<span class="bg360-pv-image bg360-pv-image-placeholder">' + icon('image') + '</span>';
	}

	function renderPreviewNavigation(palette) {
		var nav = design.navigation || {};
		if (nav.type === 'none') {
			return '';
		}
		var items = (nav.items || []).filter(function (item) { return item.visible; });
		if (!items.length) {
			return '';
		}
		if (nav.type === 'bottom') {
			var visible = items.slice(0, 5);
			var overflow = items.length > 5;
			if (overflow) {
				visible = items.slice(0, 4);
			}
			return '<nav class="bg360-pv-bottom-nav">' + visible.map(function (item, index) {
				return '<button type="button" class="' + (index === 0 ? 'is-active' : '') + '" data-preview-link="1">' + icon(item.icon) + '<span>' + esc(item.label) + '</span></button>';
			}).join('') + (overflow ? '<button type="button" data-preview-menu="1">' + icon('more') + '<span>' + esc(nav.more_label || 'More') + '</span></button>' : '') + '</nav>';
		}
		if (nav.type === 'top') {
			return '<nav class="bg360-pv-top-nav">' + items.slice(0, 6).map(function (item, index) {
				return '<button type="button" class="' + (index === 0 ? 'is-active' : '') + '" data-preview-link="1">' + esc(item.label) + '</button>';
			}).join('') + '</nav>';
		}
		return '';
	}

	function renderPreviewDrawer(palette) {
		var menu = design.menu || {};
		var groups = menu.groups || [];
		var items = (menu.items || []).filter(function (item) { return item.enabled; });
		var header = design.header || {};
		var html = '<div class="bg360-pv-drawer-head"><span class="bg360-pv-logo bg360-pv-logo-letter">' + esc((DS.app_name || 'A').charAt(0).toUpperCase()) + '</span><strong>' + esc(header.title || DS.app_name || 'Menu') + '</strong><button type="button" class="bg360-pv-icon-btn" data-preview-close="1">' + icon('chevron-right') + '</button></div><div class="bg360-pv-drawer-body">';
		if (!items.length) {
			html += '<p class="bg360-pv-empty-text">No menu items configured.</p>';
		} else {
			groups.forEach(function (group) {
				var groupItems = items.filter(function (item) { return item.group === group.id; });
				if (!groupItems.length) return;
				html += '<span class="bg360-pv-drawer-group">' + esc(group.label) + '</span>';
				groupItems.forEach(function (item) {
					html += '<button type="button" class="bg360-pv-drawer-item" data-preview-link="1">' + icon(item.icon) + '<span>' + esc(item.label) + '</span></button>';
				});
			});
		}
		html += '</div>';
		return html;
	}

	/* ---------------------------------------------------------------- events */

	function bindEvents() {
		var studio = document.getElementById('bg360-design-studio');
		if (!studio) {
			return;
		}

		studio.addEventListener('input', handleInput);
		studio.addEventListener('change', handleChange);
		studio.addEventListener('click', handleClick);
		studio.addEventListener('dragstart', handleDragStart);
		studio.addEventListener('dragover', handleDragOver);
		studio.addEventListener('dragleave', handleDragLeave);
		studio.addEventListener('drop', handleDrop);
		studio.addEventListener('dragend', handleDragEnd);
		window.addEventListener('beforeunload', function (event) {
			if (dirty) {
				event.preventDefault();
				event.returnValue = '';
			}
		});
		if (window.matchMedia) {
			var media = window.matchMedia('(prefers-color-scheme: dark)');
			var handler = function () {
				if ((design.theme || {}).mode === 'auto') {
					renderPreview();
				}
			};
			if (media.addEventListener) media.addEventListener('change', handler);
			else if (media.addListener) media.addListener(handler);
		}
	}

	function handleInput(event) {
		var input = event.target.closest('[data-path]');
		if (!input) {
			return;
		}
		var path = input.getAttribute('data-path');
		var value;
		if (input.type === 'checkbox') {
			value = input.checked;
		} else if (input.hasAttribute('data-number')) {
			value = input.value === '' ? 0 : Number(input.value);
			if (Number.isNaN(value)) value = 0;
		} else if (input.hasAttribute('data-color-hex')) {
			value = input.value.trim();
			if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value)) {
				return;
			}
			syncColorSibling(path, value);
		} else if (input.hasAttribute('data-color-picker')) {
			value = input.value;
			syncColorSibling(path, value);
		} else {
			value = input.value;
		}
		setPath(design, path, value);
		markDirty();
		if (isPreviewRelevant(path)) {
			renderPreview();
		}
	}

	function handleChange(event) {
		if (event.target && event.target.id === 'bg360-preview-size-panel') {
			previewSize = event.target.value;
			renderPreview();
			return;
		}
		var input = event.target.closest('[data-path]');
		if (!input) {
			return;
		}
		var path = input.getAttribute('data-path');
		if (input.tagName === 'SELECT') {
			setPath(design, path, input.value);
			markDirty();
			if (path === 'theme.mode') {
				renderPreview();
			}
			if (path === 'components.style_preset') {
				applyComponentPreset(input.value);
				renderPanels();
				renderPreview();
			}
			if (/^homepage\.sections\.\d+\.type$/.test(path)) {
				renderPanels();
				renderPreview();
			}
			if (/^menu\.items\.\d+\.group$/.test(path)) {
				renderPreview();
			}
			if (path === 'navigation.type' || path === 'navigation.style') {
				renderPreview();
			}
		} else if (input.type === 'checkbox') {
			setPath(design, path, input.checked);
			markDirty();
			renderPreview();
		}
	}

	function syncColorSibling(path, value) {
		var studio = document.getElementById('bg360-design-studio');
		if (!studio) return;
		studio.querySelectorAll('[data-path="' + path.replace(/"/g, '\\"') + '"]').forEach(function (input) {
			if (input.type === 'color' || input.hasAttribute('data-color-hex')) {
				input.value = value;
			}
		});
	}

	function isPreviewRelevant(path) {
		return /^(theme|header|navigation|menu|homepage|components|typography|layout)\./.test(path);
	}

	function handleClick(event) {
		var panelButton = event.target.closest('[data-design-panel]');
		if (panelButton) {
			switchPanel(panelButton.getAttribute('data-design-panel'));
			return;
		}
		var modeButton = event.target.closest('[data-preview-mode]');
		if (modeButton) {
			previewMode = modeButton.getAttribute('data-preview-mode');
			document.querySelectorAll('[data-preview-mode]').forEach(function (button) {
				button.classList.toggle('is-active', button.getAttribute('data-preview-mode') === previewMode);
			});
			renderPreview();
			return;
		}
		if (event.target.closest('#bg360-preview-theme-toggle') || event.target.closest('[data-action="toggle-preview-theme"]')) {
			previewDark = !previewDark;
			renderPreview();
			return;
		}
		var saveButton = event.target.closest('#bg360-design-save-btn, #bg360-design-save-btn-2');
		if (saveButton) {
			saveDesign(false);
			return;
		}
		if (event.target.closest('#bg360-design-discard-btn')) {
			discardChanges();
			return;
		}
		var mediaButton = event.target.closest('[data-media-path]');
		if (mediaButton) {
			openMediaPicker(mediaButton);
			return;
		}
		if (event.target.closest('[data-preview-link]') || event.target.closest('[data-preview-menu]') || event.target.closest('[data-preview-close]')) {
			event.preventDefault();
			if (event.target.closest('[data-preview-menu]')) {
				previewMode = 'menu';
				document.querySelectorAll('[data-preview-mode]').forEach(function (button) {
					button.classList.toggle('is-active', button.getAttribute('data-preview-mode') === 'menu');
				});
				renderPreview();
			} else if (event.target.closest('[data-preview-close]')) {
				previewMode = 'home';
				renderPreview();
			} else {
				showToast(DS.strings.preview_only);
			}
			return;
		}
		var action = event.target.closest('[data-action]');
		if (!action) {
			return;
		}
		event.preventDefault();
		var name = action.getAttribute('data-action');
		if (name === 'preset') {
			applyPreset(action.getAttribute('data-preset'));
		} else if (name === 'copy-dark') {
			copyLightToDark();
		} else if (name === 'add-header-action') {
			addHeaderAction();
		} else if (name === 'add-nav-item') {
			addNavItem();
		} else if (name === 'add-menu-group') {
			addMenuGroup();
		} else if (name === 'add-menu-item') {
			addMenuItem();
		} else if (name === 'remove-group') {
			removeMenuItemByAction(action);
		} else if (name === 'add-section') {
			addSection();
		} else if (name === 'add-section-item') {
			addSectionItem(parseInt(action.getAttribute('data-section'), 10));
		} else if (name === 'move-up' || name === 'move-down') {
			moveItem(action.getAttribute('data-list'), parseInt(action.getAttribute('data-index'), 10), name === 'move-up' ? -1 : 1);
		} else if (name === 'remove') {
			removeItem(action.getAttribute('data-list'), parseInt(action.getAttribute('data-index'), 10));
		} else if (name === 'discard') {
			discardChanges();
		} else if (name === 'reset-section') {
			resetSection();
		} else if (name === 'reset-all') {
			resetAll();
		} else if (name === 'toggle-preview-theme') {
			previewDark = !previewDark;
			renderPreview();
		}
	}

	function switchPanel(panel) {
		activePanel = panel;
		document.querySelectorAll('[data-design-panel]').forEach(function (button) {
			button.classList.toggle('active', button.getAttribute('data-design-panel') === panel);
		});
		document.querySelectorAll('[data-design-panel-content]').forEach(function (section) {
			section.classList.toggle('active', section.getAttribute('data-design-panel-content') === panel);
		});
		if (panel === 'preview') {
			renderPreview();
			var preview = document.querySelector('.bg360-studio-preview');
			if (preview && window.innerWidth < 1200) {
				preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}
	}

	function applyPreset(key) {
		var preset = DS.presets[key];
		if (!preset) return;
		var theme = design.theme;
		Object.keys(preset.colors || {}).forEach(function (field) {
			theme[field] = preset.colors[field];
		});
		theme.mode = preset.mode || 'light';
		theme.dark = deriveDark(preset.colors || {});
		design.header.background_color = preset.colors.primary_color || theme.primary_color;
		design.header.text_color = contrast(preset.colors.primary_color || theme.primary_color);
		design.components.style_preset = preset.components || design.components.style_preset;
		applyComponentPreset(design.components.style_preset);
		markDirty();
		renderPanels();
		renderPreview();
		showToast(preset.label + ' applied');
	}

	function deriveDark(light) {
		return {
			primary_color: light.primary_color || '#2563EB',
			secondary_color: '#E2E8F0',
			accent_color: light.accent_color || '#F59E0B',
			background_color: '#0B1220',
			card_color: '#111827',
			text_color: '#E5E7EB',
			heading_color: '#F9FAFB',
			button_color: light.button_color || light.primary_color || '#2563EB',
			button_text_color: contrast(light.button_color || light.primary_color || '#2563EB'),
			border_color: '#1F2937',
			status_bar_color: '#0B1220',
			navigation_bar_color: '#0B1220'
		};
	}

	function copyLightToDark() {
		design.theme.dark = deriveDark(design.theme);
		markDirty();
		renderPanels();
		renderPreview();
		showToast('Dark palette updated from the light palette');
	}

	function applyComponentPreset(name) {
		var presets = {
			modern: { card_radius: 12, button_radius: 10, card_style: 'elevated', button_style: 'solid', image_ratio: '16:9', shadow: 'soft', card_spacing: 12, section_spacing: 20, content_width: 100, icon_style: 'rounded', show_dividers: false },
			minimal: { card_radius: 6, button_radius: 6, card_style: 'flat', button_style: 'solid', image_ratio: '4:3', shadow: 'none', card_spacing: 10, section_spacing: 16, content_width: 100, icon_style: 'outline', show_dividers: true },
			rounded: { card_radius: 20, button_radius: 18, card_style: 'elevated', button_style: 'pill', image_ratio: '1:1', shadow: 'soft', card_spacing: 14, section_spacing: 22, content_width: 100, icon_style: 'rounded', show_dividers: false },
			material: { card_radius: 12, button_radius: 8, card_style: 'elevated', button_style: 'solid', image_ratio: '16:9', shadow: 'medium', card_spacing: 12, section_spacing: 20, content_width: 100, icon_style: 'filled', show_dividers: true },
			compact: { card_radius: 8, button_radius: 6, card_style: 'outlined', button_style: 'solid', image_ratio: '1:1', shadow: 'none', card_spacing: 8, section_spacing: 14, content_width: 100, icon_style: 'outline', show_dividers: true },
			premium: { card_radius: 18, button_radius: 14, card_style: 'elevated', button_style: 'pill', image_ratio: '3:2', shadow: 'strong', card_spacing: 16, section_spacing: 24, content_width: 100, icon_style: 'filled', show_dividers: false }
		};
		var values = presets[name] || presets.modern;
		Object.keys(values).forEach(function (key) { design.components[key] = values[key]; });
	}

	/* --------------------------------------------------------------- repeaters */

	function addHeaderAction() {
		if ((design.header.actions || []).length >= 4) {
			showToast('Maximum four custom header actions');
			return;
		}
		design.header.actions = design.header.actions || [];
		design.header.actions.push({ id: uid('action'), label: 'Action', icon: 'link', url: '', enabled: true });
		markDirty();
		renderPanels();
		renderPreview();
	}

	function addNavItem() {
		if ((design.navigation.items || []).length >= 8) {
			showToast('Maximum eight navigation items');
			return;
		}
		design.navigation.items = design.navigation.items || [];
		design.navigation.items.push({ id: uid('nav'), label: 'Website', icon: 'website', type: 'website', url: DS.website_url || '', visible: true });
		markDirty();
		renderPanels();
		renderPreview();
	}

	function addMenuGroup() {
		if (design.menu.groups.length >= 8) {
			showToast('Maximum eight menu groups');
			return;
		}
		design.menu.groups.push({ id: uid('group'), label: 'New group' });
		markDirty();
		renderPanels();
	}

	function removeMenuItemByAction(action) {
		var index = parseInt(action.getAttribute('data-index'), 10);
		if (design.menu.groups.length < 2) return;
		var removed = design.menu.groups.splice(index, 1)[0];
		var fallback = design.menu.groups[0].id;
		design.menu.items.forEach(function (item) {
			if (item.group === removed.id) item.group = fallback;
		});
		markDirty();
		renderPanels();
		renderPreview();
	}

	function addMenuItem() {
		if (design.menu.items.length >= 40) {
			showToast('Maximum forty menu items');
			return;
		}
		design.menu.items.push({ id: uid('menu'), label: 'Menu item', icon: 'link', type: 'website', url: '', enabled: true, group: design.menu.groups[0].id });
		markDirty();
		renderPanels();
		renderPreview();
	}

	function addSection() {
		if (design.homepage.sections.length >= 20) {
			showToast('Maximum twenty homepage sections');
			return;
		}
		var select = document.getElementById('bg360-new-section-type');
		var type = select ? select.value : 'welcome';
		var section = defaultSection(type);
		design.homepage.enabled = true;
		design.homepage.sections.push(section);
		markDirty();
		renderPanels();
		renderPreview();
		showToast((SECTION_LABELS[type] || type) + ' added');
	}

	function defaultSection(type) {
		var base = {
			id: uid('section'), type: type, enabled: true, title: '', subtitle: '', text: '', image_url: '',
			link_url: DS.website_url || '', button_label: '', layout: 'default', items: []
		};
		if (type === 'hero') {
			base.title = 'Welcome to ' + (DS.app_name || 'our app');
			base.subtitle = 'A better way to stay connected.';
			base.button_label = 'Open Website';
			base.layout = 'overlay';
		} else if (type === 'welcome') {
			base.title = 'Welcome';
			base.text = 'Tell customers what they can do in your app.';
		} else if (type === 'promo') {
			base.title = 'Special offer';
			base.text = 'Highlight a promotion or announcement here.';
			base.button_label = 'Learn more';
		} else if (type === 'custom_html') {
			base.text = '<p>Add safe HTML content here.</p>';
		} else if (type === 'contact') {
			base.title = 'Contact us';
			base.text = 'We are here to help.';
			base.button_label = 'Call us';
			base.link_url = 'tel:';
		} else if (type === 'slider') {
			base.items = [1, 2, 3].map(function (i) {
				return { id: uid('slide'), label: 'Slide ' + i, title: 'Slide ' + i, subtitle: '', url: '', image_url: '', icon: 'image', meta: '' };
			});
		} else if (type === 'featured_links' || type === 'quick_actions') {
			base.title = type === 'featured_links' ? 'Featured links' : 'Quick actions';
			base.items = [' Website', 'Shop', 'Contact', 'Account'].map(function (label) {
				return { id: uid('item'), label: label.trim(), title: label.trim(), subtitle: '', url: '', image_url: '', icon: label.toLowerCase().indexOf('shop') === 0 ? 'shop' : 'link', meta: '' };
			});
		} else if (type === 'categories' || type === 'products' || type === 'image_cards' || type === 'featured_content' || type === 'latest_posts') {
			base.title = SECTION_LABELS[type] || type;
			base.items = [1, 2, 3].map(function (i) {
				return { id: uid('item'), label: 'Item ' + i, title: 'Item ' + i, subtitle: 'Add a short description', url: '', image_url: '', icon: 'image', meta: type === 'products' ? '$0.00' : '' };
			});
		}
		return base;
	}

	function addSectionItem(sectionIndex) {
		var section = design.homepage.sections[sectionIndex];
		if (!section) return;
		section.items = section.items || [];
		if (section.items.length >= 20) {
			showToast('Maximum twenty items per section');
			return;
		}
		section.items.push({ id: uid('item'), label: 'Item', title: 'Item', subtitle: '', text: '', url: '', image_url: '', icon: 'link', meta: '' });
		markDirty();
		renderPanels();
		renderPreview();
	}

	function moveItem(listPath, index, delta) {
		var list = getPath(design, listPath);
		if (!Array.isArray(list) || !list[index]) return;
		var target = index + delta;
		if (target < 0 || target >= list.length) return;
		var temp = list[index];
		list[index] = list[target];
		list[target] = temp;
		markDirty();
		renderPanels();
		renderPreview();
	}

	function removeItem(listPath, index) {
		var list = getPath(design, listPath);
		if (!Array.isArray(list)) return;
		list.splice(index, 1);
		markDirty();
		renderPanels();
		renderPreview();
	}

	/* ------------------------------------------------------------------ drag */

	var dragState = null;

	function handleDragStart(event) {
		var card = event.target.closest('[data-drag-list]');
		if (!card) return;
		dragState = {
			list: card.getAttribute('data-drag-list'),
			index: parseInt(card.getAttribute('data-drag-index'), 10)
		};
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData('text/plain', JSON.stringify(dragState));
		card.classList.add('is-dragging');
	}

	function handleDragOver(event) {
		var card = event.target.closest('[data-drag-list]');
		if (!card || !dragState) return;
		if (card.getAttribute('data-drag-list') !== dragState.list) return;
		event.preventDefault();
		card.classList.add('is-drag-over');
	}

	function handleDragLeave(event) {
		var card = event.target.closest('[data-drag-list]');
		if (card) card.classList.remove('is-drag-over');
	}

	function handleDrop(event) {
		var card = event.target.closest('[data-drag-list]');
		if (!card || !dragState) return;
		if (card.getAttribute('data-drag-list') !== dragState.list) return;
		event.preventDefault();
		var target = parseInt(card.getAttribute('data-drag-index'), 10);
		var list = getPath(design, dragState.list);
		if (!Array.isArray(list)) return;
		var moved = list.splice(dragState.index, 1)[0];
		list.splice(target, 0, moved);
		dragState = null;
		markDirty();
		renderPanels();
		renderPreview();
	}

	function handleDragEnd() {
		document.querySelectorAll('.is-dragging, .is-drag-over').forEach(function (el) {
			el.classList.remove('is-dragging', 'is-drag-over');
		});
		dragState = null;
	}

	/* --------------------------------------------------------------- media */

	function openMediaPicker(button) {
		var path = button.getAttribute('data-media-path');
		if (!window.wp || !window.wp.media) return;
		mediaFrame = window.wp.media({
			title: DS.strings.select_image,
			button: { text: DS.strings.use_image },
			multiple: false,
			library: { type: 'image' }
		});
		mediaFrame.on('select', function () {
			var attachment = mediaFrame.state().get('selection').first().toJSON();
			setPath(design, path, attachment.url);
			markDirty();
			renderPanels();
			renderPreview();
		});
		mediaFrame.open();
	}

	/* ------------------------------------------------------------- save/reset */

	function markDirty() {
		dirty = true;
		setSaveState(DS.strings.unsaved, 'is-dirty');
		scheduleAutosave();
	}

	function setSaveState(text, cls) {
		var badge = document.getElementById('bg360-design-dirty');
		if (badge) {
			badge.textContent = text;
			badge.className = 'bg360-save-state ' + (cls || 'is-saved');
		}
	}

	function scheduleAutosave() {
		if (autosaveTimer) clearTimeout(autosaveTimer);
		var checkbox = document.getElementById('bg360-design-autosave');
		if (!checkbox || !checkbox.checked) return;
		autosaveTimer = setTimeout(function () {
			if (dirty) saveDesign(true);
		}, 1800);
	}

	function saveDesign(silent) {
		if (saving) {
			savePending = true;
			return Promise.resolve(false);
		}
		saving = true;
		setSaveState(silent ? DS.strings.saving : DS.strings.saving, 'is-saving');
		var payload = new URLSearchParams();
		payload.set('action', 'bizgrowth360_save_design');
		payload.set('nonce', DS.nonce);
		payload.set('design', JSON.stringify(design));

		return fetch(DS.ajax_url, { method: 'POST', credentials: 'same-origin', body: payload })
			.then(function (response) { return response.json(); })
			.then(function (result) {
				saving = false;
				if (!result || !result.success) {
					setSaveState(DS.strings.save_error, 'is-error');
					showToast(result && result.data && result.data.message ? result.data.message : DS.strings.save_error, true);
					if (savePending) { savePending = false; scheduleAutosave(); }
					return false;
				}
				var saved = result.data.design || design;
				design = clone(saved);
				lastSaved = clone(saved);
				DS.saved_at = result.data.saved_at || Math.floor(Date.now() / 1000);
				dirty = false;
				setSaveState((silent ? DS.strings.autosaved : DS.strings.saved) + ' · ' + new Date(DS.saved_at * 1000).toLocaleTimeString(), 'is-saved');
				var lastSavedEl = document.getElementById('bg360-design-last-saved');
				if (lastSavedEl) lastSavedEl.textContent = new Date(DS.saved_at * 1000).toLocaleString();
				if (!silent) {
					renderPanels();
					renderPreview();
					showToast(DS.strings.saved);
				}
				if (savePending && dirty) {
					savePending = false;
					scheduleAutosave();
				}
				return true;
			})
			.catch(function () {
				saving = false;
				setSaveState(DS.strings.save_error, 'is-error');
				showToast(DS.strings.save_error, true);
				return false;
			});
	}

	function discardChanges() {
		if (!dirty || !window.confirm(DS.strings.discard)) return;
		design = clone(lastSaved);
		dirty = false;
		setSaveState(DS.strings.saved, 'is-saved');
		renderPanels();
		renderPreview();
	}

	function resetSection() {
		var select = document.getElementById('bg360-reset-section');
		var section = select ? select.value : '';
		if (!window.confirm(section ? DS.strings.confirm_section : DS.strings.confirm_reset)) return;
		performReset(section);
	}

	function performReset( section ) {
		var payload = new URLSearchParams();
		payload.set('action', 'bizgrowth360_reset_design');
		payload.set('nonce', DS.nonce);
		payload.set('section', section);
		setSaveState(DS.strings.saving, 'is-saving');
		fetch(DS.ajax_url, { method: 'POST', credentials: 'same-origin', body: payload })
			.then(function (response) { return response.json(); })
			.then(function (result) {
				if (result && result.success) {
					design = clone(result.data.design || design);
					lastSaved = clone(design);
					dirty = false;
					DS.saved_at = result.data.saved_at || Math.floor(Date.now() / 1000);
					setSaveState(DS.strings.saved, 'is-saved');
					renderPanels();
					renderPreview();
					showToast(result.data.message || DS.strings.saved);
				} else {
					setSaveState(DS.strings.save_error, 'is-error');
					showToast(result && result.data && result.data.message ? result.data.message : DS.strings.save_error, true);
				}
			})
			.catch(function () {
				setSaveState(DS.strings.save_error, 'is-error');
				showToast(DS.strings.save_error, true);
			});
	}

	function resetAll() {
		if (!window.confirm(DS.strings.confirm_reset)) return;
		var select = document.getElementById('bg360-reset-section');
		if (select) select.value = '';
		performReset('');
	}

	function showToast(message, isError) {
		var toast = document.getElementById('bg360-design-toast');
		if (!toast) return;
		toast.textContent = message;
		toast.classList.toggle('is-error', !!isError);
		toast.classList.add('is-visible');
		clearTimeout(showToast.timer);
		showToast.timer = setTimeout(function () { toast.classList.remove('is-visible'); }, 3000);
	}

	/* --------------------------------------------------------------- startup */

	function init() {
		if (!document.getElementById('bg360-design-studio')) return;
		renderPanels();
		renderPreview();
		bindEvents();
		setSaveState(DS.saved_at ? DS.strings.saved + ' · ' + new Date(DS.saved_at * 1000).toLocaleTimeString() : 'Not saved yet', 'is-saved');

		var sizeSelect = document.getElementById('bg360-preview-size');
		if (sizeSelect) {
			sizeSelect.addEventListener('change', function () {
				previewSize = sizeSelect.value;
				renderPreview();
			});
		}
		var panelSizeSelect = document.getElementById('bg360-preview-size-panel');
		if (panelSizeSelect) {
			panelSizeSelect.addEventListener('change', function () {
				previewSize = panelSizeSelect.value;
				renderPreview();
			});
		}
		document.querySelectorAll('[data-preview-mode]').forEach(function (button) {
			button.addEventListener('click', function () {
				previewMode = button.getAttribute('data-preview-mode');
				document.querySelectorAll('[data-preview-mode]').forEach(function (b) {
					b.classList.toggle('is-active', b.getAttribute('data-preview-mode') === previewMode);
				});
				renderPreview();
			});
		});
		var themeToggle = document.getElementById('bg360-preview-theme-toggle');
		if (themeToggle) {
			themeToggle.addEventListener('click', function () {
				previewDark = !previewDark;
				renderPreview();
			});
		}
		window.bg360DesignStudio = {
			save: function () {
				if (!dirty) {
					return Promise.resolve(true);
				}
				return saveDesign(true);
			},
			isDirty: function () { return dirty; }
		};
		// Re-render when the Design Studio tab becomes visible.
		document.querySelectorAll('.bizgrowth360-nav-tabs a[data-tab="tab-design"]').forEach(function (link) {
			link.addEventListener('click', function () {
				setTimeout(renderPreview, 80);
			});
		});
	}

	document.addEventListener('DOMContentLoaded', init);
	if (document.readyState !== 'loading') {
		init();
	}
})();
