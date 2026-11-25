<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Roles and menu access, per restaurant.
 *  - A role has a name, a base login role (manager, waiter, kitchen, cashier), the menus it can open, and active on/off.
 *  - The restaurant admin creates, edits and turns roles on or off in Settings → Roles.
 *  - A staff member gets one role. Their login role follows the base role, their menus follow the role.
 */

function qrrs_menu_catalog() {
    return [
        'take-order' => 'Take an Order',
        'orders'     => 'Order Management',
        'tables'     => 'Table Management',
        'rooms'      => 'Rooms',
        'billing'    => 'Billing & POS',
        'reports'    => 'Reports',
        'accounts'   => 'Accounts & HR',
        'categories' => 'Categories',
        'items'      => 'Items',
        'inventory'  => 'Inventory',
        'crm'        => 'CRM',
        'settings'   => 'Settings',
        'audit'      => 'Advanced Change Tracker',
        'backup'     => 'Backup',
    ];
}

/** Actions inside the modules. Each one is checked on the server, not only hidden on screen. */
function qrrs_action_catalog() {
    return [
        'Order'     => [ 'order.create' => 'Take an order', 'order.edit' => 'Change items', 'order.cancel' => 'Cancel an order' ],
        'Billing'   => [ 'billing.settle' => 'Collect payment & settle', 'billing.discount' => 'Give a discount', 'billing.credit' => 'Sell on credit (বাকি)', 'billing.void' => 'Void or refund a bill' ],
        'Accounts'  => [ 'accounts.expense' => 'Record expenses', 'accounts.closing' => 'Close the day' ],
        'HR'        => [ 'hr.staff' => 'Manage staff', 'hr.leave' => 'Record leave', 'hr.salary' => 'Record salary' ],
        'Menu'      => [ 'menu.edit' => 'Change items, prices & offers' ],
        'Inventory' => [ 'inventory.edit' => 'Change stock' ],
        'Reports'   => [ 'reports.view' => 'View reports' ],
        'Settings'  => [ 'settings.roles' => 'Manage roles' ],
    ];
}

function qrrs_action_keys() {
    $keys = [];
    foreach ( qrrs_action_catalog() as $items ) { $keys = array_merge( $keys, array_keys( $items ) ); }
    return $keys;
}

function qrrs_default_actions( $base ) {
    $map = [
        'qr_manager' => [ 'order.create', 'order.edit', 'order.cancel', 'billing.settle', 'billing.discount', 'billing.credit', 'billing.void', 'accounts.expense', 'accounts.closing', 'hr.leave', 'menu.edit', 'inventory.edit', 'reports.view' ],
        'qr_waiter'  => [ 'order.create', 'order.edit' ],
        'qr_kitchen' => [],
        'qr_cashier' => [ 'billing.settle', 'billing.credit', 'accounts.expense', 'accounts.closing' ],
    ];
    return $map[ $base ] ?? [];
}

/** The actions of a role. A role saved before actions existed uses the defaults of its login type. */
function qrrs_role_actions( $role ) {
    if ( ! $role ) return [];
    return isset( $role['actions'] ) ? (array) $role['actions'] : qrrs_default_actions( $role['base'] ?? '' );
}

/** Can the current user do this action? Full admins can do everything. */
function qrrs_can( $action ) {
    if ( ! is_user_logged_in() ) return false;
    if ( function_exists( 'qrrs_is_full_admin' ) && qrrs_is_full_admin() ) return true;
    $user = wp_get_current_user();
    $rid  = function_exists( 'qrrs_inventory_get_active_restaurant_id' ) ? intval( qrrs_inventory_get_active_restaurant_id() ) : 0;
    $role = qrrs_role_find( $rid, qrrs_user_role_key( $user ) );
    if ( ! $role || empty( $role['active'] ) ) return false;
    return in_array( $action, qrrs_role_actions( $role ), true );
}

/** Tick boxes for the actions, grouped by module. */
function qrrs_action_pills( $selected, $prefix = '' ) {
    $sel = (array) $selected;
    ob_start();
    foreach ( qrrs_action_catalog() as $module => $items ) : ?>
        <div style="margin-top:10px;">
            <div style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-bottom:6px;"><?php echo esc_html( $module ); ?></div>
            <div class="rl-menus">
            <?php foreach ( $items as $k => $label ) : $on = in_array( $k, $sel, true ); ?>
                <label class="rl-pill <?php echo $on ? 'on' : ''; ?>"><input type="checkbox" name="actions[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $on ); ?>> <?php echo esc_html( $label ); ?></label>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

function qrrs_base_role_names() {
    return [ 'qr_manager' => 'Manager', 'qr_waiter' => 'Waiter', 'qr_kitchen' => 'Kitchen', 'qr_cashier' => 'Cashier' ];
}

function qrrs_default_roles() {
    return [
        [ 'key' => 'qr_manager', 'name' => 'Manager',  'base' => 'qr_manager', 'active' => 1,
          'menus' => [ 'take-order', 'orders', 'tables', 'billing', 'reports', 'categories', 'items', 'inventory', 'crm' ] ],
        [ 'key' => 'qr_waiter',  'name' => 'Waiter',   'base' => 'qr_waiter',  'active' => 1,
          'menus' => [ 'take-order', 'orders', 'tables' ] ],
        [ 'key' => 'qr_kitchen', 'name' => 'Kitchen',  'base' => 'qr_kitchen', 'active' => 1,
          'menus' => [ 'orders' ] ],
        [ 'key' => 'qr_cashier', 'name' => 'Cashier',  'base' => 'qr_cashier', 'active' => 1,
          'menus' => [ 'orders', 'billing', 'accounts' ] ],
    ];
}

/** All roles of a restaurant (defaults are used until the admin changes them). */
function qrrs_roles_get( $restaurant_id ) {
    $list = get_option( 'qrrs_roles_' . intval( $restaurant_id ), null );
    return is_array( $list ) ? $list : qrrs_default_roles();
}

function qrrs_roles_put( $restaurant_id, array $list ) {
    update_option( 'qrrs_roles_' . intval( $restaurant_id ), array_values( $list ) );
}

function qrrs_role_find( $restaurant_id, $key ) {
    foreach ( qrrs_roles_get( $restaurant_id ) as $r ) {
        if ( $r['key'] === $key ) return $r;
    }
    return null;
}

/** The WordPress login role behind a role key. */
function qrrs_role_base( $restaurant_id, $key ) {
    if ( 'qr_restaurant_admin' === $key ) return 'qr_restaurant_admin';
    $r = qrrs_role_find( $restaurant_id, $key );
    return $r ? $r['base'] : 'qr_waiter';
}

/** The role key of a user (set on the staff form); falls back to the WordPress role. */
function qrrs_user_role_key( $user ) {
    $k = (string) get_user_meta( $user->ID, 'qrrs_role_key', true );
    if ( $k !== '' ) return $k;
    foreach ( [ 'qr_manager', 'qr_waiter', 'qr_kitchen', 'qr_cashier' ] as $base ) {
        if ( in_array( $base, (array) $user->roles, true ) ) return $base;
    }
    return '';
}

/** Can the current user open this menu? Full admins can open everything. */
function qrrs_menu_allowed( $tab ) {
    if ( ! is_user_logged_in() ) return false;
    if ( function_exists( 'qrrs_is_full_admin' ) && qrrs_is_full_admin() ) return true;
    $user = wp_get_current_user();
    $rid  = function_exists( 'qrrs_inventory_get_active_restaurant_id' ) ? intval( qrrs_inventory_get_active_restaurant_id() ) : 0;
    $role = qrrs_role_find( $rid, qrrs_user_role_key( $user ) );
    if ( ! $role || empty( $role['active'] ) ) return false;
    return in_array( $tab, (array) $role['menus'], true );
}

/** Is the page (tab) open for this user? Used before a page is drawn. */
function qrrs_tab_allowed( $tab ) {
    if ( in_array( $tab, [ 'profile', 'denied' ], true ) ) return true;
    if ( in_array( $tab, [ 'restaurants', 'license' ], true ) ) return function_exists( 'qrrs_is_full_admin' ) && qrrs_is_full_admin();
    if ( in_array( $tab, [ 'add-staff', 'online-settings', 'roles', 'offers' ], true ) ) return qrrs_menu_allowed( 'settings' );
    if ( 'closing' === $tab ) return qrrs_menu_allowed( 'accounts' );
    return qrrs_menu_allowed( $tab );
}

add_action( 'admin_post_qrrs_role_save', function () {
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_is_full_admin' ) || ! qrrs_is_full_admin() ) wp_die( 'Not allowed.' );
    check_admin_referer( 'qrrs_role_save' );
    $rid   = intval( $_POST['restaurant_id'] ?? 0 );
    $key   = sanitize_key( $_POST['role_key'] ?? '' );
    $name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    $base  = in_array( $_POST['base'] ?? '', array_keys( qrrs_base_role_names() ), true ) ? $_POST['base'] : 'qr_waiter';
    $menus = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['menus'] ?? [] ) ), array_keys( qrrs_menu_catalog() ) ) );
    $active = ! empty( $_POST['active'] ) ? 1 : 0;
    $actions = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $_POST['actions'] ?? [] ) ), qrrs_action_keys() ) );
    $back  = add_query_arg( [ 'tab' => 'settings', 'set' => 'roles' ], home_url( '/restaurant-dashboard/' ) );
    if ( $rid <= 0 || '' === trim( $name ) ) { wp_safe_redirect( add_query_arg( 'rl', 'bad', $back ) ); exit; }

    $list = qrrs_roles_get( $rid );
    $row  = [ 'key' => $key ?: 'r_' . substr( md5( uniqid( '', true ) ), 0, 8 ), 'name' => substr( $name, 0, 60 ),
              'base' => $base, 'active' => $active, 'menus' => $menus, 'actions' => $actions ];
    $found = false;
    foreach ( $list as $i => $r ) { if ( $r['key'] === $row['key'] ) { $list[ $i ] = $row; $found = true; } }
    if ( ! $found ) $list[] = $row;
    qrrs_roles_put( $rid, $list );
    if ( function_exists( 'qrrs_audit' ) ) qrrs_audit( $found ? 'role_updated' : 'role_added', 'role', 0, [ 'name' => $row['name'], 'menus' => $menus, 'active' => $active ], $rid );
    wp_safe_redirect( add_query_arg( 'rl', 'saved', $back ) ); exit;
} );

function qrrs_roles_render( $restaurant_id ) {
    $rid   = intval( $restaurant_id );
    $roles = qrrs_roles_get( $rid );
    $cat   = qrrs_menu_catalog();
    $bases = qrrs_base_role_names();
    $st    = sanitize_key( $_GET['rl'] ?? '' );
    $inp   = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:10px; font-size:14px; box-sizing:border-box; width:100%;';
    ?>
    <style>
    .rl-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;margin-bottom:14px;}
    .rl-menus{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
    .rl-pill{cursor:pointer;border:1.5px solid #cbd5e1;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;color:#334155;background:#fff;user-select:none;}
    .rl-pill input{display:none;}
    .rl-pill.on{background:#0f172a;border-color:#0f172a;color:#fff;}
    .rl-tag{font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;}
    </style>
    <div style="font-size:16px; font-weight:800; color:#0f172a; margin-bottom:4px;">Roles &amp; menu access</div>
    <div style="font-size:13px; color:#64748b; margin-bottom:14px;">Choose which menus each role can open. A staff member's role decides what they see.</div>

    <?php if ( $st === 'saved' ) : ?><div style="background:#dcfce7;color:#166534;padding:10px 14px;border-radius:10px;margin-bottom:12px;">Role saved.</div><?php endif; ?>
    <?php if ( $st === 'bad' ) : ?><div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:10px;margin-bottom:12px;">Enter a role name.</div><?php endif; ?>

    <?php foreach ( $roles as $r ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rl-card">
        <input type="hidden" name="action" value="qrrs_role_save">
        <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
        <input type="hidden" name="role_key" value="<?php echo esc_attr( $r['key'] ); ?>">
        <?php wp_nonce_field( 'qrrs_role_save' ); ?>
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
            <label style="flex:1; min-width:180px; font-size:12px; font-weight:700; color:#334155;">Role name
                <input type="text" name="name" value="<?php echo esc_attr( $r['name'] ); ?>" required maxlength="60" style="<?php echo $inp; ?> margin-top:4px;"></label>
            <label style="min-width:160px; font-size:12px; font-weight:700; color:#334155;">Login type
                <select name="base" style="<?php echo $inp; ?> margin-top:4px;">
                    <?php foreach ( $bases as $bk => $bl ) : ?><option value="<?php echo $bk; ?>" <?php selected( $r['base'], $bk ); ?>><?php echo $bl; ?></option><?php endforeach; ?>
                </select></label>
            <label style="font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px; padding-bottom:10px;"><input type="checkbox" name="active" value="1" <?php checked( ! empty( $r['active'] ) ); ?>> Active</label>
            <button type="submit" style="padding:10px 16px; border:none; border-radius:10px; background:#16a34a; color:#fff; font-weight:800; cursor:pointer;">Save</button>
        </div>
        <div style="margin-top:12px; font-size:12px; font-weight:700; color:#334155;">Menus this role can open</div>
        <div class="rl-menus">
            <?php foreach ( $cat as $mk => $ml ) : $on = in_array( $mk, (array) $r['menus'], true ); ?>
                <label class="rl-pill <?php echo $on ? 'on' : ''; ?>"><input type="checkbox" name="menus[]" value="<?php echo esc_attr( $mk ); ?>" <?php checked( $on ); ?>> <?php echo esc_html( $ml ); ?></label>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:12px; font-size:12px; font-weight:700; color:#334155;">What this role can do</div>
        <?php echo qrrs_action_pills( qrrs_role_actions( $r ) ); ?>
        <div style="margin-top:12px;"><button type="submit" style="padding:10px 16px; border:none; border-radius:10px; background:#16a34a; color:#fff; font-weight:800; cursor:pointer;">Save role</button></div>
    </form>
    <?php endforeach; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rl-card" style="background:#f8fafc;">
        <input type="hidden" name="action" value="qrrs_role_save">
        <input type="hidden" name="restaurant_id" value="<?php echo $rid; ?>">
        <?php wp_nonce_field( 'qrrs_role_save' ); ?>
        <div style="font-size:14px; font-weight:800; color:#0f172a; margin-bottom:10px;">Add a new role</div>
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
            <label style="flex:1; min-width:180px; font-size:12px; font-weight:700; color:#334155;">Role name
                <input type="text" name="name" required maxlength="60" placeholder="e.g. Assistant Manager" style="<?php echo $inp; ?> margin-top:4px;"></label>
            <label style="min-width:160px; font-size:12px; font-weight:700; color:#334155;">Login type
                <select name="base" style="<?php echo $inp; ?> margin-top:4px;">
                    <?php foreach ( $bases as $bk => $bl ) : ?><option value="<?php echo $bk; ?>"><?php echo $bl; ?></option><?php endforeach; ?>
                </select></label>
            <label style="font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px; padding-bottom:10px;"><input type="checkbox" name="active" value="1" checked> Active</label>
        </div>
        <div style="margin-top:12px; font-size:12px; font-weight:700; color:#334155;">Menus</div>
        <div class="rl-menus">
            <?php foreach ( $cat as $mk => $ml ) : ?>
                <label class="rl-pill"><input type="checkbox" name="menus[]" value="<?php echo esc_attr( $mk ); ?>"> <?php echo esc_html( $ml ); ?></label>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:12px; font-size:12px; font-weight:700; color:#334155;">What this role can do</div>
        <?php echo qrrs_action_pills( [] ); ?>
        <div style="margin-top:14px;"><button type="submit" style="padding:10px 18px; border:none; border-radius:10px; background:#0f172a; color:#fff; font-weight:800; cursor:pointer;">Add role</button></div>
    </form>
    <script>
    document.querySelectorAll('.rl-pill input').forEach(function (cb) {
        cb.addEventListener('change', function () { cb.parentNode.classList.toggle('on', cb.checked); });
    });
    </script>
    <?php
}