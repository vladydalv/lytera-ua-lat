<?php
/**
 * Tools page, REST API, activation notice.
 *
 * @package Lytera_UA_LAT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activation: show the notice once.
 */
function uatr_activate() {
	set_transient( 'uatr_activated', 1, DAY_IN_SECONDS );
	delete_option( 'uatr_wc_migrated' );
}
register_activation_hook( UATR_FILE, 'uatr_activate' );

/**
 * Tools → Lytera UA LAT.
 */
function uatr_admin_menu() {
	$hook = add_management_page(
		__( 'Lytera UA LAT', 'lytera-ua-lat' ),
		__( 'Lytera UA LAT', 'lytera-ua-lat' ),
		'manage_options',
		'lytera-ua-lat',
		'uatr_render_page'
	);
	add_action( 'admin_print_scripts-' . $hook, 'uatr_enqueue' );
}
add_action( 'admin_menu', 'uatr_admin_menu' );

/**
 * Page container.
 */
function uatr_render_page() {
	echo '<div class="wrap"><h1>' . esc_html__( 'Lytera UA LAT', 'lytera-ua-lat' ) . '</h1><div id="uatr-app"></div></div>';
}

/**
 * Assets.
 */
function uatr_enqueue() {
	wp_enqueue_style( 'wp-components' );
	wp_enqueue_script(
		'uatr-admin',
		plugins_url( 'assets/admin.js', UATR_FILE ),
		array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
		UATR_VERSION,
		true
	);
	wp_set_script_translations( 'uatr-admin', 'lytera-ua-lat' );
}

/**
 * Notice after activation if there are slugs to convert.
 */
function uatr_admin_notice() {
	if ( ! get_transient( 'uatr_activated' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && 'tools_page_lytera-ua-lat' === $screen->id ) {
		delete_transient( 'uatr_activated' );
		return;
	}
	if ( 0 === uatr_count( 'post' ) + uatr_count( 'term' ) ) {
		delete_transient( 'uatr_activated' );
		return;
	}
	printf(
		'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'Lytera UA LAT: the site has slugs with Cyrillic characters.', 'lytera-ua-lat' ),
		esc_url( admin_url( 'tools.php?page=lytera-ua-lat' ) ),
		esc_html__( 'Review and convert', 'lytera-ua-lat' )
	);
}
add_action( 'admin_notices', 'uatr_admin_notice' );

/**
 * REST routes.
 */
function uatr_rest_routes() {
	$args = array(
		'type'  => array(
			'type'     => 'string',
			'enum'     => array( 'post', 'term' ),
			'required' => true,
		),
		'after' => array(
			'type'    => 'integer',
			'minimum' => 0,
			'default' => 0,
		),
	);
	$perm = static function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route(
		'lytera-ua-lat/v1',
		'/status',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => static function () {
				return array(
					'post' => uatr_count( 'post' ),
					'term' => uatr_count( 'term' ),
				);
			},
		)
	);
	register_rest_route(
		'lytera-ua-lat/v1',
		'/preview',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'args'                => $args,
			'callback'            => static function ( WP_REST_Request $req ) {
				return uatr_find( $req['type'], (int) $req['after'] );
			},
		)
	);
	register_rest_route(
		'lytera-ua-lat/v1',
		'/convert',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'args'                => $args,
			'callback'            => static function ( WP_REST_Request $req ) {
				return uatr_convert( $req['type'], (int) $req['after'] );
			},
		)
	);
}
add_action( 'rest_api_init', 'uatr_rest_routes' );
