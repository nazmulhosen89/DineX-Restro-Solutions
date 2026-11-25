<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bill (receipt) for an order, sized to the paper width set in the restaurant's printer settings.
 * Shows each item, the offer discount, free items, VAT, service charge, rounding, the total and the payments.
 */
function qrrs_bill_html( $order_id, $print_script = true ) {
    global $wpdb;
    $oid = intval( $order_id );
    $o = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_orders WHERE id = %d", $oid ) );
    if ( ! $o ) return '';
    $rid   = intval( $o->restaurant_id );
    $pset  = function_exists( 'qrrs_printer_get' ) ? qrrs_printer_get( $rid ) : [ 'paper' => '80' ];
    $paper = ( '58' === ( $pset['paper'] ?? '80' ) ) ? '58' : '80';
    $cw    = '58' === $paper ? 52 : 72;
    $rest  = $wpdb->get_var( $wpdb->prepare( "SELECT restaurant_name FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $rid ) );

    $lines = $wpdb->get_results( $wpdb->prepare(
        "SELECT oi.item_name, oi.quantity, oi.price, oi.item_id, i.price AS menu_price
         FROM {$wpdb->prefix}qrrs_order_items oi
         LEFT JOIN {$wpdb->prefix}qrrs_items i ON i.id = oi.item_id
         WHERE oi.order_id = %d ORDER BY oi.id ASC",
        $oid
    ) );
    $pays = $wpdb->get_results( $wpdb->prepare(
        "SELECT method, amount FROM {$wpdb->prefix}qrrs_payments WHERE order_id = %d ORDER BY id ASC", $oid
    ) );

    $subtotal = 0.0; $offer_total = 0.0;
    $rows = [];
    foreach ( $lines as $l ) {
        $qty   = max( 1, intval( $l->quantity ) );
        $price = (float) $l->price;
        $menu  = (float) $l->menu_price;
        $line  = round( $price * $qty, 2 );
        $subtotal += $line;
        $disc = ( $menu > $price && $price >= 0 ) ? round( ( $menu - $price ) * $qty, 2 ) : 0.0;
        $offer_total += $disc;
        $oname = '';
        if ( $disc > 0 && function_exists( 'qrrs_best_offer_for_item' ) ) {
            $bo = qrrs_best_offer_for_item( $rid, intval( $l->item_id ), $menu, [] );
            $oname = $bo ? (string) $bo['name'] : '';
        }
        $rows[] = [ 'name' => $l->item_name, 'qty' => $qty, 'price' => $price, 'line' => $line, 'free' => ( $price <= 0 && $menu > 0 ), 'off' => $disc > 0, 'oname' => $oname ];
    }
    $subtotal = round( $subtotal, 2 );
    $vat   = (float) $o->tax_amount;
    $sc    = (float) $o->service_charge;
    $round = (float) ( $o->rounding_amount ?? 0 );
    $total = (float) ( ( $o->final_total ?: $o->grand_total ) );
    $paid  = (float) ( $o->amount_received ?? 0 );
    $change = max( 0, round( $paid - $total, 2 ) );
    $footer = trim( (string) ( $pset['footer'] ?? '' ) );

    $css = function_exists( 'qrrs_print_css' ) ? qrrs_print_css( $paper ) : '@page { size: 80mm auto; margin: 2mm; }';
    $f = function ( $v ) { return number_format( (float) $v, 2 ); };

    ob_start(); ?>
<!doctype html><html><head><meta charset="utf-8"><title>Bill #<?php echo $oid; ?></title>
<style>
<?php echo $css; ?>
body { font-family: monospace; width: <?php echo $cw; ?>mm; margin: 0 auto; padding: 2mm; font-size: 12px; color: #000; }
.c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
.row { display: flex; justify-content: space-between; gap: 6px; }
.dash { border-top: 1px dashed #000; margin: 5px 0; }
.big { font-size: 15px; font-weight: bold; }
.off { font-size: 10px; } s { color: #666; }
</style></head><body>
<div class="c b"><?php echo esc_html( $rest ); ?></div>
<div class="c">Bill #<?php echo $oid; ?> · <?php echo esc_html( wp_date( 'd M Y, h:i A', strtotime( $o->created_at ) ) ); ?></div>
<div class="c"><?php echo esc_html( $o->table_name ?: ( $o->order_type ?: '' ) ); ?></div>
<div class="dash"></div>
<?php foreach ( $rows as $r ) : ?>
    <div class="row"><span><?php echo esc_html( $r['name'] ); ?></span></div>
    <div class="row" style="padding-left:8px;">
        <span><?php echo $r['qty']; ?> x <?php echo $f( $r['price'] ); ?><?php echo $r['off'] ? ' <span class="off">(' . esc_html( $r['oname'] ?: 'offer' ) . ')</span>' : ''; ?><?php echo $r['free'] ? ' <span class="off">(free)</span>' : ''; ?></span>
        <span><?php echo $f( $r['line'] ); ?></span>
    </div>
<?php endforeach; ?>
<div class="dash"></div>
<div class="row"><span>Subtotal</span><span><?php echo $f( $subtotal ); ?></span></div>
<?php if ( $offer_total > 0 ) : ?>
<div class="row"><span>Offer discount</span><span>-<?php echo $f( $offer_total ); ?></span></div>
<?php endif; ?>
<?php if ( $vat > 0 ) : ?><div class="row"><span>VAT</span><span><?php echo $f( $vat ); ?></span></div><?php endif; ?>
<?php if ( $sc > 0 ) : ?><div class="row"><span>Service charge</span><span><?php echo $f( $sc ); ?></span></div><?php endif; ?>
<?php if ( abs( $round ) > 0.001 ) : ?><div class="row"><span>Rounding</span><span><?php echo $f( $round ); ?></span></div><?php endif; ?>
<div class="dash"></div>
<div class="row big"><span>TOTAL</span><span><?php echo $f( $total ); ?></span></div>
<div class="dash"></div>
<?php foreach ( $pays as $p ) : ?>
    <div class="row"><span>Paid · <?php echo esc_html( ucfirst( $p->method ) ); ?></span><span><?php echo $f( $p->amount ); ?></span></div>
<?php endforeach; ?>
<?php if ( $change > 0 ) : ?><div class="row"><span>Change</span><span><?php echo $f( $change ); ?></span></div><?php endif; ?>
<?php if ( $footer !== '' ) : ?>
<div class="dash"></div>
<div class="c"><?php echo nl2br( esc_html( $footer ) ); ?></div>
<?php endif; ?>
<?php if ( $print_script ) : ?>
<script>window.onload = function () { setTimeout(function () { window.print(); }, 300); };</script>
<?php endif; ?>
</body></html>
<?php
    return ob_get_clean();
}

add_action( 'wp_ajax_qrrs_bill_print', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in again.' );
    $html = qrrs_bill_html( intval( $_POST['order_id'] ?? 0 ), empty( $_POST['silent'] ) );
    if ( '' === $html ) wp_send_json_error( 'Bill not found.' );
    wp_send_json_success( [ 'html' => $html ] );
} );

/**
 * After a settle: when the bill is paid and the printer has "auto bill" on, print it silently
 * on the Windows app's printer. Does nothing in a normal browser.
 */
function qrrs_bill_auto_print_script( $order_id ) {
    global $wpdb;
    $oid = intval( $order_id );
    $o = $wpdb->get_row( $wpdb->prepare( "SELECT restaurant_id, payment_status FROM {$wpdb->prefix}qrrs_orders WHERE id = %d", $oid ) );
    if ( ! $o || 'paid' !== (string) $o->payment_status ) return '';
    $pset = function_exists( 'qrrs_printer_get' ) ? qrrs_printer_get( intval( $o->restaurant_id ) ) : [];
    if ( empty( $pset['auto_bill'] ) ) return '';
    $cfg = wp_json_encode( [
        'drawer' => ! empty( $pset['drawer'] ),
        'ajax'   => admin_url( 'admin-ajax.php' ),
        'nonce'  => wp_create_nonce( 'qr_order_nonce' ),
        'order'  => $oid,
        'device' => (string) ( $pset['usb_name'] ?? '' ),
        'copies' => max( 1, intval( $pset['copies'] ?? 1 ) ),
    ] );
    return '<script>(function(){ var c=' . $cfg . ';
        if (!window.dinexPos || !window.dinexPos.silentPrint) return;
        var body = new URLSearchParams({ action: "qrrs_bill_print", order_id: c.order, security: c.nonce, silent: 1 });
        fetch(c.ajax, { method: "POST", body: body, credentials: "same-origin" }).then(function (r) { return r.json(); })
          .then(function (j) { if (j && j.success) return window.dinexPos.silentPrint(j.data.html, c.device, c.copies).then(function () { if (c.drawer && window.dinexPos.openDrawer) window.dinexPos.openDrawer(c.device); }); });
    })();</script>';
}