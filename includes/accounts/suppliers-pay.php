<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Supplier payments (Accounts & HR > Suppliers).
 *  - Due = purchases from the supplier − payments made to them.
 *  - A payment cannot be more than the due amount.
 *  - Payments are money out. They are not added to Profit & Loss again,
 *    because the cost of goods is already counted from stock usage.
 */

function qrrs_sup_user_can() {
    return function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
}

function qrrs_sup_balances( $rid ) {
    global $wpdb;
    $p = $wpdb->prefix;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT s.id, s.supplier_name,
                COALESCE(pu.total,0) AS purchased,
                COALESCE(pa.total,0) AS paid
         FROM {$p}qrrs_suppliers s
         LEFT JOIN (SELECT supplier_id, SUM(total_amount) AS total FROM {$p}qrrs_purchases WHERE restaurant_id = %d GROUP BY supplier_id) pu ON pu.supplier_id = s.id
         LEFT JOIN (SELECT supplier_id, SUM(amount) AS total FROM {$p}qrrs_supplier_payments WHERE restaurant_id = %d GROUP BY supplier_id) pa ON pa.supplier_id = s.id
         WHERE s.restaurant_id = %d AND s.status = 'active'
         ORDER BY s.supplier_name ASC",
        intval( $rid ), intval( $rid ), intval( $rid )
    ) );
}

function qrrs_sup_due( $rid, $supplier_id ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $bought = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM {$p}qrrs_purchases WHERE restaurant_id = %d AND supplier_id = %d", intval( $rid ), intval( $supplier_id ) ) );
    $paid   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$p}qrrs_supplier_payments WHERE restaurant_id = %d AND supplier_id = %d", intval( $rid ), intval( $supplier_id ) ) );
    return round( $bought - $paid, 2 );
}

add_action( 'admin_post_qrrs_pay_supplier', function () {
    if ( ! is_user_logged_in() || ! qrrs_sup_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    check_admin_referer( 'qrrs_pay_supplier' );
    $rid = intval( qrrs_inventory_get_active_restaurant_id() );
    $sid = intval( $_POST['supplier_id'] ?? 0 );
    $amt = round( (float) ( $_POST['amount'] ?? 0 ), 2 );
    $method = sanitize_key( $_POST['method'] ?? 'cash' );
    $ref  = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );
    $date = sanitize_text_field( $_POST['paid_on'] ?? '' );
    $note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
    $back = add_query_arg( [ 'tab' => 'accounts', 'acc' => 'suppliers' ], home_url( '/restaurant-dashboard/' ) );
    $fail = function ( $code ) use ( $back ) { wp_safe_redirect( add_query_arg( 'sp_status', $code, $back ) ); exit; };

    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}qrrs_suppliers WHERE id = %d AND restaurant_id = %d", $sid, $rid ) );
    if ( ! $exists ) $fail( 'no_supplier' );
    if ( $amt <= 0 ) $fail( 'bad_amount' );
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) $fail( 'bad_date' );
    if ( ! array_key_exists( $method, qrrs_payment_methods() ) ) $fail( 'bad_method' );
    if ( 'cash' !== $method && '' === trim( $ref ) ) $fail( 'ref_needed' );
    $due = qrrs_sup_due( $rid, $sid );
    if ( $amt > $due + 0.005 ) $fail( 'over_due' );

    $wpdb->insert( $wpdb->prefix . 'qrrs_supplier_payments', [
        'restaurant_id' => $rid, 'supplier_id' => $sid, 'amount' => $amt, 'method' => $method,
        'reference' => substr( $ref, 0, 100 ), 'note' => substr( $note, 0, 255 ), 'paid_on' => $date,
        'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
    ] );
    $pid = (int) $wpdb->insert_id;
    qrrs_audit( 'supplier_paid', 'supplier', $sid, [ 'amount' => $amt, 'method' => $method, 'ref' => $ref, 'payment_id' => $pid ], $rid );
    wp_safe_redirect( add_query_arg( 'sp_status', 'paid', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_delete_supplier_payment', function () {
    if ( ! is_user_logged_in() || ! qrrs_sup_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    $pid = intval( $_POST['payment_id'] ?? 0 );
    check_admin_referer( 'qrrs_delete_supplier_payment_' . $pid );
    $rid = intval( qrrs_inventory_get_active_restaurant_id() );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_supplier_payments WHERE id = %d AND restaurant_id = %d", $pid, $rid ) );
    if ( $row ) {
        $wpdb->delete( $wpdb->prefix . 'qrrs_supplier_payments', [ 'id' => $pid ] );
        qrrs_audit( 'supplier_payment_deleted', 'supplier', intval( $row->supplier_id ), [ 'amount' => $row->amount, 'payment_id' => $pid ], $rid );
    }
    wp_safe_redirect( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'suppliers', 'sp_status' => 'deleted' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );

function qrrs_suppliers_render( $rid ) {
    if ( ! qrrs_sup_user_can() ) {
        echo '<div class="qrrs-card" style="padding:20px;">Supplier payments are for managers and admins.</div>';
        return;
    }
    global $wpdb;
    $rid = intval( $rid );
    $st  = sanitize_key( $_GET['sp_status'] ?? '' );
    $msgs = [
        'paid' => [ 'ok', 'Payment recorded.' ], 'deleted' => [ 'ok', 'Payment removed.' ],
        'no_supplier' => [ 'err', 'Choose a supplier.' ], 'bad_amount' => [ 'err', 'Enter an amount greater than 0.' ],
        'bad_date' => [ 'err', 'Choose a valid date.' ], 'bad_method' => [ 'err', 'Choose a payment method.' ],
        'ref_needed' => [ 'err', 'Enter the transaction reference for this payment.' ],
        'over_due' => [ 'err', 'The amount is more than the due to this supplier.' ],
    ];
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:8px; margin-bottom:14px;">' . esc_html( $txt ) . '</div>';
    }
    $rows  = qrrs_sup_balances( $rid );
    $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
    $inp   = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;';
    $total_due = 0.0; foreach ( $rows as $r ) { $total_due += (float) $r->purchased - (float) $r->paid; }
    $sel = intval( $_GET['sup'] ?? 0 );
    $hist = $sel ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_supplier_payments WHERE restaurant_id = %d AND supplier_id = %d ORDER BY paid_on DESC, id DESC", $rid, $sel ) ) : [];
    $methods = qrrs_payment_methods();
    ?>
    <div>
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <div style="background:#fff7ed; border-radius:10px; padding:14px 18px;"><small style="color:#9a3412;">Total due to suppliers</small><div style="font-size:20px; font-weight:800; color:#c2410c;"><?php echo $money( $total_due ); ?></div></div>
        </div>

        <table style="width:100%; border-collapse:collapse; font-size:14px; margin-bottom:20px;">
            <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                <th style="padding:10px;">Supplier</th><th style="padding:10px; text-align:right;">Purchased</th><th style="padding:10px; text-align:right;">Paid</th><th style="padding:10px; text-align:right;">Due</th><th style="padding:10px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) : $due = round( (float) $r->purchased - (float) $r->paid, 2 ); ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:10px; font-weight:600;"><?php echo esc_html( $r->supplier_name ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $money( $r->purchased ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $money( $r->paid ); ?></td>
                    <td style="padding:10px; text-align:right; font-weight:700; color:<?php echo $due > 0 ? '#dc2626' : '#166534'; ?>;"><?php echo $money( $due ); ?></td>
                    <td style="padding:10px; text-align:right;"><a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'suppliers', 'sup' => $r->id ], home_url( '/restaurant-dashboard/' ) ) ); ?>">History</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rows ) ) : ?><tr><td colspan="5" style="padding:16px; text-align:center; color:#94a3b8;">No suppliers yet. Add them in Inventory &gt; Suppliers.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:18px;">
            <strong style="font-size:15px; color:#1e293b;">Pay a supplier</strong>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px;">
                <input type="hidden" name="action" value="qrrs_pay_supplier">
                <?php wp_nonce_field( 'qrrs_pay_supplier' ); ?>
                <select name="supplier_id" required style="<?php echo $inp; ?> min-width:200px;">
                    <option value="">Choose supplier</option>
                    <?php foreach ( $rows as $r ) : ?><option value="<?php echo intval( $r->id ); ?>" <?php selected( $sel, $r->id ); ?>><?php echo esc_html( $r->supplier_name ); ?></option><?php endforeach; ?>
                </select>
                <input type="date" name="paid_on" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required style="<?php echo $inp; ?>">
                <select name="method" style="<?php echo $inp; ?>">
                    <?php foreach ( $methods as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option><?php endforeach; ?>
                </select>
                <input type="number" name="amount" step="0.01" min="0.01" required placeholder="Amount" style="<?php echo $inp; ?> width:130px;">
                <input type="text" name="reference" maxlength="100" placeholder="Trx ID / reference" style="<?php echo $inp; ?> width:170px;">
                <input type="text" name="note" maxlength="255" placeholder="Note (optional)" style="<?php echo $inp; ?> flex:1; min-width:140px;">
                <button type="submit" style="padding:9px 16px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Record payment</button>
            </form>
        </div>

        <?php if ( $sel ) : ?>
            <h4 style="margin:0 0 8px; font-size:15px; color:#1e293b;">Payments to this supplier</h4>
            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                    <th style="padding:10px;">Date</th><th style="padding:10px;">Method</th><th style="padding:10px;">Reference</th><th style="padding:10px;">Note</th><th style="padding:10px; text-align:right;">Amount</th><th style="padding:10px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $hist as $h ) : ?>
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:9px;"><?php echo esc_html( $h->paid_on ); ?></td>
                        <td style="padding:9px;"><?php echo esc_html( $methods[ $h->method ] ?? $h->method ); ?></td>
                        <td style="padding:9px; color:#64748b;"><?php echo esc_html( $h->reference ?: '—' ); ?></td>
                        <td style="padding:9px; color:#64748b;"><?php echo esc_html( $h->note ?: '—' ); ?></td>
                        <td style="padding:9px; text-align:right;"><?php echo $money( $h->amount ); ?></td>
                        <td style="padding:9px; text-align:right;">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;" onsubmit="return confirm('Remove this payment?');">
                                <input type="hidden" name="action" value="qrrs_delete_supplier_payment">
                                <input type="hidden" name="payment_id" value="<?php echo intval( $h->id ); ?>">
                                <?php wp_nonce_field( 'qrrs_delete_supplier_payment_' . intval( $h->id ) ); ?>
                                <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:13px;">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $hist ) ) : ?><tr><td colspan="6" style="padding:14px; text-align:center; color:#94a3b8;">No payments yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}