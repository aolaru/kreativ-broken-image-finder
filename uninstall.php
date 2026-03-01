<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kbif_last_scan_results' );
delete_option( 'kbif_last_scan_stats' );
delete_option( 'kbif_scan_queue' );
delete_option( 'kbif_scan_progress' );
delete_option( 'kbif_settings' );
delete_option( 'kbif_scan_url_cache' );
