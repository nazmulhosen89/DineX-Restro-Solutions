<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HR: leave taken by staff. Uses the leave types set per restaurant.
 * Days are counted from start to end date (both days included).
 * The leave is blocked when it would go over the days allowed for that type in the year.
 */

function qrrs_leave_staff_name( $user_id, $full_name = '' ) {
    if ( intval( $user_id ) > 0 ) {
        $u = get_userdata( intval( $user_id ) );
        if ( $u ) return $u->display_name;
    }
    return $full_name !== '' ? $full_name : 'Staff';
}

function qrrs_leave_days_used( $restaurant_id, $staff_id, $type_id, $year ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(days),0) FROM {$wpdb->prefix}qrrs_leaves
         WHERE restaurant_id = %d AND staff_id = %d AND leave_type_id = %s AND YEAR(start_date) = %d",
        intval( $restaurant_id ), intval( $staff_id ), $type_id, intval( $year )
    ) );
}

add_action( 'admin_post_qrrs_leave_save', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() || ( function_exists( 'qrrs_can' ) && ! qrrs_can( 'hr.leave' ) ) ) wp_die( 'Not allowed.' );
    check_admin_referer( 'qrrs_leave_save' );
    global $wpdb;
    $rid   = intval( $_POST['restaurant_id'] ?? 0 );
    $sid   = intval( $_POST['staff_id'] ?? 0 );
    $tid   = sanitize_key( $_POST['leave_type_id'] ?? '' );
    $start = sanitize_text_field( $_POST['start_date'] ?? '' );
    $end   = sanitize_text_field( $_POST['end_date'] ?? '' );
    $note  = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
    $back  = add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr' ], home_url( '/restaurant-dashboard/' ) );

    $valid = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) && $end >= $start;
    $staff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}qrrs_staff WHERE id = %d AND restaurant_id = %d", $sid, $rid ) );
    $type  = null;
    foreach ( qrrs_leave_types_get( $rid ) as $t ) { if ( $t['id'] === $tid ) $type = $t; }
    if ( ! $valid || ! $staff || ! $type ) {
        wp_safe_redirect( add_query_arg( 'lv_leave', 'bad', $back ) ); exit;
    }

    $days = (int) ( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;
    $year = (int) substr( $start, 0, 4 );
    if ( intval( $type['days'] ) > 0 && qrrs_leave_days_used( $rid, $sid, $tid, $year ) + $days > intval( $type['days'] ) ) {
        wp_safe_redirect( add_query_arg( 'lv_leave', 'over', $back ) ); exit;
    }

    $wpdb->insert( $wpdb->prefix . 'qrrs_leaves', [
        'restaurant_id' => $rid, 'staff_id' => $sid, 'leave_type_id' => $tid,
        'start_date' => $start, 'end_date' => $end, 'days' => $days,
        'note' => substr( $note, 0, 255 ), 'created_by' => get_current_user_id(),
        'created_at' => current_time( 'mysql' ),
    ] );
    if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( 'leave_taken', 'leave', (int) $wpdb->insert_id, [ 'staff' => $sid, 'type' => $type['name'], 'from' => $start, 'to' => $end, 'days' => $days ], $rid );
    wp_safe_redirect( add_query_arg( 'lv_leave', 'saved', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_leave_remove', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() || ( function_exists( 'qrrs_can' ) && ! qrrs_can( 'hr.leave' ) ) ) wp_die( 'Not allowed.' );
    global $wpdb;
    $id  = intval( $_POST['leave_id'] ?? 0 );
    check_admin_referer( 'qrrs_leave_remove_' . $id );
    $rid = intval( $_POST['restaurant_id'] ?? 0 );
    $wpdb->delete( $wpdb->prefix . 'qrrs_leaves', [ 'id' => $id, 'restaurant_id' => $rid ] );
    if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( 'leave_removed', 'leave', $id, [], $rid );
    wp_safe_redirect( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr', 'lv_leave' => 'removed' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );

function qrrs_leaves_render( $restaurant_id ) {
    global $wpdb;
    $rid   = intval( $restaurant_id );
    $can   = function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
    $today = current_time( 'Y-m-d' );
    $week  = wp_date( 'Y-m-d', strtotime( $today ) + 7 * DAY_IN_SECONDS );
    $types = qrrs_leave_types_get( $rid );
    $names = [];
    foreach ( $types as $t ) $names[ $t['id'] ] = $t['name'];

    $staff = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, user_id, full_name FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d AND status = 'active' ORDER BY id", $rid ) );

    // leaves that touch today or the next 7 days
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, s.user_id, s.full_name FROM {$wpdb->prefix}qrrs_leaves l
         JOIN {$wpdb->prefix}qrrs_staff s ON s.id = l.staff_id
         WHERE l.restaurant_id = %d AND l.end_date >= %s AND l.start_date <= %s
         ORDER BY l.start_date ASC", $rid, $today, $week ) );

    $st  = sanitize_key( $_GET['lv_leave'] ?? '' );
    $msg = [ 'saved' => [ '#dcfce7', '#166534', 'Leave saved.' ], 'removed' => [ '#dcfce7', '#166534', 'Leave removed.' ],
             'bad' => [ '#fee2e2', '#991b1b', 'Check the staff, the leave type and the dates (end date cannot be before start date).' ],
             'over' => [ '#fee2e2', '#991b1b', 'This leave goes over the days allowed for this leave type this year.' ] ];
    $inp = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:10px; font-size:14px; box-sizing:border-box; width:100%;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-top:14px;">
        <div style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">Leave</div>
        <div style="font-size:13px; color:#64748b; margin-bottom:12px;">Who is on leave today and in the next 7 days.</div>

        <?php if ( isset( $msg[ $st ] ) ) : [ $bg, $fg, $txt ] = $msg[ $st ]; ?>
            <div style="background:<?php echo $bg; ?>; color:<?php echo $fg; ?>; padding:10px 14px; border-radius:10px; margin-bottom:12px; font-size:14px;"><?php echo esc_html( $txt ); ?></div>
        <?php endif; ?>

        <?php if ( empty( $rows ) ) : ?>
            <div style="padding:14px; text-align:center; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:10px; margin-bottom:12px;">Nobody is on leave today or in the next 7 days.</div>
        <?php else : ?>
            <table style="width:100%; border-collapse:collapse; font-size:14px; margin-bottom:14px;">
                <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                    <th style="padding:10px;">Staff</th><th style="padding:10px;">Leave</th><th style="padding:10px;">From</th><th style="padding:10px;">To</th><th style="padding:10px;">Days</th><th style="padding:10px;">Status</th><th style="padding:10px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) :
                    $on_now = ( $r->start_date <= $today && $r->end_date >= $today ); ?>
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:10px; font-weight:600;"><?php echo esc_html( qrrs_leave_staff_name( $r->user_id, $r->full_name ) ); ?></td>
                        <td style="padding:10px;"><?php echo esc_html( $names[ $r->leave_type_id ] ?? $r->leave_type_id ); ?><?php if ( $r->note ) echo '<div style="font-size:12px; color:#64748b;">' . esc_html( $r->note ) . '</div>'; ?></td>
                        <td style="padding:10px;"><?php echo esc_html( $r->start_date ); ?></td>
                        <td style="padding:10px;"><?php echo esc_html( $r->end_date ); ?></td>
                        <td style="padding:10px;"><?php echo intval( $r->days ); ?></td>
                        <td style="padding:10px;"><?php echo $on_now ? '<span style="background:#fee2e2;color:#991b1b;font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;">On leave today</span>' : '<span style="background:#e0f2fe;color:#075985;font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;">Upcoming</span>'; ?></td>
                        <td style="padding:10px; text-align:right;">
                            <?php if ( $can ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remove this leave?');" style="margin:0;">
                                <input type="hidden" name="action" value="qrrs_leave_remove">
                                <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                                <input type="hidden" name="leave_id" value="<?php echo intval( $r->id ); ?>">
                                <?php wp_nonce_field( 'qrrs_leave_remove_' . intval( $r->id ) ); ?>
                                <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:13px;">Remove</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( $can && ! empty( $staff ) && ! empty( $types ) ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; align-items:end; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px;">
            <input type="hidden" name="action" value="qrrs_leave_save">
            <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
            <?php wp_nonce_field( 'qrrs_leave_save' ); ?>
            <label style="font-size:12px; font-weight:700; color:#334155;">Staff
                <select name="staff_id" required style="<?php echo $inp; ?> margin-top:4px;">
                    <?php foreach ( $staff as $s ) : ?><option value="<?php echo intval( $s->id ); ?>"><?php echo esc_html( qrrs_leave_staff_name( $s->user_id, $s->full_name ) ); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label style="font-size:12px; font-weight:700; color:#334155;">Leave type
                <select name="leave_type_id" required style="<?php echo $inp; ?> margin-top:4px;">
                    <?php foreach ( $types as $t ) : ?><option value="<?php echo esc_attr( $t['id'] ); ?>"><?php echo esc_html( $t['name'] ); ?><?php echo $t['paid'] ? '' : ' (unpaid)'; ?></option><?php endforeach; ?>
                </select>
            </label>
            <label style="font-size:12px; font-weight:700; color:#334155;">From
                <input type="date" name="start_date" required value="<?php echo esc_attr( $today ); ?>" style="<?php echo $inp; ?> margin-top:4px;">
            </label>
            <label style="font-size:12px; font-weight:700; color:#334155;">To
                <input type="date" name="end_date" required value="<?php echo esc_attr( $today ); ?>" style="<?php echo $inp; ?> margin-top:4px;">
            </label>
            <label style="font-size:12px; font-weight:700; color:#334155;">Note (optional)
                <input type="text" name="note" maxlength="255" style="<?php echo $inp; ?> margin-top:4px;">
            </label>
            <div><button type="submit" style="padding:10px 18px; background:#16a34a; color:#fff; border:none; border-radius:10px; font-weight:800; cursor:pointer;">Save leave</button></div>
        </form>
        <?php elseif ( empty( $types ) ) : ?>
            <div style="font-size:13px; color:#92400e;">Add leave types above first, then you can record leave.</div>
        <?php endif; ?>
    </div>
    <?php
}