<?php
/**
 * Plugin Name:       Kreativ Broken Image Finder
 * Plugin URI:        https://kreativfont.com/tools/kreativ-broken-image-finder-wp-plugin
 * Description:       Scan your site for broken images in posts, pages and custom post types, and get a simple report inside your dashboard.
 * Version:           1.2.3
 * Author:            Andrei Olaru
 * Author URI:        https://kreativfont.com/
 * Text Domain:       kreativ-broken-image-finder
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package Kreativ_Broken_Image_Finder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Kreativ_Broken_Image_Finder' ) ) :

class Kreativ_Broken_Image_Finder {

	const VERSION         = '1.2.3';
	const OPTION_RESULTS  = 'kbif_last_scan_results';
	const OPTION_STATS    = 'kbif_last_scan_stats';
	const OPTION_QUEUE    = 'kbif_scan_queue';
	const OPTION_PROGRESS = 'kbif_scan_progress';
	const OPTION_SETTINGS = 'kbif_settings';
	const OPTION_URL_CACHE = 'kbif_scan_url_cache';

	/**
	 * Per-request cache of URL checks for the active scan.
	 *
	 * @var array|null
	 */
	private $url_check_cache = null;

	/**
	 * Whether the in-memory cache needs to be flushed back to storage.
	 *
	 * @var bool
	 */
	private $url_check_cache_dirty = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_kbif_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_kbif_scan_step', array( $this, 'ajax_scan_step' ) );
	}

	/**
	 * Register top level Kreativ Broken Image Finder menu.
	 */

	public function add_admin_menu() {

		add_menu_page(
			__( 'Kreativ Broken Image Finder', 'kreativ-broken-image-finder' ),
			__( 'Kreativ Broken Image Finder', 'kreativ-broken-image-finder' ),
			'manage_options',
			'kreativ-broken-image-finder',
			array( $this, 'render_admin_page' ),
			'dashicons-format-image',
			66
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {

		if ( 'toplevel_page_kreativ-broken-image-finder' !== $hook ) {
			return;
		}		

		wp_enqueue_style(
			'kbif-style',
			plugin_dir_url( __FILE__ ) . 'css/kbif.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'kbif-scan',
			plugin_dir_url( __FILE__ ) . 'js/kbif-scan.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'kbif-scan',
			'KBIF_Ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'kbif_scan_nonce' ),
			)
		);
	}

	/**
	 * Save plugin settings from the admin screen.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'kreativ-broken-image-finder' ) );
		}

		check_admin_referer( 'kbif_save_settings', 'kbif_settings_nonce' );

		$mode = isset( $_POST['kbif_missing_featured_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['kbif_missing_featured_mode'] ) ) : 'all';
		$mode = in_array( $mode, array( 'all', 'none', 'selected' ), true ) ? $mode : 'all';

		$post_types = isset( $_POST['kbif_missing_featured_post_types'] ) ? (array) wp_unslash( $_POST['kbif_missing_featured_post_types'] ) : array();
		$post_types = array_map( 'sanitize_key', $post_types );
		$post_types = array_values( array_intersect( $post_types, $this->get_scannable_post_types() ) );

		update_option(
			self::OPTION_SETTINGS,
			array(
				'missing_featured_mode'       => $mode,
				'missing_featured_post_types' => $post_types,
			)
		);

		$redirect_url = add_query_arg(
			array(
				'page'                  => 'kreativ-broken-image-finder',
				'kbif_settings_updated' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * AJAX handler for multi-step scan.
	 */
	public function ajax_scan_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'kreativ-broken-image-finder' ) );
		}

		check_ajax_referer( 'kbif_scan_nonce', 'nonce' );

		$step = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';

		if ( 'init' === $step ) {
			$total = $this->reset_scan_state( 5 );

			wp_send_json_success(
				array(
					'total' => $total,
				)
			);
		}

		if ( 'process' === $step ) {
			$progress = get_option( self::OPTION_PROGRESS, array() );
			$stats    = get_option( self::OPTION_STATS, array() );
			$total    = isset( $stats['total_posts'] ) ? intval( $stats['total_posts'] ) : 0;

			if ( 0 === $total ) {
				wp_send_json_success(
					array(
						'finished' => true,
						'total'    => 0,
						'pointer'  => 0,
					)
				);
			}

			$pointer    = isset( $_POST['pointer'] ) ? max( 0, intval( $_POST['pointer'] ) ) : 0;
			$batch_size = isset( $progress['batch_size'] ) ? max( 1, intval( $progress['batch_size'] ) ) : 5;

			if ( $pointer >= $total ) {
				wp_send_json_success(
					array(
						'finished' => true,
						'total'    => $total,
						'pointer'  => $pointer,
					)
				);
			}

			$pointer = $this->process_scan_batch( $pointer, $batch_size, $total, $progress, $stats );

			$finished = ( $pointer >= $total );

			wp_send_json_success(
				array(
					'finished' => $finished,
					'total'    => $total,
					'pointer'  => $pointer,
				)
			);
		}

		if ( 'finish' === $step ) {
			$this->finalize_scan_state();

			wp_send_json_success();
		}

		wp_send_json_error( __( 'Invalid request.', 'kreativ-broken-image-finder' ) );
	}

	/**
	 * Scan a single post for broken / missing images.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function scan_single_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'items'                    => array(),
				'images_found'             => 0,
				'broken_images'            => 0,
				'missing_featured_images'  => 0,
			);
		}

		$post_title = get_the_title( $post_id );
		$post_type  = get_post_type( $post_id );
		$post_url   = get_permalink( $post_id );
		$items      = array();

		$images_found            = 0;
		$broken_images           = 0;
		$missing_featured_images = 0;

		// Content images.
		$content        = $post->post_content;
		$content_images = $this->find_images_in_content( $content );

		foreach ( $content_images as $image_url ) {
			$images_found++;

			usleep( 150000 );

			$check = $this->check_image_url( $image_url, $post_url );

			if ( $check['is_broken'] ) {
				$broken_images++;

				$items[] = array(
					'post_id'     => $post_id,
					'post_title'  => $post_title,
					'post_type'   => $post_type,
					'image_url'   => $image_url,
					'source'      => 'content',
					'status_code' => $check['status_code'],
					'error'       => $check['error_message'],
				);
			}
		}

		// Featured image & missing featured.
		if ( has_post_thumbnail( $post_id ) ) {
			$thumb_id  = get_post_thumbnail_id( $post_id );
			$image_url = wp_get_attachment_image_url( $thumb_id, 'full' );

			if ( $image_url ) {
				$images_found++;

				$check = $this->check_image_url( $image_url, $post_url );

				if ( $check['is_broken'] ) {
					$broken_images++;

					$items[] = array(
						'post_id'     => $post_id,
						'post_title'  => $post_title,
						'post_type'   => $post_type,
						'image_url'   => $image_url,
						'source'      => 'featured',
						'status_code' => $check['status_code'],
						'error'       => $check['error_message'],
					);
				}
			}
		} elseif ( $this->should_report_missing_featured_image( $post_type ) ) {
			$missing_featured_images++;

			$items[] = array(
				'post_id'     => $post_id,
				'post_title'  => $post_title,
				'post_type'   => $post_type,
				'image_url'   => '',
				'source'      => 'missing_featured',
				'status_code' => '',
				'error'       => __( 'No featured image set', 'kreativ-broken-image-finder' ),
			);
		}

		return array(
			'items'                   => $items,
			'images_found'            => $images_found,
			'broken_images'           => $broken_images,
			'missing_featured_images' => $missing_featured_images,
		);
	}

	/**
	 * Find all image URLs in post content.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	protected function find_images_in_content( $content ) {
		$urls = array();

		if ( empty( $content ) ) {
			return $urls;
		}

		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = trim( $url );

				// Skip empty and data URIs.
				if ( empty( $url ) || 0 === strpos( $url, 'data:' ) ) {
					continue;
				}

				$urls[] = $url;
			}
		}

		$urls = array_values( array_unique( $urls ) );

		return $urls;
	}

	/**
	 * Check if a given image URL is broken.
	 *
	 * @param string $url Image URL.
	 * @return array
	 */
	protected function check_image_url( $url, $base_url = '' ) {
		$result = array(
			'is_broken'     => false,
			'status_code'   => 0,
			'error_message' => '',
		);

		$url = $this->normalize_image_url( $url, $base_url );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $result;
		}

		$cache = $this->get_url_check_cache();

		if ( isset( $cache[ $url ] ) && is_array( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$response = wp_remote_head( $url, $this->get_request_args() );

		if ( $this->should_retry_with_get( $response ) ) {
			$response = wp_remote_get( $url, $this->get_request_args() );
		}

		if ( is_wp_error( $response ) ) {
			$result['is_broken']     = true;
			$result['status_code']   = 0;
			$result['error_message'] = $response->get_error_message();
			$this->remember_url_check( $url, $result );
			return $result;
		}

		$status_code             = wp_remote_retrieve_response_code( $response );
		$result['status_code']   = (int) $status_code;

		if ( $status_code >= 400 || $status_code < 200 ) {
			$result['is_broken']     = true;
			$result['error_message'] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'HTTP status %d', 'kreativ-broken-image-finder' ),
				$status_code
			);
		}

		$this->remember_url_check( $url, $result );

		return $result;
	}

	/**
	 * Reset saved state for a new scan.
	 *
	 * @param int $batch_size Batch size.
	 * @return int
	 */
	private function reset_scan_state( $batch_size ) {
		$total = $this->count_scannable_posts();

		delete_option( self::OPTION_QUEUE );
		delete_option( self::OPTION_URL_CACHE );

		update_option(
			self::OPTION_STATS,
			array(
				'total_posts'             => $total,
				'total_images_found'      => 0,
				'broken_images'           => 0,
				'missing_featured_images' => 0,
				'scan_time'               => 0,
			)
		);

		update_option( self::OPTION_RESULTS, array() );
		update_option(
			self::OPTION_PROGRESS,
			array(
				'processed_posts' => 0,
				'batch_size'      => max( 1, (int) $batch_size ),
				'started_at'      => microtime( true ),
			)
		);

		$this->url_check_cache       = array();
		$this->url_check_cache_dirty = false;

		return $total;
	}

	/**
	 * Finalize a scan and clear temporary state.
	 */
	private function finalize_scan_state() {
		$stats    = get_option( self::OPTION_STATS, array() );
		$progress = get_option( self::OPTION_PROGRESS, array() );

		if ( isset( $progress['started_at'] ) ) {
			$stats['scan_time'] = round( microtime( true ) - $progress['started_at'], 2 );
			update_option( self::OPTION_STATS, $stats );
		}

		$this->flush_url_check_cache();

		delete_option( self::OPTION_QUEUE );
		delete_option( self::OPTION_PROGRESS );
		delete_option( self::OPTION_URL_CACHE );
	}

	/**
	 * Process one scan batch and persist progress.
	 *
	 * @param int   $pointer Current pointer.
	 * @param int   $batch_size Batch size.
	 * @param int   $total Total posts in scan.
	 * @param array $progress Progress payload.
	 * @param array $stats Scan stats payload.
	 * @return int
	 */
	private function process_scan_batch( $pointer, $batch_size, $total, $progress, $stats ) {
		$posts         = $this->get_scan_batch_posts( $pointer, $batch_size );
		$batch_items   = array();
		$delta_images  = 0;
		$delta_broken  = 0;
		$delta_missing = 0;

		$this->get_url_check_cache();

		if ( empty( $posts ) ) {
			return $total;
		}

		foreach ( $posts as $post_id ) {
			$scan_data = $this->scan_single_post( $post_id );

			$batch_items   = array_merge( $batch_items, $scan_data['items'] );
			$delta_images  += $scan_data['images_found'];
			$delta_broken  += $scan_data['broken_images'];
			$delta_missing += $scan_data['missing_featured_images'];
		}

		$pointer += count( $posts );

		$existing_results = get_option( self::OPTION_RESULTS, array() );
		update_option( self::OPTION_RESULTS, array_merge( $existing_results, $batch_items ) );

		if ( empty( $stats ) ) {
			$stats = array(
				'total_posts'             => $total,
				'total_images_found'      => 0,
				'broken_images'           => 0,
				'missing_featured_images' => 0,
				'scan_time'               => 0,
			);
		}

		$stats['total_images_found']      += $delta_images;
		$stats['broken_images']           += $delta_broken;
		$stats['missing_featured_images'] += $delta_missing;
		update_option( self::OPTION_STATS, $stats );

		$progress['processed_posts'] = $pointer;
		update_option( self::OPTION_PROGRESS, $progress );

		$this->flush_url_check_cache();

		return $pointer;
	}

	/**
	 * Get plugin settings with defaults applied.
	 *
	 * @return array
	 */
	private function get_settings() {
		$defaults = array(
			'missing_featured_mode'       => 'all',
			'missing_featured_post_types' => array(),
		);

		$settings = get_option( self::OPTION_SETTINGS, array() );
		$settings = wp_parse_args( $settings, $defaults );
		$settings['missing_featured_mode'] = in_array( $settings['missing_featured_mode'], array( 'all', 'none', 'selected' ), true ) ? $settings['missing_featured_mode'] : 'all';
		$settings['missing_featured_post_types'] = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $settings['missing_featured_post_types'] ),
				$this->get_scannable_post_types()
			)
		);

		return $settings;
	}

	/**
	 * Determine whether a post type should be flagged for missing featured images.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private function should_report_missing_featured_image( $post_type ) {
		$settings = $this->get_settings();

		if ( 'none' === $settings['missing_featured_mode'] ) {
			return false;
		}

		if ( 'selected' === $settings['missing_featured_mode'] ) {
			return in_array( $post_type, $settings['missing_featured_post_types'], true );
		}

		return true;
	}

	/**
	 * Load the per-scan URL cache.
	 *
	 * @return array
	 */
	private function get_url_check_cache() {
		if ( null === $this->url_check_cache ) {
			$cache = get_option( self::OPTION_URL_CACHE, array() );
			$this->url_check_cache = is_array( $cache ) ? $cache : array();
		}

		return $this->url_check_cache;
	}

	/**
	 * Remember the check result for a URL in the active scan cache.
	 *
	 * @param string $url URL cache key.
	 * @param array  $result Scan result.
	 */
	private function remember_url_check( $url, $result ) {
		$this->get_url_check_cache();
		$this->url_check_cache[ $url ] = $result;
		$this->url_check_cache_dirty    = true;
	}

	/**
	 * Persist the in-memory URL cache.
	 */
	private function flush_url_check_cache() {
		if ( ! $this->url_check_cache_dirty || null === $this->url_check_cache ) {
			return;
		}

		update_option( self::OPTION_URL_CACHE, $this->url_check_cache, false );
		$this->url_check_cache_dirty = false;
	}

	/**
	 * Run a full scan for WP-CLI.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array
	 */
	public function run_cli_scan( $assoc_args = array() ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 20;
		$total      = $this->reset_scan_state( $batch_size );
		$pointer    = 0;

		while ( $pointer < $total ) {
			$progress = get_option( self::OPTION_PROGRESS, array() );
			$stats    = get_option( self::OPTION_STATS, array() );
			$pointer  = $this->process_scan_batch( $pointer, $batch_size, $total, $progress, $stats );
		}

		$this->finalize_scan_state();

		return array(
			'stats'   => get_option( self::OPTION_STATS, array() ),
			'results' => get_option( self::OPTION_RESULTS, array() ),
		);
	}

	/**
	 * Get public post types that should be scanned.
	 *
	 * @return array
	 */
	private function get_scannable_post_types() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		unset( $post_types['attachment'] );

		return array_values( $post_types );
	}

	/**
	 * Count all published posts that should be scanned.
	 *
	 * @return int
	 */
	private function count_scannable_posts() {
		$total = 0;

		foreach ( $this->get_scannable_post_types() as $post_type ) {
			$counts = wp_count_posts( $post_type );

			if ( isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}

		return $total;
	}

	/**
	 * Get one scan batch of published post IDs.
	 *
	 * @param int $offset Batch offset.
	 * @param int $batch_size Batch size.
	 * @return array
	 */
	private function get_scan_batch_posts( $offset, $batch_size ) {
		return get_posts(
			array(
				'post_type'              => $this->get_scannable_post_types(),
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => $batch_size,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Build request arguments for image checks.
	 *
	 * @return array
	 */
	private function get_request_args() {
		return array(
			'timeout'             => 10,
			'redirection'         => 3,
			'limit_response_size' => 1024,
		);
	}

	/**
	 * Determine whether a HEAD response should be retried with GET.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return bool
	 */
	private function should_retry_with_get( $response ) {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		return in_array( $status_code, array( 403, 405, 501 ), true );
	}

	/**
	 * Normalize an image URL into an absolute HTTP(S) URL when possible.
	 *
	 * @param string $url Image URL from content.
	 * @param string $base_url Base document URL.
	 * @return string
	 */
	private function normalize_image_url( $url, $base_url = '' ) {
		$url = trim( $url );

		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = is_ssl() ? 'https:' : 'http:';
			return $scheme . $url;
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return rtrim( home_url(), '/' ) . $url;
		}

		if ( '' === $base_url || ! preg_match( '#^https?://#i', $base_url ) ) {
			return '';
		}

		return $this->build_absolute_url_from_base( $base_url, $url );
	}

	/**
	 * Resolve a relative URL against a base document URL.
	 *
	 * @param string $base_url Base document URL.
	 * @param string $relative_url Relative image URL.
	 * @return string
	 */
	private function build_absolute_url_from_base( $base_url, $relative_url ) {
		$base_parts = wp_parse_url( $base_url );

		if ( empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return '';
		}

		$base_root = $base_parts['scheme'] . '://' . $base_parts['host'];

		if ( ! empty( $base_parts['port'] ) ) {
			$base_root .= ':' . $base_parts['port'];
		}

		$fragment = '';
		$query    = '';

		if ( false !== strpos( $relative_url, '#' ) ) {
			list( $relative_url, $fragment ) = explode( '#', $relative_url, 2 );
			$fragment = '#' . $fragment;
		}

		if ( false !== strpos( $relative_url, '?' ) ) {
			list( $relative_url, $query ) = explode( '?', $relative_url, 2 );
			$query = '?' . $query;
		}

		$base_path      = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$base_directory = $this->get_url_path_directory( $base_path );
		$segments       = explode( '/', trim( $base_directory . $relative_url, '/' ) );
		$resolved       = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $resolved );
				continue;
			}

			$resolved[] = $segment;
		}

		return $base_root . '/' . implode( '/', $resolved ) . $query . $fragment;
	}

	/**
	 * Get the directory portion of a URL path.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	private function get_url_path_directory( $path ) {
		$path = '' === $path ? '/' : $path;

		if ( '/' === substr( $path, -1 ) ) {
			return $path;
		}

		return trailingslashit( dirname( $path ) );
	}

	/**
	 * Render admin page with filters + pagination.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$results = get_option( self::OPTION_RESULTS, array() );
		$settings = $this->get_settings();
		$stats   = get_option(
			self::OPTION_STATS,
			array(
				'total_posts'             => 0,
				'total_images_found'      => 0,
				'broken_images'           => 0,
				'missing_featured_images' => 0,
				'scan_time'               => 0,
			)
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scanned = isset( $_GET['kbif_scanned'] ) && '1' === $_GET['kbif_scanned'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings_updated = isset( $_GET['kbif_settings_updated'] ) && '1' === $_GET['kbif_settings_updated'];

		// Filters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type_filter = isset( $_GET['kbif_post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['kbif_post_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source_filter    = isset( $_GET['kbif_source'] ) ? sanitize_text_field( wp_unslash( $_GET['kbif_source'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter    = isset( $_GET['kbif_status'] ) ? sanitize_text_field( wp_unslash( $_GET['kbif_status'] ) ) : '';

		// Distinct status codes.
		$distinct_status = array();
		foreach ( $results as $r ) {
			if ( '' === $r['status_code'] ) {
				continue;
			}
			if ( ! in_array( $r['status_code'], $distinct_status, true ) ) {
				$distinct_status[] = $r['status_code'];
			}
		}
		sort( $distinct_status );

		// Apply filters.
		$filtered_results = array_filter(
			$results,
			function( $row ) use ( $post_type_filter, $source_filter, $status_filter ) {
				if ( $post_type_filter && $row['post_type'] !== $post_type_filter ) {
					return false;
				}
				if ( $source_filter && $row['source'] !== $source_filter ) {
					return false;
				}
				if ( '' !== $status_filter && '' !== $row['status_code'] && intval( $status_filter ) !== intval( $row['status_code'] ) ) {
					return false;
				}
				if ( '' !== $status_filter && '' === $row['status_code'] ) {
					return false;
				}
				return true;
			}
		);

		// Pagination.
		$per_page    = 20;
		$total       = count( $filtered_results );
		$total_pages = max( 1, ceil( $total / $per_page ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended		
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$current_page = min( $current_page, $total_pages );

		$offset        = ( $current_page - 1 ) * $per_page;
		$paged_results = array_slice( $filtered_results, $offset, $per_page );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kreativ Broken Image Finder', 'kreativ-broken-image-finder' ); ?></h1>

			<p><?php esc_html_e( 'Scan your posts, pages and custom post types for broken images inside the content and featured images. The scan runs in the background in small chunks to avoid timeouts.', 'kreativ-broken-image-finder' ); ?></p>

			<?php if ( $scanned ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Scan completed. See the summary and report below.', 'kreativ-broken-image-finder' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $settings_updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'kreativ-broken-image-finder' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<button id="kbif-start-scan" class="button button-primary">
					<?php esc_html_e( 'Run Full Scan', 'kreativ-broken-image-finder' ); ?>
				</button>
			</p>

			<div id="kbif-progress-wrapper">
				<div id="kbif-progress-container">
					<div id="kbif-progress-bar"></div>
				</div>
				<div id="kbif-progress-text">Starting...</div>
			</div>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Scan Settings', 'kreativ-broken-image-finder' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
				<input type="hidden" name="action" value="kbif_save_settings">
				<?php wp_nonce_field( 'kbif_save_settings', 'kbif_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Missing featured image rule', 'kreativ-broken-image-finder' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="kbif_missing_featured_mode" value="all" <?php checked( $settings['missing_featured_mode'], 'all' ); ?>>
										<?php esc_html_e( 'Report missing featured images for all scanned post types', 'kreativ-broken-image-finder' ); ?>
									</label>
									<br>
									<label>
										<input type="radio" name="kbif_missing_featured_mode" value="none" <?php checked( $settings['missing_featured_mode'], 'none' ); ?>>
										<?php esc_html_e( 'Do not report missing featured images', 'kreativ-broken-image-finder' ); ?>
									</label>
									<br>
									<label>
										<input type="radio" name="kbif_missing_featured_mode" value="selected" <?php checked( $settings['missing_featured_mode'], 'selected' ); ?>>
										<?php esc_html_e( 'Report missing featured images only for selected post types', 'kreativ-broken-image-finder' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Post types for missing featured image reporting', 'kreativ-broken-image-finder' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( $this->get_scannable_post_types() as $post_type_name ) : ?>
										<label style="display:inline-block;min-width:160px;margin:0 12px 8px 0;">
											<input type="checkbox" name="kbif_missing_featured_post_types[]" value="<?php echo esc_attr( $post_type_name ); ?>" <?php checked( in_array( $post_type_name, $settings['missing_featured_post_types'], true ) ); ?>>
											<?php echo esc_html( $post_type_name ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'These selections apply only when "selected post types" is chosen above.', 'kreativ-broken-image-finder' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Save Settings', 'kreativ-broken-image-finder' ); ?>">
				</p>
			</form>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Last Scan Summary', 'kreativ-broken-image-finder' ); ?></h2>
			<table class="widefat striped" style="max-width: 700px;">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Total posts scanned', 'kreativ-broken-image-finder' ); ?></th>
						<td><?php echo esc_html( (int) $stats['total_posts'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Images checked', 'kreativ-broken-image-finder' ); ?></th>
						<td><?php echo esc_html( (int) $stats['total_images_found'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Broken images found', 'kreativ-broken-image-finder' ); ?></th>
						<td>
							<?php
							$broken = (int) $stats['broken_images'];
							if ( $broken > 0 ) {
								echo '<strong style="color:#d63638;">' . esc_html( $broken ) . '</strong>';
							} else {
								echo '<strong style="color:#1a7f37;">' . esc_html( $broken ) . '</strong>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Posts without featured image', 'kreativ-broken-image-finder' ); ?></th>
						<td><?php echo esc_html( (int) $stats['missing_featured_images'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Scan time (seconds)', 'kreativ-broken-image-finder' ); ?></th>
						<td><?php echo esc_html( $stats['scan_time'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 style="margin-top:2em;"><?php esc_html_e( 'Broken Images & Missing Featured Images Report', 'kreativ-broken-image-finder' ); ?></h2>

			<!-- Filters -->
			<form method="get" style="margin: 1em 0;">
				<input type="hidden" name="page" value="kreativ-broken-image-finder">

				<select name="kbif_post_type">
					<option value=""><?php esc_html_e( 'All Post Types', 'kreativ-broken-image-finder' ); ?></option>
					<?php
					$post_types = get_post_types( array( 'public' => true ), 'names' );
					unset( $post_types['attachment'] );
					foreach ( $post_types as $pt ) :
						?>
						<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $post_type_filter, $pt ); ?>>
							<?php echo esc_html( $pt ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="kbif_source">
					<option value=""><?php esc_html_e( 'All Sources', 'kreativ-broken-image-finder' ); ?></option>
					<option value="content" <?php selected( $source_filter, 'content' ); ?>>
						<?php esc_html_e( 'Content Images', 'kreativ-broken-image-finder' ); ?>
					</option>
					<option value="featured" <?php selected( $source_filter, 'featured' ); ?>>
						<?php esc_html_e( 'Featured Images', 'kreativ-broken-image-finder' ); ?>
					</option>
					<option value="missing_featured" <?php selected( $source_filter, 'missing_featured' ); ?>>
						<?php esc_html_e( 'Missing Featured Image', 'kreativ-broken-image-finder' ); ?>
					</option>
				</select>

				<select name="kbif_status">
					<option value=""><?php esc_html_e( 'All Status Codes', 'kreativ-broken-image-finder' ); ?></option>
					<?php foreach ( $distinct_status as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( intval( $status_filter ), intval( $code ) ); ?>>
							<?php echo esc_html( $code ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="submit" class="button" value="<?php esc_attr_e( 'Apply Filters', 'kreativ-broken-image-finder' ); ?>">
			</form>

			<?php if ( empty( $paged_results ) ) : ?>
				<p><?php esc_html_e( 'No results found with current filters, or you have not run a scan yet.', 'kreativ-broken-image-finder' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'kreativ-broken-image-finder' ); ?></th>
							<th><?php esc_html_e( 'Post type', 'kreativ-broken-image-finder' ); ?></th>
							<th><?php esc_html_e( 'Source', 'kreativ-broken-image-finder' ); ?></th>
							<th><?php esc_html_e( 'Image URL', 'kreativ-broken-image-finder' ); ?></th>
							<th><?php esc_html_e( 'Status', 'kreativ-broken-image-finder' ); ?></th>
							<th><?php esc_html_e( 'Error', 'kreativ-broken-image-finder' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $paged_results as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>">
										<?php echo esc_html( $row['post_title'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $row['post_type'] ); ?></td>
								<td>
									<?php
									if ( 'featured' === $row['source'] ) {
										esc_html_e( 'Featured image', 'kreativ-broken-image-finder' );
									} elseif ( 'missing_featured' === $row['source'] ) {
										esc_html_e( 'Missing featured image', 'kreativ-broken-image-finder' );
									} else {
										esc_html_e( 'Content', 'kreativ-broken-image-finder' );
									}
									?>
								</td>
								<td style="word-break: break-all;">
									<?php if ( ! empty( $row['image_url'] ) ) : ?>
										<a href="<?php echo esc_url( $row['image_url'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( $row['image_url'] ); ?>
										</a>
									<?php else : ?>
										<em><?php esc_html_e( '—', 'kreativ-broken-image-finder' ); ?></em>
									<?php endif; ?>
								</td>
								<td><?php echo '' === $row['status_code'] ? '—' : esc_html( (int) $row['status_code'] ); ?></td>
								<td><?php echo esc_html( $row['error'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="tablenav bottom" style="margin-top:10px;">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php echo esc_html( $total ); ?> <?php esc_html_e( 'items', 'kreativ-broken-image-finder' ); ?>
						</span>
						<span class="pagination-links">
							<?php
							$request_uri = isset( $_SERVER['REQUEST_URI'] )	? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )	: '';

							$base_url = esc_url_raw( remove_query_arg( array( 'paged' ), $request_uri ) );

							// Prev.
							if ( $current_page > 1 ) {
								$prev_url = add_query_arg( 'paged', $current_page - 1, $base_url );
								echo '<a class="prev-page" href="' . esc_url( $prev_url ) . '">&laquo;</a>';
							} else {
								echo '<span class="tablenav-pages-navspan">&laquo;</span>';
							}

							echo '<span class="paging-input">' . esc_html( $current_page ) . ' / ' . esc_html( $total_pages ) . '</span>';

							// Next.
							if ( $current_page < $total_pages ) {
								$next_url = add_query_arg( 'paged', $current_page + 1, $base_url );
								echo '<a class="next-page" href="' . esc_url( $next_url ) . '">&raquo;</a>';
							} else {
								echo '<span class="tablenav-pages-navspan">&raquo;</span>';
							}
							?>
						</span>
					</div>
				</div>
			<?php endif; ?>

			<p style="margin-top:2em;font-size:12px;color:#6c757d;">
				<?php esc_html_e( 'Made with KREATIV — helping you keep your content fresh and healthy.', 'kreativ-broken-image-finder' ); ?>
			</p>
		</div>
		<?php
	}
}

endif;

$kreativ_broken_image_finder = new Kreativ_Broken_Image_Finder();

if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'Kreativ_Broken_Image_Finder_CLI_Command' ) ) {
	/**
	 * WP-CLI commands for Kreativ Broken Image Finder.
	 */
	class Kreativ_Broken_Image_Finder_CLI_Command {

		/**
		 * Plugin instance.
		 *
		 * @var Kreativ_Broken_Image_Finder
		 */
		private $plugin;

		/**
		 * Constructor.
		 *
		 * @param Kreativ_Broken_Image_Finder $plugin Plugin instance.
		 */
		public function __construct( $plugin ) {
			$this->plugin = $plugin;
		}

		/**
		 * Run a full scan.
		 *
		 * ## OPTIONS
		 *
		 * [--batch-size=<number>]
		 * : Number of posts to scan per batch. Default: 20.
		 *
		 * ## EXAMPLES
		 *
		 *     wp kbif scan
		 *     wp kbif scan --batch-size=10
		 *
		 * @param array $args Positional args.
		 * @param array $assoc_args Associative args.
		 */
		public function scan( $args, $assoc_args ) {
			$result  = $this->plugin->run_cli_scan( $assoc_args );
			$stats   = isset( $result['stats'] ) ? $result['stats'] : array();
			$results = isset( $result['results'] ) ? $result['results'] : array();

			\WP_CLI::log( 'Scan complete.' );
			\WP_CLI\Utils\format_items(
				'table',
				array(
					array(
						'total_posts'             => isset( $stats['total_posts'] ) ? (int) $stats['total_posts'] : 0,
						'total_images_found'      => isset( $stats['total_images_found'] ) ? (int) $stats['total_images_found'] : 0,
						'broken_images'           => isset( $stats['broken_images'] ) ? (int) $stats['broken_images'] : 0,
						'missing_featured_images' => isset( $stats['missing_featured_images'] ) ? (int) $stats['missing_featured_images'] : 0,
						'scan_time'               => isset( $stats['scan_time'] ) ? $stats['scan_time'] : 0,
						'reported_items'          => count( $results ),
					),
				),
				array( 'total_posts', 'total_images_found', 'broken_images', 'missing_featured_images', 'scan_time', 'reported_items' )
			);
		}
	}

	\WP_CLI::add_command( 'kbif', new Kreativ_Broken_Image_Finder_CLI_Command( $kreativ_broken_image_finder ) );
}
