<?php
/**
 * Plugin Name:       Lytera UA LAT
 * Description:       Transliterates Ukrainian characters in post, page and term slugs and in attachment file names to Latin (official KMU 2010 standard).
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Vlad Zelinskyi
 * Author URI:        https://www.spacenerd.space/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lytera-ua-lat
 *
 * @package Lytera_UA_LAT
 */

defined( 'ABSPATH' ) || exit;

define( 'UATR_FILE', __FILE__ );
define( 'UATR_VERSION', '1.0.0' );
define( 'UATR_BATCH', 100 );

require __DIR__ . '/includes/class-uatr-translit.php';

/**
 * Slugs: runs before sanitize_title_with_dashes() (priority 10).
 *
 * @param string $title     Title.
 * @param string $raw_title Raw title.
 * @param string $context   Context.
 * @return string
 */
function uatr_sanitize_title( $title, $raw_title = '', $context = 'save' ) {
	// Lookups keep the original slug so not-yet-converted URLs still resolve.
	if ( 'query' === $context || ! empty( $GLOBALS['uatr_lookup'] ) ) {
		return $title;
	}
	if ( is_string( $title ) && preg_match( '/%d[0-4]/i', $title ) ) {
		$title = rawurldecode( $title );
	}
	return UATR_Translit::transliterate( $title );
}
add_filter( 'sanitize_title', 'uatr_sanitize_title', 9, 3 );

/**
 * Front-end main query resolves slugs from the URL: keep them as is.
 *
 * WP_Tax_Query and get_term_by() sanitize term slugs with the "save"
 * context, so a flag covers the main query. REST API requests are served
 * earlier on this hook (priority 10) and never reach it.
 */
function uatr_lookup_start() {
	$GLOBALS['uatr_lookup'] = true;
}
add_action( 'parse_request', 'uatr_lookup_start', PHP_INT_MAX );

/**
 * End of the main query.
 *
 * @param WP $wp WP instance.
 */
function uatr_lookup_end( $wp ) {
	unset( $wp );
	if ( ! empty( $GLOBALS['uatr_lookup'] ) && isset( $GLOBALS['wp_query'] ) ) {
		$GLOBALS['wp_query']->get_queried_object(); // Resolve and cache with the original slug.
	}
	$GLOBALS['uatr_lookup'] = false;
}
add_action( 'wp', 'uatr_lookup_end', 0 );

/**
 * Attachment file names.
 *
 * @param string $filename File name.
 * @return string
 */
function uatr_sanitize_file_name( $filename ) {
	if ( ! UATR_Translit::has_cyrillic( $filename ) ) {
		return $filename;
	}
	$filename = UATR_Translit::transliterate( $filename );
	$filename = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $filename );
	$filename = preg_replace( '/-{2,}/', '-', $filename );
	return trim( $filename, '-' );
}
add_filter( 'sanitize_file_name', 'uatr_sanitize_file_name' );


require __DIR__ . '/includes/converter.php';
require __DIR__ . '/includes/redirects.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/woocommerce.php';
