<?php
/**
 * Remove temporary processing state on uninstall.
 *
 * Conversion records and statistics are intentionally retained in attachment
 * metadata. Removing them could orphan source files that an administrator has
 * not yet reviewed or deleted.
 *
 * @package CompactIMG
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( '_wmfc_processing_lock' );
delete_option( '_wmfc_rewrite_required' );
delete_option( '_wmfc_rewrite_state' );
