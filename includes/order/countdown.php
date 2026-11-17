<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cooking countdown.
 *  - When an order starts cooking, we store the start time and the expected ready time.
 *  - Expected time = the longest prep_time of its items (minimum 5 min, default 15 min).
 *  - Kitchen, Orders board and Waiter Terminal show a live countdown; after the time it shows "Overdue".
 */

/**
 * Adds the countdown columns to the orders table once, if they are missing.
 * Runs on init, so no reactivation is needed. Countdown queries are skipped until this succeeds.
 */
add_action( 'init', function () {
    if ( get_option( 'qrrs_countdown_cols_v1' ) ) return;
    global $wpdb;
    $t = $wpdb->prefix . 'qrrs_orders';
    $has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $t LIKE %s", 'ready_due_at' ) );
    if ( ! $has ) {
        $wpdb->query( "ALTER TABLE $t ADD COLUMN cooking_started_at datetime DEFAULT NULL, ADD COLUMN ready_due_at datetime DEFAULT NULL" );
        $has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $t LIKE %s", 'ready_due_at' ) );
    }
    if ( $has ) update_option( 'qrrs_countdown_cols_v1', 1, false );
}, 5 );

/** Local timestamp of a MySQL datetime stored in the site timezone. */
function qrrs_local_ts( $mysql ) {
    if ( empty( $mysql ) ) return 0;
    try {
        return ( new DateTime( $mysql, wp_timezone() ) )->getTimestamp();
    } catch ( Exception $e ) {
        return 0;
    }
}

function qrrs_order_prep_minutes( $order_id ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $times = $wpdb->get_col( $wpdb->prepare(
        "SELECT i.prep_time FROM {$p}qrrs_order_items oi JOIN {$p}qrrs_items i ON i.id = oi.item_id WHERE oi.order_id = %d",
        intval( $order_id )
    ) );
    $max = 0;
    foreach ( (array) $times as $t ) {
        $n = intval( preg_replace( '/[^0-9]/', '', (string) $t ) );
        if ( $n > $max ) $max = $n;
    }
    return max( 5, $max ?: 15 );
}

/** Ready-due timestamp while the order is cooking, otherwise 0. */
function qrrs_ready_due_ts_by_id( $order_id ) {
    if ( ! get_option( 'qrrs_countdown_cols_v1' ) ) return 0;
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT order_status, ready_due_at FROM {$wpdb->prefix}qrrs_orders WHERE id = %d", intval( $order_id )
    ) );
    if ( ! $row || 'processing' !== $row->order_status || empty( $row->ready_due_at ) ) return 0;
    return qrrs_local_ts( $row->ready_due_at );
}

// Start the countdown the first time an order goes to cooking
add_action( 'qrrs_order_status_changed', function ( $order_id, $status ) {
    if ( 'processing' !== $status || ! get_option( 'qrrs_countdown_cols_v1' ) ) return;
    global $wpdb;
    $t = $wpdb->prefix . 'qrrs_orders';
    $started = $wpdb->get_var( $wpdb->prepare( "SELECT cooking_started_at FROM $t WHERE id = %d", intval( $order_id ) ) );
    if ( $started ) return;
    $now = new DateTime( 'now', wp_timezone() );
    $due = clone $now;
    $due->modify( '+' . qrrs_order_prep_minutes( $order_id ) . ' minutes' );
    $wpdb->update( $t, [
        'cooking_started_at' => $now->format( 'Y-m-d H:i:s' ),
        'ready_due_at'       => $due->format( 'Y-m-d H:i:s' ),
    ], [ 'id' => intval( $order_id ) ] );
}, 10, 2 );

// Shared countdown script on every page of the site
add_action( 'wp_enqueue_scripts', function () {
    $ver = (string) @filemtime( QRRS_PATH . 'assets/js/qrrs-countdown.js' );
    wp_enqueue_script( 'qrrs-countdown', QRRS_URL . 'assets/js/qrrs-countdown.js', [], $ver ?: '1', true );
} );