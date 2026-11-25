<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Split payment: one bill can be paid with several methods (cash, bKash, Nagad, card ...).
 *  - Every payment is a line in qrrs_payments.
 *  - The bill closes only when the lines cover the total (after discount).
 *  - Cash may give change. Other methods cannot be more than the amount still due.
 */

function qrrs_payment_methods() {
    return [ 'cash' => 'Cash', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'card' => 'Card', 'other' => 'Other' ];
}

function qrrs_payments_sum( $order_id ) {
    global $wpdb;
    return round( (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}qrrs_payments WHERE order_id = %d", intval( $order_id )
    ) ), 2 );
}

/** One method name, or 'split' when several methods were used. */
function qrrs_payments_label( $order_id ) {
    global $wpdb;
    $n = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT method) FROM {$wpdb->prefix}qrrs_payments WHERE order_id = %d", intval( $order_id )
    ) );
    if ( $n > 1 ) return 'split';
    return (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT method FROM {$wpdb->prefix}qrrs_payments WHERE order_id = %d LIMIT 1", intval( $order_id )
    ) ) ?: 'cash';
}

/** Total after discount, the same way the complete handler calculates it. */
function qrrs_bill_final_total( $grand, $discount_type, $discount_value ) {
    $grand = (float) $grand;
    $disc  = 0.0;
    if ( 'percent' === $discount_type ) {
        $v = min( max( (float) $discount_value, 0 ), 100 );
        $disc = round( $grand * $v / 100, 2 );
    } elseif ( 'flat' === $discount_type ) {
        $disc = min( max( (float) $discount_value, 0 ), $grand );
    }
    return round( $grand - $disc );
}

/** True when split lines exist but do not yet cover the bill. */
function qrrs_split_insufficient( $order_id, $grand, $discount_type, $discount_value ) {
    $sum = qrrs_payments_sum( $order_id );
    if ( $sum <= 0 ) return false; // no split lines: the normal single payment applies
    $short = $sum + 0.005 < qrrs_bill_final_total( $grand, $discount_type, $discount_value );
    if ( $short ) $GLOBALS['qrrs_split_short'] = true;
    return $short;
}

function qrrs_billing_user_can_take_payment() {
    if ( current_user_can( 'administrator' ) ) return true;
    $u = wp_get_current_user();
    return $u && ( in_array( 'qr_manager', (array) $u->roles, true ) || in_array( 'qr_waiter', (array) $u->roles, true ) );
}

add_action( 'admin_post_qrrs_add_payment', function () {
    if ( ! is_user_logged_in() || ! qrrs_billing_user_can_take_payment() || ( function_exists( 'qrrs_can' ) && ! qrrs_can( 'billing.settle' ) ) ) wp_die( 'Not allowed to take payments.' );
    global $wpdb;
    $id = intval( $_POST['order_id'] ?? 0 );
    check_admin_referer( 'qrrs_add_payment_' . $id );
    $back = add_query_arg( [ 'tab' => 'billing', 'order_id' => $id ], home_url( '/restaurant-dashboard/' ) );
    $t    = $wpdb->prefix . 'qrrs_orders';
    $o    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
    $method = sanitize_key( $_POST['method'] ?? '' );
    $amount = round( (float) ( $_POST['amount'] ?? 0 ), 2 );
    $ref    = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );
    $methods = qrrs_payment_methods();

    $ajax = ! empty( $_POST['ajax'] );
    $fail = function ( $code ) use ( $back, $ajax ) {
        if ( $ajax ) {
            $texts = [ 'not_allowed' => 'This bill cannot take payments now.', 'bad_method' => 'Choose a payment method.', 'bad_amount' => 'Enter an amount greater than 0.', 'ref_needed' => 'Enter the transaction reference for this payment.', 'over_due' => 'The amount is more than the balance due. Only cash can give change.' ];
            wp_send_json_error( $texts[ $code ] ?? 'Could not add the payment.' );
        }
        wp_safe_redirect( add_query_arg( 'pay_status', $code, $back ) ); exit;
    };
    if ( ! $o || 'paid' === $o->payment_status || 'cancelled' === $o->order_status ) $fail( 'not_allowed' );
    if ( ! isset( $methods[ $method ] ) ) $fail( 'bad_method' );
    if ( $amount <= 0 ) $fail( 'bad_amount' );
    if ( 'cash' !== $method && '' === trim( $ref ) ) $fail( 'ref_needed' );

    // The popup shows the payable in whole taka, so the due is compared the same way
    $due = round( (float) $o->grand_total - qrrs_payments_sum( $id ) );
    if ( 'cash' !== $method && $amount > $due + 0.005 ) $fail( 'over_due' );

    $wpdb->insert( $wpdb->prefix . 'qrrs_payments', [
        'order_id'      => $id,
        'restaurant_id' => (int) $o->restaurant_id,
        'method'        => $method,
        'amount'        => $amount,
        'reference'     => substr( $ref, 0, 100 ),
        'created_by'    => get_current_user_id(),
        'created_at'    => current_time( 'mysql' ),
    ] );
    qrrs_audit( 'payment_added', 'order', $id, [ 'method' => $method, 'amount' => $amount, 'ref' => $ref ], (int) $o->restaurant_id );
    if ( $ajax ) {
        wp_send_json_success( [ 'total' => qrrs_payments_sum( $id ), 'lines' => qrrs_payment_lines_for_js( $id ) ] );
    }
    wp_safe_redirect( add_query_arg( 'pay_status', 'added', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_remove_payment', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) wp_die( 'Only a manager or administrator can remove a payment line.' );
    global $wpdb;
    $pid = intval( $_POST['payment_id'] ?? 0 );
    $oid = intval( $_POST['order_id'] ?? 0 );
    check_admin_referer( 'qrrs_remove_payment_' . $pid );
    $line = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_payments WHERE id = %d", $pid ) );
    if ( $line ) {
        $wpdb->delete( $wpdb->prefix . 'qrrs_payments', [ 'id' => $pid ] );
        qrrs_audit( 'payment_removed', 'order', $oid, [ 'method' => $line->method, 'amount' => $line->amount ], (int) $line->restaurant_id );
    }
    wp_safe_redirect( add_query_arg( [ 'tab' => 'billing', 'order_id' => $oid, 'pay_status' => 'removed' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );

/** Payment lines for the settle popup (JSON-ready). */
function qrrs_payment_lines_for_js( $order_id ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, method, amount, reference FROM {$wpdb->prefix}qrrs_payments WHERE order_id = %d ORDER BY id ASC", intval( $order_id )
    ) );
    $out = [];
    foreach ( $rows as $r ) {
        $out[] = [ 'id' => (int) $r->id, 'key' => (string) $r->method, 'method' => qrrs_payment_methods()[ $r->method ] ?? $r->method, 'amount' => (float) $r->amount, 'ref' => (string) $r->reference ];
    }
    return $out;
}

/** Panel on the billing page: payment lines, balance due, and the add form. */
function qrrs_split_payment_panel_html( $order ) {
    global $wpdb;
    $id = intval( $order->id );
    $msgs = [
        'added'      => [ 'ok',  'Payment added.' ],
        'removed'    => [ 'ok',  'Payment line removed.' ],
        'bad_method' => [ 'err', 'Choose a payment method.' ],
        'bad_amount' => [ 'err', 'Enter an amount greater than 0.' ],
        'ref_needed' => [ 'err', 'Enter the transaction reference for this payment.' ],
        'over_due'   => [ 'err', 'The amount is more than the balance due. Only cash can give change.' ],
        'not_allowed'=> [ 'err', 'This bill cannot take payments now.' ],
    ];
    $st = sanitize_key( $_GET['pay_status'] ?? '' );
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:8px; margin-bottom:12px;">' . esc_html( $txt ) . '</div>';
    }
    if ( ! empty( $GLOBALS['qrrs_split_short'] ) ) {
        echo '<div style="background:#fee2e2;color:#991b1b; padding:10px 14px; border-radius:8px; margin-bottom:12px;">The payment lines do not cover the bill yet. Add the rest before completing.</div>';
    }
    if ( 'paid' === $order->payment_status || 'cancelled' === $order->order_status ) return;

    $lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_payments WHERE order_id = %d ORDER BY id ASC", $id ) );
    $paid  = qrrs_payments_sum( $id );
    $due   = round( (float) $order->grand_total - $paid, 2 );
    $can_remove = function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
    $inp = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;';
    ?>
    <details <?php echo $st ? 'open' : ''; ?> style="border:1px solid #bae6fd; background:#f0f9ff; border-radius:10px; margin-bottom:14px;">
        <summary style="cursor:pointer; padding:12px 16px; font-weight:700; color:#075985; list-style:none; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
            <span>💳 Collect payment</span>
            <span style="font-size:13px; font-weight:600; color:#334155;">Bill <?php echo number_format( (float) $order->grand_total, 2 ); ?> · Paid <?php echo number_format( $paid, 2 ); ?> · <span style="color:<?php echo $due > 0 ? '#dc2626' : '#16a34a'; ?>">Due <?php echo number_format( max( 0, $due ), 2 ); ?></span></span>
        </summary>
        <div style="padding:4px 16px 16px;">
        <?php if ( $lines ) : ?>
        <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:10px;">
            <?php foreach ( $lines as $l ) : ?>
            <tr style="border-top:1px solid #e0f2fe;">
                <td style="padding:6px 4px;"><?php echo esc_html( qrrs_payment_methods()[ $l->method ] ?? $l->method ); ?></td>
                <td style="padding:6px 4px; color:#64748b;"><?php echo esc_html( $l->reference ?: '—' ); ?></td>
                <td style="padding:6px 4px; text-align:right; font-weight:700;"><?php echo number_format( (float) $l->amount, 2 ); ?></td>
                <td style="padding:6px 0 6px 10px; width:90px;">
                    <?php if ( $can_remove ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remove this payment line?');">
                        <input type="hidden" name="action" value="qrrs_remove_payment">
                        <input type="hidden" name="payment_id" value="<?php echo intval( $l->id ); ?>">
                        <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                        <?php wp_nonce_field( 'qrrs_remove_payment_' . intval( $l->id ) ); ?>
                        <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:12px;">Remove</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <?php if ( $due > 0.005 ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="action" value="qrrs_add_payment">
            <input type="hidden" name="order_id" value="<?php echo $id; ?>">
            <?php wp_nonce_field( 'qrrs_add_payment_' . $id ); ?>
            <select name="method" style="<?php echo $inp; ?>">
                <?php foreach ( qrrs_payment_methods() as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option><?php endforeach; ?>
            </select>
            <input type="number" name="amount" step="0.01" min="0.01" value="<?php echo esc_attr( number_format( $due, 2, '.', '' ) ); ?>" style="<?php echo $inp; ?> width:120px;">
            <input type="text" name="reference" maxlength="100" placeholder="Trx ID (non-cash)" style="<?php echo $inp; ?> width:180px;">
            <button type="submit" style="background:#0284c7; color:#fff; border:none; padding:9px 16px; border-radius:8px; font-weight:700; cursor:pointer;">Add payment</button>
        </form>
        <p style="margin:6px 0 0; font-size:12px; color:#64748b;">Cash can be more than the due amount. The extra is returned as change. For bKash, Nagad, Rocket or card, the amount cannot be more than the due.</p>
        <?php else : ?>
        <p style="margin:0; font-size:13px; color:#166534;">The payments cover the bill. Press Complete to close it.</p>
        <?php endif; ?>
        </div>
    </details>
    <?php
}