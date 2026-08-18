<?php
/**
 * Plugin Name:       Compact IMG: Compress & Convert Images to WebP/AVIF
 * Plugin URI:        https://compactimg.com/
 * Description:       Optimize Media Library images by converting them to WebP, AVIF, or JPG and see how much storage space you save.
 * Version:           1.6.2
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Compact IMG
 * Author URI:        https://compactimg.com/
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       compact-img-compress-convert-images-to-webpavif
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMFC_VERSION', '1.6.2' );
define( 'WMFC_FILE', __FILE__ );
define( 'WMFC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMFC_URL', plugin_dir_url( __FILE__ ) );

require_once WMFC_DIR . 'includes/class-wmfc-converter.php';
require_once WMFC_DIR . 'includes/class-wmfc-plugin.php';

WMFC_Plugin::instance();
