<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Void and refund (manager and admin only).
 *  - VOID: for an unpaid bill. The bill is cancelled and its kitchen stock is returned.
 *  - REFUND: for a paid bill. The money is marked refunded. The order stays as it was,
 *    because the food was already made. Refunded bills drop out of payment totals.
 *  - Both need a reason, and both are written to the audit log.
 */

function qrrs_void_panel_html( $order ) {
    if ( function_exists( 'qrrs_can' ) && ! qrrs_can( 'billing.void' ) ) return '';
    if ( ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) return;
    $msgs = [
        'voided'        => [ 'ok',  'Bill voided. Kitchen stock was returned.' ],
        'refunded'      => [ 'ok',  'Refund recorded.' ],
        'not_allowed'   => [ 'err', 'This bill cannot be voided or refunded in its current state.' ],
        'reason_needed' => [ 'err', 'Please write a reason (at least 3 characters).' ],
    ];
    $st = sanitize_key( $_GET['vr_status'] ?? '' );
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:8px; margin-bottom:12px;">' . esc_html( $txt ) . '</div>';
    }
    $id = intval( $order->id );
    $pay = (string) ( $order->payment_status ?? '' );
    $done = in_array( $pay, [ 'voided', 'refunded' ], true ) || 'cancelled' === $order->order_status;
    echo '<div style="border:1px solid #fde68a; background:#fffbeb; border-radius:10px; padding:14px 16px; margin-bottom:14px;">';
    if ( $done ) {
        echo '<strong style="color:#92400e;">This bill is ' . esc_html( 'refunded' === $pay ? 'refunded' : ( 'voided' === $pay || 'cancelled' === $order->order_status ? 'voided' : $order->order_status ) ) . '.</strong>';
        if ( ! empty( $order->void_reason ) || ! empty( $order->refund_reason ) ) {
            echo '<div style="font-size:13px; color:#78350f; margin-top:4px;">Reason: ' . esc_html( $order->refund_reason ?: $order->void_reason ) . '</div>';
        }
        echo '</div>';
        return;
    }
    global $wpdb;
    $used = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d AND inventory_deducted = 1", $id
    ) ) > 0;
    $is_paid = 'paid' === $pay;
    $mode    = $is_paid ? 'refund' : 'void';
    $label   = $is_paid ? 'Refund ' . number_format( (float) $order->grand_total, 2 ) . '৳' : 'Void this bill';
    if ( $is_paid ) {
        $warn = 'Money goes back to the customer. The food stays counted as used.';
    } elseif ( $used ) {
        $warn = 'The kitchen already started this order, so the raw materials are used and will NOT go back to stock. The bill is closed as void.';
    } else {
        $warn = 'The kitchen has not started this order, so the raw materials go back to stock.';
    }
    ?>
    <strong style="color:#92400e;"><?php echo $is_paid ? 'Refund a paid bill' : 'Void an unpaid bill'; ?></strong>
    <p style="margin:4px 0 10px; font-size:12px; color:#78350f;"><?php echo esc_html( $warn ); ?></p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( $label . '? This cannot be undone.' ); ?>');" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <input type="hidden" name="action" value="qrrs_void_refund">
        <input type="hidden" name="order_id" value="<?php echo $id; ?>">
        <input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
        <?php wp_nonce_field( 'qrrs_void_refund_' . $id ); ?>
        <input type="text" name="reason" required minlength="3" maxlength="200" placeholder="Reason (required)" style="padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; min-width:260px;">
        <button type="submit" style="background:#dc2626; color:#fff; border:none; padding:10px 16px; border-radius:8px; font-weight:700; cursor:pointer;"><?php echo esc_html( $label ); ?></button>
    </form>
    </div>
    <?php
}

add_action( 'admin_post_qrrs_void_refund', function () {
    if ( ! is_user_logged_in() || ( ! function_exists( 'qrrs_can' ) ? ( ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) : ! qrrs_can( 'billing.void' ) ) ) {
        wp_die( 'You are not allowed to void or refund a bill.' );
    }
    global $wpdb;
    $id   = intval( $_POST['order_id'] ?? 0 );
    check_admin_referer( 'qrrs_void_refund_' . $id );
    $mode   = sanitize_key( $_POST['mode'] ?? '' );
    $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
    $back   = add_query_arg( [ 'tab' => 'billing', 'order_id' => $id ], home_url( '/restaurant-dashboard/' ) );
    if ( mb_strlen( trim( $reason ) ) < 3 ) {
        wp_safe_redirect( add_query_arg( 'vr_status', 'reason_needed', $back ) ); exit;
    }
    $t     = $wpdb->prefix . 'qrrs_orders';
    $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
    $now   = current_time( 'mysql' );
    $uid   = get_current_user_id();
    if ( ! $order ) {
        wp_safe_redirect( add_query_arg( 'vr_status', 'not_allowed', $back ) ); exit;
    }

    if ( 'void' === $mode && 'paid' !== $order->payment_status && 'cancelled' !== $order->order_status ) {
        $wpdb->update( $t, [
            'order_status'   => 'cancelled',
            'payment_status' => 'voided',
            'void_reason'    => $reason,
            'voided_at'      => $now,
            'voided_by'      => $uid,
        ], [ 'id' => $id ] );
        $used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d AND inventory_deducted = 1", $id
        ) ) > 0;
        if ( ! $used ) {
            // Not cooked yet: the cancel hook returns the raw materials
            do_action( 'qrrs_order_status_changed', $id, 'cancelled' );
        }
        qrrs_audit( 'order_voided', 'order', $id, [ 'reason' => $reason, 'total' => $order->grand_total, 'stock_returned' => ! $used ], (int) $order->restaurant_id );
        wp_safe_redirect( add_query_arg( 'vr_status', 'voided', $back ) ); exit;
    }

    if ( 'refund' === $mode && 'paid' === $order->payment_status ) {
        $amount = round( (float) $order->grand_total, 2 );
        $wpdb->update( $t, [
            'payment_status' => 'refunded',
            'refund_reason'  => $reason,
            'refunded_at'    => $now,
            'refunded_by'    => $uid,
            'refund_amount'  => $amount,
        ], [ 'id' => $id ] );
        qrrs_audit( 'order_refunded', 'order', $id, [ 'reason' => $reason, 'amount' => $amount ], (int) $order->restaurant_id );
        wp_safe_redirect( add_query_arg( 'vr_status', 'refunded', $back ) ); exit;
    }

    wp_safe_redirect( add_query_arg( 'vr_status', 'not_allowed', $back ) ); exit;
} );

/** Void / refund button (placed beside the collect button) with a reason popup. */
function qrrs_void_button_html( $order ) {
    if ( function_exists( 'qrrs_can' ) && ! qrrs_can( 'billing.void' ) ) return '';
    if ( ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) return;
    global $wpdb;
    $id   = intval( $order->id );
    $pay  = (string) ( $order->payment_status ?? '' );
    $st   = sanitize_key( $_GET['vr_status'] ?? '' );
    $msgs = [
        'voided'        => [ 'ok',  'Bill voided.' ],
        'refunded'      => [ 'ok',  'Refund recorded.' ],
        'not_allowed'   => [ 'err', 'This bill cannot be voided or refunded now.' ],
        'reason_needed' => [ 'err', 'Please write a reason (at least 3 characters).' ],
    ];
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="flex-basis:100%; background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:8px 12px; border-radius:8px; margin-top:10px;">' . esc_html( $txt ) . '</div>';
    }
    $done = in_array( $pay, [ 'voided', 'refunded' ], true ) || 'cancelled' === $order->order_status;
    if ( $done ) return;

    $is_paid = 'paid' === $pay;
    $mode    = $is_paid ? 'refund' : 'void';
    $used    = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_order_items WHERE order_id = %d AND inventory_deducted = 1", $id
    ) ) > 0;
    if ( $is_paid ) {
        $warn = 'Money goes back to the customer. The food stays counted as used.';
    } elseif ( $used ) {
        $warn = 'The kitchen already started this order, so the raw materials are used and will NOT go back to stock. The bill is closed as void.';
    } else {
        $warn = 'The kitchen has not started this order, so the raw materials go back to stock.';
    }
    $title = $is_paid ? 'Refund this bill?' : 'Void this bill?';
    $label = $is_paid ? 'Refund' : 'Void';
    ?>
    <button type="button" onclick="document.getElementById('vr-modal-<?php echo $id; ?>').style.display='flex';"
        style="flex:0 0 150px; height:55px; margin-top:20px; background:#fff; border:2px solid #dc2626; color:#dc2626; border-radius:8px; font-weight:700; font-size:15px; cursor:pointer;">
        ✖ <?php echo esc_html( $label ); ?>
    </button>
    <div id="vr-modal-<?php echo $id; ?>" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100001; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:12px; padding:22px; width:400px; max-width:92vw; box-shadow:0 15px 40px rgba(0,0,0,0.2);">
            <h3 style="margin:0 0 8px; color:#1e293b; font-size:18px;"><?php echo esc_html( $title ); ?></h3>
            <p style="margin:0 0 14px; font-size:13px; color:#78350f; background:#fffbeb; padding:10px 12px; border-radius:8px;"><?php echo esc_html( $warn ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="qrrs_void_refund">
                <input type="hidden" name="order_id" value="<?php echo $id; ?>">
                <input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>">
                <?php wp_nonce_field( 'qrrs_void_refund_' . $id ); ?>
                <label style="font-size:13px; font-weight:700; color:#1e293b; display:block; margin-bottom:6px;">Reason (required)</label>
                <textarea name="reason" required minlength="3" maxlength="200" rows="3" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;"></textarea>
                <div style="display:flex; gap:10px; margin-top:14px;">
                    <button type="button" onclick="document.getElementById('vr-modal-<?php echo $id; ?>').style.display='none';" style="flex:1; padding:12px; background:#f1f5f9; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Cancel</button>
                    <button type="submit" style="flex:1; padding:12px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Submit</button>
                </div>
            </form>
        </div>
    </div>
    <?php
}