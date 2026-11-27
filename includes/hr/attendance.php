<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Load the other HR files from this folder, so they do not depend on the plugin loader
foreach ( [ 'staff-list.php', 'leave-types.php', 'leaves.php', 'salary.php', 'attendance-qr.php' ] as $__hr_file ) {
    if ( file_exists( __DIR__ . '/' . $__hr_file ) ) require_once __DIR__ . '/' . $__hr_file;
}

/**
 * HR: attendance on or off, per restaurant.
 *  - Off: the manager enters attendance by hand.
 *  - On: staff check in and out (mobile app, QR scan or a device). The check-in tools come next.
 */

function qrrs_attendance_enabled( $restaurant_id ) {
    return 'on' === get_option( 'qrrs_attendance_' . intval( $restaurant_id ), 'off' );
}

add_action( 'admin_post_qrrs_attendance_toggle', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) {
        wp_die( 'Not allowed.' );
    }
    check_admin_referer( 'qrrs_attendance_toggle' );
    $rid  = intval( $_POST['restaurant_id'] ?? 0 );
    $mode = ( ( $_POST['mode'] ?? '' ) === 'on' ) ? 'on' : 'off';
    if ( $rid > 0 ) {
        update_option( 'qrrs_attendance_' . $rid, $mode );
        if ( function_exists( 'qrrs_audit' ) ) {
            qrrs_audit( 'attendance_setting', 'restaurant', $rid, [ 'attendance' => $mode ], $rid );
        }
    }
    wp_safe_redirect( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr', 'att_status' => 'saved' ], home_url( '/restaurant-dashboard/' ) ) );
    exit;
} );

function qrrs_hr_render( $restaurant_id ) {
    global $wpdb;
    $rid   = intval( $restaurant_id );
    $on    = qrrs_attendance_enabled( $rid );
    $staff = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d AND status = 'active'", $rid
    ) );
    $can   = function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
    ?>
    <?php echo function_exists( 'qrrs_hide_forms_script' ) ? qrrs_hide_forms_script() : ''; ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-bottom:14px;">
        <p style="margin:0 0 6px; font-size:15px; color:#1e293b;"><strong>Active staff:</strong> <?php echo $staff; ?></p>
        <p style="margin:0; font-size:13px; color:#64748b;">Staff list: Settings → Staff.</p>
    </div>

    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
                <div style="font-size:15px; font-weight:800; color:#0f172a;">Attendance system</div>
                <div style="font-size:13px; color:#64748b; margin-top:4px;">
                    <?php if ( $on ) : ?>
                        <b style="color:#166534;">On</b> · Staff check in and out themselves (mobile, QR or device). Set up next.
                    <?php else : ?>
                        <b style="color:#92400e;">Off</b> · The manager enters attendance by hand.
                    <?php endif; ?>
                </div>
            </div>
            <?php if ( $can ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                <input type="hidden" name="action" value="qrrs_attendance_toggle">
                <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                <input type="hidden" name="mode" value="<?php echo $on ? 'off' : 'on'; ?>">
                <?php wp_nonce_field( 'qrrs_attendance_toggle' ); ?>
                <button type="submit" style="padding:10px 18px; border:none; border-radius:10px; font-weight:800; font-size:14px; cursor:pointer; color:#fff; background:<?php echo $on ? '#dc2626' : '#16a34a'; ?>;"><?php echo $on ? 'Turn attendance off' : 'Turn attendance on'; ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
    if ( function_exists( 'qrrs_att_manual_render' ) ) { qrrs_att_manual_render( $rid ); }
    if ( function_exists( 'qrrs_att_report_render' ) ) { qrrs_att_report_render( $rid ); }
    if ( function_exists( 'qrrs_staff_list_html' ) ) { qrrs_staff_list_html( $rid, 'hr' ); }
    if ( function_exists( 'qrrs_leave_types_render' ) ) { qrrs_leave_types_render( $rid ); }
    if ( function_exists( 'qrrs_leaves_render' ) ) { qrrs_leaves_render( $rid ); }
    if ( function_exists( 'qrrs_salary_render' ) ) { qrrs_salary_render( $rid ); }
}