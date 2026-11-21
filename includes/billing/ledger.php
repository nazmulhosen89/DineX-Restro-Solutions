<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Payment ledger: every paid bill must have a payment record.
 *  1. qrrs_record_payment_line(): writes one record for a bill, only if it has none.
 *  2. Any status change to paid/completed (Orders board, Waiter, billing) writes the record.
 *  3. qrrs_sync_payment_lines(): on Billing and Sales report load, fills in any paid bill
 *     of the last 60 days that has no record. This is the safety net.
 */

function qrrs_record_payment_line( $order_id, $restaurant_id, $method, $amount, $created_at = '' ) {
    global $wpdb;
    $order_id = intval( $order_id );
    $amount   = round( (float) $amount, 2 );
    if ( $order_id <= 0 || $amount <= 0.005 ) return false;
    $have = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}qrrs_payments WHERE order_id = %d", $order_id
    ) );
    if ( $have > 0.005 ) return false;
    if ( ! array_key_exists( $method, qrrs_payment_methods() ) ) $method = 'cash';
    $wpdb->insert( $wpdb->prefix . 'qrrs_payments', [
        'order_id'      => $order_id,
        'restaurant_id' => intval( $restaurant_id ),
        'method'        => $method,
        'amount'        => $amount,
        'reference'     => '',
        'created_by'    => get_current_user_id(),
        'created_at'    => $created_at ?: current_time( 'mysql' ),
    ] );
    if ( $wpdb->last_error ) error_log( 'QRRS ledger: could not save payment for order ' . $order_id . ': ' . $wpdb->last_error );
    return true;
}

/** The record for one bill, from what the bill itself says. */
function qrrs_ledger_row_for_bill( $o ) {
    $total  = (float) ( $o->total ?? 0 );
    $method = ( $o->payment_method ?? '' ) ?: 'cash';
    if ( (int) ( $o->has_due ?? 0 ) > 0 || 'credit' === $method ) {
        // part of the bill is on credit: only the cash actually received is recorded
        return [ 'cash', min( $total, (float) ( $o->amount_received ?? 0 ) ) ];
    }
    return [ $method, $total ];
}

/** Safety net: fill in the missing records of paid bills from the last $days days. */
function qrrs_sync_payment_lines( $restaurant_id, $days = 60 ) {
    global $wpdb;
    $p   = $wpdb->prefix;
    $rid = intval( $restaurant_id );
    if ( $rid <= 0 ) return 0;
    $from = wp_date( 'Y-m-d', strtotime( '-' . intval( $days ) . ' days', current_time( 'timestamp' ) ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT o.id, o.restaurant_id, o.payment_method, o.amount_received, o.created_at,
                COALESCE(o.final_total, o.grand_total) AS total,
                (SELECT COUNT(*) FROM {$p}qrrs_dues d WHERE d.order_id = o.id) AS has_due
         FROM {$p}qrrs_orders o
         WHERE o.restaurant_id = %d AND o.payment_status = 'paid' AND DATE(o.created_at) >= %s
           AND NOT EXISTS (SELECT 1 FROM {$p}qrrs_payments pl WHERE pl.order_id = o.id)",
        $rid, $from
    ) );
    $done = 0;
    foreach ( $rows as $r ) {
        list( $method, $amount ) = qrrs_ledger_row_for_bill( $r );
        if ( qrrs_record_payment_line( $r->id, $r->restaurant_id, $method, $amount, $r->created_at ) ) $done++;
    }
    return $done;
}

/** Every paid or completed bill gets its record, whichever screen closed it. */
add_action( 'qrrs_order_status_changed', function ( $order_id, $status ) {
    if ( ! in_array( $status, [ 'paid', 'completed' ], true ) ) return;
    global $wpdb;
    $o = $wpdb->get_row( $wpdb->prepare(
        "SELECT o.id, o.restaurant_id, o.payment_status, o.payment_method, o.amount_received, o.created_at,
                COALESCE(o.final_total, o.grand_total) AS total,
                (SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_dues d WHERE d.order_id = o.id) AS has_due
         FROM {$wpdb->prefix}qrrs_orders o WHERE o.id = %d", intval( $order_id )
    ) );
    if ( ! $o || 'paid' !== $o->payment_status ) return;
    list( $method, $amount ) = qrrs_ledger_row_for_bill( $o );
    qrrs_record_payment_line( $o->id, $o->restaurant_id, $method, $amount, $o->created_at );
}, 20, 2 );