<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! qrrs_is_full_admin() && ! qrrs_menu_allowed( 'settings' ) ) { QRRS_Auth::is_admin_only(); }

global $wpdb;

$edit_mode = false;
$staff_data = null;
$staff_table = $wpdb->prefix . 'qrrs_staff';
$res_table = $wpdb->prefix . 'qrrs_restaurants';

/**
 * 1. Restaurant ID
 */
if ( ! session_id() ) session_start();
$active_res_id = isset($_SESSION['qrrs_active_res_id']) ? intval($_SESSION['qrrs_active_res_id']) : 0;

if (!$active_res_id && !isset($_GET['action'])) {
    echo '<div style="padding:50px; text-align:center;"><h3>❌ Please select a restaurant from the dashboard first.</h3></div>';
    return;
}

/**
 * 2. Delete
 */
if ( isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) ) {
    $uid_to_delete = intval($_GET['id']);
    if ( function_exists( 'qrrs_audit' ) ) { qrrs_audit( 'staff_removed', 'user', (int) $uid_to_delete, [], 0 ); }
    $wpdb->delete($staff_table, ['user_id' => $uid_to_delete]);
    require_once(ABSPATH . 'wp-admin/includes/user.php');
    wp_delete_user( $uid_to_delete );
    echo "<div class='qrrs-toast success'>Staff member removed successfully!</div>";
}

/**
 * 3. Edit Mode Detect
 */
if ( isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id']) ) {
    $edit_mode = true;
    $staff_id = intval($_GET['id']);
    $staff_data = get_userdata($staff_id);
}

// 4. Save/Update
if ( isset($_POST['save_staff']) ) {
    $sid = $edit_mode ? intval($_GET['id']) : 0;
    $restaurant_id = intval( $_POST['restaurant_id'] );
    $role_key   = sanitize_text_field( $_POST['staff_role'] );
    $staff_role = function_exists( 'qrrs_role_base' ) ? qrrs_role_base( $restaurant_id, $role_key ) : $role_key;
    $status = sanitize_text_field( $_POST['staff_status'] );
    $staff_name = sanitize_text_field($_POST['staff_name']);

    if ( $edit_mode ) {
        // --- UPDATE ---
        wp_update_user([
            'ID'           => $sid,
            'display_name' => $staff_name
        ]);

        if ( !empty($_POST['staff_pass']) ) {
            wp_set_password( $_POST['staff_pass'], $sid );
        }

        update_user_meta( $sid, 'staff_photo', esc_url_raw( $_POST['staff_photo'] ) );
        update_user_meta( $sid, 'staff_nid_front', esc_url_raw( $_POST['nid_front'] ) );
        update_user_meta( $sid, 'staff_nid_back', esc_url_raw( $_POST['nid_back'] ) );
        update_user_meta( $sid, 'assigned_restaurant', $restaurant_id );
        update_user_meta( $sid, 'staff_status', $status );
        update_user_meta( $sid, 'qrrs_role_key', $role_key );
        $wpdb->update( $staff_table, [ 'full_name' => $staff_name, 'phone' => sanitize_text_field( $_POST['phone'] ?? '' ), 'address' => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ) ], [ 'user_id' => $sid ], [ '%s', '%s', '%s' ], [ '%d' ] );

        $user = new WP_User( $sid );
        $user->set_role( $staff_role );

        // Update the existing staff row. Never add a new row for the same user,
        // and remove any extra rows that an earlier edit created.
        $staff_fields = [
            'restaurant_id' => $restaurant_id,
            'staff_role'    => $staff_role,
            'status'        => $status,
        ];
        $staff_rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $staff_table WHERE user_id = %d ORDER BY id DESC", $sid ) );
        if ( ! empty( $staff_rows ) ) {
            $wpdb->update( $staff_table, $staff_fields, [ 'id' => intval( $staff_rows[0] ) ], [ '%d', '%s', '%s' ], [ '%d' ] );
            if ( function_exists( 'qrrs_audit' ) ) { qrrs_audit( 'staff_updated', 'user', (int) $sid, [ 'role' => $staff_role, 'status' => $status ], $restaurant_id ); }
            if ( count( $staff_rows ) > 1 ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM $staff_table WHERE user_id = %d AND id <> %d", $sid, intval( $staff_rows[0] ) ) );
            }
        } else {
            $wpdb->insert( $staff_table, array_merge( [ 'user_id' => $sid, 'assigned_by' => get_current_user_id() ], $staff_fields ), [ '%d', '%d', '%d', '%s', '%s' ] );
        }

        echo "<div class='qrrs-toast success'>Staff updated successfully!</div>";
        echo "<script>setTimeout(function(){ window.location.href='?tab=add-staff'; }, 2000);</script>";
    } else {
        // --- CREATE ---
        $has_login = ! empty( $_POST['has_login'] );
        $sal_type  = in_array( $_POST['salary_type'] ?? '', [ 'daily', 'weekly', 'half_month', 'monthly' ], true ) ? $_POST['salary_type'] : 'monthly';
        $sal_amt   = max( 0, (float) ( $_POST['salary_amount'] ?? 0 ) );
        $phone     = sanitize_text_field( $_POST['phone'] ?? '' );
        $address   = sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) );

        if ( ! $has_login ) {
            // staff without login: a staff record only, no WordPress user
            if ( function_exists( 'qrrs_free_limit_reached' ) && qrrs_free_limit_reached( $staff_role, $restaurant_id ) ) {
                echo "<div class='qrrs-toast error'>Free version allows only 1 " . esc_html( str_replace( 'qr_', '', $staff_role ) ) . " user per restaurant.</div>";
            } else {
                $wpdb->insert( $staff_table, [
                    'user_id' => 0, 'restaurant_id' => $restaurant_id, 'staff_role' => $staff_role,
                    'assigned_by' => get_current_user_id(), 'status' => $status, 'full_name' => $staff_name,
                    'phone' => $phone, 'salary_type' => $sal_type, 'salary_amount' => $sal_amt, 'address' => $address,
                ], [ '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%s' ] );
                if ( function_exists( 'qrrs_audit' ) ) { qrrs_audit( 'staff_added', 'staff', (int) $wpdb->insert_id, [ 'role' => $staff_role, 'login' => 0 ], $restaurant_id ); }
                echo "<div class='qrrs-toast success'>Staff added (no login).</div>";
                echo "<script>setTimeout(function(){ window.location.href='?tab=add-staff'; }, 2000);</script>";
            }
        } else {
        $username = sanitize_user($_POST['staff_user']);
        $password = $_POST['staff_pass'];

        if ( function_exists( 'qrrs_free_limit_reached' ) && qrrs_free_limit_reached( $staff_role, $restaurant_id ) ) {
            echo "<div class='qrrs-toast error'>Free version allows only 1 " . esc_html( str_replace( 'qr_', '', $staff_role ) ) . " user per restaurant. Upgrade to Pro to add more staff.</div>";
        } elseif ( username_exists( $username ) ) {
            echo "<div class='qrrs-toast error'>Error: Username already exists!</div>";
        } else {
            $new_user_id = wp_create_user($username, $password, $username . '@restaurant.com');

            if ( !is_wp_error($new_user_id) ) {
                wp_update_user(['ID' => $new_user_id, 'display_name' => $staff_name]);
                
                update_user_meta( $new_user_id, 'qrrs_role_key', $role_key );
                update_user_meta( $new_user_id, 'staff_photo', esc_url_raw( $_POST['staff_photo'] ) );
                update_user_meta( $new_user_id, 'staff_nid_front', esc_url_raw( $_POST['nid_front'] ) );
                update_user_meta( $new_user_id, 'staff_nid_back', esc_url_raw( $_POST['nid_back'] ) );
                update_user_meta( $new_user_id, 'assigned_restaurant', $restaurant_id );
                update_user_meta( $new_user_id, 'staff_status', $status );

                $user = new WP_User( $new_user_id );
                $user->set_role( $staff_role );

                // --- DATABASE TABLE INSERT ---
                $db_inserted = $wpdb->insert($staff_table, [
                    'user_id'       => $new_user_id,
                    'restaurant_id' => $restaurant_id,
                    'staff_role'    => $staff_role,
                    'assigned_by'   => get_current_user_id(),
                    'status'        => $status
                ], ['%d', '%d', '%s', '%d', '%s']);

                if($db_inserted !== false) {
                    $wpdb->update( $staff_table, [ 'full_name' => $staff_name, 'phone' => $phone, 'salary_type' => $sal_type, 'salary_amount' => $sal_amt, 'address' => $address ], [ 'user_id' => $new_user_id ], [ '%s', '%s', '%s', '%f', '%s' ], [ '%d' ] );
                    echo "<div class='qrrs-toast success'>Staff created successfully!</div>";
                    echo "<script>setTimeout(function(){ window.location.href='?tab=add-staff'; }, 2000);</script>";
                } else {
                    echo "<div class='qrrs-toast error'>WP User created, but DB Table insert failed!</div>";
                }
            } else {
                echo "<div class='qrrs-toast error'>Error: " . $new_user_id->get_error_message() . "</div>";
            }
        }
        }
    }
}

$active_res_name = $wpdb->get_var($wpdb->prepare("SELECT restaurant_name FROM $res_table WHERE id = %d", $active_res_id));
?>

<div class="items-add">
    <div class="qrrs-card">
        <div class="card-header">
            <h3>
                <?php echo $edit_mode ? '<span class="material-icons-outlined">edit_note</span> Edit Staff Member' : '<span class="material-icons-outlined">add</span> Add Staff for ' . esc_html($active_res_name); ?>
            </h3>
        </div>
        
        <?php if ( function_exists( 'qrrs_staff_list_html' ) && ! $edit_mode ) { qrrs_staff_list_html( $active_res_id, 'page' ); } ?>
        <style>
        .qs-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:14px;}
        .qs-title{font-size:15px;font-weight:800;color:#0f172a;margin:0 0 12px;}
        .qs-chips{display:flex;flex-wrap:wrap;gap:8px;}
        .qs-chip{cursor:pointer;border:1.5px solid #cbd5e1;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:700;color:#334155;background:#fff;}
        .qs-chip input{display:none;}
        .qs-chip.on{background:#0f172a;border-color:#0f172a;color:#fff;}
        .qs-hint{font-size:12px;color:#64748b;margin-top:6px;}
        .qs-login{display:none;margin-top:12px;padding:14px;border:1px dashed #94a3b8;border-radius:12px;background:#f8fafc;}
        .qs-login.show{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
        .qs-login-toggle{display:flex;align-items:center;gap:10px;font-weight:800;color:#0f172a;cursor:pointer;}
        </style>
        <form method="POST" class="qrrs-form" id="sf-form">
            <input type="hidden" name="restaurant_id" value="<?php echo $active_res_id; ?>">
            <?php
                global $wpdb;
                $__srow = ( $edit_mode && ! empty( $staff_data->ID ) ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_staff WHERE user_id = %d", $staff_data->ID ) ) : null;
            ?>
            <style>
            .sf-wrap{display:grid;gap:14px;}
            .sf-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;box-shadow:0 1px 3px rgba(15,23,42,.05);}
            .sf-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
            .sf-num{width:28px;height:28px;border-radius:50%;background:#0f172a;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;}
            .sf-title{font-size:15px;font-weight:800;color:#0f172a;}
            .sf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;}
            .sf-field label{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:5px;}
            .sf-field input,.sf-field select,.sf-field textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;background:#fff;transition:border .15s, box-shadow .15s;}
            .sf-field input:focus,.sf-field select:focus,.sf-field textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);}
            .sf-field input[readonly]{background:#f8fafc;color:#64748b;}
            .sf-chips{display:flex;flex-wrap:wrap;gap:8px;}
            .sf-chip{cursor:pointer;border:1.5px solid #cbd5e1;border-radius:999px;padding:8px 16px;font-size:13px;font-weight:700;color:#334155;background:#fff;user-select:none;}
            .sf-chip input{display:none;}
            .sf-chip.on{background:#0f172a;border-color:#0f172a;color:#fff;}
            .sf-hint{font-size:12px;color:#64748b;margin-top:8px;}
            .sf-toggle{display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px 14px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;font-weight:700;color:#0f172a;}
            .sf-toggle input{width:18px;height:18px;}
            .sf-login{display:none;margin-top:12px;}
            .sf-login.show{display:grid;}
            .sf-err{color:#dc2626;font-size:12px;font-weight:700;margin-top:6px;min-height:16px;}
            .sf-foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:6px;}
            .sf-btn{padding:12px 22px;border:none;border-radius:12px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;font-weight:800;font-size:15px;cursor:pointer;box-shadow:0 4px 10px rgba(22,163,74,.3);}
            .sf-btn:hover{filter:brightness(1.05);}
            .sf-cancel{color:#64748b;font-weight:700;text-decoration:none;}
            </style>

            <div class="sf-wrap">

                <!-- 1. Personal -->
                <div class="sf-card">
                    <div class="sf-head"><span class="sf-num">1</span><span class="sf-title">Personal details</span></div>
                    <div class="sf-grid">
                        <div class="sf-field"><label>Full name *</label>
                            <input type="text" name="staff_name" required value="<?php echo $edit_mode ? esc_attr( $staff_data->display_name ) : ''; ?>" placeholder="e.g. Iqbal Mahmud"></div>
                        <div class="sf-field"><label>Phone</label>
                            <input type="text" name="phone" maxlength="30" placeholder="01XXXXXXXXX" value="<?php echo $__srow ? esc_attr( $__srow->phone ) : ''; ?>"></div>
                        <div class="sf-field" style="grid-column:1/-1;"><label>Address</label>
                            <textarea name="address" rows="2" maxlength="500" placeholder="Village / road, area, district"><?php echo $__srow ? esc_textarea( $__srow->address ) : ''; ?></textarea></div>
                    </div>
                </div>

                <!-- 2. Role & status -->
                <div class="sf-card">
                    <div class="sf-head"><span class="sf-num">2</span><span class="sf-title">Role &amp; status</span></div>
                    <?php $current_role = $edit_mode ? ( get_user_meta( $staff_data->ID, 'qrrs_role_key', true ) ?: $staff_data->roles[0] ) : ''; ?>
                    <?php $status = $edit_mode ? get_user_meta( $staff_data->ID, 'staff_status', true ) : 'active'; ?>
                    <div class="sf-grid">
                        <div class="sf-field"><label>Role *</label>
                            <select name="staff_role">
                                <?php foreach ( qrrs_roles_get( $active_res_id ) as $__r ) : if ( empty( $__r['active'] ) && $__r['key'] !== $current_role ) continue; ?>
                                    <option value="<?php echo esc_attr( $__r['key'] ); ?>" <?php selected( $current_role, $__r['key'] ); ?>><?php echo esc_html( $__r['name'] ); ?><?php echo empty( $__r['active'] ) ? ' (inactive)' : ''; ?></option>
                                <?php endforeach; ?>
                                <?php if ( current_user_can( 'administrator' ) ) : ?>
                                <option value="qr_restaurant_admin" <?php selected( $current_role, 'qr_restaurant_admin' ); ?>>Restaurant admin</option>
                                <?php endif; ?>
                            </select></div>
                        <div class="sf-field"><label>Status *</label>
                            <select name="staff_status">
                                <option value="active" <?php selected( $status, 'active' ); ?>>Active</option>
                                <option value="inactive" <?php selected( $status, 'inactive' ); ?>>Inactive</option>
                            </select></div>
                        <div class="sf-field"><label>Restaurant</label>
                            <input type="text" value="<?php echo esc_attr( $active_res_name ); ?>" disabled></div>
                    </div>
                </div>

                <?php if ( ! $edit_mode ) : ?>
                <!-- 3. Pay -->
                <div class="sf-card">
                    <div class="sf-head"><span class="sf-num">3</span><span class="sf-title">Pay</span></div>
                    <div class="sf-chips" id="sf-pay">
                        <label class="sf-chip"><input type="radio" name="salary_type_pick" value="daily"> Daily</label>
                        <label class="sf-chip"><input type="radio" name="salary_type_pick" value="weekly"> Weekly</label>
                        <label class="sf-chip"><input type="radio" name="salary_type_pick" value="half_month"> Half month</label>
                        <label class="sf-chip on"><input type="radio" name="salary_type_pick" value="monthly" checked> Monthly</label>
                    </div>
                    <input type="hidden" name="salary_type" id="sf-pay-value" value="monthly">
                    <div class="sf-grid" style="margin-top:14px;">
                        <div class="sf-field"><label>Amount (৳) for this pay type</label>
                            <input type="number" name="salary_amount" id="sf-amt" step="0.01" min="0" value="0"></div>
                    </div>
                    <div class="sf-hint" id="sf-preview">Enter the amount to see the per-day value.</div>
                </div>
                <?php endif; ?>

                <!-- 4. Login -->
                <div class="sf-card">
                    <div class="sf-head"><span class="sf-num"><?php echo $edit_mode ? '3' : '4'; ?></span><span class="sf-title">Login access</span></div>
                    <?php if ( ! $edit_mode ) : ?>
                    <label class="sf-toggle"><input type="checkbox" name="has_login" id="sf-has-login" value="1"> Give this person a login (username &amp; password)</label>
                    <?php endif; ?>
                    <div class="sf-login <?php echo $edit_mode ? 'show' : ''; ?>" id="sf-login">
                        <div class="sf-grid">
                            <div class="sf-field"><label>Username *</label>
                                <input type="text" name="staff_user" id="sf-user" autocomplete="off" <?php echo $edit_mode ? 'readonly' : ''; ?> value="<?php echo $edit_mode ? esc_attr( $staff_data->user_login ) : ''; ?>"></div>
                            <div class="sf-field"><label>Password <?php echo $edit_mode ? '(leave blank to keep)' : '*'; ?></label>
                                <input type="password" name="staff_pass" id="sf-pass" autocomplete="new-password"></div>
                            <div class="sf-field"><label>Confirm password *</label>
                                <input type="password" id="sf-pass2" autocomplete="new-password"></div>
                        </div>
                        <div class="sf-err" id="sf-err"></div>
                    </div>
                </div>

                <!-- 5. Documents -->
                <div class="sf-card">
                    <div class="sf-head"><span class="sf-num"><?php echo $edit_mode ? '4' : '5'; ?></span><span class="sf-title">Photo &amp; NID</span></div>
                    <div class="sf-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
                <!-- Staff Photo (Circular Upload) -->
                <div class="form-col" style="text-align:center;">
                    <label style="display:block; margin-bottom:8px; font-weight:600;">Staff Photo</label>
                    <?php $photo = $edit_mode ? get_user_meta($staff_data->ID, 'staff_photo', true) : ''; ?>
                    <div class="upload-media-btn" data-preview="#staff_photo_preview" data-input="#staff_photo_url" style="display:inline-block; position:relative; cursor:pointer;" title="Click to upload photo">
                        <img id="staff_photo_preview" src="<?php echo $photo ? $photo : 'https://via.placeholder.com/90?text=Upload'; ?>" style="width:85px; height:85px; border-radius:50%; object-fit:cover; border:2px dashed #cbd5e1; padding:3px; background:#f8fafc; transition:all 0.3s ease;">
                        <!-- <div style="position:absolute; bottom:0; right:0; background:#2563eb; color:#fff; border-radius:50%; width:28px; height:28px; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 4px rgba(0,0,0,0.2);">
                            <span class="material-icons-outlined" style="font-size:16px;">camera_alt</span>
                        </div> -->
                    </div>
                    <input type="hidden" name="staff_photo" id="staff_photo_url" value="<?php echo $photo; ?>">
                </div>

                <!-- NID Front -->
                <div class="form-col" style="text-align:center;">
                    <label style="display:block; margin-bottom:8px; font-weight:600;">NID Front</label>
                    <?php $nid_f = $edit_mode ? get_user_meta($staff_data->ID, 'staff_nid_front', true) : ''; ?>
                    <div class="upload-media-btn" data-preview="#nid_front_preview" data-input="#nid_front_url" style="display:inline-block; position:relative; cursor:pointer;" title="Click to upload NID front">
                        <div id="nid_front_container" style="width:140px; height:85px; border:2px dashed #cbd5e1; border-radius:8px; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;">
                            <img id="nid_front_preview" src="<?php echo $nid_f ? $nid_f : ''; ?>" style="width:100%; height:100%; object-fit:cover; <?php echo $nid_f ? '' : 'display:none;'; ?>">
                            <div id="nid_front_placeholder" style="<?php echo $nid_f ? 'display:none;' : 'display:flex;'; ?> flex-direction:column; align-items:center; color:#64748b;">
                                <span class="material-icons-outlined" style="font-size:24px;">badge</span>
                                <span style="font-size:11px; margin-top:2px;">Upload Front</span>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="nid_front" id="nid_front_url" value="<?php echo $nid_f; ?>">
                </div>

                <!-- NID Back -->
                <div class="form-col" style="text-align:center;">
                    <label style="display:block; margin-bottom:8px; font-weight:600;">NID Back</label>
                    <?php $nid_b = $edit_mode ? get_user_meta($staff_data->ID, 'staff_nid_back', true) : ''; ?>
                    <div class="upload-media-btn" data-preview="#nid_back_preview" data-input="#nid_back_url" style="display:inline-block; position:relative; cursor:pointer;" title="Click to upload NID back">
                        <div id="nid_back_container" style="width:140px; height:85px; border:2px dashed #cbd5e1; border-radius:8px; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; position:relative;">
                            <img id="nid_back_preview" src="<?php echo $nid_b ? $nid_b : ''; ?>" style="width:100%; height:100%; object-fit:cover; <?php echo $nid_b ? '' : 'display:none;'; ?>">
                            <div id="nid_back_placeholder" style="<?php echo $nid_b ? 'display:none;' : 'display:flex;'; ?> flex-direction:column; align-items:center; color:#64748b;">
                                <span class="material-icons-outlined" style="font-size:24px;">badge</span>
                                <span style="font-size:11px; margin-top:2px;">Upload Back</span>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="nid_back" id="nid_back_url" value="<?php echo $nid_b; ?>">
                </div>
                    </div>
                </div>

                <div class="sf-foot">
                    <button type="submit" name="save_staff" class="sf-btn" id="sf-submit">
                        <?php echo $edit_mode ? 'Update staff member' : 'Save staff member'; ?>
                    </button>
                    <?php if ( $edit_mode ) : ?><a class="sf-cancel" href="?tab=add-staff">Cancel</a><?php endif; ?>
                </div>
                <?php if ( ! $edit_mode && function_exists( 'qrrs_is_pro' ) && ! qrrs_is_pro() ) : ?>
                    <p style="margin:4px 0 0; color:#92400e; font-size:13px;">Free version allows 1 manager, 1 waiter and 1 kitchen user per restaurant.</p>
                <?php endif; ?>
            </div>

            <script>
            (function () {
                var form = document.getElementById('sf-form');
                if (!form) return;
                var $ = function (id) { return document.getElementById(id); };
                var cb = $('sf-has-login'), login = $('sf-login');
                var user = $('sf-user'), pass = $('sf-pass'), pass2 = $('sf-pass2'), err = $('sf-err');
                var amt = $('sf-amt'), pv = $('sf-preview'), val = $('sf-pay-value');
                var editMode = <?php echo $edit_mode ? 'true' : 'false'; ?>;

                function setLogin() {
                    var on = editMode || (cb && cb.checked);
                    if (login) login.classList.toggle('show', on);
                    if (user) user.required = on && !editMode ? true : (editMode ? false : on);
                    if (!editMode && pass) { pass.required = on; pass2 && (pass2.required = on); }
                    if (!on) { if (user) user.value = ''; if (pass) pass.value = ''; if (pass2) pass2.value = ''; err.textContent = ''; }
                }
                function pay() {
                    var pick = document.querySelector('input[name="salary_type_pick"]:checked');
                    if (!pick) return;
                    var t = pick.value, a = parseFloat(amt ? amt.value : 0) || 0;
                    if (val) val.value = t;
                    document.querySelectorAll('#sf-pay .sf-chip').forEach(function (c) { c.classList.toggle('on', c.querySelector('input').checked); });
                    var per = t === 'daily' ? a : (t === 'weekly' ? a / 7 : (t === 'half_month' ? a / 15 : a / 30));
                    if (pv) pv.textContent = a > 0 ? 'About ৳' + per.toFixed(2) + ' per day (used for leave deduction).' : 'Enter the amount to see the per-day value.';
                }
                function check(e) {
                    err.textContent = '';
                    var on = editMode || (cb && cb.checked);
                    if (!on) return true;
                    if (!editMode && user && user.value.trim().length < 3) { err.textContent = 'Username must be at least 3 characters.'; user.focus(); e.preventDefault(); return false; }
                    if (pass && pass.value.length > 0 && pass.value.length < 6) { err.textContent = 'Password must be at least 6 characters.'; pass.focus(); e.preventDefault(); return false; }
                    if (!editMode && pass && pass.value.length < 6) { err.textContent = 'Password must be at least 6 characters.'; pass.focus(); e.preventDefault(); return false; }
                    if (pass && pass2 && pass.value !== pass2.value) { err.textContent = 'The two passwords do not match.'; pass2.focus(); e.preventDefault(); return false; }
                    return true;
                }
                if (cb) cb.addEventListener('change', setLogin);
                if (amt) amt.addEventListener('input', pay);
                document.querySelectorAll('input[name="salary_type_pick"]').forEach(function (r) { r.addEventListener('change', pay); });
                form.addEventListener('submit', check);
                setLogin(); pay();
            })();
            </script>
        </form>

            
    </div>

    <hr style="margin:20px 0; border:0; border-top:1px solid #eee;">

    <div class="qrrs-card">
        <div class="card-header">
            <h3>📋 Staff List (<?php echo esc_html($active_res_name); ?>)</h3>
        </div>
        <table class="qrrs-table" style="width:100%; border-collapse:collapse; margin-top:15px;">
            <thead>
                <tr style="background:#f8f9fa; text-align:left; border-bottom:2px solid #eee;">
                    <th style="padding:12px;">Photo</th>
                    <th style="padding:12px;">Name</th>
                    <th style="padding:12px;">Role</th>
                    <th style="padding:12px;">Status</th>
                    <th style="padding:12px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $staff_results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $staff_table WHERE restaurant_id = %d ORDER BY id DESC", $active_res_id));
                if($staff_results):
                    foreach($staff_results as $staff):
                        $user = get_userdata($staff->user_id);
                        if(!$user) continue; 
                        $photo = get_user_meta($user->ID, 'staff_photo', true);
                ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:10px;"><img src="<?php echo $photo ? $photo : 'https://via.placeholder.com/40'; ?>" style="width:35px; height:35px; border-radius:50%; object-fit:cover;"></td>
                    <td style="padding:10px;"><strong><?php echo esc_html($user->display_name); ?></strong><br><small>@<?php echo esc_html($user->user_login); ?></small></td>
                    <td style="padding:10px;"><span style="background:#eee; padding:2px 8px; border-radius:10px; font-size:11px;"><?php echo ucfirst(str_replace('qr_', '', $staff->staff_role)); ?></span></td>
                    <td style="padding:10px;"><span style="color:<?php echo $staff->status == 'active' ? 'green' : 'red'; ?>; font-weight:bold; font-size:12px;"><?php echo ucfirst($staff->status); ?></span></td>
                    <td style="padding:10px;">
                        <a href="?tab=add-staff&action=edit&id=<?php echo $user->ID; ?>" style="color:#2271b1; text-decoration:none; font-size:13px; margin-right:10px;">Edit</a>
                        <a href="?tab=add-staff&action=delete&id=<?php echo $user->ID; ?>" style="color:#e53e3e; text-decoration:none; font-size:13px;" onclick="return confirm('Delete this staff?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">No staff found for this restaurant.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
jQuery(document).ready(function($){
    function hideToast() {
        if ($('.qrrs-toast').length > 0) {
            setTimeout(function() {
                $('.qrrs-toast').addClass('toast-fade-out');
                setTimeout(function() {
                    $('.qrrs-toast').remove();
                }, 500);
            }, 3000);
        }
    }
    
    hideToast(); 
    $('.upload-media-btn').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var targetInput = button.data('input');
        var targetPreview = button.data('preview');

        var uploader = wp.media({
            title: 'Select Media',
            multiple: false
        }).on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $(targetInput).val(attachment.url);
            
            if(targetPreview.includes('nid')) {
                $(targetPreview).attr('src', attachment.url).show();
                $(targetPreview).siblings('div').hide();
            } else {
                $(targetPreview).attr('src', attachment.url);
            }
        }).open();
    });
});
</script>