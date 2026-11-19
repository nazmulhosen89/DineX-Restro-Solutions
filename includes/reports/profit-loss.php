<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Profit & Loss section of the Reports page (manager and admin).
 *  - Expenses are entered here (rent, electricity, salary ...).
 *  - Net sales = paid bills − VAT & service − refunds.
 *  - Food cost = ingredients used for sold items (from stock movements).
 *  - Net profit = gross profit − expenses.
 */

function qrrs_pl_expense_categories() {
    return [ 'Rent', 'Electricity', 'Gas', 'Water', 'Salary', 'Maintenance', 'Transport', 'Supplies', 'Marketing', 'Other' ];
}

function qrrs_pl_data( $rid, $from, $to ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $rid = intval( $rid );
    $sales = $wpdb->get_row( $wpdb->prepare(
        "SELECT COALESCE(SUM(COALESCE(final_total,grand_total)),0) AS gross,
                COALESCE(SUM(tax_amount + service_charge),0) AS taxes,
                COUNT(*) AS bills
         FROM {$p}qrrs_orders WHERE restaurant_id = %d AND payment_status = 'paid' AND DATE(created_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );
    $refund = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(refund_amount),0) FROM {$p}qrrs_orders
         WHERE restaurant_id = %d AND payment_status = 'refunded' AND DATE(refunded_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );
    $food = $wpdb->get_row( $wpdb->prepare(
        "SELECT COALESCE(SUM(CASE WHEN movement_type = 'sale' THEN quantity_out * unit_cost
                                  WHEN movement_type = 'sale_reversal' THEN -quantity_in * unit_cost ELSE 0 END),0) AS cost
         FROM {$p}qrrs_stock_movements
         WHERE restaurant_id = %d AND movement_type IN ('sale','sale_reversal') AND DATE(created_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );
    $exp_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT category, COALESCE(SUM(amount),0) AS total FROM {$p}qrrs_expenses
         WHERE restaurant_id = %d AND expense_date BETWEEN %s AND %s GROUP BY category ORDER BY total DESC",
        $rid, $from, $to
    ) );
    $exp_total = 0.0; foreach ( $exp_rows as $e ) { $exp_total += (float) $e->total; }

    $gross     = round( (float) ( $sales->gross ?? 0 ), 2 );
    $net_sales = round( $gross - (float) ( $sales->taxes ?? 0 ) - $refund, 2 );
    $food_cost = round( (float) ( $food->cost ?? 0 ), 2 );
    $gp        = round( $net_sales - $food_cost, 2 );
    $np        = round( $gp - $exp_total, 2 );
    return [
        'bills' => (int) ( $sales->bills ?? 0 ), 'gross' => $gross, 'taxes' => round( (float) ( $sales->taxes ?? 0 ), 2 ),
        'refunds' => round( $refund, 2 ), 'net_sales' => $net_sales, 'food_cost' => $food_cost,
        'gross_profit' => $gp, 'expenses' => round( $exp_total, 2 ), 'exp_rows' => $exp_rows, 'net_profit' => $np,
    ];
}

add_action( 'admin_post_qrrs_add_expense', function () {
    if ( ! is_user_logged_in() || ! qrrs_pl_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    check_admin_referer( 'qrrs_add_expense' );
    $rid  = intval( qrrs_inventory_get_active_restaurant_id() );
    $date = sanitize_text_field( $_POST['expense_date'] ?? '' );
    $cat  = sanitize_text_field( $_POST['category'] ?? '' );
    $amt  = round( (float) ( $_POST['amount'] ?? 0 ), 2 );
    $note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
    $back = qrrs_pl_back_url();
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! in_array( $cat, qrrs_pl_expense_categories(), true ) || $amt <= 0 ) {
        wp_safe_redirect( add_query_arg( 'pl_status', 'bad', $back ) ); exit;
    }
    $wpdb->insert( $wpdb->prefix . 'qrrs_expenses', [
        'restaurant_id' => $rid, 'expense_date' => $date, 'category' => $cat, 'note' => substr( $note, 0, 255 ),
        'amount' => $amt, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
    ] );
    qrrs_audit( 'expense_added', 'expense', (int) $wpdb->insert_id, [ 'date' => $date, 'category' => $cat, 'amount' => $amt, 'note' => $note ], $rid );
    wp_safe_redirect( add_query_arg( 'pl_status', 'added', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_delete_expense', function () {
    if ( ! is_user_logged_in() || ! qrrs_pl_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    $id = intval( $_POST['expense_id'] ?? 0 );
    check_admin_referer( 'qrrs_delete_expense_' . $id );
    $rid = intval( qrrs_inventory_get_active_restaurant_id() );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_expenses WHERE id = %d AND restaurant_id = %d", $id, $rid ) );
    if ( $row ) {
        $wpdb->delete( $wpdb->prefix . 'qrrs_expenses', [ 'id' => $id ] );
        qrrs_audit( 'expense_deleted', 'expense', $id, [ 'date' => $row->expense_date, 'category' => $row->category, 'amount' => $row->amount ], $rid );
    }
    wp_safe_redirect( add_query_arg( 'pl_status', 'deleted', qrrs_pl_back_url() ) ); exit;
} );

function qrrs_pl_user_can() {
    return function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
}

function qrrs_pl_back_url() {
    $from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['pl_from'] ?? '' ) ? $_POST['pl_from'] : current_time( 'Y-m-01' );
    $to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['pl_to'] ?? '' )   ? $_POST['pl_to']   : current_time( 'Y-m-d' );
    return add_query_arg( [ 'tab' => 'accounts', 'acc' => 'expenses' ], home_url( '/restaurant-dashboard/' ) );
}

function qrrs_pl_render( $rid ) {
    if ( ! qrrs_pl_user_can() ) {
        echo '<div class="qrrs-card" style="padding:20px;">Profit &amp; Loss is for managers and admins.</div>';
        return;
    }
    $rid   = intval( $rid );
    $today = current_time( 'Y-m-d' );
    $from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['pl_from'] ?? '' ) ? $_GET['pl_from'] : current_time( 'Y-m-01' );
    $to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['pl_to'] ?? '' )   ? $_GET['pl_to']   : $today;
    if ( $to < $from ) $to = $from;
    $d = qrrs_pl_data( $rid, $from, $to );
    $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
    $pct   = $d['net_sales'] > 0 ? round( $d['gross_profit'] / $d['net_sales'] * 100, 1 ) : 0;
    $inp   = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;';
    ?>
    <div>
        <form method="get" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:16px;">
            <input type="hidden" name="tab" value="reports">
            <input type="hidden" name="rs" value="profit">
            <label style="font-size:13px; color:#64748b;">From</label><input type="date" name="pl_from" value="<?php echo esc_attr( $from ); ?>" style="<?php echo $inp; ?>">
            <label style="font-size:13px; color:#64748b;">To</label><input type="date" name="pl_to" value="<?php echo esc_attr( $to ); ?>" style="<?php echo $inp; ?>">
            <button type="submit" style="padding:9px 14px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Show</button>
        </form>
        <table style="width:100%; max-width:640px; border-collapse:collapse; font-size:14px; margin-bottom:14px;">
            <tbody>
                <tr style="border-top:1px solid #f1f5f9;"><td style="padding:9px;">Bills paid (<?php echo intval( $d['bills'] ); ?>)</td><td style="padding:9px; text-align:right;"><?php echo $money( $d['gross'] ); ?></td></tr>
                <tr><td style="padding:9px; color:#64748b;">Less VAT &amp; service charge</td><td style="padding:9px; text-align:right; color:#64748b;">− <?php echo $money( $d['taxes'] ); ?></td></tr>
                <tr><td style="padding:9px; color:#64748b;">Less refunds</td><td style="padding:9px; text-align:right; color:#dc2626;">− <?php echo $money( $d['refunds'] ); ?></td></tr>
                <tr style="border-top:1px solid #e2e8f0;"><td style="padding:9px; font-weight:700;">Net sales</td><td style="padding:9px; text-align:right; font-weight:700;"><?php echo $money( $d['net_sales'] ); ?></td></tr>
                <tr><td style="padding:9px; color:#64748b;">Less food cost (ingredients used)</td><td style="padding:9px; text-align:right; color:#64748b;">− <?php echo $money( $d['food_cost'] ); ?></td></tr>
                <tr style="border-top:1px solid #e2e8f0;"><td style="padding:9px; font-weight:700;">Gross profit <span style="font-weight:400; color:#64748b;">(<?php echo $pct; ?>%)</span></td><td style="padding:9px; text-align:right; font-weight:700;"><?php echo $money( $d['gross_profit'] ); ?></td></tr>
                <tr><td style="padding:9px; color:#64748b;">Less expenses</td><td style="padding:9px; text-align:right; color:#64748b;">− <?php echo $money( $d['expenses'] ); ?></td></tr>
                <tr style="border-top:2px solid #1e293b;"><td style="padding:10px; font-weight:800; font-size:16px;">Net profit</td><td style="padding:10px; text-align:right; font-weight:800; font-size:16px; color:<?php echo $d['net_profit'] < 0 ? '#dc2626' : '#166534'; ?>;"><?php echo $money( $d['net_profit'] ); ?></td></tr>
            </tbody>
        </table>
        <p style="font-size:12px; color:#94a3b8; margin:0;">Expenses are added in the <strong>Accounts &amp; HR</strong> menu.</p>
    </div>
    <?php
}