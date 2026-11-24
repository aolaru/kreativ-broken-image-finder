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
	function __( $message ) { return $message; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_ai_client_prompt( $prompt ) { return $GLOBALS['builder']; }
	function wp_remote_post( $url, $args ) { return $GLOBALS['response']; }
	function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
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
	assert_feature( 'kbif_invalid_image_data' === call_feature( 'create_feature_image_attachment', 'image bytes', 1, 'Title' )->code, 'Non-image bytes must be rejected.' );
	$settings = array( 'feature_api_key' => 'test', 'feature_model' => 'gpt-image-1', 'feature_image_size' => '1024x1024' );
	$GLOBALS['response'] = array( 'code' => 429, 'body' => '{"error":{"message":"rate limited"}}' );
	assert_feature( 'kbif_generation_failed' === call_feature( 'request_feature_image_from_openai', 'prompt', $settings )->code, 'OpenAI error must be returned.' );
	$GLOBALS['response'] = array( 'code' => 200, 'body' => '{"data":[{"b64_json":"%%%"}]}' );
	assert_feature( 'kbif_invalid_image_data' === call_feature( 'request_feature_image_from_openai', 'prompt', $settings )->code, 'Invalid base64 must be rejected.' );
	$GLOBALS['response'] = array( 'code' => 200, 'body' => json_encode( array( 'data' => array( array( 'b64_json' => base64_encode( 'image bytes' ) ) ) ) ) );
	assert_feature( 'image bytes' === call_feature( 'request_feature_image_from_openai', 'prompt', $settings ), 'OpenAI inline data must decode.' );
	echo "Featured-image provider and validation tests passed.\n";
}
