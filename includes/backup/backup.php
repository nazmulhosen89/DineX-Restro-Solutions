<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Backup (administrator only).
 *  - Exports every qrrs_ table and the plugin's settings to one JSON file.
 *  - Restore is a separate step, added after this backup is tested.
 */

function qrrs_backup_tables() {
    global $wpdb;
    $like = $wpdb->esc_like( $wpdb->prefix . 'qrrs_' ) . '%';
    return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
}

function qrrs_backup_options() {
    global $wpdb;
    $out = [];
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( 'qrrs_' ) . '%'
    ) );
    foreach ( $rows as $r ) {
        $out[ $r->option_name ] = maybe_unserialize( $r->option_value );
    }
    return $out;
}

add_action( 'admin_post_qrrs_backup_export', function () {
    if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) {
        wp_die( 'Only the administrator can download a backup.' );
    }
    check_admin_referer( 'qrrs_backup_export' );
    global $wpdb;
    if ( function_exists( 'qrrs_audit' ) ) { qrrs_audit( 'backup_downloaded', 'backup', 0, [], 0 ); }

    nocache_headers();
    $file = 'dinex-backup-' . current_time( 'Ymd-His' ) . '.json';
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $file . '"' );

    echo '{"plugin":"dinex-restro","version":' . wp_json_encode( defined( 'QRRS_SCHEMA_VERSION' ) ? QRRS_SCHEMA_VERSION : '' ) . ',';
    echo '"created_at":' . wp_json_encode( current_time( 'mysql' ) ) . ',';
    echo '"site":' . wp_json_encode( home_url() ) . ',';
    echo '"options":' . wp_json_encode( qrrs_backup_options() ) . ',';
    echo '"tables":{';
    $first = true;
    foreach ( qrrs_backup_tables() as $table ) {
        $rows = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );
        echo ( $first ? '' : ',' ) . wp_json_encode( $table ) . ':' . wp_json_encode( $rows );
        $first = false;
    }
    echo '}}';
    exit;
} );

function qrrs_backup_render() {
    if ( ! current_user_can( 'administrator' ) ) {
        echo '<div class="qrrs-card" style="padding:20px;">Backup is for the administrator only.</div>';
        return;
    }
    $tables = qrrs_backup_tables();
    $total  = 0;
    global $wpdb;
    foreach ( $tables as $t ) {
        $total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" );
    }
    $url = wp_nonce_url( admin_url( 'admin-post.php?action=qrrs_backup_export' ), 'qrrs_backup_export' );
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; max-width:720px;">
        <h3 style="margin:0 0 6px; font-size:20px; color:#1e293b;">Backup</h3>
        <p style="color:#64748b; font-size:14px; margin:0 0 18px;">Download a full copy of your restaurant data and settings. Keep this file in a safe place.</p>
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px;">
            <div style="background:#f8fafc; border-radius:10px; padding:12px 16px;"><small style="color:#64748b;">Tables</small><br><strong><?php echo count( $tables ); ?></strong></div>
            <div style="background:#f8fafc; border-radius:10px; padding:12px 16px;"><small style="color:#64748b;">Total records</small><br><strong><?php echo number_format( $total ); ?></strong></div>
        </div>
        <a href="<?php echo esc_url( $url ); ?>" style="display:inline-flex; align-items:center; gap:8px; background:#2563eb; color:#fff; padding:12px 20px; border-radius:10px; font-weight:600; text-decoration:none;">⬇ Download backup (JSON)</a>
        <p style="color:#94a3b8; font-size:12px; margin:16px 0 0;">Restore from this file will be added in the next step, after you have checked a backup file.</p>
    </div>
    <?php
}