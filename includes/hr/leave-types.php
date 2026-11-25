<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HR: leave types per restaurant (set by the admin or manager).
 * Each type has a name, paid or unpaid, and the days allowed per year.
 */

function qrrs_leave_types_get( $restaurant_id ) {
    $list = get_option( 'qrrs_leave_types_' . intval( $restaurant_id ), [] );
    return is_array( $list ) ? $list : [];
}

function qrrs_leave_types_put( $restaurant_id, array $list ) {
    update_option( 'qrrs_leave_types_' . intval( $restaurant_id ), array_values( $list ) );
}

add_action( 'admin_post_qrrs_leave_type_save', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) wp_die( 'Not allowed.' );
    check_admin_referer( 'qrrs_leave_type_save' );
    $rid  = intval( $_POST['restaurant_id'] ?? 0 );
    $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    $days = max( 0, min( 365, intval( $_POST['days'] ?? 0 ) ) );
    $paid = ! empty( $_POST['paid'] ) ? 1 : 0;
    $back = add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr' ], home_url( '/restaurant-dashboard/' ) );
    if ( $rid <= 0 || '' === trim( $name ) ) {
        wp_safe_redirect( add_query_arg( 'lv_status', 'bad', $back ) ); exit;
    }
    $list = qrrs_leave_types_get( $rid );
    $item = [ 'id' => substr( md5( uniqid( '', true ) ), 0, 8 ), 'name' => substr( $name, 0, 80 ), 'days' => $days, 'paid' => $paid ];
    $list[] = $item;
    qrrs_leave_types_put( $rid, $list );
    if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( 'leave_type_added', 'leave_type', 0, $item, $rid );
    wp_safe_redirect( add_query_arg( 'lv_status', 'saved', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_leave_type_delete', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) wp_die( 'Not allowed.' );
    $rid = intval( $_POST['restaurant_id'] ?? 0 );
    $id  = sanitize_key( $_POST['leave_id'] ?? '' );
    check_admin_referer( 'qrrs_leave_type_delete_' . $id );
    $list = qrrs_leave_types_get( $rid );
    $gone = null;
    $list = array_values( array_filter( $list, function ( $t ) use ( $id, &$gone ) {
        if ( $t['id'] === $id ) { $gone = $t; return false; }
        return true;
    } ) );
    qrrs_leave_types_put( $rid, $list );
    if ( $gone && function_exists( 'qrrs_audit' ) ) qrrs_audit( 'leave_type_deleted', 'leave_type', 0, $gone, $rid );
    wp_safe_redirect( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr', 'lv_status' => 'deleted' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );

function qrrs_leave_types_render( $restaurant_id ) {
    $rid  = intval( $restaurant_id );
    $list = qrrs_leave_types_get( $rid );
    $can  = function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
    $st   = sanitize_key( $_GET['lv_status'] ?? '' );
    $msgs = [ 'saved' => [ '#dcfce7', '#166534', 'Leave type saved.' ], 'deleted' => [ '#dcfce7', '#166534', 'Leave type removed.' ], 'bad' => [ '#fee2e2', '#991b1b', 'Enter a name for the leave type.' ] ];
    $inp  = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:10px; font-size:14px; box-sizing:border-box;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-top:14px;">
        <div style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">Leave types</div>
        <div style="font-size:13px; color:#64748b; margin-bottom:12px;">Set the leave types and the days allowed per year. Paid leave is not deducted from salary; unpaid leave is.</div>

        <?php if ( isset( $msgs[ $st ] ) ) : [ $bg, $fg, $txt ] = $msgs[ $st ]; ?>
            <div style="background:<?php echo $bg; ?>; color:<?php echo $fg; ?>; padding:10px 14px; border-radius:10px; margin-bottom:12px; font-size:14px;"><?php echo esc_html( $txt ); ?></div>
        <?php endif; ?>

        <?php if ( empty( $list ) ) : ?>
            <div style="padding:14px; text-align:center; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:10px; margin-bottom:12px;">No leave types yet. Add the first one below.</div>
        <?php else : ?>
            <table style="width:100%; border-collapse:collapse; font-size:14px; margin-bottom:14px;">
                <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                    <th style="padding:10px;">Leave type</th><th style="padding:10px;">Days per year</th><th style="padding:10px;">Paid / unpaid</th><th style="padding:10px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $list as $t ) : ?>
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:10px; font-weight:600;"><?php echo esc_html( $t['name'] ); ?></td>
                        <td style="padding:10px;"><?php echo intval( $t['days'] ); ?> days</td>
                        <td style="padding:10px;"><?php echo $t['paid'] ? '<span style="color:#166534; font-weight:700;">Paid</span>' : '<span style="color:#b45309; font-weight:700;">Unpaid (salary deducted)</span>'; ?></td>
                        <td style="padding:10px; text-align:right;">
                            <?php if ( $can ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remove this leave type?');" style="margin:0;">
                                <input type="hidden" name="action" value="qrrs_leave_type_delete">
                                <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                                <input type="hidden" name="leave_id" value="<?php echo esc_attr( $t['id'] ); ?>">
                                <?php wp_nonce_field( 'qrrs_leave_type_delete_' . $t['id'] ); ?>
                                <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:13px;">Remove</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( $can ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; align-items:end; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px;">
            <input type="hidden" name="action" value="qrrs_leave_type_save">
            <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
            <?php wp_nonce_field( 'qrrs_leave_type_save' ); ?>
            <label style="font-size:12px; font-weight:700; color:#334155;">Leave type name
                <input type="text" name="name" required maxlength="80" placeholder="e.g. Casual, Sick, Annual" style="<?php echo $inp; ?> width:100%; margin-top:4px;">
            </label>
            <label style="font-size:12px; font-weight:700; color:#334155;">Days per year
                <input type="number" name="days" min="0" max="365" value="0" style="<?php echo $inp; ?> width:100%; margin-top:4px;">
            </label>
            <label style="font-size:13px; font-weight:700; color:#334155; display:flex; align-items:center; gap:8px; padding-bottom:10px;">
                <input type="checkbox" name="paid" value="1" checked> Paid leave
            </label>
            <div><button type="submit" style="padding:10px 18px; background:#16a34a; color:#fff; border:none; border-radius:10px; font-weight:800; cursor:pointer;">Add leave type</button></div>
        </form>
        <?php endif; ?>
    </div>
    <?php
}