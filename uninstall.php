<?php
/**
 * Uninstall.
 *
 * @package Lytera_UA_LAT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'uatr_activated' );
delete_metadata( 'term', 0, '_uatr_old_slug', '', true );
delete_option( 'uatr_wc_migrated' );
delete_option( 'uatr_wc_state' );
delete_transient( 'uatr_wc_lock' );
