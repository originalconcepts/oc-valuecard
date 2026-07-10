<?php
/**
 * Uninstall cleanup.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ocvc_settings' );

// Remove the protected log directory, if any.
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'oc-valuecard';
if ( is_dir( $dir ) ) {
	foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
	@rmdir( $dir ); // phpcs:ignore
}
