<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HR: salary for each staff member, for the pay cycle that contains the chosen date.
 *  - Daily: the day. Weekly: 7 days ending on the date. Half month: 1-15 or 16-end.
 *  - The amount is paid for each cycle. Unpaid leave days in the cycle are deducted at the per-day rate.
 *  - Paid cycles are recorded, so it is clear who has been paid.
 */

function qrrs_salary_period( $type, $date ) {
    $t = strtotime( $date );
    switch ( $type ) {
        case 'daily':
            return [ gmdate( 'Y-m-d', $t ), gmdate( 'Y-m-d', $t ) ];
        case 'weekly':
            return [ gmdate( 'Y-m-d', $t - 6 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $t ) ];
        case 'half_month':
            $d = (int) gmdate( 'j', $t );
            $y = gmdate( 'Y-m', $t );
            return $d <= 15
                ? [ "$y-01", "$y-15" ]
                : [ "$y-16", gmdate( 'Y-m-t', $t ) ];
        default:
            return [ gmdate( 'Y-m-01', $t ), gmdate( 'Y-m-t', $t ) ];
    }
}

function qrrs_salary_days_between( $a, $b ) {
    return (int) ( ( strtotime( $b ) - strtotime( $a ) ) / DAY_IN_SECONDS ) + 1;
}

function qrrs_salary_unpaid_days( $restaurant_id, $staff_id, $pstart, $pend ) {
    global $wpdb;
    $unpaid_ids = [];
    foreach ( qrrs_leave_types_get( $restaurant_id ) as $t ) { if ( empty( $t['paid'] ) ) $unpaid_ids[] = $t['id']; }
    if ( empty( $unpaid_ids ) ) return 0;
    $in = implode( ',', array_map( fn( $id ) => $wpdb->prepare( '%s', $id ), $unpaid_ids ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT start_date, end_date FROM {$wpdb->prefix}qrrs_leaves
         WHERE restaurant_id = %d AND staff_id = %d AND leave_type_id IN ($in) AND end_date >= %s AND start_date <= %s",
        intval( $restaurant_id ), intval( $staff_id ), $pstart, $pend ) );
    $days = 0;
    foreach ( $rows as $r ) {
        $from = max( strtotime( $r->start_date ), strtotime( $pstart ) );
        $to   = min( strtotime( $r->end_date ), strtotime( $pend ) );
        if ( $to >= $from ) $days += (int) ( ( $to - $from ) / DAY_IN_SECONDS ) + 1;
    }
    return $days;
}

add_action( 'admin_post_qrrs_salary_pay', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) wp_die( 'Not allowed.' );
    check_admin_referer( 'qrrs_salary_pay' );
    global $wpdb;
    $rid    = intval( $_POST['restaurant_id'] ?? 0 );
    $sid    = intval( $_POST['staff_id'] ?? 0 );
    $pstart = sanitize_text_field( $_POST['period_start'] ?? '' );
    $pend   = sanitize_text_field( $_POST['period_end'] ?? '' );
    $gross  = (float) ( $_POST['gross'] ?? 0 );
    $deduct = (float) ( $_POST['deduction'] ?? 0 );
    $net    = max( 0, round( $gross - $deduct, 2 ) );
    $back   = add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr', 'sal' => sanitize_text_field( $_POST['sal_date'] ?? '' ) ], home_url( '/restaurant-dashboard/' ) );
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}qrrs_salary_payments WHERE restaurant_id = %d AND staff_id = %d AND period_start = %s AND period_end = %s",
        $rid, $sid, $pstart, $pend ) );
    if ( ! $exists && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pstart ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pend ) ) {
        $wpdb->insert( $wpdb->prefix . 'qrrs_salary_payments', [
            'restaurant_id' => $rid, 'staff_id' => $sid, 'period_start' => $pstart, 'period_end' => $pend,
            'gross' => $gross, 'deduction' => $deduct, 'net' => $net,
            'paid_by' => get_current_user_id(), 'paid_at' => current_time( 'mysql' ),
        ] );
        // the same payment goes into Expenses, under Salary
        $srow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE id = %d", $sid ) );
        $sname = $srow ? qrrs_staff_display_name( $srow ) : ( 'Staff #' . $sid );
        $wpdb->insert( $wpdb->prefix . 'qrrs_expenses', [
            'restaurant_id' => $rid,
            'expense_date'  => current_time( 'Y-m-d' ),
            'category'      => 'Salary',
            'amount'        => $net,
            'note'          => substr( 'Salary: ' . $sname . ' (' . $pstart . ' to ' . $pend . ')', 0, 255 ),
        ], [ '%d', '%s', '%s', '%f', '%s' ] );
        if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( 'salary_paid', 'staff', $sid, [ 'from' => $pstart, 'to' => $pend, 'net' => $net ], $rid );
    }
    wp_safe_redirect( $back ); exit;
} );

function qrrs_salary_render( $restaurant_id ) {
    global $wpdb;
    $rid   = intval( $restaurant_id );
    $can   = function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
    $date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['sal'] ?? '' ) ? $_GET['sal'] : current_time( 'Y-m-d' );
    $staff = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d AND status = 'active' ORDER BY id", $rid ) );
    $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
    $inp   = 'padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-top:14px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <div>
                <div style="font-size:15px; font-weight:800; color:#0f172a;">Salary</div>
                <div style="font-size:13px; color:#64748b;">Pick a date. Each staff member is shown for the pay cycle that contains it.</div>
            </div>
            <form method="get" style="display:flex; gap:8px; align-items:center; margin:0;">
                <input type="hidden" name="tab" value="accounts">
                <input type="hidden" name="acc" value="hr">
                <input type="date" name="sal" value="<?php echo esc_attr( $date ); ?>" style="<?php echo $inp; ?>">
                <button type="submit" style="padding:8px 14px; border:none; border-radius:8px; background:#0f172a; color:#fff; font-weight:700; cursor:pointer;">Show</button>
            </form>
        </div>

        <?php if ( empty( $staff ) ) : ?>
            <div style="padding:14px; text-align:center; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:10px;">No active staff yet.</div>
        <?php else : ?>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:14px;">
            <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                <th style="padding:10px;">Staff</th><th style="padding:10px;">Pay type</th><th style="padding:10px;">Cycle</th><th style="padding:10px; text-align:right;">Pay</th><th style="padding:10px; text-align:right;">Unpaid leave</th><th style="padding:10px; text-align:right;">Deduction</th><th style="padding:10px; text-align:right;">Net</th><th style="padding:10px;">Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $staff as $s ) :
                [ $ps, $pe ] = qrrs_salary_period( $s->salary_type, $date );
                $per_day  = qrrs_staff_per_day( $s->salary_type, $s->salary_amount );
                $gross    = (float) $s->salary_amount;
                $u_days   = qrrs_salary_unpaid_days( $rid, $s->id, $ps, $pe );
                $deduct   = round( $u_days * $per_day, 2 );
                $net      = max( 0, round( $gross - $deduct, 2 ) );
                $paid     = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}qrrs_salary_payments WHERE restaurant_id = %d AND staff_id = %d AND period_start = %s AND period_end = %s",
                    $rid, $s->id, $ps, $pe ) );
                $name = qrrs_staff_display_name( $s ); ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:10px; font-weight:600;"><?php echo esc_html( $name ); ?></td>
                    <td style="padding:10px;"><?php echo esc_html( qrrs_staff_pay_label( $s->salary_type ) ); ?></td>
                    <td style="padding:10px; font-size:12px; color:#475569;"><?php echo esc_html( $ps === $pe ? $ps : $ps . ' → ' . $pe ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $money( $gross ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo intval( $u_days ); ?> day(s)</td>
                    <td style="padding:10px; text-align:right; color:#b45309;"><?php echo $deduct > 0 ? '−' . $money( $deduct ) : '—'; ?></td>
                    <td style="padding:10px; text-align:right; font-weight:800;"><?php echo $money( $net ); ?></td>
                    <td style="padding:10px;">
                    <?php if ( $paid ) : ?>
                        <span style="background:#dcfce7; color:#166534; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px;">Paid <?php echo esc_html( date( 'd M', strtotime( $paid->paid_at ) ) ); ?></span>
                    <?php elseif ( $can ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Record this payment?');" style="margin:0;">
                            <input type="hidden" name="action" value="qrrs_salary_pay">
                            <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                            <input type="hidden" name="staff_id" value="<?php echo intval( $s->id ); ?>">
                            <input type="hidden" name="period_start" value="<?php echo esc_attr( $ps ); ?>">
                            <input type="hidden" name="period_end" value="<?php echo esc_attr( $pe ); ?>">
                            <input type="hidden" name="gross" value="<?php echo esc_attr( $gross ); ?>">
                            <input type="hidden" name="deduction" value="<?php echo esc_attr( $deduct ); ?>">
                            <input type="hidden" name="sal_date" value="<?php echo esc_attr( $date ); ?>">
                            <?php wp_nonce_field( 'qrrs_salary_pay' ); ?>
                            <button type="submit" style="padding:6px 12px; border:none; border-radius:8px; background:#16a34a; color:#fff; font-size:12px; font-weight:800; cursor:pointer;">Record payment</button>
                        </form>
                    <?php else : ?>
                        <span style="background:#fef3c7; color:#92400e; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px;">Due</span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:10px;">Deduction = unpaid leave days × per-day rate. Paid leave is not deducted. Per-day rate: daily = amount, weekly = amount ÷ 7, half month = amount ÷ 15, monthly = amount ÷ 30.</div>
        <?php endif; ?>
    </div>
    <?php
}