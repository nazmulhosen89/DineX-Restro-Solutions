<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore (administrator only).
 *  1. Upload a backup file. It is checked, not applied yet.
 *  2. Preview: site, date, number of tables and records.
 *  3. Type RESTORE to confirm.
 *  4. The current data is saved first as a "pre-restore" backup (downloadable).
 *  5. The backup is applied in one transaction. Any error rolls everything back.
 */

function qrrs_private_dir() {
    $up  = wp_upload_dir();
    $dir = trailingslashit( $up['basedir'] ) . 'qrrs-private/backups';
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    if ( ! file_exists( $dir . '/.htaccess' ) ) {
        file_put_contents( $dir . '/.htaccess', "Deny from all\nRequire all denied\n" );
    }
    if ( ! file_exists( $dir . '/index.php' ) ) {
        file_put_contents( $dir . '/index.php', "<?php // silence\n" );
    }
    return $dir;
}

/** Full backup as an array (same shape as the export file). */
function qrrs_backup_payload() {
    global $wpdb;
    $tables = [];
    foreach ( qrrs_backup_tables() as $t ) {
        $tables[ $t ] = $wpdb->get_results( "SELECT * FROM `$t`", ARRAY_A );
    }
    return [
        'plugin'     => 'dinex-restro',
        'version'    => defined( 'QRRS_SCHEMA_VERSION' ) ? QRRS_SCHEMA_VERSION : '',
        'created_at' => current_time( 'mysql' ),
        'site'       => home_url(),
        'options'    => qrrs_backup_options(),
        'tables'     => $tables,
    ];
}

/** Validate a decoded backup. Returns an error message or '' when valid. */
function qrrs_backup_validate( $data ) {
    global $wpdb;
    if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'dinex-restro' ) {
        return 'This is not a DineX backup file.';
    }
    if ( ! isset( $data['tables'] ) || ! is_array( $data['tables'] ) || empty( $data['tables'] ) ) {
        return 'The backup has no tables.';
    }
    foreach ( array_keys( $data['tables'] ) as $t ) {
        if ( strpos( $t, $wpdb->prefix . 'qrrs_' ) !== 0 ) {
            return 'The backup contains an unexpected table name.';
        }
    }
    return '';
}

/** Apply a validated backup in one transaction. Returns true or an error message. */
function qrrs_restore_apply( $data ) {
    global $wpdb;
    $wpdb->query( 'START TRANSACTION' );
    try {
        foreach ( $data['tables'] as $table => $rows ) {
            $cols = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" );
            if ( empty( $cols ) ) {
                continue; // table not present on this install
            }
            if ( false === $wpdb->query( "DELETE FROM `$table`" ) ) {
                throw new Exception( 'Could not clear ' . $table . ': ' . $wpdb->last_error );
            }
            foreach ( (array) $rows as $row ) {
                $clean = array_intersect_key( (array) $row, array_flip( $cols ) );
                if ( empty( $clean ) ) continue;
                if ( false === $wpdb->insert( $table, $clean ) ) {
                    throw new Exception( 'Could not write a row to ' . $table . ': ' . $wpdb->last_error );
                }
            }
        }
        $wpdb->query( 'COMMIT' );
    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        return $e->getMessage();
    }
    // Settings are written after the data is safely committed
    foreach ( (array) ( $data['options'] ?? [] ) as $name => $value ) {
        if ( strpos( $name, 'qrrs_' ) === 0 ) {
            update_option( $name, $value, false );
        }
    }
    return true;
}

/** Step 1: upload and check. */
add_action( 'admin_post_qrrs_restore_upload', function () {
    if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) wp_die( 'Only the administrator can restore.' );
    check_admin_referer( 'qrrs_restore_upload' );
    $back = add_query_arg( [ 'tab' => 'backup' ], home_url( '/restaurant-dashboard/' ) );
    $f = $_FILES['backup_file'] ?? null;
    if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] ) {
        wp_safe_redirect( add_query_arg( 'restore_status', 'upload_failed', $back ) ); exit;
    }
    if ( 'json' !== strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) || (int) $f['size'] > 200 * 1024 * 1024 ) {
        wp_safe_redirect( add_query_arg( 'restore_status', 'bad_file', $back ) ); exit;
    }
    $data = json_decode( (string) file_get_contents( $f['tmp_name'] ), true );
    $err  = qrrs_backup_validate( $data );
    if ( $err ) {
        set_transient( 'qrrs_restore_error_' . get_current_user_id(), $err, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( 'restore_status', 'invalid', $back ) ); exit;
    }
    $name = 'pending-' . get_current_user_id() . '-' . time() . '.json';
    move_uploaded_file( $f['tmp_name'], qrrs_private_dir() . '/' . $name ) || copy( $f['tmp_name'], qrrs_private_dir() . '/' . $name );
    set_transient( 'qrrs_restore_pending_' . get_current_user_id(), $name, 30 * MINUTE_IN_SECONDS );
    wp_safe_redirect( add_query_arg( 'restore_status', 'preview', $back ) ); exit;
} );

/** Step 2: confirm, save current data, apply. */
add_action( 'admin_post_qrrs_restore_run', function () {
    if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) wp_die( 'Only the administrator can restore.' );
    check_admin_referer( 'qrrs_restore_run' );
    $uid  = get_current_user_id();
    $back = add_query_arg( [ 'tab' => 'backup' ], home_url( '/restaurant-dashboard/' ) );
    $name = get_transient( 'qrrs_restore_pending_' . $uid );
    $confirm = trim( (string) ( $_POST['confirm_text'] ?? '' ) );
    if ( ! $name || 'RESTORE' !== $confirm ) {
        wp_safe_redirect( add_query_arg( 'restore_status', 'not_confirmed', $back ) ); exit;
    }
    $path = qrrs_private_dir() . '/' . basename( $name );
    $data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
    if ( qrrs_backup_validate( $data ) ) {
        wp_safe_redirect( add_query_arg( 'restore_status', 'invalid', $back ) ); exit;
    }
    // Save the current state first, so the restore can be undone
    $pre = 'pre-restore-' . current_time( 'Ymd-His' ) . '.json';
    file_put_contents( qrrs_private_dir() . '/' . $pre, wp_json_encode( qrrs_backup_payload() ) );
    set_transient( 'qrrs_restore_prefile_' . $uid, $pre, 60 * MINUTE_IN_SECONDS );

    $result = qrrs_restore_apply( $data );
    delete_transient( 'qrrs_restore_pending_' . $uid );
    @unlink( $path );
    if ( true !== $result ) {
        set_transient( 'qrrs_restore_error_' . $uid, $result, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( 'restore_status', 'failed', $back ) ); exit;
    }
    wp_safe_redirect( add_query_arg( 'restore_status', 'done', $back ) ); exit;
} );

/** Download a pre-restore backup (name checked against the private folder). */
add_action( 'admin_post_qrrs_backup_file', function () {
    if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) wp_die( 'Not allowed.' );
    check_admin_referer( 'qrrs_backup_file' );
    $name = basename( (string) ( $_GET['file'] ?? '' ) );
    $path = qrrs_private_dir() . '/' . $name;
    if ( ! preg_match( '/^pre-restore-[0-9\-]+\.json$/', $name ) || ! is_file( $path ) ) wp_die( 'File not found.' );
    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $name . '"' );
    readfile( $path );
    exit;
} );

/** Restore section on the Backup page. */
function qrrs_restore_render() {
    if ( ! current_user_can( 'administrator' ) ) return;
    $uid    = get_current_user_id();
    $status = sanitize_key( $_GET['restore_status'] ?? '' );
    $msgs = [
        'upload_failed' => [ 'err', 'Upload failed. Please choose the file again.' ],
        'bad_file'      => [ 'err', 'Please choose a .json backup file.' ],
        'invalid'       => [ 'err', 'This file is not a valid DineX backup.' ],
        'not_confirmed' => [ 'err', 'Restore was not started. You must type RESTORE exactly.' ],
        'failed'        => [ 'err', 'Restore failed. Nothing was changed.' ],
        'done'          => [ 'ok',  'Restore completed. The data is now from the backup.' ],
    ];
    if ( isset( $msgs[ $status ] ) ) {
        [ $k, $txt ] = $msgs[ $status ];
        $err_detail = get_transient( 'qrrs_restore_error_' . $uid );
        echo '<div style="background:' . ( 'ok' === $k ? '#ecfdf5;color:#166534' : '#fef2f2;color:#991b1b' ) . '; padding:12px 16px; border-radius:8px; margin-bottom:16px;">' . esc_html( $txt );
        if ( $err_detail && in_array( $status, [ 'failed', 'invalid' ], true ) ) echo '<br><small>' . esc_html( $err_detail ) . '</small>';
        echo '</div>';
        delete_transient( 'qrrs_restore_error_' . $uid );
    }
    $pre = get_transient( 'qrrs_restore_prefile_' . $uid );
    $pending = get_transient( 'qrrs_restore_pending_' . $uid );
    $lbl = 'display:block; font-weight:700; font-size:13px; color:#1e293b; margin-bottom:6px;';
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; max-width:720px; margin-top:24px;">
        <h3 style="margin:0 0 6px; font-size:20px; color:#1e293b;">Restore</h3>
        <p style="color:#64748b; font-size:14px; margin:0 0 16px;">Restore replaces the restaurant data with the data in a backup file. Records that are not in the backup stay as they are.</p>

        <?php if ( $pending && is_file( qrrs_private_dir() . '/' . basename( $pending ) ) ) :
            $pdata = json_decode( (string) file_get_contents( qrrs_private_dir() . '/' . basename( $pending ) ), true );
            $rec = 0; foreach ( (array) ( $pdata['tables'] ?? [] ) as $rows ) { $rec += count( (array) $rows ); }
        ?>
            <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:16px; margin-bottom:16px;">
                <strong style="color:#92400e;">Check before you restore</strong>
                <ul style="margin:8px 0 0 18px; font-size:14px; color:#334155;">
                    <li>Backup from: <?php echo esc_html( $pdata['site'] ?? '' ); ?></li>
                    <li>Created: <?php echo esc_html( $pdata['created_at'] ?? '' ); ?></li>
                    <li>Tables: <?php echo count( (array) $pdata['tables'] ); ?>, records: <?php echo number_format( $rec ); ?></li>
                </ul>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="qrrs_restore_run">
                <?php wp_nonce_field( 'qrrs_restore_run' ); ?>
                <label style="<?php echo $lbl; ?>">Type RESTORE to confirm</label>
                <input type="text" name="confirm_text" autocomplete="off" required style="width:260px; padding:10px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:12px;">
                <br>
                <button type="submit" style="background:#dc2626; color:#fff; border:none; padding:12px 20px; border-radius:10px; font-weight:700; cursor:pointer;">Restore now</button>
            </form>
        <?php else : ?>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="qrrs_restore_upload">
                <?php wp_nonce_field( 'qrrs_restore_upload' ); ?>
                <label style="<?php echo $lbl; ?>">Choose a backup file (.json)</label>
                <input type="file" name="backup_file" accept=".json,application/json" required style="margin-bottom:12px;">
                <br>
                <button type="submit" style="background:#2563eb; color:#fff; border:none; padding:12px 20px; border-radius:10px; font-weight:600; cursor:pointer;">Check file</button>
            </form>
        <?php endif; ?>

        <?php if ( $pre ) : ?>
            <div style="margin-top:20px; font-size:13px; color:#334155;">
                Data saved before the last restore:
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=qrrs_backup_file&file=' . rawurlencode( $pre ) ), 'qrrs_backup_file' ) ); ?>">⬇ Download</a>
            </div>
        <?php endif; ?>
    </div>
    <?php
}