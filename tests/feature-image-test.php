<?php
namespace WordPress\AiClient\Files\Enums {
	class FileTypeEnum {
		public static function inline() { return 'inline'; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MB_IN_BYTES', 1048576 );
	function add_action() {}
	function add_filter( $hook, $callback ) { $GLOBALS['filters'][ $hook ] = $callback; }
	function remove_filter( $hook, $callback ) { unset( $GLOBALS['filters'][ $hook ] ); }
	function __( $message ) { return $message; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_ai_client_prompt( $prompt ) {
		$GLOBALS['observed_timeout'] = $GLOBALS['filters']['wp_ai_client_default_request_timeout']( 30 );
		return $GLOBALS['builder'];
	}
	function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
	function strip_shortcodes( $text ) { return $text; }
	function get_bloginfo( $name ) { return 'Example Site'; }
	function has_excerpt( $post ) { return ! empty( $post->post_excerpt ); }
	class WP_Error {
		public $code;
		public function __construct( $code, $message ) { $this->code = $code; }
	}
	class Fake_Image {
		public function getBase64Data() { return base64_encode( 'image bytes' ); }
	}
	class Fake_Builder {
		public $supported = true;
		public $result;
		public function as_output_file_type( $type ) { return $this; }
		public function is_supported_for_image_generation() { return $this->supported; }
		public function generate_image() { return $this->result; }
	}
	require dirname( __DIR__ ) . '/kreativ-broken-image-finder.php';
	function call_feature( $method, ...$args ) {
		$reflection = new \ReflectionMethod( $GLOBALS['kreativ_broken_image_finder'], $method );
		if ( PHP_VERSION_ID < 80100 ) { $reflection->setAccessible( true ); }
		return $reflection->invokeArgs( $GLOBALS['kreativ_broken_image_finder'], $args );
	}
	function assert_feature( $condition, $message ) {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
	}
	$GLOBALS['builder'] = new Fake_Builder();
	$GLOBALS['builder']->supported = false;
	assert_feature( 'kbif_wordpress_ai_unsupported' === call_feature( 'request_feature_image_from_wordpress_ai', 'prompt' )->code, 'Unsupported provider must be rejected.' );
	$GLOBALS['builder']->supported = true;
	$GLOBALS['builder']->result = new WP_Error( 'provider_error', 'Failed.' );
	assert_feature( 'provider_error' === call_feature( 'request_feature_image_from_wordpress_ai', 'prompt' )->code, 'Provider errors must be returned.' );
	$GLOBALS['builder']->result = new Fake_Image();
	assert_feature( 'image bytes' === call_feature( 'request_feature_image_from_wordpress_ai', 'prompt' ), 'Inline image must decode.' );
	assert_feature( 120 === $GLOBALS['observed_timeout'] && ! isset( $GLOBALS['filters']['wp_ai_client_default_request_timeout'] ), 'Image timeout must be raised only while constructing the builder.' );
	assert_feature( 'kbif_invalid_image_data' === call_feature( 'create_feature_image_attachment', 'image bytes', 1, 'Title' )->code, 'Non-image bytes must be rejected.' );
	$settings = array( 'feature_template_primary_color' => '#102A43', 'feature_template_accent_color' => '#00A6A6', 'feature_template_label' => 'Example' );
	$post = (object) array( 'post_title' => 'Typography and Creative Design', 'post_excerpt' => 'A short introduction to the article.' );
	foreach ( array( '1024x1024', '1536x1024', '1024x1536' ) as $size ) {
		$settings['feature_image_size'] = $size;
		$bytes = call_feature( 'render_feature_template_png', $post, $settings );
		assert_feature( ! is_wp_error( $bytes ), 'Local image generation must succeed with GD and FreeType.' );
		$info = getimagesizefromstring( $bytes );
		assert_feature( $info && 'image/png' === $info['mime'] && $size === $info[0] . 'x' . $info[1], 'Generated PNG dimensions must match the selected size.' );
	}
	assert_feature( 'Café' === call_feature( 'get_feature_template_text', 'Café extra', 4 ), 'Unicode text must not be stripped or cut mid-character.' );
	$font = dirname( __DIR__ ) . '/assets/fonts/Lato-Regular.ttf';
	$long_title = str_repeat( 'WordPress ', 20 ) . str_repeat( 'x', 80 ) . ' Café';
	$lines = call_feature( 'wrap_feature_text_pixels', $long_title, 32, $font, 500 );
	assert_feature( preg_replace( '/\s+/', '', implode( '', $lines ) ) === preg_replace( '/\s+/', '', $long_title ), 'Wrapping must preserve the text.' );
	foreach ( $lines as $line ) {
		$box = imagettfbbox( 32, 0, $font, $line );
		assert_feature( $box && $box[2] - $box[0] <= 500, 'Wrapped lines must fit the available width.' );
	}
	assert_feature( false !== strpos( implode( ' ', $lines ), 'Café' ), 'Wrapped text must preserve accented characters.' );
	echo "Featured-image provider and validation tests passed.\n";
}
