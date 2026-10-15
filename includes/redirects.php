<?php
/**
 * 301 redirects from old (Cyrillic) slugs.
 *
 * Posts use core `_wp_old_slug`; terms use `_uatr_old_slug` term meta.
 *
 * @package Lytera_UA_LAT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store an old term slug.
 *
 * @param int    $term_id Term ID.
 * @param string $slug    Old slug as stored in DB.
 */
function uatr_remember_term_slug( $term_id, $slug ) {
	$old = get_term_meta( $term_id, '_uatr_old_slug', false );
	if ( '' !== $slug && ! in_array( $slug, $old, true ) ) {
		add_term_meta( $term_id, '_uatr_old_slug', $slug );
	}
}

/**
 * Remember the slug before a term is edited.
 *
 * @param int $term_id Term ID.
 */
function uatr_before_term_edit( $term_id ) {
	$term = get_term( $term_id );
	if ( $term instanceof WP_Term ) {
		$GLOBALS['uatr_term_slugs'][ $term_id ] = $term->slug;
	}
}
add_action( 'edit_terms', 'uatr_before_term_edit' );

/**
 * Store the old slug if a manual edit changed it.
 *
 * @param int $term_id Term ID.
 */
function uatr_after_term_edit( $term_id ) {
	$old = $GLOBALS['uatr_term_slugs'][ $term_id ] ?? '';
	unset( $GLOBALS['uatr_term_slugs'][ $term_id ] );
	$term = get_term( $term_id );
	if ( '' !== $old && $term instanceof WP_Term && $term->slug !== $old ) {
		uatr_remember_term_slug( $term_id, $old );
		delete_term_meta( $term_id, '_uatr_old_slug', $term->slug );
	}
}
add_action( 'edited_term', 'uatr_after_term_edit' );

/**
 * Normalize a requested slug to the stored form.
 *
 * @param string $value Query var value.
 * @return string
 */
function uatr_stored_form( $value ) {
	$value = rawurldecode( basename( (string) $value ) );
	return strtolower( rawurlencode( $value ) );
}

/**
 * Term ID by an old slug.
 *
 * Direct query: get_terms() arguments can be changed by other plugins
 * (e.g. WooCommerce sets its own meta_key for product categories).
 *
 * @param string $taxonomy Taxonomy.
 * @param string $slug     Old slug as stored.
 * @return int
 */
function uatr_find_term_by_old_slug( $taxonomy, $slug ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key = '_uatr_old_slug' AND tm.meta_value = %s AND tt.taxonomy = %s LIMIT 1",
			$slug,
			$taxonomy
		)
	);
}

/**
 * Redirect requests made with an old slug.
 */
function uatr_redirect_old_slugs() {
	global $wp;
	if ( ! is_404() || empty( $wp->query_vars ) ) {
		return;
	}

	$target = '';

	// Terms.
	foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
		$var = ( 'category' === $tax->name ) ? 'category_name' : ( 'post_tag' === $tax->name ? 'tag' : $tax->query_var );
		if ( ! $var || empty( $wp->query_vars[ $var ] ) || ! is_string( $wp->query_vars[ $var ] ) ) {
			continue;
		}
		$term_id = uatr_find_term_by_old_slug( $tax->name, uatr_stored_form( $wp->query_vars[ $var ] ) );
		if ( $term_id ) {
			$link   = get_term_link( $term_id, $tax->name );
			$target = is_wp_error( $link ) ? '' : $link;
			break;
		}
	}

	// Posts.
	if ( ! $target ) {
		foreach ( array( 'name', 'pagename', 'attachment' ) as $var ) {
			if ( empty( $wp->query_vars[ $var ] ) || ! is_string( $wp->query_vars[ $var ] ) ) {
				continue;
			}
			$slug  = uatr_stored_form( $wp->query_vars[ $var ] );
			$posts = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_wp_old_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( $posts ) {
				$target = get_permalink( $posts[0] );
				break;
			}
		}
	}

	if ( $target ) {
		wp_safe_redirect( $target, 301, 'Lytera UA LAT' );
		exit;
	}
}
add_action( 'template_redirect', 'uatr_redirect_old_slugs', 1 );
