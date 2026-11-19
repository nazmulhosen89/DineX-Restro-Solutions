<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Daily closing (manager and admin).
 *  - Money received per payment method on the day (from payment lines), minus refunds.
 *  - The cash in hand is counted and compared with the expected cash.
 *  - A day can be closed once. The closing is saved and written to the audit log.
 *  - Refunds are taken from cash (the refund method is not recorded yet).
 */

function qrrs_closing_user_can() {
    return function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
}

function qrrs_closing_expected( $rid, $date ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $by_method = $wpdb->get_results( $wpdb->prepare(
        "SELECT method, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
         FROM {$p}qrrs_payments WHERE restaurant_id = %d AND DATE(created_at) = %s
         GROUP BY method ORDER BY total DESC",
        intval( $rid ), $date
    ) );
    $refunds = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount),0) AS total FROM {$p}qrrs_orders
         WHERE restaurant_id = %d AND payment_status = 'refunded' AND DATE(refunded_at) = %s",
        intval( $rid ), $date
    ) );
    $voids = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}qrrs_orders WHERE restaurant_id = %d AND payment_status = 'voided' AND DATE(voided_at) = %s",
        intval( $rid ), $date
    ) );
    // Bills paid before payment lines existed: take method and amount from the order
    $legacy = $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF(o.payment_method,''),'cash') AS method, COUNT(*) AS n,
                COALESCE(SUM(COALESCE(o.final_total,o.grand_total)),0) AS total
         FROM {$p}qrrs_orders o
         WHERE o.restaurant_id = %d AND o.payment_status = 'paid' AND DATE(o.created_at) = %s
           AND NOT EXISTS (SELECT 1 FROM {$p}qrrs_payments pl WHERE pl.order_id = o.id)
         GROUP BY method",
        intval( $rid ), $date
    ) );
    $merged = [];
    foreach ( array_merge( (array) $by_method, (array) $legacy ) as $r ) {
        $k = $r->method;
        if ( ! isset( $merged[ $k ] ) ) $merged[ $k ] = (object) [ 'method' => $k, 'n' => 0, 'total' => 0.0 ];
        $merged[ $k ]->n     += (int) $r->n;
        $merged[ $k ]->total += (float) $r->total;
    }
    $by_method = array_values( $merged );
    usort( $by_method, function ( $a, $b ) { return $b->total <=> $a->total; } );

    $collected = 0.0; $cash = 0.0;
    foreach ( $by_method as $m ) {
        $collected += (float) $m->total;
        if ( 'cash' === $m->method ) $cash = (float) $m->total;
    }
    $refund_total = (float) $refunds->total;
    return [
        'methods'      => $by_method,
        'collected'    => round( $collected, 2 ),
        'refunds'      => round( $refund_total, 2 ),
        'refund_count' => (int) $refunds->n,
        'voids'        => $voids,
        'net'          => round( $collected - $refund_total, 2 ),
        'expected_cash'=> round( $cash - $refund_total, 2 ),
    ];
}

function qrrs_closing_saved( $rid, $date ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT c.*, u.display_name AS by_name FROM {$wpdb->prefix}qrrs_day_closings c
         LEFT JOIN {$wpdb->users} u ON u.ID = c.closed_by
         WHERE c.restaurant_id = %d AND c.closing_date = %s", intval( $rid ), $date
    ) );
}

add_action( 'admin_post_qrrs_day_close', function () {
    if ( ! is_user_logged_in() || ! qrrs_closing_user_can() ) wp_die( 'Only a manager or administrator can close the day.' );
    global $wpdb;
    $rid  = intval( $_POST['restaurant_id'] ?? 0 );
    $date = sanitize_text_field( $_POST['closing_date'] ?? '' );
    check_admin_referer( 'qrrs_day_close_' . $date );
    $back = add_query_arg( [ 'tab' => 'closing', 'closing_date' => $date ], home_url( '/restaurant-dashboard/' ) );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || qrrs_closing_saved( $rid, $date ) ) {
        wp_safe_redirect( add_query_arg( 'cl_status', 'exists', $back ) ); exit;
    }
    $counted = round( (float) ( $_POST['counted_cash'] ?? 0 ), 2 );
    $note    = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
    $exp     = qrrs_closing_expected( $rid, $date );
    $diff    = round( $counted - $exp['expected_cash'], 2 );
    $wpdb->insert( $wpdb->prefix . 'qrrs_day_closings', [
        'restaurant_id'  => $rid,
        'closing_date'   => $date,
        'expected_cash'  => $exp['expected_cash'],
        'counted_cash'   => $counted,
        'difference'     => $diff,
        'net_total'      => $exp['net'],
        'note'           => substr( $note, 0, 255 ),
        'closed_by'      => get_current_user_id(),
        'closed_at'      => current_time( 'mysql' ),
    ] );
    qrrs_audit( 'day_closed', 'day', 0, [ 'date' => $date, 'net' => $exp['net'], 'expected_cash' => $exp['expected_cash'], 'counted_cash' => $counted, 'difference' => $diff ], $rid );
    wp_safe_redirect( add_query_arg( 'cl_status', 'closed', $back ) ); exit;
} );

function qrrs_closing_render( $rid ) {
    if ( ! qrrs_closing_user_can() ) {
        echo '<div class="qrrs-card" style="padding:20px;">Daily closing is for managers and admins.</div>';
        return;
    }
    $rid  = intval( $rid );
    $date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['closing_date'] ?? '' ) ? $_GET['closing_date'] : current_time( 'Y-m-d' );
    $st   = sanitize_key( $_GET['cl_status'] ?? '' );
    $msgs = [ 'closed' => [ 'ok', 'Day closed and saved.' ], 'exists' => [ 'err', 'This day is already closed.' ], 'reopened' => [ 'ok', 'Day reopened. You can close it again.' ] ];
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:8px; margin-bottom:14px;">' . esc_html( $txt ) . '</div>';
    }
    $exp   = qrrs_closing_expected( $rid, $date );
    $saved = qrrs_closing_saved( $rid, $date );
    $names = qrrs_payment_methods();
    $base  = add_query_arg( [ 'tab' => 'closing' ], home_url( '/restaurant-dashboard/' ) );
    $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; max-width:780px;">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:20px; color:#1e293b;">Daily closing</h3>
            <form method="get" style="margin:0; display:flex; gap:8px; align-items:center;">
                <input type="hidden" name="tab" value="closing">
                <input type="date" name="closing_date" value="<?php echo esc_attr( $date ); ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                <button type="submit" style="padding:8px 14px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Show</button>
            </form>
        </div>

        <table style="width:100%; border-collapse:collapse; font-size:14px; margin-bottom:14px;">
            <thead><tr style="text-align:left; color:#94a3b8; font-size:11px; text-transform:uppercase; background:#f8fafc;">
                <th style="padding:10px;">Method</th><th style="padding:10px;">Payments</th><th style="padding:10px; text-align:right;">Amount</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $exp['methods'] as $m ) : ?>
                <tr style="border-top:1px solid #f1f5f9;"><td style="padding:10px;"><?php echo esc_html( $names[ $m->method ] ?? ( 'split' === $m->method ? 'Split' : $m->method ) ); ?></td><td style="padding:10px;"><?php echo intval( $m->n ); ?></td><td style="padding:10px; text-align:right;"><?php echo $money( $m->total ); ?></td></tr>
            <?php endforeach; ?>
            <?php if ( empty( $exp['methods'] ) ) : ?><tr><td colspan="3" style="padding:16px; text-align:center; color:#94a3b8;">No payments on this day.</td></tr><?php endif; ?>
            <tr style="border-top:1px solid #e2e8f0;"><td style="padding:10px;">Refunds (<?php echo intval( $exp['refund_count'] ); ?>)</td><td></td><td style="padding:10px; text-align:right; color:#dc2626;">− <?php echo $money( $exp['refunds'] ); ?></td></tr>
            <tr style="border-top:2px solid #1e293b;"><td style="padding:10px; font-weight:700;">Net collected</td><td></td><td style="padding:10px; text-align:right; font-weight:700;"><?php echo $money( $exp['net'] ); ?></td></tr>
            </tbody>
        </table>
        <p style="font-size:13px; color:#64748b; margin:0 0 16px;">Voided bills on this day: <?php echo intval( $exp['voids'] ); ?>. Expected cash in hand: <strong><?php echo $money( $exp['expected_cash'] ); ?></strong> (cash received minus refunds).</p>

        <?php if ( $saved ) : ?>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px;">
                <strong style="color:#166534;">Closed</strong>
                <div style="font-size:14px; margin-top:8px; color:#334155;">
                    Counted cash: <strong><?php echo $money( $saved->counted_cash ); ?></strong> ·
                    Difference: <strong style="color:<?php echo (float) $saved->difference < 0 ? '#dc2626' : '#166534'; ?>"><?php echo $money( $saved->difference ); ?></strong><br>
                    By <?php echo esc_html( $saved->by_name ?: 'user' ); ?> at <?php echo esc_html( $saved->closed_at ); ?>
                    <?php if ( $saved->note ) : ?><br>Note: <?php echo esc_html( $saved->note ); ?><?php endif; ?>
                </div>
            </div>
            <?php if ( current_user_can( 'administrator' ) ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Reopen this day? The saved closing will be removed and the day can be closed again.');" style="margin-top:12px;">
                <input type="hidden" name="action" value="qrrs_day_reopen">
                <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                <input type="hidden" name="closing_date" value="<?php echo esc_attr( $date ); ?>">
                <?php wp_nonce_field( 'qrrs_day_reopen_' . $date ); ?>
                <button type="submit" style="background:none; border:1px solid #dc2626; color:#dc2626; padding:8px 14px; border-radius:8px; font-weight:600; cursor:pointer;">Reopen this day</button>
            </form>
            <?php endif; ?>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Close this day? A closed day cannot be closed again.');" style="background:#f8fafc; border-radius:10px; padding:16px;">
                <input type="hidden" name="action" value="qrrs_day_close">
                <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                <input type="hidden" name="closing_date" value="<?php echo esc_attr( $date ); ?>">
                <?php wp_nonce_field( 'qrrs_day_close_' . $date ); ?>
                <label style="display:block; font-weight:700; font-size:13px; margin-bottom:6px;">Cash counted in hand (৳)</label>
                <input type="number" name="counted_cash" step="0.01" min="0" required style="width:220px; padding:10px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:12px;">
                <label style="display:block; font-weight:700; font-size:13px; margin-bottom:6px;">Note (optional)</label>
                <input type="text" name="note" maxlength="200" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; margin-bottom:12px;">
                <button type="submit" style="background:#16a34a; color:#fff; border:none; padding:12px 20px; border-radius:10px; font-weight:700; cursor:pointer;">Close the day</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

add_action( 'admin_post_qrrs_day_reopen', function () {
    if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) wp_die( 'Only the administrator can reopen a day.' );
    global $wpdb;
    $rid  = intval( $_POST['restaurant_id'] ?? 0 );
    $date = sanitize_text_field( $_POST['closing_date'] ?? '' );
    check_admin_referer( 'qrrs_day_reopen_' . $date );
    $old = qrrs_closing_saved( $rid, $date );
    if ( $old ) {
        $wpdb->delete( $wpdb->prefix . 'qrrs_day_closings', [ 'id' => intval( $old->id ) ] );
        qrrs_audit( 'day_reopened', 'day', 0, [ 'date' => $date, 'was_counted' => $old->counted_cash, 'was_net' => $old->net_total ], $rid );
    }
    wp_safe_redirect( add_query_arg( [ 'tab' => 'closing', 'closing_date' => $date, 'cl_status' => 'reopened' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );