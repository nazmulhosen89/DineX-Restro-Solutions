<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customer due (money a customer owes the restaurant).
 *  - A due is recorded with the customer's name and mobile, the amount, and a note.
 *  - Customers are grouped by mobile number (or by name when there is no mobile).
 *  - Repayments are cash in on the day they are paid, so they appear in the daily closing.
 */

function qrrs_due_user_can() {
    return function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
}

function qrrs_due_key( $name, $phone ) {
    $phone = preg_replace( '/[\s\-]/', '', (string) $phone );
    return $phone !== '' ? 'p:' . $phone : 'n:' . mb_strtolower( trim( (string) $name ) );
}

function qrrs_due_balances( $rid ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT d.customer_key, MAX(d.customer_name) AS customer_name, MAX(d.phone) AS phone,
                COALESCE(SUM(d.amount),0) AS owed,
                (SELECT COALESCE(SUM(p.amount),0) FROM {$p}qrrs_due_payments p
                   WHERE p.restaurant_id = %d AND p.customer_key = d.customer_key) AS repaid
         FROM {$p}qrrs_dues d WHERE d.restaurant_id = %d
         GROUP BY d.customer_key ORDER BY (owed - repaid) DESC, customer_name ASC",
        intval( $rid ), intval( $rid )
    ) );
    return $rows;
}

add_action( 'admin_post_qrrs_add_due', function () {
    if ( ! is_user_logged_in() || ! qrrs_due_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    check_admin_referer( 'qrrs_add_due' );
    $rid   = intval( qrrs_inventory_get_active_restaurant_id() );
    $name  = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
    $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
    $amt   = round( (float) ( $_POST['amount'] ?? 0 ), 2 );
    $note  = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
    $date  = sanitize_text_field( $_POST['due_on'] ?? '' );
    $back  = add_query_arg( [ 'tab' => 'accounts', 'acc' => 'customers' ], home_url( '/restaurant-dashboard/' ) );
    if ( mb_strlen( trim( $name ) ) < 2 || $amt <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        wp_safe_redirect( add_query_arg( 'cd_status', 'bad', $back ) ); exit;
    }
    $key = qrrs_due_key( $name, $phone );
    $wpdb->insert( $wpdb->prefix . 'qrrs_dues', [
        'restaurant_id' => $rid, 'customer_key' => $key, 'customer_name' => substr( $name, 0, 150 ),
        'phone' => substr( preg_replace( '/[\s\-]/', '', $phone ), 0, 20 ), 'amount' => $amt, 'note' => substr( $note, 0, 255 ),
        'due_on' => $date, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
    ] );
    qrrs_audit( 'due_added', 'customer', 0, [ 'customer' => $name, 'phone' => $phone, 'amount' => $amt ], $rid );
    wp_safe_redirect( add_query_arg( 'cd_status', 'added', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_repay_due', function () {
    if ( ! is_user_logged_in() || ! qrrs_due_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    check_admin_referer( 'qrrs_repay_due' );
    $rid    = intval( qrrs_inventory_get_active_restaurant_id() );
    $key    = sanitize_text_field( wp_unslash( $_POST['customer_key'] ?? '' ) );
    $amt    = round( (float) ( $_POST['amount'] ?? 0 ), 2 );
    $method = sanitize_key( $_POST['method'] ?? 'cash' );
    $ref    = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );
    $back   = add_query_arg( [ 'tab' => 'accounts', 'acc' => 'customers' ], home_url( '/restaurant-dashboard/' ) );
    $fail   = function ( $code ) use ( $back ) { wp_safe_redirect( add_query_arg( 'cd_status', $code, $back ) ); exit; };
    $bal = null;
    foreach ( qrrs_due_balances( $rid ) as $b ) { if ( $b->customer_key === $key ) { $bal = round( (float) $b->owed - (float) $b->repaid, 2 ); } }
    if ( null === $bal ) $fail( 'no_customer' );
    if ( $amt <= 0 ) $fail( 'bad_amount' );
    if ( $amt > $bal + 0.005 ) $fail( 'over_due' );
    if ( ! array_key_exists( $method, qrrs_payment_methods() ) ) $fail( 'bad_method' );
    if ( 'cash' !== $method && '' === trim( $ref ) ) $fail( 'ref_needed' );
    $wpdb->insert( $wpdb->prefix . 'qrrs_due_payments', [
        'restaurant_id' => $rid, 'customer_key' => $key, 'amount' => $amt, 'method' => $method,
        'reference' => substr( $ref, 0, 100 ), 'paid_on' => current_time( 'Y-m-d' ),
        'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
    ] );
    qrrs_audit( 'due_repaid', 'customer', 0, [ 'customer_key' => $key, 'amount' => $amt, 'method' => $method, 'ref' => $ref ], $rid );
    wp_safe_redirect( add_query_arg( 'cd_status', 'repaid', $back ) ); exit;
} );

function qrrs_customers_due_render( $rid ) {
    if ( ! qrrs_due_user_can() ) {
        echo '<div class="qrrs-card" style="padding:20px;">Customer due is for managers and admins.</div>';
        return;
    }
    global $wpdb;
    $rid = intval( $rid );
    $st  = sanitize_key( $_GET['cd_status'] ?? '' );
    $msgs = [
        'added' => [ 'ok', 'Due recorded.' ], 'repaid' => [ 'ok', 'Payment recorded.' ],
        'bad' => [ 'err', 'Enter the customer name, amount and date.' ], 'no_customer' => [ 'err', 'Choose a customer.' ],
        'bad_amount' => [ 'err', 'Enter an amount greater than 0.' ], 'over_due' => [ 'err', 'The amount is more than the balance.' ],
        'bad_method' => [ 'err', 'Choose a payment method.' ], 'ref_needed' => [ 'err', 'Enter the transaction reference.' ],
    ];
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:8px; margin-bottom:14px;">' . esc_html( $txt ) . '</div>';
    }
    $rows  = qrrs_due_balances( $rid );
    $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
    $inp   = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;';
    $total = 0.0; foreach ( $rows as $r ) { $total += (float) $r->owed - (float) $r->repaid; }
    $methods = qrrs_payment_methods();
    ?>
    <div>
        <div style="background:#fff7ed; border-radius:10px; padding:14px 18px; display:inline-block; margin-bottom:16px;">
            <small style="color:#9a3412;">Total customer due (বাকি)</small>
            <div style="font-size:20px; font-weight:800; color:#c2410c;"><?php echo $money( $total ); ?></div>
        </div>

        <table style="width:100%; border-collapse:collapse; font-size:14px; margin-bottom:20px;">
            <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                <th style="padding:10px;">Customer</th><th style="padding:10px;">Mobile</th><th style="padding:10px; text-align:right;">Owed</th><th style="padding:10px; text-align:right;">Repaid</th><th style="padding:10px; text-align:right;">Balance</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) : $bal = round( (float) $r->owed - (float) $r->repaid, 2 ); ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:10px; font-weight:600;"><?php echo esc_html( $r->customer_name ); ?></td>
                    <td style="padding:10px; color:#64748b;"><?php echo esc_html( $r->phone ?: '—' ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $money( $r->owed ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $money( $r->repaid ); ?></td>
                    <td style="padding:10px; text-align:right; font-weight:700; color:<?php echo $bal > 0 ? '#dc2626' : '#166534'; ?>;"><?php echo $money( $bal ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rows ) ) : ?><tr><td colspan="5" style="padding:16px; text-align:center; color:#94a3b8;">No customer due yet.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                <strong style="font-size:15px; color:#1e293b;">Record a due (বাকি)</strong>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid; gap:8px; margin-top:10px;">
                    <input type="hidden" name="action" value="qrrs_add_due">
                    <?php wp_nonce_field( 'qrrs_add_due' ); ?>
                    <input type="text" name="customer_name" required minlength="2" maxlength="150" placeholder="Customer name" style="<?php echo $inp; ?>">
                    <input type="text" name="phone" maxlength="20" placeholder="Mobile (01XXXXXXXXX)" style="<?php echo $inp; ?>">
                    <input type="number" name="amount" step="0.01" min="0.01" required placeholder="Amount" style="<?php echo $inp; ?>">
                    <input type="date" name="due_on" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required style="<?php echo $inp; ?>">
                    <input type="text" name="note" maxlength="255" placeholder="Note (what was taken)" style="<?php echo $inp; ?>">
                    <button type="submit" style="padding:10px; background:#ea580c; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Save due</button>
                </form>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                <strong style="font-size:15px; color:#1e293b;">Customer paid back</strong>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid; gap:8px; margin-top:10px;">
                    <input type="hidden" name="action" value="qrrs_repay_due">
                    <?php wp_nonce_field( 'qrrs_repay_due' ); ?>
                    <select name="customer_key" required style="<?php echo $inp; ?>">
                        <option value="">Choose customer</option>
                        <?php foreach ( $rows as $r ) : $bal = round( (float) $r->owed - (float) $r->repaid, 2 ); if ( $bal <= 0 ) continue; ?>
                            <option value="<?php echo esc_attr( $r->customer_key ); ?>"><?php echo esc_html( $r->customer_name . ' · ' . $bal ); ?> ৳</option>
                        <?php endforeach; ?>
                    </select>
                    <select name="method" style="<?php echo $inp; ?>">
                        <?php foreach ( $methods as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option><?php endforeach; ?>
                    </select>
                    <input type="number" name="amount" step="0.01" min="0.01" required placeholder="Amount received" style="<?php echo $inp; ?>">
                    <input type="text" name="reference" maxlength="100" placeholder="Trx ID (non-cash)" style="<?php echo $inp; ?>">
                    <button type="submit" style="padding:10px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Record payment</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}