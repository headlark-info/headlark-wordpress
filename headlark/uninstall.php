<?php
// Deleting the plugin removes its settings, the stored key, status and every post's stored SEO.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function headlark_uninstall_site() {
	foreach ( array( 'headlark_settings', 'headlark_key', 'headlark_recent', 'headlark_backfill', 'headlark_lock' ) as $option ) {
		delete_option( $option );
	}
	delete_post_meta_by_key( '_headlark' );
	delete_post_meta_by_key( '_headlark_pending' );
	wp_clear_scheduled_hook( 'headlark_backfill' );
	wp_clear_scheduled_hook( 'headlark_recheck' );
	wp_unschedule_hook( 'headlark_optimize' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $headlark_site_id ) {
		switch_to_blog( $headlark_site_id );
		headlark_uninstall_site();
		restore_current_blog();
	}
} else {
	headlark_uninstall_site();
}
