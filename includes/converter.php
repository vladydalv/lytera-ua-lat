<?php
/**
 * Conversion of existing slugs.
 *
 * @package Lytera_UA_LAT
 */

defined( 'ABSPATH' ) || exit;

/**
 * LIKE patterns for URL-encoded Cyrillic (UTF-8 lead bytes D0–D2).
 *
 * @return string[]
 */
function uatr_like_patterns() {
	global $wpdb;
	return array(
		'%' . $wpdb->esc_like( '%d0' ) . '%',
		'%' . $wpdb->esc_like( '%d1' ) . '%',
		'%' . $wpdb->esc_like( '%d2' ) . '%',
	);
}

/**
 * Whether a stored slug has Ukrainian letters to transliterate.
 *
 * @param string $slug Slug as stored.
 * @return bool
 */
function uatr_needs_conversion( $slug ) {
	$decoded = rawurldecode( $slug );
	return UATR_Translit::has_cyrillic( $decoded ) && UATR_Translit::transliterate( $decoded ) !== $decoded;
}

/**
 * Number of slugs to convert.
 *
 * @param string $type post|term.
 * @return int
 */
function uatr_count( $type ) {
	global $wpdb;
	$like = uatr_like_patterns();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( 'term' === $type ) {
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT slug FROM {$wpdb->terms} WHERE ( slug LIKE %s OR slug LIKE %s OR slug LIKE %s )",
				$like[0],
				$like[1],
				$like[2]
			)
		);
	} else {
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_name FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft','trash') AND ( post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s )",
				$like[0],
				$like[1],
				$like[2]
			)
		);
	}
	// phpcs:enable
	return count( array_filter( $slugs, 'uatr_needs_conversion' ) );
}

/**
 * Find a batch of slugs to convert, with the new slug calculated.
 *
 * @param string $type  post|term.
 * @param int    $after Last processed ID.
 * @return array{items: array, next: int, done: bool}
 */
function uatr_find( $type, $after ) {
	global $wpdb;
	$items = array();

	$like = uatr_like_patterns();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( 'term' === $type ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id AS id, slug FROM {$wpdb->terms} WHERE term_id > %d AND ( slug LIKE %s OR slug LIKE %s OR slug LIKE %s ) ORDER BY term_id ASC LIMIT %d",
				$after,
				$like[0],
				$like[1],
				$like[2],
				UATR_BATCH
			)
		);
	} else {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS id, post_name AS slug FROM {$wpdb->posts} WHERE ID > %d AND post_status NOT IN ('auto-draft','trash') AND ( post_name LIKE %s OR post_name LIKE %s OR post_name LIKE %s ) ORDER BY ID ASC LIMIT %d",
				$after,
				$like[0],
				$like[1],
				$like[2],
				UATR_BATCH
			)
		);
	}
	// phpcs:enable

	$next = $after;
	foreach ( $rows as $row ) {
		$next = (int) $row->id;
		if ( ! uatr_needs_conversion( $row->slug ) ) {
			continue;
		}
		$decoded = rawurldecode( $row->slug );

		if ( 'term' === $type ) {
			$term = get_term( (int) $row->id );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$tax     = get_taxonomy( $term->taxonomy );
			$new     = wp_unique_term_slug( sanitize_title( $decoded ), $term );
			$items[] = array(
				'id'    => (int) $term->term_id,
				'title' => $term->name,
				'kind'  => $tax ? $tax->labels->singular_name : $term->taxonomy,
				'old'   => $decoded,
				'new'   => $new,
				'edit'  => (string) get_edit_term_link( $term->term_id, $term->taxonomy ),
				'tax'   => $term->taxonomy,
				'raw'   => $row->slug,
			);
		} else {
			$post = get_post( (int) $row->id );
			if ( ! $post ) {
				continue;
			}
			$pto     = get_post_type_object( $post->post_type );
			$new     = wp_unique_post_slug( sanitize_title( $decoded ), $post->ID, $post->post_status, $post->post_type, $post->post_parent );
			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'kind'  => $pto ? $pto->labels->singular_name : $post->post_type,
				'old'   => $decoded,
				'new'   => $new,
				'edit'  => (string) get_edit_post_link( $post->ID, 'raw' ),
				'raw'   => $row->slug,
			);
		}
	}

	return array(
		'items' => $items,
		'next'  => $next,
		'done'  => count( $rows ) < UATR_BATCH,
	);
}

/**
 * Convert one batch.
 *
 * @param string $type  post|term.
 * @param int    $after Last processed ID.
 * @return array{converted: int, next: int, done: bool}
 */
function uatr_convert( $type, $after ) {
	global $wpdb;
	$batch     = uatr_find( $type, $after );
	$converted = 0;

	foreach ( $batch['items'] as $item ) {
		if ( '' === $item['new'] ) {
			continue;
		}
		$old = $item['raw'];

		if ( 'term' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->terms, array( 'slug' => $item['new'] ), array( 'term_id' => $item['id'] ) );
			uatr_remember_term_slug( $item['id'], $old );
			clean_term_cache( $item['id'], $item['tax'] );
			/**
			 * Fires after a term slug is converted.
			 *
			 * @param string $taxonomy Taxonomy.
			 * @param string $old      Old slug as stored.
			 * @param string $new      New slug.
			 */
			do_action( 'uatr_term_converted', $item['tax'], $old, $item['new'] );
		} else {
			add_post_meta( $item['id'], '_wp_old_slug', $old );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->posts, array( 'post_name' => $item['new'] ), array( 'ID' => $item['id'] ) );
			clean_post_cache( $item['id'] );
		}
		++$converted;
	}

	return array(
		'converted' => $converted,
		'next'      => $batch['next'],
		'done'      => $batch['done'],
	);
}
