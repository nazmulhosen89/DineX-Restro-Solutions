<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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

/** Audit page with dynamic pagination & per-page selection */
function qrrs_audit_render( $restaurant_id ) {
    global $wpdb;
    if ( ! current_user_can( 'administrator' ) ) {
        echo '<div class="qrrs-restricted-box">
                <div class="qrrs-restricted-icon">🔒</div>
                <h3>Restricted Access</h3>
                <p>The audit log is securely restricted for administrators only.</p>
              </div>';
        return;
    }
    
    $rid      = intval( $restaurant_id );
    $from     = sanitize_text_field( $_GET['aud_from'] ?? '' );
    $to       = sanitize_text_field( $_GET['aud_to'] ?? '' );
    $action   = sanitize_key( $_GET['aud_action'] ?? '' );
    
    // Pagination variables
    $per_page = $_GET['aud_per_page'] ?? '25';
    $paged    = max( 1, intval( $_GET['aud_page'] ?? 1 ) );
    
    $where  = [ $wpdb->prepare( 'restaurant_id = %d', $rid ) ];
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) $where[] = $wpdb->prepare( 'created_at >= %s', $from . ' 00:00:00' );
    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) )   $where[] = $wpdb->prepare( 'created_at <= %s', $to . ' 23:59:59' );
    if ( $action )                                       $where[] = $wpdb->prepare( 'action = %s', $action );
    
    $where_sql = implode( ' AND ', $where );

    // Total count for pagination calculation
    $total_rows = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_audit_log WHERE {$where_sql}" );

    // Limit and Offset setup
    if ( $per_page === 'all' ) {
        $limit_sql = '';
    } else {
        $limit_num = intval( $per_page );
        if ( ! in_array( $limit_num, [ 25, 50, 100, 150 ] ) ) {
            $limit_num = 25;
        }
        $offset    = ( $paged - 1 ) * $limit_num;
        $limit_sql = $wpdb->prepare( " LIMIT %d OFFSET %d", $limit_num, $offset );
    }
    
    $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}qrrs_audit_log WHERE {$where_sql} ORDER BY id DESC {$limit_sql}" );
    $actions = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT action FROM {$wpdb->prefix}qrrs_audit_log WHERE restaurant_id = %d", $rid ) );
    
    // Base URL for links retaining filter state
    $base_params = [ 'tab' => 'audit', 'aud_from' => $from, 'aud_to' => $to, 'aud_action' => $action, 'aud_per_page' => $per_page ];
    $base = add_query_arg( $base_params, home_url( '/restaurant-dashboard/' ) );
    
    // Pagination calculation
    $total_pages = ( $per_page === 'all' || intval( $per_page ) <= 0 ) ? 1 : ceil( $total_rows / intval( $per_page ) );
    ?>
    <div class="qrrs-audit-wrapper">
        
        <!-- Header Section -->
        <div class="qrrs-audit-header">
            <div>
                <h2>🛡️ Advanced Change Tracker</h2>
                <p>Track absolute security actions, user activities, and live changes.</p>
            </div>
            <div class="qrrs-audit-badge">
                Total Records: <?php echo intval( $total_rows ); ?>
            </div>
        </div>

        <!-- Filter & Per-Page Form -->
        <form method="get" class="qrrs-audit-form">
            <input type="hidden" name="tab" value="audit">
            <input type="hidden" name="aud_page" value="1"> <!-- Reset to page 1 on new filter -->
            
            <div class="qrrs-form-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)) 130px 140px;">
                <div>
                    <label>From Date</label>
                    <input type="date" name="aud_from" value="<?php echo esc_attr( $from ); ?>">
                </div>
                
                <div>
                    <label>To Date</label>
                    <input type="date" name="aud_to" value="<?php echo esc_attr( $to ); ?>">
                </div>
                
                <div>
                    <label>Action Type</label>
                    <select name="aud_action">
                        <option value="">⚡ All Actions</option>
                        <?php foreach ( $actions as $a ) : ?>
                            <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $action, $a ); ?>><?php echo esc_html( qrrs_audit_action_label( $a ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Rows per page</label>
                    <select name="aud_per_page">
                        <?php foreach ( ['25', '50', '100', '150', 'all'] as $val ) : ?>
                            <option value="<?php echo $val; ?>" <?php selected( $per_page, $val ); ?>><?php echo ($val === 'all') ? 'All' : $val; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="qrrs-form-actions">
                    <button type="submit" class="qrrs-btn-primary">Filter</button>
                    <a href="<?php echo esc_url( add_query_arg( ['tab' => 'audit'], home_url( '/restaurant-dashboard/' ) ) ); ?>" class="qrrs-btn-secondary">Reset</a>
                </div>
            </div>
        </form>

        <!-- Data Table Container -->
        <div class="qrrs-table-container">
            <div class="qrrs-table-responsive">
                <table class="qrrs-audit-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User / Actor</th>
                            <th>Action Type</th>
                            <th>Target Entity</th>
                            <th>Details & Context</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    if ( ! empty( $rows ) ) :
                        foreach ( $rows as $r ) : 
                            $d = json_decode( (string) $r->details, true ) ?: [];
                            $action_raw = $r->action;
                            $badge_class = 'badge-default';
                            if(strpos($action_raw, 'cancelled') !== false || strpos($action_raw, 'deleted') !== false) { $badge_class = 'badge-danger'; }
                            elseif(strpos($action_raw, 'added') !== false) { $badge_class = 'badge-success'; }
                            elseif(strpos($action_raw, 'updated') !== false) { $badge_class = 'badge-warning'; }
                    ?>
                        <tr>
                            <td class="col-time">
                                <div class="time-primary"><?php echo esc_html( date('M j, Y', strtotime($r->created_at)) ); ?></div>
                                <div class="time-secondary"><?php echo esc_html( date('h:i:s A', strtotime($r->created_at)) ); ?></div>
                            </td>
                            <td class="col-user">
                                <div class="user-info">
                                    <span class="user-avatar"><?php echo strtoupper(substr($r->user_name, 0, 1)); ?></span>
                                    <span class="user-name"><?php echo esc_html( $r->user_name ); ?></span>
                                </div>
                            </td>
                            <td class="col-action">
                                <span class="qrrs-badge <?php echo $badge_class; ?>">
                                    <?php echo esc_html( qrrs_audit_action_label( $r->action ) ); ?>
                                </span>
                            </td>
                            <td class="col-entity">
                                <?php if($r->entity): ?>
                                    <code><?php echo esc_html( ucfirst($r->entity) . ' #' . $r->entity_id ); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-details">
                                <?php 
                                if ( ! empty( $d ) ) {
                                    echo '<div class="details-pills-wrap">';
                                    foreach ( $d as $k => $v ) {
                                        $val_str = is_scalar( $v ) ? $v : wp_json_encode( $v );
                                        echo '<span class="detail-pill"><strong>' . esc_html($k) . ':</strong> ' . esc_html($val_str) . '</span>';
                                    }
                                    echo '</div>';
                                } else {
                                    echo '<span class="text-muted">No metadata</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5" class="qrrs-empty-state">
                                <div class="empty-icon">🔍</div>
                                <div class="empty-title">No audit trail entries found</div>
                                <div class="empty-subtitle">Try adjusting your date range or action filters.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Footer Controls -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="qrrs-pagination-bar">
                    <span class="pagination-info">Page <?php echo $paged; ?> of <?php echo $total_pages; ?></span>
                    <div class="pagination-links">
                        <?php if ( $paged > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'aud_page' => $paged - 1 ], $base ) ); ?>" class="page-btn">Previous</a>
                        <?php endif; ?>
                        
                        <?php 
                        // Show smart page numbers links
                        for ( $i = max( 1, $paged - 2 ); $i <= min( $total_pages, $paged + 2 ); $i++ ) : 
                        ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'aud_page' => $i ], $base ) ); ?>" class="page-btn <?php echo ($i === $paged) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ( $paged < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'aud_page' => $paged + 1 ], $base ) ); ?>" class="page-btn">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </div>
    <?php
}