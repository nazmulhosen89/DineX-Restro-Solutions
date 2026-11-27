<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Staff list (all staff, with or without login), with pay details and edit / remove.
 * Staff with login are edited on the Settings → Staff form. Staff without login are edited here.
 */

function qrrs_staff_pay_label( $type ) {
    return [ 'daily' => 'Daily', 'weekly' => 'Weekly', 'half_month' => 'Half month', 'monthly' => 'Monthly' ][ $type ] ?? 'Monthly';
}

function qrrs_staff_per_day( $type, $amount ) {
    $a = (float) $amount;
    switch ( $type ) {
        case 'daily':      return $a;
        case 'weekly':     return $a / 7;
        case 'half_month': return $a / 15;
        default:           return $a / 30;
    }
}

function qrrs_staff_display_name( $row ) {
    if ( intval( $row->user_id ) > 0 ) {
        $u = get_userdata( intval( $row->user_id ) );
        if ( $u ) return $u->display_name;
    }
    return $row->full_name !== '' ? $row->full_name : 'Staff #' . intval( $row->id );
}

add_action( 'admin_post_qrrs_staff_pay_save', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() || ( function_exists( 'qrrs_can' ) && ! qrrs_can( 'hr.staff' ) ) ) wp_die( 'Not allowed.' );
    global $wpdb;
    $id  = intval( $_POST['row_id'] ?? 0 );
    check_admin_referer( 'qrrs_staff_pay_save_' . $id );
    $rid = intval( $_POST['restaurant_id'] ?? 0 );
    $type = in_array( $_POST['salary_type'] ?? '', [ 'daily', 'weekly', 'half_month', 'monthly' ], true ) ? $_POST['salary_type'] : 'monthly';
    $role_key = sanitize_key( $_POST['role_key'] ?? '' );
    $row_now  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE id = %d AND restaurant_id = %d", $id, $rid ) );
    if ( $row_now && $role_key !== '' && function_exists( 'qrrs_role_base' ) && qrrs_role_find( $rid, $role_key ) ) {
        $base = qrrs_role_base( $rid, $role_key );
        $wpdb->update( $wpdb->prefix . 'qrrs_staff', [ 'staff_role' => $base ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        if ( intval( $row_now->user_id ) > 0 ) {
            $u = get_userdata( intval( $row_now->user_id ) );
            if ( $u && ! in_array( 'administrator', (array) $u->roles, true ) ) {
                $u->set_role( $base );
                update_user_meta( $u->ID, 'qrrs_role_key', $role_key );
            }
        } else {
            // no login: the role key itself is kept on the staff row
            $wpdb->update( $wpdb->prefix . 'qrrs_staff', [ 'staff_role' => $role_key ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        }
    }
    $wpdb->update( $wpdb->prefix . 'qrrs_staff', [
        'full_name'     => sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) ),
        'phone'         => sanitize_text_field( $_POST['phone'] ?? '' ),
        'salary_type'   => $type,
        'salary_amount' => max( 0, (float) ( $_POST['salary_amount'] ?? 0 ) ),
        'status'        => ( ( $_POST['status'] ?? '' ) === 'inactive' ) ? 'inactive' : 'active',
    ], [ 'id' => $id, 'restaurant_id' => $rid ], [ '%s', '%s', '%s', '%f', '%s' ], [ '%d', '%d' ] );
    if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( 'staff_pay_updated', 'staff', $id, [ 'type' => $type ], $rid );
    wp_safe_redirect( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );

add_action( 'admin_post_qrrs_staff_row_remove', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() || ( function_exists( 'qrrs_can' ) && ! qrrs_can( 'hr.staff' ) ) ) wp_die( 'Not allowed.' );
    global $wpdb;
    $id  = intval( $_POST['row_id'] ?? 0 );
    check_admin_referer( 'qrrs_staff_row_remove_' . $id );
    $rid = intval( $_POST['restaurant_id'] ?? 0 );
    // only staff without login can be removed here; staff with login are removed on the staff page
    $wpdb->delete( $wpdb->prefix . 'qrrs_staff', [ 'id' => $id, 'restaurant_id' => $rid, 'user_id' => 0 ], [ '%d', '%d', '%d' ] );
    if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( 'staff_removed', 'staff', $id, [], $rid );
    wp_safe_redirect( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'hr' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );

function qrrs_staff_list_html( $restaurant_id, $context = 'page' ) {
    global $wpdb;
    $rid  = intval( $restaurant_id );
    $can  = function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d ORDER BY status = 'active' DESC, id ASC", $rid ) );
    $inp  = 'padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; width:100%; box-sizing:border-box;';
    $role_names = [ 'qr_manager' => 'Manager', 'qr_waiter' => 'Waiter', 'qr_kitchen' => 'Kitchen', 'qr_cashier' => 'Cashier' ];
    foreach ( qrrs_roles_get( $rid ) as $__rn ) { $role_names[ $__rn['key'] ] = $__rn['name']; }
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px; margin-bottom:14px;">
        <div style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:10px;">All staff (<?php echo count( $rows ); ?>)</div>
        <?php if ( empty( $rows ) ) : ?>
            <div style="padding:14px; text-align:center; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:10px;">No staff yet. Add staff below.</div>
        <?php else : ?>
        <div style="display:grid; gap:10px;">
        <?php foreach ( $rows as $r ) :
            $name  = qrrs_staff_display_name( $r );
            $login = intval( $r->user_id ) > 0;
            $per   = qrrs_staff_per_day( $r->salary_type, $r->salary_amount ); ?>
            <details style="border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; background:<?php echo $r->status === 'active' ? '#fff' : '#f8fafc'; ?>;">
                <summary style="cursor:pointer; list-style:none; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <span style="font-weight:800; color:#0f172a; font-size:15px;"><?php echo esc_html( $name ); ?></span>
                    <span style="background:#eef2ff; color:#3730a3; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px;"><?php echo esc_html( $role_names[ $r->staff_role ] ?? $r->staff_role ); ?></span>
                    <span style="background:<?php echo $login ? '#dcfce7' : '#f1f5f9'; ?>; color:<?php echo $login ? '#166534' : '#475569'; ?>; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px;"><?php echo $login ? 'Has login' : 'No login'; ?></span>
                    <span style="background:<?php echo $r->status === 'active' ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $r->status === 'active' ? '#166534' : '#991b1b'; ?>; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px;"><?php echo $r->status === 'active' ? 'Active' : 'Inactive'; ?></span>
                    <span style="margin-left:auto; font-size:13px; color:#334155;">৳<?php echo number_format( (float) $r->salary_amount, 2 ); ?> · <?php echo esc_html( qrrs_staff_pay_label( $r->salary_type ) ); ?> <span style="color:#94a3b8;">(≈ ৳<?php echo number_format( $per, 2 ); ?>/day)</span></span>
                </summary>

                <div style="margin-top:12px; font-size:13px; color:#475569;">Phone: <?php echo esc_html( $r->phone ?: '—' ); ?></div>

                <?php if ( $can ) : ?>
                <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                <?php if ( $login ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'add-staff', 'action' => 'edit', 'id' => intval( $r->user_id ) ], home_url( '/restaurant-dashboard/' ) ) ); ?>" style="padding:8px 14px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-size:13px; font-weight:700;">Edit login &amp; role</a>
                <?php endif; ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:1; min-width:260px; display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:8px; align-items:end; margin:0;">
                        <input type="hidden" name="action" value="qrrs_staff_pay_save">
                        <input type="hidden" name="row_id" value="<?php echo intval( $r->id ); ?>">
                        <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                        <?php wp_nonce_field( 'qrrs_staff_pay_save_' . intval( $r->id ) ); ?>
                        <label style="font-size:12px; font-weight:700;">Name<input type="text" name="full_name" value="<?php echo esc_attr( $r->full_name ); ?>" style="<?php echo $inp; ?> margin-top:3px;"></label>
                        <label style="font-size:12px; font-weight:700;">Phone<input type="text" name="phone" value="<?php echo esc_attr( $r->phone ); ?>" style="<?php echo $inp; ?> margin-top:3px;"></label>
                        <label style="font-size:12px; font-weight:700;">Pay type
                            <select name="salary_type" style="<?php echo $inp; ?> margin-top:3px;">
                                <?php foreach ( [ 'daily' => 'Daily', 'weekly' => 'Weekly', 'half_month' => 'Half month', 'monthly' => 'Monthly' ] as $k => $lbl ) : ?>
                                    <option value="<?php echo $k; ?>" <?php selected( $r->salary_type, $k ); ?>><?php echo $lbl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="font-size:12px; font-weight:700;">Amount (৳)<input type="number" step="0.01" min="0" name="salary_amount" value="<?php echo esc_attr( $r->salary_amount ); ?>" style="<?php echo $inp; ?> margin-top:3px;"></label>
                        <label style="font-size:12px; font-weight:700;">Role
                            <select name="role_key" style="<?php echo $inp; ?> margin-top:3px;">
                                <?php $cur_key = $login ? ( get_user_meta( intval( $r->user_id ), 'qrrs_role_key', true ) ?: $r->staff_role ) : $r->staff_role; ?>
                                <?php foreach ( qrrs_roles_get( $rid ) as $__rr ) : if ( empty( $__rr['active'] ) && $__rr['key'] !== $cur_key ) continue; ?>
                                    <option value="<?php echo esc_attr( $__rr['key'] ); ?>" <?php selected( $cur_key, $__rr['key'] ); ?>><?php echo esc_html( $__rr['name'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="font-size:12px; font-weight:700;">Status
                            <select name="status" style="<?php echo $inp; ?> margin-top:3px;">
                                <option value="active" <?php selected( $r->status, 'active' ); ?>>Active</option>
                                <option value="inactive" <?php selected( $r->status, 'inactive' ); ?>>Inactive</option>
                            </select>
                        </label>
                        <button type="submit" style="padding:9px 14px; border:none; border-radius:8px; background:#16a34a; color:#fff; font-weight:800; cursor:pointer;">Save</button>
                    </form>
                <?php if ( ! $login ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remove this staff member?');" style="margin:0;">
                        <input type="hidden" name="action" value="qrrs_staff_row_remove">
                        <input type="hidden" name="row_id" value="<?php echo intval( $r->id ); ?>">
                        <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
                        <?php wp_nonce_field( 'qrrs_staff_row_remove_' . intval( $r->id ) ); ?>
                        <button type="submit" style="padding:9px 14px; border:1px solid #fca5a5; border-radius:8px; background:#fff; color:#dc2626; font-weight:700; cursor:pointer;">Remove</button>
                    </form>
                <?php endif; ?>
                </div>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}