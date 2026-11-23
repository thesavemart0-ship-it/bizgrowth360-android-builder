<?php
/**
 * Standalone validation tests for DesignConfig using WordPress function stubs.
 * Run: php tests/php/design-config-test.php
 */

namespace Bizgrowth360\AppBuilder {
	class Settings {
		const OPTION_KEY = 'bizgrowth360_app_builder_options';
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	$GLOBALS['test_options'] = array(
		'bizgrowth360_app_builder_options' => array(
			'primary_color'        => '#123456',
			'background_color'     => '#FAFAFA',
			'status_bar_color'     => '#111111',
			'navigation_bar_color' => '#EEEEEE',
		),
	);

	function get_option( $key, $default = null ) {
		return array_key_exists( $key, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $key ] : $default;
	}
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['test_options'][ $key ] = $value;
		return true;
	}
	function sanitize_text_field( $value ) {
		$value = strip_tags( (string) $value );
		return trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) );
	}
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
	function sanitize_hex_color( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? strtoupper( $value ) : null;
	}
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function home_url( $path = '' ) {
		return 'https://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	}
	function esc_url_raw( $url, $protocols = null ) {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( $protocols && ! in_array( $scheme, $protocols, true ) ) {
			return '';
		}
		return $url;
	}
	function wp_kses( $html, $allowed ) {
		$tags = array();
		foreach ( array_keys( $allowed ) as $tag ) {
			$tags[] = '<' . $tag . '>';
		}
		$clean = strip_tags( (string) $html, implode( '', $tags ) );
		$clean = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean );
		$clean = preg_replace( '/javascript:/i', '', $clean );
		return $clean;
	}
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
	function __( $text, $domain = null ) {
		return $text;
	}

	require_once __DIR__ . '/../../wordpress-plugin/bizgrowth360-app-builder/includes/class-design-config.php';

	use Bizgrowth360\AppBuilder\DesignConfig;

	$passed = 0;
	$failed = 0;
	function check( $condition, $label ) {
		global $passed, $failed;
		if ( $condition ) {
			$passed++;
			echo "  [PASS] {$label}\n";
		} else {
			$failed++;
			echo "  [FAIL] {$label}\n";
		}
	}

	echo "DesignConfig validation tests\n";
	$config = new DesignConfig();
	$defaults = $config->get_defaults();

	check( 1 === $defaults['schema_version'], 'schema version is 1' );
	check( '#123456' === $defaults['theme']['primary_color'], 'theme primary is seeded from app settings' );
	check( '#FAFAFA' === $defaults['theme']['background_color'], 'theme background is seeded from app settings' );
	check( 'none' === $defaults['navigation']['type'], 'navigation is disabled by default' );
	check( false === $defaults['homepage']['enabled'], 'custom homepage is disabled by default' );

	$sanitized = $config->sanitize(
		array(
			'schema_version' => 99,
			'theme'          => array(
				'mode'            => 'dark',
				'primary_color'   => 'not-a-color',
				'accent_color'    => '#abc',
				'status_bar_color' => '#000000',
			),
			'header'         => array(
				'actions' => array_fill( 0, 6, array( 'label' => 'A', 'icon' => 'link', 'url' => 'https://example.com', 'enabled' => true ) ),
			),
			'navigation'     => array(
				'type'  => 'bottom',
				'items' => array_fill( 0, 12, array( 'label' => 'Item', 'type' => 'website', 'url' => 'https://example.com', 'visible' => true ) ),
			),
			'menu'           => array(
				'groups' => array( array( 'id' => 'main', 'label' => 'Main' ) ),
				'items'  => array(
					array( 'label' => 'Home', 'type' => 'home', 'group' => 'missing-group', 'enabled' => true ),
					array( 'label' => 'Bad', 'type' => 'website', 'url' => 'javascript:alert(1)', 'enabled' => true ),
				),
			),
			'homepage'       => array(
				'enabled'  => true,
				'sections' => array_fill( 0, 25, array( 'type' => 'welcome', 'title' => 'Hello', 'enabled' => true ) ),
			),
			'components'     => array( 'card_radius' => 999, 'style_preset' => 'bad' ),
			'typography'     => array( 'body_font' => 'comic-sans', 'base_size' => 100 ),
			'layout'         => array( 'page_padding' => 999, 'max_width' => 10 ),
		)
	);

	check( 1 === $sanitized['schema_version'], 'input schema version is replaced with current version' );
	check( '#123456' === $sanitized['theme']['primary_color'], 'invalid color falls back to default' );
	check( '#AABBCC' === $sanitized['theme']['accent_color'], '3-digit color is normalized' );
	check( 'dark' === $sanitized['theme']['mode'], 'valid light/dark/auto mode is preserved' );
	check( 4 === count( $sanitized['header']['actions'] ), 'header actions are capped at 4' );
	check( 8 === count( $sanitized['navigation']['items'] ), 'navigation items are capped at 8' );
	check( 20 === count( $sanitized['homepage']['sections'] ), 'homepage sections are capped at 20' );
	check( 'main' === $sanitized['menu']['items'][0]['group'], 'orphan menu item is assigned to first group' );
	check( '' === $sanitized['menu']['items'][1]['url'], 'javascript URL is rejected' );
	check( 32 === $sanitized['components']['card_radius'], 'component radius is clamped' );
	check( 'modern' === $sanitized['components']['style_preset'], 'invalid component preset falls back' );
	check( 'system' === $sanitized['typography']['body_font'], 'invalid font falls back to system' );
	check( 20 === $sanitized['typography']['base_size'], 'typography size is clamped' );
	check( 32 === $sanitized['layout']['page_padding'], 'page padding is clamped' );
	check( 320 === $sanitized['layout']['max_width'], 'max width is clamped' );

	$urls = $config->sanitize(
		array(
			'navigation' => array(
				'items' => array(
					array( 'label' => 'One', 'type' => 'custom', 'url' => 'https://example.com/a', 'visible' => true ),
					array( 'label' => 'Two', 'type' => 'custom', 'url' => '/relative', 'visible' => true ),
					array( 'label' => 'Three', 'type' => 'custom', 'url' => 'app:home', 'visible' => true ),
					array( 'label' => 'Four', 'type' => 'custom', 'url' => 'app:evil', 'visible' => true ),
					array( 'label' => 'Five', 'type' => 'contact', 'url' => 'tel:+911234567890', 'visible' => true ),
					array( 'label' => 'Six', 'type' => 'contact', 'url' => 'javascript:alert(1)', 'visible' => true ),
				),
			),
		)
	);
	$urls = $urls['navigation']['items'];
	check( 'https://example.com/a' === $urls[0]['url'], 'https URL preserved' );
	check( 'https://example.com/relative' === $urls[1]['url'], 'relative URL resolved against site' );
	check( 'app:home' === $urls[2]['url'], 'allowed internal app URL preserved' );
	check( '' === $urls[3]['url'], 'unsafe internal app URL rejected' );
	check( 'tel:+911234567890' === $urls[4]['url'], 'telephone URL preserved' );
	check( '' === $urls[5]['url'], 'javascript URL rejected for navigation' );

	$html = $config->sanitize(
		array(
			'homepage' => array(
				'enabled'  => true,
				'sections' => array(
					array(
						'type' => 'custom_html',
						'text' => '<p>Hello</p><script>alert(1)</script><a href="javascript:alert(1)" onclick="steal()">x</a>',
					),
				),
			),
		)
	);
	$text = $html['homepage']['sections'][0]['text'];
	check( false === stripos( $text, '<script' ), 'script tags are stripped from custom HTML' );
	check( false === stripos( $text, 'onclick' ), 'event handler attributes are stripped' );
	check( false === stripos( $text, 'javascript:' ), 'javascript URLs are stripped' );
	check( false !== stripos( $text, '<p>Hello</p>' ), 'safe formatting tags are preserved' );

	$saved = $config->save( $defaults );
	check( ! is_wp_error( $saved ), 'save accepts a valid design' );
	check( is_array( $config->get() ), 'get returns the persisted design' );
	check( 0 < (int) get_option( DesignConfig::SAVED_AT_KEY, 0 ), 'save timestamp is stored' );

	$reset = $config->reset( 'theme' );
	check( ! is_wp_error( $reset ) && '#123456' === $reset['theme']['primary_color'], 'section reset restores defaults' );

	$all = $config->reset();
	check( ! is_wp_error( $all ) && false === $all['homepage']['enabled'], 'full reset restores defaults' );

	echo "\nDesignConfig tests: {$passed} passed, {$failed} failed.\n";
	exit( $failed ? 1 : 0 );
}
