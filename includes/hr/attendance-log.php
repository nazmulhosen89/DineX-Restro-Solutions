<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Attendance records.
 *  - Attendance on: each staff member checks in and out on the Attendance page.
 *  - Attendance off (or for any day): the manager enters the times by hand.
 *  - The monthly report shows, for each staff member, the days present, the hours worked and the leave days.
 */

/** The staff row of the logged-in user in this restaurant (null if none). */
function qrrs_my_staff_row( $restaurant_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d AND restaurant_id = %d AND status = 'active' LIMIT 1",
        get_current_user_id(), intval( $restaurant_id ) ) );
}

add_action( 'admin_post_qrrs_att_self', function () {
    if ( ! is_user_logged_in() ) wp_die( 'Please log in.' );
    check_admin_referer( 'qrrs_att_self' );
    global $wpdb;
    $rid   = intval( $_POST['restaurant_id'] ?? 0 );
    $row   = qrrs_my_staff_row( $rid );
    $back  = add_query_arg( [ 'tab' => 'attendance' ], home_url( '/restaurant-dashboard/' ) );
    if ( ! $row || ! qrrs_attendance_enabled( $rid ) ) { wp_safe_redirect( add_query_arg( 'at', 'off', $back ) ); exit; }
    $now   = current_time( 'mysql' );
    $today = current_time( 'Y-m-d' );
    $open  = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_attendance WHERE staff_id = %d AND work_date = %s ORDER BY id DESC LIMIT 1", $row->id, $today ) );
    if ( ( $_POST['do'] ?? '' ) === 'in' ) {
        if ( $open && empty( $open->check_out ) ) { wp_safe_redirect( add_query_arg( 'at', 'already_in', $back ) ); exit; }
        $wpdb->insert( $wpdb->prefix . 'qrrs_attendance', [
            'restaurant_id' => $rid, 'staff_id' => $row->id, 'work_date' => $today,
            'check_in' => $now, 'source' => 'self' ] );
        wp_safe_redirect( add_query_arg( 'at', 'in', $back ) ); exit;
    }
    if ( $open && empty( $open->check_out ) ) {
        $wpdb->update( $wpdb->prefix . 'qrrs_attendance', [ 'check_out' => $now ], [ 'id' => $open->id ] );
        wp_safe_redirect( add_query_arg( 'at', 'out', $back ) ); exit;
    }
    wp_safe_redirect( add_query_arg( 'at', 'not_in', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_att_manual', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) wp_die( 'Not allowed.' );
    check_admin_referer( 'qrrs_att_manual' );
    global $wpdb;
    $rid  = intval( $_POST['restaurant_id'] ?? 0 );
    $sid  = intval( $_POST['staff_id'] ?? 0 );
    $date = sanitize_text_field( $_POST['work_date'] ?? '' );
    $in   = sanitize_text_field( $_POST['check_in'] ?? '' );
    $out  = sanitize_text_field( $_POST['check_out'] ?? '' );
    $back = add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr' ], home_url( '/restaurant-dashboard/' ) );
    $ok   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && preg_match( '/^\d{2}:\d{2}$/', $in );
    if ( ! $ok || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}qrrs_staff WHERE id = %d AND restaurant_id = %d", $sid, $rid ) ) ) {
        wp_safe_redirect( add_query_arg( 'at_status', 'bad', $back ) ); exit;
    }
    $wpdb->insert( $wpdb->prefix . 'qrrs_attendance', [
        'restaurant_id' => $rid, 'staff_id' => $sid, 'work_date' => $date,
        'check_in' => $date . ' ' . $in . ':00',
        'check_out' => preg_match( '/^\d{2}:\d{2}$/', $out ) ? $date . ' ' . $out . ':00' : null,
        'source' => 'manual', 'note' => substr( sanitize_text_field( $_POST['note'] ?? '' ), 0, 255 ) ] );
    if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( 'attendance_manual', 'staff', $sid, [ 'date' => $date ], $rid );
    wp_safe_redirect( add_query_arg( 'at_status', 'saved', $back ) ); exit;
} );

/** The self check-in and check-out card, for staff on the Attendance page. */
function qrrs_att_self_render( $restaurant_id ) {
    global $wpdb;
    $rid = intval( $restaurant_id );
    $me  = qrrs_my_staff_row( $rid );
    $st  = sanitize_key( $_GET['at'] ?? '' );
    $msg = [ 'in' => 'Checked in.', 'out' => 'Checked out. Have a good rest!', 'already_in' => 'You are already checked in.', 'not_in' => 'Check in first.', 'off' => 'Attendance is not on for this restaurant.' ];
    echo '<div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:22px; max-width:520px;">';
    echo '<div style="font-size:16px; font-weight:800; color:#0f172a;">My attendance</div>';
    if ( isset( $msg[ $st ] ) ) echo '<div style="margin:12px 0; padding:10px 14px; background:#f0fdf4; color:#166534; border-radius:10px; font-size:14px;">' . esc_html( $msg[ $st ] ) . '</div>';
    if ( ! $me ) { echo '<p style="color:#64748b;">Your account is not linked to a staff record.</p></div>'; return; }
    if ( ! qrrs_attendance_enabled( $rid ) ) { echo '<p style="color:#64748b;">Attendance is not on. The manager records it.</p></div>'; return; }
    $today = current_time( 'Y-m-d' );
    $open  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_attendance WHERE staff_id = %d AND work_date = %s ORDER BY id DESC LIMIT 1", $me->id, $today ) );
    $in_now = $open && empty( $open->check_out );
    echo '<div style="font-size:13px; color:#64748b; margin:6px 0 16px;">Today: ' . esc_html( wp_date( 'd M Y', current_time( 'timestamp' ) ) ) . '</div>';
    if ( $open ) echo '<div style="font-size:14px; margin-bottom:14px;">In: <b>' . esc_html( wp_date( 'h:i A', strtotime( $open->check_in ) ) ) . '</b>' . ( $open->check_out ? ' · Out: <b>' . esc_html( wp_date( 'h:i A', strtotime( $open->check_out ) ) ) . '</b>' : ' · <span style="color:#16a34a;font-weight:700;">working now</span>' ) . '</div>';
    echo '<div style="display:flex; gap:12px;">';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
    echo '<input type="hidden" name="action" value="qrrs_att_self"><input type="hidden" name="restaurant_id" value="' . $rid . '"><input type="hidden" name="do" value="in">';
    wp_nonce_field( 'qrrs_att_self' );
    echo '<button type="submit" ' . ( $in_now ? 'disabled' : '' ) . ' style="padding:14px 22px; border:none; border-radius:12px; background:#16a34a; color:#fff; font-weight:800; font-size:15px; cursor:pointer; opacity:' . ( $in_now ? '.4' : '1' ) . ';">Check in</button></form>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
    echo '<input type="hidden" name="action" value="qrrs_att_self"><input type="hidden" name="restaurant_id" value="' . $rid . '"><input type="hidden" name="do" value="out">';
    wp_nonce_field( 'qrrs_att_self' );
    echo '<button type="submit" ' . ( $in_now ? '' : 'disabled' ) . ' style="padding:14px 22px; border:none; border-radius:12px; background:#dc2626; color:#fff; font-weight:800; font-size:15px; cursor:pointer; opacity:' . ( $in_now ? '1' : '.4' ) . ';">Check out</button></form>';
    echo '</div></div>';
}

/** Monthly report, and the manual entry form, for the manager. */
function qrrs_att_report_render( $restaurant_id ) {
    global $wpdb;
    $rid   = intval( $restaurant_id );
    $month = preg_match( '/^\d{4}-\d{2}$/', $_GET['att_month'] ?? '' ) ? $_GET['att_month'] : current_time( 'Y-m' );
    $from  = $month . '-01';
    $to    = gmdate( 'Y-m-t', strtotime( $from ) );
    $staff = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d AND status = 'active' ORDER BY id", $rid ) );
    $recs  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_attendance WHERE restaurant_id = %d AND work_date BETWEEN %s AND %s", $rid, $from, $to ) );
    $leaves = $wpdb->get_results( $wpdb->prepare( "SELECT staff_id, start_date, end_date FROM {$wpdb->prefix}qrrs_leaves WHERE restaurant_id = %d AND end_date >= %s AND start_date <= %s", $rid, $from, $to ) );
    $by = [];
    foreach ( $recs as $r ) { $by[ $r->staff_id ][] = $r; }
    $inp = 'padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-top:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
            <div><div style="font-size:15px; font-weight:800; color:#0f172a;">Attendance report</div><div style="font-size:13px; color:#64748b;">Days present, hours worked and leave for the month.</div></div>
            <form method="get" style="display:flex; gap:8px; margin:0;">
                <input type="hidden" name="tab" value="accounts"><input type="hidden" name="acc" value="hr">
                <input type="month" name="att_month" value="<?php echo esc_attr( $month ); ?>" style="<?php echo $inp; ?>">
                <button type="submit" style="padding:8px 14px; border:none; border-radius:8px; background:#0f172a; color:#fff; font-weight:700;">Show</button>
            </form>
        </div>
        <table style="width:100%; border-collapse:collapse; font-size:14px;">
            <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                <th style="padding:10px;">Staff</th><th style="padding:10px;">Days present</th><th style="padding:10px;">Hours</th><th style="padding:10px;">Leave days</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $staff as $s ) :
                $days = []; $mins = 0;
                foreach ( $by[ $s->id ] ?? [] as $r ) {
                    $days[ substr( $r->work_date, 0, 10 ) ] = true;
                    if ( $r->check_out ) $mins += max( 0, ( strtotime( $r->check_out ) - strtotime( $r->check_in ) ) / 60 );
                }
                $lv = 0;
                foreach ( $leaves as $l ) {
                    if ( (int) $l->staff_id !== (int) $s->id ) continue;
                    $a = max( strtotime( $l->start_date ), strtotime( $from ) ); $b = min( strtotime( $l->end_date ), strtotime( $to ) );
                    if ( $b >= $a ) $lv += (int) ( ( $b - $a ) / DAY_IN_SECONDS ) + 1;
                }
                $name = function_exists( 'qrrs_staff_display_name' ) ? qrrs_staff_display_name( $s ) : ( 'Staff #' . $s->id ); ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:10px; font-weight:600;"><?php echo esc_html( $name ); ?></td>
                    <td style="padding:10px;"><?php echo count( $days ); ?></td>
                    <td style="padding:10px;"><?php echo number_format( $mins / 60, 1 ); ?> h</td>
                    <td style="padding:10px;"><?php echo intval( $lv ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ( empty( $staff ) ) : ?><div style="padding:14px; text-align:center; color:#94a3b8;">No active staff yet.</div><?php endif; ?>
    </div>
    <?php
}

/** Manager's manual entry (used when attendance is off, or for a missed day). */
function qrrs_att_manual_render( $restaurant_id ) {
    $rid   = intval( $restaurant_id );
    $staff = qrrs_staff_list_for_pick( $rid );
    $st    = sanitize_key( $_GET['at_status'] ?? '' );
    $inp   = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:10px; font-size:14px; width:100%; box-sizing:border-box;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-top:14px;">
        <div style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">Add attendance by hand</div>
        <div style="font-size:13px; color:#64748b; margin-bottom:12px;">For a missed day, or when attendance is off.</div>
        <?php if ( $st === 'saved' ) : ?><div style="background:#dcfce7;color:#166534;padding:10px 14px;border-radius:10px;margin-bottom:12px;">Attendance saved.</div><?php endif; ?>
        <?php if ( $st === 'bad' ) : ?><div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:10px;margin-bottom:12px;">Check the staff, the date and the check-in time.</div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; align-items:end; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px;">
            <input type="hidden" name="action" value="qrrs_att_manual">
            <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
            <?php wp_nonce_field( 'qrrs_att_manual' ); ?>
            <label style="font-size:12px; font-weight:700; color:#334155;">Staff
                <select name="staff_id" required style="<?php echo $inp; ?> margin-top:4px;">
                    <?php foreach ( $staff as $s ) : ?><option value="<?php echo intval( $s->id ); ?>"><?php echo esc_html( $s->label ); ?></option><?php endforeach; ?>
                </select></label>
            <label style="font-size:12px; font-weight:700; color:#334155;">Date
                <input type="date" name="work_date" required value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" style="<?php echo $inp; ?> margin-top:4px;"></label>
            <label style="font-size:12px; font-weight:700; color:#334155;">Check in
                <input type="time" name="check_in" required style="<?php echo $inp; ?> margin-top:4px;"></label>
            <label style="font-size:12px; font-weight:700; color:#334155;">Check out
                <input type="time" name="check_out" style="<?php echo $inp; ?> margin-top:4px;"></label>
            <label style="font-size:12px; font-weight:700; color:#334155;">Note
                <input type="text" name="note" maxlength="255" style="<?php echo $inp; ?> margin-top:4px;"></label>
            <div><button type="submit" style="padding:10px 18px; border:none; border-radius:10px; background:#16a34a; color:#fff; font-weight:800; cursor:pointer;">Save</button></div>
        </form>
    </div>
    <?php
}

/** Active staff for a dropdown, with a readable label. */
function qrrs_staff_list_for_pick( $restaurant_id ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d AND status = 'active' ORDER BY id", intval( $restaurant_id ) ) );
    foreach ( $rows as $r ) {
        $r->label = function_exists( 'qrrs_staff_display_name' ) ? qrrs_staff_display_name( $r ) : ( 'Staff #' . $r->id );
    }
    return $rows;
}