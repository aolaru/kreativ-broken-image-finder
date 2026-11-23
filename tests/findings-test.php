<?php
/** Standalone regression tests for URL checks and finding classifications. */
define( 'ABSPATH', __DIR__ );
function add_action() {}
function __( $value ) { return $value; }
function get_option( $name, $default = false ) { return $default; }
function is_wp_error( $value ) { return $value instanceof Test_Error; }
function wp_remote_head( $url, $args ) { return $GLOBALS['head_response']; }
function wp_remote_get( $url, $args ) { ++$GLOBALS['get_calls']; return $GLOBALS['get_response']; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
class Test_Error {
	public function get_error_message() { return 'Connection timed out'; }
}
require dirname( __DIR__ ) . '/kreativ-broken-image-finder.php';

function invoke_private( $object, $method, ...$args ) {
	$reflection = new ReflectionMethod( $object, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}
	return $reflection->invokeArgs( $object, $args );
}
function expect_same( $expected, $actual, $name ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "$name: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$cases = array(
	array( array( 'source' => 'content', 'status_code' => 404 ), 'URL reported missing' ),
	array( array( 'source' => 'custom_field', 'status_code' => 410 ), 'URL reported missing' ),
	array( array( 'source' => 'excerpt', 'status_code' => 403 ), 'Needs verification' ),
	array( array( 'source' => 'content', 'status_code' => 500 ), 'Needs verification' ),
	array( array( 'source' => 'content', 'status_code' => 0 ), 'Needs verification' ),
	array( array( 'source' => 'media_library', 'status_code' => '' ), 'Missing local file' ),
	array( array( 'source' => 'missing_featured', 'status_code' => '' ), 'No featured image' ),
	array( array( 'source' => 'content' ), 'Needs verification' ),
);
foreach ( $cases as $case ) {
	expect_same( $case[1], invoke_private( $kreativ_broken_image_finder, 'get_finding_label', $case[0] ), 'Finding label' );
}

$responses = array(
	array( array( 'code' => 200 ), null, false, 0 ),
	array( array( 'code' => 404 ), null, true, 0 ),
	array( array( 'code' => 403 ), array( 'code' => 200 ), false, 1 ),
	array( array( 'code' => 403 ), array( 'code' => 403 ), true, 1 ),
	array( new Test_Error(), new Test_Error(), true, 1 ),
);
foreach ( $responses as $index => $case ) {
	$GLOBALS['head_response'] = $case[0];
	$GLOBALS['get_response']  = $case[1];
	$GLOBALS['get_calls']     = 0;
	$result = invoke_private( $kreativ_broken_image_finder, 'check_image_url', "https://example.test/$index.jpg" );
	expect_same( $case[2], $result['is_broken'], 'HTTP result' );
	expect_same( $case[3], $GLOBALS['get_calls'], 'GET fallback' );
}
echo "Finding and HTTP regression tests passed.\n";
