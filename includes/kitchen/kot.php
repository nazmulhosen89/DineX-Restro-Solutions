<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KOT (Kitchen Order Ticket): a printed ticket for the kitchen.
 * Shows what to cook (no prices). Printed through the browser print dialog,
 * the same way as the token slip.
 */

function qrrs_kot_html( $order_id, $print_script = true ) {
    global $wpdb;
    $o   = $wpdb->prefix . 'qrrs_orders';
    $oi  = $wpdb->prefix . 'qrrs_order_items';
    $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $o WHERE id = %d", intval( $order_id ) ) );
    if ( ! $order ) return '';
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT item_name, quantity, variants_selected FROM $oi WHERE order_id = %d ORDER BY id ASC", intval( $order_id )
    ) );
    $pset = function_exists( 'qrrs_printer_get' ) ? qrrs_printer_get( $order->restaurant_id ) : [ 'paper' => '80' ];
    $cw   = ( '58' === ( $pset['paper'] ?? '80' ) ) ? 52 : 72;
    $hotel = $wpdb->get_var( $wpdb->prepare(
        "SELECT restaurant_name FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", intval( $order->restaurant_id )
    ) );
    $when  = $order->created_at ? date_i18n( 'd M Y, h:i A', qrrs_local_ts( $order->created_at ) ) : '';
    $where = $order->token_no ? 'Token ' . $order->token_no : ( $order->table_name ?: '—' );

    ob_start(); ?>
<html><head><meta charset="utf-8"><title>KOT #<?php echo intval( $order->id ); ?></title>
<style>
  @page { size: <?php echo ( '58' === ( $pset['paper'] ?? '80' ) ) ? 58 : 80; ?>mm auto; margin: 2mm; }
  body { font-family: monospace; width: <?php echo $cw; ?>mm; margin: 0 auto; padding: 2mm; font-size: 14px; color: #000; }
  .c { text-align: center; }
  .big { font-size: 26px; font-weight: bold; text-align: center; margin: 6px 0; }
  .item { font-size: 18px; font-weight: bold; margin-top: 8px; }
  .addon { font-size: 14px; margin-left: 14px; }
  hr { border: none; border-top: 1px dashed #000; margin: 8px 0; }
</style></head><body>
<div class="c"><strong>KITCHEN ORDER TICKET</strong></div>
<div class="c"><?php echo esc_html( $hotel ); ?></div>
<div class="big"><?php echo esc_html( $where ); ?></div>
<div class="c">KOT #<?php echo intval( $order->id ); ?> · <?php echo esc_html( str_replace( '_', ' ', $order->order_type ) ); ?></div>
<div class="c"><?php echo esc_html( $when ); ?></div>
<hr>
<?php foreach ( $items as $it ) : ?>
  <div class="item"><?php echo intval( $it->quantity ); ?> x <?php echo esc_html( $it->item_name ); ?></div>
  <?php if ( ! empty( $it->variants_selected ) ) : ?>
    <div class="addon">+ <?php echo esc_html( $it->variants_selected ); ?></div>
  <?php endif; ?>
<?php endforeach; ?>
<hr>
<div class="c"><?php echo count( $items ); ?> item line(s)</div>
<?php if ( $print_script ) : ?><script>window.onload = function () { setTimeout(function () { window.print(); }, 300); };</script><?php endif; ?>
</body></html>
<?php
    return ob_get_clean();
}

add_action( 'wp_ajax_qrrs_kot_print', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in again.' );
    $order_id = intval( $_POST['order_id'] ?? 0 );
    $html = qrrs_kot_html( $order_id, empty( $_POST['silent'] ) );
    if ( '' === $html ) wp_send_json_error( 'Order not found.' );
    wp_send_json_success( [ 'html' => $html ] );
} );