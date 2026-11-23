<?php
/**
 * App Design Studio configuration, validation and persistence.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned design configuration.
 */
class DesignConfig {

	const OPTION_KEY   = 'bizgrowth360_app_builder_design';
	const SAVED_AT_KEY = 'bizgrowth360_app_builder_design_saved_at';
	const SCHEMA_VERSION = 1;

	const NAV_TYPES = array( 'none', 'bottom', 'drawer', 'top' );
	const NAV_STYLES = array( 'solid', 'transparent', 'floating' );
	const MENU_TYPES = array( 'home', 'website', 'page', 'category', 'external', 'custom', 'contact', 'whatsapp', 'account', 'cart' );
	const SECTION_TYPES = array( 'hero', 'slider', 'welcome', 'featured_links', 'quick_actions', 'categories', 'products', 'featured_content', 'latest_posts', 'promo', 'image_cards', 'custom_html', 'contact' );
	const COMPONENT_PRESETS = array( 'modern', 'minimal', 'rounded', 'material', 'compact', 'premium' );
	const FONTS = array( 'system', 'arial', 'georgia', 'verdana', 'trebuchet', 'tahoma', 'times', 'courier' );
	const ICONS = array( 'home', 'menu', 'search', 'bell', 'cart', 'user', 'grid', 'link', 'star', 'heart', 'info', 'phone', 'mail', 'whatsapp', 'settings', 'image', 'chevron-right', 'more', 'website', 'shop', 'bag', 'calendar', 'location', 'play' );
	const THEME_COLOR_FIELDS = array( 'primary_color', 'secondary_color', 'accent_color', 'background_color', 'card_color', 'text_color', 'heading_color', 'button_color', 'button_text_color', 'border_color', 'status_bar_color', 'navigation_bar_color' );

	const SECTION_LAYOUTS = array( 'default', 'grid', 'list', 'carousel', 'split', 'overlay' );
	const IMAGE_RATIOS   = array( '1:1', '4:3', '16:9', '3:2' );
	const SHADOWS        = array( 'none', 'soft', 'medium', 'strong' );
	const CARD_STYLES    = array( 'flat', 'outlined', 'elevated' );
	const BUTTON_STYLES  = array( 'solid', 'outline', 'pill' );
	const ICON_STYLES    = array( 'rounded', 'outline', 'filled' );

	/**
	 * Return the saved design merged with defaults.
	 *
	 * @return array
	 */
	public function get() {
		$defaults = $this->get_defaults();
		$stored   = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$merged = $this->merge_deep( $defaults, $stored );
		$merged['schema_version'] = self::SCHEMA_VERSION;

		return $this->sanitize( $merged );
	}

	/**
	 * Default design configuration. On first use, theme colors are seeded from
	 * the existing app configuration so upgrading does not silently change an
	 * existing customer app.
	 *
	 * @return array
	 */
	public function get_defaults() {
		$app = get_option( Settings::OPTION_KEY, array() );
		if ( ! is_array( $app ) ) {
			$app = array();
		}

		$primary      = $this->color( $app['primary_color'] ?? '', '#2563EB' );
		$background   = $this->color( $app['background_color'] ?? '', '#FFFFFF' );
		$status       = $this->color( $app['status_bar_color'] ?? '', $primary );
		$navigation   = $this->color( $app['navigation_bar_color'] ?? '', $background );
		$text         = '#111827';
		$heading      = '#0F172A';
		$card         = '#F8FAFC';
		$border       = '#E2E8F0';

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'theme'          => array(
				'mode'                   => 'light',
				'primary_color'          => $primary,
				'secondary_color'        => '#0F172A',
				'accent_color'           => '#F59E0B',
				'background_color'       => $background,
				'card_color'             => $card,
				'text_color'             => $text,
				'heading_color'          => $heading,
				'button_color'           => $primary,
				'button_text_color'      => '#FFFFFF',
				'border_color'           => $border,
				'status_bar_color'       => $status,
				'navigation_bar_color'   => $navigation,
				'dark'                   => array(
					'primary_color'        => '#60A5FA',
					'secondary_color'      => '#E2E8F0',
					'accent_color'         => '#FBBF24',
					'background_color'     => '#0B1220',
					'card_color'           => '#111827',
					'text_color'           => '#E5E7EB',
					'heading_color'        => '#F9FAFB',
					'button_color'         => '#3B82F6',
					'button_text_color'    => '#FFFFFF',
					'border_color'         => '#1F2937',
					'status_bar_color'     => '#0B1220',
					'navigation_bar_color' => '#0B1220',
				),
			),
			'header'         => array(
				'enabled'            => true,
				'show_logo'          => true,
				'logo_url'           => '',
				'title'              => '',
				'background_color'   => $primary,
				'text_color'         => '#FFFFFF',
				'height'             => 56,
				'sticky'             => true,
				'show_search'        => true,
				'show_notifications' => false,
				'show_cart'          => false,
				'show_profile'       => false,
				'search_url'         => '',
				'notification_url'   => '',
				'cart_url'           => '',
				'profile_url'        => '',
				'actions'            => array(),
			),
			'navigation'     => array(
				'type'       => 'none',
				'style'      => 'solid',
				'more_label' => 'More',
				'items'      => array(),
			),
			'menu'           => array(
				'groups' => array(
					array(
						'id'    => 'main',
						'label' => 'Menu',
					),
				),
				'items'  => array(),
			),
			'homepage'       => array(
				'enabled'  => false,
				'sections' => array(),
			),
			'components'     => array(
				'style_preset'     => 'modern',
				'card_radius'      => 12,
				'button_radius'    => 10,
				'card_style'       => 'elevated',
				'button_style'     => 'solid',
				'image_ratio'      => '16:9',
				'shadow'           => 'soft',
				'card_spacing'     => 12,
				'section_spacing'  => 20,
				'content_width'    => 100,
				'icon_style'       => 'rounded',
				'show_dividers'    => false,
			),
			'typography'     => array(
				'font_family'    => 'system',
				'heading_font'   => 'system',
				'body_font'      => 'system',
				'base_size'      => 14,
				'heading_scale'  => 1.25,
				'button_weight'  => 600,
				'line_height'    => 1.5,
				'letter_spacing' => 0,
			),
			'layout'         => array(
				'page_padding'      => 16,
				'section_gap'       => 20,
				'max_width'         => 600,
				'safe_area'         => true,
				'bottom_nav_height' => 64,
			),
		);
	}

	/**
	 * Save a design after server-side sanitization.
	 *
	 * @param mixed $design Raw design.
	 * @return array|\WP_Error
	 */
	public function save( $design ) {
		if ( ! is_array( $design ) ) {
			return new \WP_Error( 'invalid_design', __( 'Design configuration must be an object.', 'bizgrowth360-app-builder' ) );
		}

		$sanitized = $this->sanitize( $design );
		update_option( self::OPTION_KEY, $sanitized, false );
		update_option( self::SAVED_AT_KEY, time(), false );

		return $sanitized;
	}

	/**
	 * Reset one section or the entire design.
	 *
	 * @param string $section Optional section key.
	 * @return array|\WP_Error
	 */
	public function reset( $section = '' ) {
		$defaults = $this->get_defaults();
		$section  = sanitize_key( $section );

		$allowed = array( 'theme', 'header', 'navigation', 'menu', 'homepage', 'components', 'typography', 'layout' );
		if ( $section && in_array( $section, $allowed, true ) ) {
			$current              = $this->get();
			$current[ $section ]  = $defaults[ $section ];
			$current['schema_version'] = self::SCHEMA_VERSION;
			return $this->save( $current );
		}

		return $this->save( $defaults );
	}

	/**
	 * Deep sanitization of the entire design.
	 *
	 * @param array $design Raw design.
	 * @return array
	 */
	public function sanitize( $design ) {
		$defaults = $this->get_defaults();
		if ( ! is_array( $design ) ) {
			return $defaults;
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'theme'          => $this->sanitize_theme( $design['theme'] ?? array(), $defaults['theme'] ),
			'header'         => $this->sanitize_header( $design['header'] ?? array(), $defaults['header'] ),
			'navigation'     => $this->sanitize_navigation( $design['navigation'] ?? array(), $defaults['navigation'] ),
			'menu'           => $this->sanitize_menu( $design['menu'] ?? array(), $defaults['menu'] ),
			'homepage'       => $this->sanitize_homepage( $design['homepage'] ?? array(), $defaults['homepage'] ),
			'components'     => $this->sanitize_components( $design['components'] ?? array(), $defaults['components'] ),
			'typography'     => $this->sanitize_typography( $design['typography'] ?? array(), $defaults['typography'] ),
			'layout'         => $this->sanitize_layout( $design['layout'] ?? array(), $defaults['layout'] ),
		);
	}

	/**
	 * Sanitize theme settings.
	 */
	private function sanitize_theme( $theme, $defaults ) {
		$theme = is_array( $theme ) ? $theme : array();
		$out   = array(
			'mode' => in_array( $theme['mode'] ?? '', array( 'light', 'dark', 'auto' ), true ) ? $theme['mode'] : 'light',
		);

		foreach ( self::THEME_COLOR_FIELDS as $field ) {
			$out[ $field ] = $this->color( $theme[ $field ] ?? '', $defaults[ $field ] );
		}

		$dark = is_array( $theme['dark'] ?? null ) ? $theme['dark'] : array();
		$out['dark'] = array();
		foreach ( self::THEME_COLOR_FIELDS as $field ) {
			$out['dark'][ $field ] = $this->color( $dark[ $field ] ?? '', $defaults['dark'][ $field ] );
		}

		return $out;
	}

	/**
	 * Sanitize header settings.
	 */
	private function sanitize_header( $header, $defaults ) {
		$header = is_array( $header ) ? $header : array();
		$out    = array(
			'enabled'            => $this->bool( $header['enabled'] ?? $defaults['enabled'] ),
			'show_logo'          => $this->bool( $header['show_logo'] ?? $defaults['show_logo'] ),
			'logo_url'           => $this->image_url( $header['logo_url'] ?? '' ),
			'title'              => $this->text( $header['title'] ?? '', 80 ),
			'background_color'   => $this->color( $header['background_color'] ?? '', $defaults['background_color'] ),
			'text_color'         => $this->color( $header['text_color'] ?? '', $defaults['text_color'] ),
			'height'             => $this->int_range( $header['height'] ?? $defaults['height'], 44, 88, $defaults['height'] ),
			'sticky'             => $this->bool( $header['sticky'] ?? $defaults['sticky'] ),
			'show_search'        => $this->bool( $header['show_search'] ?? $defaults['show_search'] ),
			'show_notifications' => $this->bool( $header['show_notifications'] ?? $defaults['show_notifications'] ),
			'show_cart'          => $this->bool( $header['show_cart'] ?? $defaults['show_cart'] ),
			'show_profile'       => $this->bool( $header['show_profile'] ?? $defaults['show_profile'] ),
			'search_url'         => $this->url( $header['search_url'] ?? '' ),
			'notification_url'   => $this->url( $header['notification_url'] ?? '' ),
			'cart_url'           => $this->url( $header['cart_url'] ?? '' ),
			'profile_url'        => $this->url( $header['profile_url'] ?? '' ),
			'actions'            => array(),
		);

		$actions = is_array( $header['actions'] ?? null ) ? array_slice( $header['actions'], 0, 4 ) : array();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$out['actions'][] = array(
				'id'      => $this->id( $action['id'] ?? '' ),
				'label'   => $this->text( $action['label'] ?? '', 40 ),
				'icon'    => $this->icon( $action['icon'] ?? 'link' ),
				'url'     => $this->url( $action['url'] ?? '' ),
				'enabled' => $this->bool( $action['enabled'] ?? true ),
			);
		}

		return $out;
	}

	/**
	 * Sanitize navigation settings.
	 */
	private function sanitize_navigation( $navigation, $defaults ) {
		$navigation = is_array( $navigation ) ? $navigation : array();
		$out        = array(
			'type'       => in_array( $navigation['type'] ?? '', self::NAV_TYPES, true ) ? $navigation['type'] : 'none',
			'style'      => in_array( $navigation['style'] ?? '', self::NAV_STYLES, true ) ? $navigation['style'] : 'solid',
			'more_label' => $this->text( $navigation['more_label'] ?? 'More', 20 ),
			'items'      => array(),
		);

		$items = is_array( $navigation['items'] ?? null ) ? array_slice( $navigation['items'], 0, 8 ) : array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out['items'][] = $this->sanitize_nav_item( $item );
		}

		return $out;
	}

	/**
	 * Sanitize one navigation item.
	 */
	private function sanitize_nav_item( $item ) {
		return array(
			'id'      => $this->id( $item['id'] ?? '' ),
			'label'   => $this->text( $item['label'] ?? 'Item', 40 ),
			'icon'    => $this->icon( $item['icon'] ?? 'link' ),
			'type'    => in_array( $item['type'] ?? '', self::MENU_TYPES, true ) ? $item['type'] : 'website',
			'url'     => $this->url( $item['url'] ?? '' ),
			'visible' => $this->bool( $item['visible'] ?? true ),
		);
	}

	/**
	 * Sanitize menu groups and items.
	 */
	private function sanitize_menu( $menu, $defaults ) {
		$menu   = is_array( $menu ) ? $menu : array();
		$groups = is_array( $menu['groups'] ?? null ) ? array_slice( $menu['groups'], 0, 8 ) : array();
		$out    = array( 'groups' => array(), 'items' => array() );
		$ids    = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$gid = $this->id( $group['id'] ?? '' );
			if ( isset( $ids[ $gid ] ) ) {
				$gid = $this->id( '' );
			}
			$ids[ $gid ] = true;
			$out['groups'][] = array(
				'id'    => $gid,
				'label' => $this->text( $group['label'] ?? 'Menu', 40 ),
			);
		}

		if ( empty( $out['groups'] ) ) {
			$out['groups'][] = array( 'id' => 'main', 'label' => 'Menu' );
			$ids['main']     = true;
		}

		$items = is_array( $menu['items'] ?? null ) ? array_slice( $menu['items'], 0, 40 ) : array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$group = sanitize_key( $item['group'] ?? '' );
			if ( ! isset( $ids[ $group ] ) ) {
				$group = $out['groups'][0]['id'];
			}
			$out['items'][] = array(
				'id'      => $this->id( $item['id'] ?? '' ),
				'label'   => $this->text( $item['label'] ?? 'Item', 40 ),
				'icon'    => $this->icon( $item['icon'] ?? 'link' ),
				'type'    => in_array( $item['type'] ?? '', self::MENU_TYPES, true ) ? $item['type'] : 'website',
				'url'     => $this->url( $item['url'] ?? '' ),
				'enabled' => $this->bool( $item['enabled'] ?? true ),
				'group'   => $group,
			);
		}

		return $out;
	}

	/**
	 * Sanitize homepage sections.
	 */
	private function sanitize_homepage( $homepage, $defaults ) {
		$homepage = is_array( $homepage ) ? $homepage : array();
		$out      = array(
			'enabled'  => $this->bool( $homepage['enabled'] ?? $defaults['enabled'] ),
			'sections' => array(),
		);

		$sections = is_array( $homepage['sections'] ?? null ) ? array_slice( $homepage['sections'], 0, 20 ) : array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$type = in_array( $section['type'] ?? '', self::SECTION_TYPES, true ) ? $section['type'] : 'welcome';
			$item = array(
				'id'           => $this->id( $section['id'] ?? '' ),
				'type'         => $type,
				'enabled'      => $this->bool( $section['enabled'] ?? true ),
				'title'        => $this->text( $section['title'] ?? '', 120 ),
				'subtitle'     => $this->text( $section['subtitle'] ?? '', 200 ),
				'text'         => $this->textarea( $section['text'] ?? '', 2000 ),
				'image_url'    => $this->image_url( $section['image_url'] ?? '' ),
				'link_url'     => $this->url( $section['link_url'] ?? '' ),
				'button_label' => $this->text( $section['button_label'] ?? '', 40 ),
				'layout'       => in_array( $section['layout'] ?? '', self::SECTION_LAYOUTS, true ) ? $section['layout'] : 'default',
				'items'        => array(),
			);

			$items = is_array( $section['items'] ?? null ) ? array_slice( $section['items'], 0, 20 ) : array();
			foreach ( $items as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$item['items'][] = array(
					'id'        => $this->id( $entry['id'] ?? '' ),
					'label'     => $this->text( $entry['label'] ?? '', 80 ),
					'title'     => $this->text( $entry['title'] ?? '', 120 ),
					'subtitle'  => $this->text( $entry['subtitle'] ?? '', 200 ),
					'text'      => $this->textarea( $entry['text'] ?? '', 500 ),
					'url'       => $this->url( $entry['url'] ?? '' ),
					'image_url' => $this->image_url( $entry['image_url'] ?? '' ),
					'icon'      => $this->icon( $entry['icon'] ?? 'link' ),
					'meta'      => $this->text( $entry['meta'] ?? '', 80 ),
				);
			}

			if ( 'custom_html' === $type ) {
				$item['text'] = $this->custom_html( $section['text'] ?? '' );
			}

			$out['sections'][] = $item;
		}

		return $out;
	}

	/**
	 * Sanitize component styling.
	 */
	private function sanitize_components( $components, $defaults ) {
		$components = is_array( $components ) ? $components : array();
		return array(
			'style_preset'    => in_array( $components['style_preset'] ?? '', self::COMPONENT_PRESETS, true ) ? $components['style_preset'] : $defaults['style_preset'],
			'card_radius'     => $this->int_range( $components['card_radius'] ?? $defaults['card_radius'], 0, 32, $defaults['card_radius'] ),
			'button_radius'   => $this->int_range( $components['button_radius'] ?? $defaults['button_radius'], 0, 32, $defaults['button_radius'] ),
			'card_style'      => in_array( $components['card_style'] ?? '', self::CARD_STYLES, true ) ? $components['card_style'] : $defaults['card_style'],
			'button_style'    => in_array( $components['button_style'] ?? '', self::BUTTON_STYLES, true ) ? $components['button_style'] : $defaults['button_style'],
			'image_ratio'     => in_array( $components['image_ratio'] ?? '', self::IMAGE_RATIOS, true ) ? $components['image_ratio'] : $defaults['image_ratio'],
			'shadow'          => in_array( $components['shadow'] ?? '', self::SHADOWS, true ) ? $components['shadow'] : $defaults['shadow'],
			'card_spacing'    => $this->int_range( $components['card_spacing'] ?? $defaults['card_spacing'], 0, 40, $defaults['card_spacing'] ),
			'section_spacing' => $this->int_range( $components['section_spacing'] ?? $defaults['section_spacing'], 0, 48, $defaults['section_spacing'] ),
			'content_width'   => $this->int_range( $components['content_width'] ?? $defaults['content_width'], 70, 100, $defaults['content_width'] ),
			'icon_style'      => in_array( $components['icon_style'] ?? '', self::ICON_STYLES, true ) ? $components['icon_style'] : $defaults['icon_style'],
			'show_dividers'   => $this->bool( $components['show_dividers'] ?? $defaults['show_dividers'] ),
		);
	}

	/**
	 * Sanitize typography.
	 */
	private function sanitize_typography( $typography, $defaults ) {
		$typography = is_array( $typography ) ? $typography : array();
		return array(
			'font_family'    => in_array( $typography['font_family'] ?? '', self::FONTS, true ) ? $typography['font_family'] : $defaults['font_family'],
			'heading_font'   => in_array( $typography['heading_font'] ?? '', self::FONTS, true ) ? $typography['heading_font'] : $defaults['heading_font'],
			'body_font'      => in_array( $typography['body_font'] ?? '', self::FONTS, true ) ? $typography['body_font'] : $defaults['body_font'],
			'base_size'      => $this->int_range( $typography['base_size'] ?? $defaults['base_size'], 11, 20, $defaults['base_size'] ),
			'heading_scale'  => $this->float_range( $typography['heading_scale'] ?? $defaults['heading_scale'], 1, 1.7, $defaults['heading_scale'] ),
			'button_weight'  => $this->int_range( $typography['button_weight'] ?? $defaults['button_weight'], 400, 800, $defaults['button_weight'] ),
			'line_height'    => $this->float_range( $typography['line_height'] ?? $defaults['line_height'], 1.15, 2, $defaults['line_height'] ),
			'letter_spacing' => $this->float_range( $typography['letter_spacing'] ?? $defaults['letter_spacing'], -1, 2, $defaults['letter_spacing'] ),
		);
	}

	/**
	 * Sanitize layout.
	 */
	private function sanitize_layout( $layout, $defaults ) {
		$layout = is_array( $layout ) ? $layout : array();
		return array(
			'page_padding'      => $this->int_range( $layout['page_padding'] ?? $defaults['page_padding'], 8, 32, $defaults['page_padding'] ),
			'section_gap'       => $this->int_range( $layout['section_gap'] ?? $defaults['section_gap'], 8, 48, $defaults['section_gap'] ),
			'max_width'         => $this->int_range( $layout['max_width'] ?? $defaults['max_width'], 320, 800, $defaults['max_width'] ),
			'safe_area'         => $this->bool( $layout['safe_area'] ?? $defaults['safe_area'] ),
			'bottom_nav_height' => $this->int_range( $layout['bottom_nav_height'] ?? $defaults['bottom_nav_height'], 48, 88, $defaults['bottom_nav_height'] ),
		);
	}

	/**
	 * Recursive defaults merge. List arrays are replaced, not merged by index.
	 */
	private function merge_deep( $defaults, $stored ) {
		if ( ! is_array( $defaults ) ) {
			return $stored;
		}
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$out = $defaults;
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && ! $this->is_list( $value ) ) {
				$out[ $key ] = $this->merge_deep( $defaults[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private function is_list( $array ) {
		if ( empty( $array ) ) {
			return true;
		}
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * Text sanitizer.
	 */
	private function text( $value, $max ) {
		$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
		return $this->limit( $value, $max );
	}

	/**
	 * Textarea sanitizer.
	 */
	private function textarea( $value, $max ) {
		$value = is_string( $value ) ? sanitize_textarea_field( $value ) : '';
		return $this->limit( $value, $max );
	}

	private function limit( $value, $max ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * Hex color sanitizer.
	 */
	private function color( $value, $fallback ) {
		$color = is_string( $value ) ? sanitize_hex_color( $value ) : null;
		if ( ! $color ) {
			return $fallback;
		}
		$color = strtoupper( $color );
		if ( 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}
		return $color;
	}

	/**
	 * Safe app URL sanitizer. Relative paths are resolved against the site.
	 */
	private function url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) ) {
			$value = home_url( $value );
		}

		if ( ! preg_match( '~^[a-z][a-z0-9+.-]*:~i', $value ) ) {
			$value = 'https://' . $value;
		}

		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
		$allowed = array( 'http', 'https', 'mailto', 'tel', 'sms', 'whatsapp', 'app' );
		if ( ! in_array( $scheme, $allowed, true ) ) {
			return '';
		}
		if ( 'app' === $scheme ) {
			return in_array( $value, array( 'app:home', 'app:menu', 'app:website' ), true ) ? $value : '';
		}

		return esc_url_raw( $value, $allowed );
	}

	/**
	 * Image URL sanitizer.
	 */
	private function image_url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' ) ) {
			$value = home_url( $value );
		}
		if ( 0 === strpos( $value, '//' ) ) {
			$value = 'https:' . $value;
		}
		if ( ! preg_match( '~^https?://~i', $value ) ) {
			$value = 'https://' . $value;
		}
		return esc_url_raw( $value, array( 'http', 'https' ) );
	}

	/**
	 * Custom HTML sanitizer. No scripts, styles, iframes, forms, PHP, or event handlers.
	 */
	private function custom_html( $html ) {
		if ( ! is_string( $html ) ) {
			return '';
		}
		$html = $this->limit( $html, 5000 );

		$allowed = array(
			'a'          => array( 'href' => true, 'title' => true, 'class' => true, 'rel' => true ),
			'img'        => array( 'src' => true, 'alt' => true, 'title' => true, 'class' => true, 'loading' => true ),
			'div'        => array( 'class' => true ),
			'span'       => array( 'class' => true ),
			'p'          => array( 'class' => true ),
			'h2'         => array( 'class' => true ),
			'h3'         => array( 'class' => true ),
			'h4'         => array( 'class' => true ),
			'strong'     => array(),
			'em'         => array(),
			'b'          => array(),
			'i'          => array(),
			'u'          => array(),
			'small'      => array(),
			'ul'         => array( 'class' => true ),
			'ol'         => array( 'class' => true ),
			'li'         => array( 'class' => true ),
			'blockquote' => array( 'class' => true ),
			'hr'         => array( 'class' => true ),
			'br'         => array(),
		);

		$clean = wp_kses( $html, $allowed );
		// Never allow a serialized payload to terminate the admin inline JSON script.
		$clean = str_ireplace( array( '</script', '<!--', '<![CDATA[' ), array( '&lt;/script', '&lt;!--', '&lt;![CDATA[' ), $clean );
		return $clean;
	}

	private function icon( $value ) {
		return in_array( $value, self::ICONS, true ) ? $value : 'link';
	}

	private function bool( $value ) {
		return (bool) $value;
	}

	private function int_range( $value, $min, $max, $fallback ) {
		$value = is_numeric( $value ) ? (int) $value : (int) $fallback;
		return max( $min, min( $max, $value ) );
	}

	private function float_range( $value, $min, $max, $fallback ) {
		$value = is_numeric( $value ) ? (float) $value : (float) $fallback;
		return max( $min, min( $max, round( $value, 2 ) ) );
	}

	private function id( $value ) {
		$id = is_string( $value ) ? sanitize_key( $value ) : '';
		if ( ! $id ) {
			$id = 'bg360_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		return $id;
	}
}
