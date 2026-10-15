<?php
/**
 * WooCommerce compatibility.
 *
 * WooCommerce derives attribute keys from sanitize_title() at runtime. Data
 * saved before activation has Cyrillic keys, which no longer match the
 * transliterated keys. This migrates those keys once, in batches,
 * and keeps variation values in sync when attribute term slugs are converted.
 *
 * @package Lytera_UA_LAT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a stored key contains Ukrainian letters (raw or URL-encoded).
 *
 * @param string $key Key.
 * @return bool
 */
function uatr_wc_needs( $key ) {
	return UATR_Translit::has_cyrillic( rawurldecode( (string) $key ) );
}

/**
 * Transliterated form of a stored key.
 *
 * @param string $key Key, e.g. %d0%ba..., pa_рік, pa_%d1%80...
 * @return string
 */
function uatr_wc_key( $key ) {
	return sanitize_title( rawurldecode( (string) $key ) );
}

/**
 * Re-key an attributes array (`_product_attributes` or `_default_attributes`).
 *
 * @param array $attributes Attributes.
 * @return array
 */
function uatr_wc_rekey( $attributes ) {
	$out = array();
	foreach ( $attributes as $key => $value ) {
		if ( is_array( $value ) && ! empty( $value['is_taxonomy'] ) && isset( $value['name'] ) && uatr_wc_needs( $value['name'] ) ) {
			$value['name'] = uatr_wc_key( $value['name'] );
		}
		$out[ uatr_wc_needs( $key ) ? uatr_wc_key( $key ) : $key ] = $value;
	}
	return $out;
}

/**
 * Rename global attributes with Ukrainian slugs.
 */
function uatr_wc_migrate_attributes() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery
	$attrs   = $wpdb->get_results( "SELECT attribute_id, attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies" );
	$names   = wp_list_pluck( $attrs, 'attribute_name' );
	$changed = false;

	foreach ( $attrs as $attr ) {
		$old = $attr->attribute_name;
		if ( ! uatr_wc_needs( $old ) ) {
			continue;
		}
		$new = wc_sanitize_taxonomy_name( rawurldecode( $old ) );
		if ( '' === $new || strlen( $new ) > 28 || in_array( $new, $names, true ) ) {
			continue;
		}
		$wpdb->update( $wpdb->prefix . 'woocommerce_attribute_taxonomies', array( 'attribute_name' => $new ), array( 'attribute_id' => $attr->attribute_id ) );
		$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => 'pa_' . $new ), array( 'taxonomy' => 'pa_' . $old ) );
		$lookup = $wpdb->prefix . 'wc_product_attributes_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) === $lookup ) {
			$wpdb->update( $lookup, array( 'taxonomy' => 'pa_' . $new ), array( 'taxonomy' => 'pa_' . $old ) );
		}
		$names[] = $new;
		$changed = true;
	}
	// phpcs:enable

	if ( $changed ) {
		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_flush();
		update_option( 'woocommerce_queue_flush_rewrite_rules', 'yes' );
	}
}

/**
 * Migrate variation keys and product attribute arrays. One batch.
 *
 * @param string $step   meta|parent.
 * @param int    $cursor Last meta_id.
 * @return int|false Next cursor, false when the step is done.
 */
function uatr_wc_migrate_meta( $step, $cursor ) {
	global $wpdb;
	$limit = 1000;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery
	if ( 'meta' === $step ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key LIKE %s ORDER BY meta_id ASC LIMIT %d",
				$cursor,
				$wpdb->esc_like( 'attribute_' ) . '%',
				$limit
			)
		);
	} else {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key IN ('_product_attributes','_default_attributes') ORDER BY meta_id ASC LIMIT %d",
				$cursor,
				$limit
			)
		);
	}

	foreach ( $rows as $row ) {
		$cursor = (int) $row->meta_id;
		if ( 'meta' === $step ) {
			$name = substr( $row->meta_key, 10 );
			if ( uatr_wc_needs( $name ) ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_key' => 'attribute_' . uatr_wc_key( $name ) ), array( 'meta_id' => $row->meta_id ) );
				clean_post_cache( (int) $row->post_id );
			}
			continue;
		}
		$value = get_post_meta( (int) $row->post_id, $row->meta_key, true );
		if ( is_array( $value ) ) {
			$new = uatr_wc_rekey( $value );
			if ( $new !== $value ) {
				update_post_meta( (int) $row->post_id, $row->meta_key, $new );
			}
		}
	}
	// phpcs:enable

	return count( $rows ) < $limit ? false : $cursor;
}

/**
 * Run the one-time migration on admin requests, within a time budget.
 */
function uatr_wc_migrate() {
	if ( ! class_exists( 'WooCommerce' ) || get_option( 'uatr_wc_migrated' ) || ! current_user_can( 'manage_options' ) || get_transient( 'uatr_wc_lock' ) ) {
		return;
	}
	set_transient( 'uatr_wc_lock', 1, MINUTE_IN_SECONDS );

	$state = get_option(
		'uatr_wc_state',
		array(
			'step'   => 'attr',
			'cursor' => 0,
		)
	);
	$start = microtime( true );

	while ( microtime( true ) - $start < 10 ) {
		if ( 'attr' === $state['step'] ) {
			uatr_wc_migrate_attributes();
			$state = array(
				'step'   => 'meta',
				'cursor' => 0,
			);
			continue;
		}
		$next = uatr_wc_migrate_meta( $state['step'], (int) $state['cursor'] );
		if ( false !== $next ) {
			$state['cursor'] = $next;
		} elseif ( 'meta' === $state['step'] ) {
			$state = array(
				'step'   => 'parent',
				'cursor' => 0,
			);
		} else {
			$state = null;
			break;
		}
	}

	if ( null === $state ) {
		delete_option( 'uatr_wc_state' );
		update_option( 'uatr_wc_migrated', 1, true );
	} else {
		update_option( 'uatr_wc_state', $state, false );
	}
	WC_Cache_Helper::get_transient_version( 'product', true );
	delete_transient( 'uatr_wc_lock' );
}
add_action( 'admin_init', 'uatr_wc_migrate' );

/**
 * Keep variation values in sync when an attribute term slug is converted.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $old_slug Old slug as stored.
 * @param string $new_slug New slug.
 */
function uatr_wc_term_converted( $taxonomy, $old_slug, $new_slug ) {
	if ( ! function_exists( 'taxonomy_is_product_attribute' ) || ! taxonomy_is_product_attribute( $taxonomy ) ) {
		return;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery
	$variations = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", 'attribute_' . $taxonomy, $old_slug ) );
	$wpdb->update(
		$wpdb->postmeta,
		array( 'meta_value' => $new_slug ),
		array(
			'meta_key'   => 'attribute_' . $taxonomy,
			'meta_value' => $old_slug,
		)
	);
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_default_attributes' AND meta_value LIKE %s",
			'%' . $wpdb->esc_like( '"' . $old_slug . '"' ) . '%'
		)
	);
	// phpcs:enable
	foreach ( $ids as $id ) {
		$defaults = get_post_meta( (int) $id, '_default_attributes', true );
		if ( is_array( $defaults ) && isset( $defaults[ $taxonomy ] ) && $old_slug === $defaults[ $taxonomy ] ) {
			$defaults[ $taxonomy ] = $new_slug;
			update_post_meta( (int) $id, '_default_attributes', $defaults );
		}
	}
	if ( class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'product', true );
	}
	foreach ( $variations as $id ) {
		clean_post_cache( (int) $id );
	}
}
add_action( 'uatr_term_converted', 'uatr_wc_term_converted', 10, 3 );
