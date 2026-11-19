<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Self-service orders (pay at the counter).
 *  1. Customer orders from the single restaurant QR and gets a daily token (0001, 0002 ...).
 *  2. The order waits as "awaiting_payment".
 *  3. Staff confirms the counter payment here. The order then goes to the kitchen
 *     and a token slip is printed through the browser print dialog.
 */

function qrrs_self_slip_html( $order_id, $paid = true ) {
    global $wpdb;
    $o  = $wpdb->prefix . 'qrrs_orders';
    $oi = $wpdb->prefix . 'qrrs_order_items';
    $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $o WHERE id = %d", intval( $order_id ) ) );
    if ( ! $order ) return '';
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT item_name, quantity, price FROM $oi WHERE order_id = %d ORDER BY id ASC", intval( $order_id )
    ) );
    $rest = $wpdb->get_row( $wpdb->prepare(
        "SELECT restaurant_name, restaurant_logo, address, phone, bin_number FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", intval( $order->restaurant_id )
    ) );
    $hotel = $rest ? $rest->restaurant_name : '';
    $date = $order->token_date ? date_i18n( 'd M Y', strtotime( $order->token_date ) ) : '';

    ob_start(); ?>
<html><head><meta charset="utf-8"><title>Token <?php echo esc_html( $order->token_no ); ?></title>
<style>
  body { font-family: monospace; width: 280px; margin: 0 auto; padding: 10px; font-size: 13px; color: #000; }
  .c { text-align: center; }
  .tok { font-size: 44px; font-weight: bold; text-align: center; margin: 8px 0; }
  .row { display: flex; justify-content: space-between; gap: 8px; }
  hr { border: none; border-top: 1px dashed #000; margin: 8px 0; }
</style></head><body>
<?php if ( $rest && $rest->restaurant_logo ) : ?>
<div class="c"><img src="<?php echo esc_url( $rest->restaurant_logo ); ?>" style="max-width:80px; height:auto;"></div>
<?php endif; ?>
<div class="c"><strong><?php echo esc_html( $hotel ); ?></strong></div>
<?php if ( $rest && ! empty( $rest->address ) ) : ?><div class="c"><?php echo esc_html( $rest->address ); ?></div><?php endif; ?>
<?php if ( $rest && ! empty( $rest->phone ) ) : ?><div class="c">Phone: <?php echo esc_html( $rest->phone ); ?></div><?php endif; ?>
<?php if ( $rest && ! empty( $rest->bin_number ) ) : ?><div class="c">BIN: <?php echo esc_html( $rest->bin_number ); ?></div><?php endif; ?>
<div class="c">Self-service token</div>
<div class="tok"><?php echo esc_html( $order->token_no ); ?></div>
<div class="c"><?php echo esc_html( $date ); ?> &middot; #<?php echo intval( $order->id ); ?></div>
<hr>
<?php foreach ( $items as $it ) : ?>
  <div class="row"><span><?php echo intval( $it->quantity ); ?> x <?php echo esc_html( $it->item_name ); ?></span><span><?php echo esc_html( number_format( (float) $it->price * (int) $it->quantity, 2 ) ); ?></span></div>
<?php endforeach; ?>
<hr>
<div class="row"><strong>Total</strong><strong><?php echo esc_html( number_format( (float) $order->grand_total, 2 ) ); ?></strong></div>
<?php if ( $paid ) : ?>
<div class="row"><span>Paid</span><span><?php echo esc_html( number_format( (float) $order->grand_total, 2 ) ); ?></span></div>
<?php else : ?>
<div class="c" style="margin-top:6px;">Pay at the counter</div>
<?php endif; ?>
<hr>
<div class="c">Thank you for visiting!</div>
<div class="c" style="margin-top:6px;">Developed for <?php echo esc_html( $hotel ); ?></div>
<div class="c" style="font-size:11px;">Proudly powered by DineX Restro Solutions</div>
<script>window.onload = function () { setTimeout(function () { window.print(); }, 300); };</script>
</body></html>
<?php
    return ob_get_clean();
}

function qrrs_ajax_confirm_self_payment() {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    global $wpdb;
    $order_id = intval( $_POST['order_id'] ?? 0 );
    $t = $wpdb->prefix . 'qrrs_orders';
    $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $order_id ) );
    if ( ! $order || 'self' !== $order->order_source || 'awaiting_payment' !== $order->order_status ) {
        wp_send_json_error( 'This order is not waiting for payment.' );
    }
    if ( ! qrrs_current_user_can_operate_restaurant( $order->restaurant_id ) ) {
        wp_send_json_error( 'You do not have permission to confirm this payment.' );
    }
    $ks   = qrrs_get_restaurant_kitchen_settings( $order->restaurant_id );
    $next = $ks['mode'] === 'ready_food' ? 'ready' : 'pending';
    $total = round( (float) $order->grand_total, 2 );
    $ok = $wpdb->update( $t, [
        'order_status'        => $next,
        'verification_status' => 'approved',
        'payment_status'      => 'paid',
        'payment_method'      => 'cash',
        'final_total'         => $total,
        'discount_amount'     => 0,
        'amount_received'     => $total,
        'cash_returned'       => 0,
    ], [ 'id' => $order_id ] );
    if ( function_exists( 'qrrs_payment_methods' ) && 0 === qrrs_payments_sum( $order_id ) ) {
        $wpdb->insert( $wpdb->prefix . 'qrrs_payments', [
            'order_id'      => $order_id,
            'restaurant_id' => (int) $order->restaurant_id,
            'method'        => 'cash',
            'amount'        => $total,
            'reference'     => '',
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time( 'mysql' ),
        ] );
    }
    if ( false === $ok ) {
        wp_send_json_error( 'Could not confirm the payment. Please try again.' );
    }
    wp_send_json_success( [ 'slip' => qrrs_self_slip_html( $order_id ) ] );
}
add_action( 'wp_ajax_qrrs_confirm_self_payment', 'qrrs_ajax_confirm_self_payment' );

// Token slip before payment (printed when the counter collects the payment)
add_action( 'wp_ajax_qrrs_self_slip', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in again.' );
    $html = qrrs_self_slip_html( intval( $_POST['order_id'] ?? 0 ), false );
    if ( '' === $html ) wp_send_json_error( 'Order not found.' );
    wp_send_json_success( [ 'html' => $html ] );
} );