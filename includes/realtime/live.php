<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Real-time updates for Kitchen, Orders, Billing and Waiter screens.
 *  - The browser asks the server every few seconds for a short "signature" of today's
 *    active orders and table states.
 *  - When it changes, the page reloads (only when no popup is open and nothing is being typed).
 *  - Reports, Accounts and Settings pages do not reload.
 */

add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_user_logged_in() ) return;
    $ver = (string) @filemtime( QRRS_PATH . 'assets/js/qrrs-live.js' );
    wp_enqueue_script( 'qrrs-live', QRRS_URL . 'assets/js/qrrs-live.js', [], $ver ?: '1', true );
    wp_localize_script( 'qrrs-live', 'qrrsLiveCfg', [
        'ajax'  => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'qr_order_nonce' ),
        'every' => 5000,
        'sound' => str_replace( ' ', '%20', QRRS_URL . 'assets/sounds/notification 01.mp3' ),
    ] );
} );

add_action( 'wp_ajax_qrrs_live_signature', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in again.' );
    global $wpdb;
    $p   = $wpdb->prefix;
    $rid = intval( qrrs_inventory_get_active_restaurant_id() );
    $today = current_time( 'Y-m-d' );
    // avoid truncated lists, otherwise a change beyond the limit would be missed
    $wpdb->query( 'SET SESSION group_concat_max_len = 1000000' );
    $orders = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(GROUP_CONCAT(CONCAT(id,':',order_status,':',COALESCE(payment_status,'')) ORDER BY id SEPARATOR ','),'')
         FROM {$p}qrrs_orders WHERE restaurant_id = %d AND DATE(created_at) = %s
           AND order_status NOT IN ('completed','cancelled')",
        $rid, $today
    ) );
    $tables = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(GROUP_CONCAT(CONCAT(id,':',COALESCE(status,'')) ORDER BY id SEPARATOR ','),'')
         FROM {$p}qrrs_tables WHERE restaurant_id = %d",
        $rid
    ) );
    wp_send_json_success( [ 'sig' => md5( $orders . '|' . $tables ) ] );
} );

add_action( 'wp_ajax_qrrs_live_ready', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in again.' );
    global $wpdb;
    $rid = intval( qrrs_inventory_get_active_restaurant_id() );
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}qrrs_orders WHERE restaurant_id = %d AND DATE(created_at) = %s AND order_status = 'ready' ORDER BY id",
        $rid, current_time( 'Y-m-d' )
    ) );
    wp_send_json_success( [ 'ids' => array_map( 'intval', $ids ) ] );
} );