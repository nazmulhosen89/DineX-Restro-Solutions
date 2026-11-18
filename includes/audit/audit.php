<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Audit log: who changed what, and when.
 *  - Written by qrrs_audit() from each important action.
 *  - Viewed by the administrator on the Audit page. Rows are never edited from the screen.
 */

function qrrs_audit( $action, $entity = '', $entity_id = 0, $details = [], $restaurant_id = 0 ) {
    global $wpdb;
    $uid  = get_current_user_id();
    $user = $uid ? wp_get_current_user() : null;
    $wpdb->insert( $wpdb->prefix . 'qrrs_audit_log', [
        'restaurant_id' => intval( $restaurant_id ),
        'user_id'       => $uid,
        'user_name'     => $user ? ( $user->display_name ?: $user->user_login ) : 'system',
        'action'        => substr( (string) $action, 0, 60 ),
        'entity'        => substr( (string) $entity, 0, 40 ),
        'entity_id'     => intval( $entity_id ),
        'details'       => wp_json_encode( $details ),
        'ip'            => substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 ),
        'created_at'    => current_time( 'mysql' ),
    ] );
}

// Order status changes and cancellations
add_action( 'qrrs_order_status_changed', function ( $order_id, $status ) {
    global $wpdb;
    $o = $wpdb->get_row( $wpdb->prepare( "SELECT restaurant_id, table_name, grand_total FROM {$wpdb->prefix}qrrs_orders WHERE id = %d", intval( $order_id ) ) );
    $rid = $o ? intval( $o->restaurant_id ) : 0;
    qrrs_audit( 'cancelled' === $status ? 'order_cancelled' : 'order_status_changed', 'order', $order_id, [ 'status' => $status, 'table' => $o->table_name ?? '', 'total' => $o->grand_total ?? '' ], $rid );
}, 20, 2 );

function qrrs_audit_action_label( $action ) {
    $map = [
        'order_status_changed' => 'Order status changed',
        'order_cancelled'      => 'Order cancelled',
        'item_price_changed'   => 'Item price changed',
        'staff_added'          => 'Staff added',
        'staff_updated'        => 'Staff updated',
        'staff_removed'        => 'Staff removed',
        'crm_customer_deleted' => 'Customer deleted',
        'room_deleted'         => 'Room deleted',
        'online_settings_saved'=> 'Online settings saved',
        'backup_downloaded'    => 'Backup downloaded',
        'restore_run'          => 'Restore run',
    ];
    return $map[ $action ] ?? $action;
}

/** Audit page (administrator only). */
function qrrs_audit_render( $restaurant_id ) {
    global $wpdb;
    if ( ! current_user_can( 'administrator' ) ) {
        echo '<div class="qrrs-card" style="padding:20px;">The audit log is for the administrator only.</div>';
        return;
    }
    $rid    = intval( $restaurant_id );
    $from   = sanitize_text_field( $_GET['aud_from'] ?? '' );
    $to     = sanitize_text_field( $_GET['aud_to'] ?? '' );
    $action = sanitize_key( $_GET['aud_action'] ?? '' );
    $where  = [ $wpdb->prepare( 'restaurant_id = %d', $rid ) ];
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $where[] = $wpdb->prepare( 'created_at >= %s', $from . ' 00:00:00' );
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $where[] = $wpdb->prepare( 'created_at <= %s', $to . ' 23:59:59' );
    if ( $action )                                       $where[] = $wpdb->prepare( 'action = %s', $action );
    $rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'qrrs_audit_log WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 300' );
    $actions = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT action FROM ' . $wpdb->prefix . 'qrrs_audit_log WHERE restaurant_id = %d', $rid ) );
    $base = add_query_arg( [ 'tab' => 'audit' ], home_url( '/restaurant-dashboard/' ) );
    $inp  = 'padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden;">
        <div style="padding:18px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
            <h3 style="margin:0; font-size:20px; color:#1e293b;">Audit log</h3>
            <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Who changed what, and when. Latest 300 entries.</p>
        </div>
        <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; padding:16px 24px; border-bottom:1px solid #f1f5f9;">
            <input type="hidden" name="tab" value="audit">
            <div><label style="font-size:12px; font-weight:700; display:block; margin-bottom:4px;">From</label><input type="date" name="aud_from" value="<?php echo esc_attr( $from ); ?>" style="<?php echo $inp; ?>"></div>
            <div><label style="font-size:12px; font-weight:700; display:block; margin-bottom:4px;">To</label><input type="date" name="aud_to" value="<?php echo esc_attr( $to ); ?>" style="<?php echo $inp; ?>"></div>
            <div><label style="font-size:12px; font-weight:700; display:block; margin-bottom:4px;">Action</label>
                <select name="aud_action" style="<?php echo $inp; ?>"><option value="">All actions</option>
                    <?php foreach ( $actions as $a ) : ?><option value="<?php echo esc_attr( $a ); ?>" <?php selected( $action, $a ); ?>><?php echo esc_html( qrrs_audit_action_label( $a ) ); ?></option><?php endforeach; ?>
                </select></div>
            <button type="submit" style="background:#2563eb; color:#fff; border:none; padding:9px 16px; border-radius:8px; font-weight:600; cursor:pointer;">Filter</button>
            <a href="<?php echo esc_url( $base ); ?>" style="padding:9px 4px; color:#64748b; font-size:13px;">Reset</a>
        </form>
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead><tr style="text-align:left; font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                <th style="padding:12px 16px;">When</th><th style="padding:12px 8px;">Who</th><th style="padding:12px 8px;">Action</th><th style="padding:12px 8px;">Item</th><th style="padding:12px 16px;">Details</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) : $d = json_decode( (string) $r->details, true ) ?: []; ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:10px 16px; white-space:nowrap; color:#334155;"><?php echo esc_html( $r->created_at ); ?></td>
                    <td style="padding:10px 8px;"><?php echo esc_html( $r->user_name ); ?></td>
                    <td style="padding:10px 8px; font-weight:600; color:#1e293b;"><?php echo esc_html( qrrs_audit_action_label( $r->action ) ); ?></td>
                    <td style="padding:10px 8px; color:#64748b;"><?php echo esc_html( $r->entity ? $r->entity . ' #' . $r->entity_id : '—' ); ?></td>
                    <td style="padding:10px 16px; color:#475569; font-size:12px;"><?php
                        $parts = [];
                        foreach ( $d as $k => $v ) { $parts[] = $k . ': ' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) ); }
                        echo esc_html( implode( ' · ', $parts ) ?: '—' );
                    ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $rows ) ) : ?><tr><td colspan="5" style="padding:30px; text-align:center; color:#94a3b8;">No entries for this filter.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}