<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Online & Delivery settings (admin only).
 *  - Services: dine-in, take-away, delivery, room service (on/off)
 *  - Online ordering on/off and a private order token (used by the public order page)
 *  - Business hours per weekday (online orders only open inside these hours)
 *  - Delivery areas with charge and minimum order (admin adds / edits / disables them)
 * Delivery charge is never taxed; VAT applies to food only.
 */

function qrrs_online_services() {
    return [
        'dine_in'      => 'Dine-in',
        'take_out'     => 'Take-away',
        'delivery'     => 'Delivery',
        'room_service' => 'Room service',
        'self_service' => 'Self-service (pay at counter)',
    ];
}

function qrrs_online_day_names() {
    return [ 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' ];
}

/** Active services of a restaurant, e.g. [ 'dine_in' => true, ... ]. Defaults: dine-in and take-away on. */
function qrrs_online_services_for( $restaurant_id ) {
    global $wpdb;
    $json = $wpdb->get_var( $wpdb->prepare( "SELECT services_json FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", intval( $restaurant_id ) ) );
    $saved = $json ? (array) json_decode( $json, true ) : [];
    $out = [];
    foreach ( qrrs_online_services() as $key => $label ) {
        $out[ $key ] = array_key_exists( $key, $saved ) ? (bool) $saved[ $key ] : in_array( $key, [ 'dine_in', 'take_out' ], true );
    }
    return $out;
}

/** Saves the form. Returns a message key. */
function qrrs_online_save( $restaurant_id ) {
    global $wpdb;
    if ( function_exists( 'qrrs_audit' ) && ! empty( $_POST['online_action'] ) === false ) { qrrs_audit( 'online_settings_saved', 'restaurant', intval( $restaurant_id ), [], intval( $restaurant_id ) ); }
    $r_t = $wpdb->prefix . 'qrrs_restaurants';
    $h_t = $wpdb->prefix . 'qrrs_business_hours';
    $a_t = $wpdb->prefix . 'qrrs_delivery_areas';
    $rid = intval( $restaurant_id );
    $action = sanitize_key( $_POST['online_action'] ?? '' );

    if ( ! isset( $_POST['online_nonce'] ) || ! wp_verify_nonce( $_POST['online_nonce'], 'qrrs_online_save' ) ) {
        wp_die( 'Security check failed' );
    }

    if ( 'settings' === $action ) {
        $services = [];
        foreach ( qrrs_online_services() as $key => $label ) {
            $services[ $key ] = ! empty( $_POST['service'][ $key ] ) ? 1 : 0;
        }
        $online = ! empty( $_POST['online_enabled'] ) ? 1 : 0;
        qrrs_otp_save_mode( $rid, sanitize_key( $_POST['otp_mode'] ?? 'off' ) );
        $row    = $wpdb->get_row( $wpdb->prepare( "SELECT online_token FROM $r_t WHERE id = %d", $rid ) );
        $token  = $row ? $row->online_token : '';
        if ( $online && '' === $token ) {
            $token = wp_generate_password( 32, false, false );
        }
        $wpdb->update( $r_t, [
            'services_json'  => wp_json_encode( $services ),
            'online_enabled' => $online,
            'online_token'   => $token,
        ], [ 'id' => $rid ] );

        // Business hours (7 rows)
        foreach ( qrrs_online_day_names() as $dow => $name ) {
            $closed = ! empty( $_POST['closed'][ $dow ] ) ? 1 : 0;
            $open   = preg_match( '/^\d{2}:\d{2}$/', $_POST['open'][ $dow ] ?? '' ) ? $_POST['open'][ $dow ] . ':00' : null;
            $close  = preg_match( '/^\d{2}:\d{2}$/', $_POST['close'][ $dow ] ?? '' ) ? $_POST['close'][ $dow ] . ':00' : null;
            if ( ! $closed && ( ! $open || ! $close ) ) {
                return 'hours_required';
            }
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $h_t (restaurant_id, day_of_week, open_time, close_time, is_closed) VALUES (%d, %d, %s, %s, %d)
                 ON DUPLICATE KEY UPDATE open_time = VALUES(open_time), close_time = VALUES(close_time), is_closed = VALUES(is_closed)",
                $rid, $dow, $open, $close, $closed
            ) );
        }
        return 'settings_saved';
    }

    if ( 'add_area' === $action ) {
        $name = sanitize_text_field( wp_unslash( $_POST['area_name'] ?? '' ) );
        $charge = floatval( $_POST['charge'] ?? -1 );
        $min    = floatval( $_POST['min_order'] ?? 0 );
        if ( '' === trim( $name ) ) return 'area_name_required';
        if ( $charge < 0 || $min < 0 ) return 'area_value_invalid';
        $wpdb->insert( $a_t, [ 'restaurant_id' => $rid, 'area_name' => $name, 'charge' => round( $charge, 2 ), 'min_order' => round( $min, 2 ), 'status' => 'active' ] );
        return 'area_saved';
    }

    if ( 'update_area' === $action ) {
        $aid    = intval( $_POST['area_id'] ?? 0 );
        $charge = floatval( $_POST['charge'] ?? -1 );
        $min    = floatval( $_POST['min_order'] ?? 0 );
        $status = ( isset( $_POST['status'] ) && 'inactive' === $_POST['status'] ) ? 'inactive' : 'active';
        if ( $charge < 0 || $min < 0 ) return 'area_value_invalid';
        $wpdb->update( $a_t, [ 'charge' => round( $charge, 2 ), 'min_order' => round( $min, 2 ), 'status' => $status ], [ 'id' => $aid, 'restaurant_id' => $rid ] );
        return 'area_saved';
    }

    if ( 'regen_token' === $action ) {
        // Changing the token breaks old public links and QR codes, so it needs its own button
        $wpdb->update( $r_t, [ 'online_token' => wp_generate_password( 32, false, false ) ], [ 'id' => $rid ] );
        return 'token_changed';
    }

    return 'error';
}

function qrrs_online_render( $restaurant_id, $is_admin ) {
    global $wpdb;
    if ( ! $is_admin ) {
        echo '<div class="qrrs-card" style="padding:20px;">Only admins can change online and delivery settings.</div>';
        return;
    }
    $rid = intval( $restaurant_id );
    if ( ! $rid ) {
        echo '<div class="qrrs-card" style="padding:20px;">Select a restaurant first.</div>';
        return;
    }

    $msg = '';
    if ( isset( $_POST['online_action'] ) ) {
        $msg = qrrs_online_save( $rid );
    }
    $texts = [
        'settings_saved'     => [ 'ok',  'Settings saved.' ],
        'hours_required'     => [ 'err', 'Set opening and closing time for every open day, or tick "Closed".' ],
        'area_saved'         => [ 'ok',  'Delivery area saved.' ],
        'area_name_required' => [ 'err', 'Area name is required.' ],
        'area_value_invalid' => [ 'err', 'Charge and minimum order must be 0 or more.' ],
        'token_changed'      => [ 'ok',  'New link created. The old link and QR code will stop working.' ],
        'room_saved'         => [ 'ok',  'Room saved.' ],
        'room_regenerated'   => [ 'ok',  'New QR created for this room. The old QR will stop working.' ],
        'room_exists'        => [ 'err', 'This room number already exists.' ],
        'room_number_required' => [ 'err', 'Enter a room number.' ],
        'error'              => [ 'err', 'Something went wrong. Please try again.' ],
    ];
    if ( $msg && isset( $texts[ $msg ] ) ) {
        [ $k, $t ] = $texts[ $msg ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:6px; margin-bottom:12px;">' . esc_html( $t ) . '</div>';
    }

    $r = $wpdb->get_row( $wpdb->prepare( "SELECT online_enabled, online_token FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $rid ) );
    $services = qrrs_online_services_for( $rid );
    $hours = [];
    foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_business_hours WHERE restaurant_id = %d", $rid ) ) as $h ) {
        $hours[ intval( $h->day_of_week ) ] = $h;
    }
    $areas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_delivery_areas WHERE restaurant_id = %d ORDER BY area_name ASC", $rid ) );
    $base  = add_query_arg( [ 'tab' => 'online-settings' ], home_url( '/restaurant-dashboard/' ) );
    ?>
    <form method="post" action="<?php echo esc_url( $base ); ?>">
        <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
        <input type="hidden" name="online_action" value="settings">

        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <h3 style="margin-top:0;">Services</h3>
            <?php foreach ( qrrs_online_services() as $key => $label ) : ?>
                <label style="display:inline-flex; align-items:center; gap:8px; margin-right:24px;">
                    <input type="checkbox" name="service[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $services[ $key ] ); ?>>
                    <?php echo esc_html( $label ); ?>
                </label>
            <?php endforeach; ?>
            <p style="color:#64748b; font-size:12px; margin:10px 0 0;">Room service needs room QR codes, set up in a later step.</p>
        </div>

        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <h3 style="margin-top:0;">Online ordering</h3>
            <label style="display:flex; align-items:center; gap:8px; font-weight:600;">
                <input type="checkbox" name="online_enabled" value="1" <?php checked( (int) $r->online_enabled, 1 ); ?>>
                Accept orders from the public order page
            </label>
            <div style="margin-top:14px;">
                <label style="font-weight:600;">Mobile verification (OTP)</label><br>
                <select name="otp_mode" style="padding:6px 10px; margin-top:6px;">
                    <option value="off" <?php selected( qrrs_otp_mode( $rid ), 'off' ); ?>>Off</option>
                    <option value="test" <?php selected( qrrs_otp_mode( $rid ), 'test' ); ?>>Test mode (code shown on the page, no SMS)</option>
                </select>
                <p style="color:#64748b; font-size:12px; margin:6px 0 0;">SMS and WhatsApp providers can be added later. Test mode is for trying the flow only.</p>
            </div>
            <?php if ( $r && $r->online_token ) : ?>
                <p style="font-size:13px; margin:10px 0 0;"><strong>Delivery &amp; take-away link (not the self-service QR):</strong><br>
                <code style="word-break:break-all;"><?php echo esc_html( add_query_arg( 't', $r->online_token, home_url( '/online-order/' ) ) ); ?></code></p>
                <p style="font-size:12px; color:#64748b; margin:6px 0 0;">Create a WordPress page named <strong>online-order</strong> with the shortcode <code>[qrrs_online_order]</code>.</p>
                <?php
                $pub_url = add_query_arg( 't', $r->online_token, home_url( '/online-order/' ) );
                $qr_src  = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&margin=10&data=' . rawurlencode( $pub_url );
                ?>
                <div style="display:flex; gap:18px; align-items:center; flex-wrap:wrap; margin-top:14px;">
                    <img src="<?php echo esc_url( $qr_src ); ?>" alt="Order QR code" width="200" height="200" style="border:1px solid #e2e8f0; border-radius:12px; padding:8px; background:#fff;">
                    <div>
                        <p style="margin:0 0 10px; font-size:13px; color:#475569;">Print this QR and place it on tables or at the counter. Customers scan it to order online.</p>
                        <a href="<?php echo esc_url( $qr_src ); ?>" target="_blank" rel="noopener" download class="button">⬇ Download / open QR</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
            <h3 style="margin-top:0;">Business hours (online orders)</h3>
            <table style="width:100%; border-collapse:collapse;">
                <thead><tr style="background:#f1f5f9; text-align:left;"><th style="padding:8px;">Day</th><th style="padding:8px;">Open</th><th style="padding:8px;">Close</th><th style="padding:8px;">Closed</th></tr></thead>
                <tbody>
                <?php foreach ( qrrs_online_day_names() as $dow => $name ) :
                    $h = $hours[ $dow ] ?? null;
                    $is_closed = $h ? (int) $h->is_closed : 0;
                ?>
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:8px; font-weight:600;"><?php echo esc_html( $name ); ?></td>
                        <td style="padding:8px;"><input type="time" name="open[<?php echo $dow; ?>]" value="<?php echo $h && $h->open_time ? esc_attr( substr( $h->open_time, 0, 5 ) ) : '10:00'; ?>"></td>
                        <td style="padding:8px;"><input type="time" name="close[<?php echo $dow; ?>]" value="<?php echo $h && $h->close_time ? esc_attr( substr( $h->close_time, 0, 5 ) ) : '22:00'; ?>"></td>
                        <td style="padding:8px;"><input type="checkbox" name="closed[<?php echo $dow; ?>]" value="1" <?php checked( $is_closed, 1 ); ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="color:#64748b; font-size:12px; margin:10px 0 0;">Time is Bangladesh time (Asia/Dhaka). Outside these hours the public page shows "Closed now" with the next opening time.</p>
        </div>

        <button type="submit" class="qrrs-btn-save" style="margin-bottom:24px;">Save online & services</button>
    </form>

    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">Delivery areas</h3>
        <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px;">
            <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
            <input type="hidden" name="online_action" value="add_area">
            <input type="text" name="area_name" placeholder="Area name e.g. Dhanmondi" required style="min-width:220px; padding:6px 10px;">
            <input type="number" name="charge" step="0.01" min="0" placeholder="Delivery charge ৳" required style="width:160px; padding:6px 10px;">
            <input type="number" name="min_order" step="0.01" min="0" placeholder="Minimum order ৳ (0 = none)" value="0" style="width:200px; padding:6px 10px;">
            <button type="submit" class="button">+ Add area</button>
        </form>
        <table style="width:100%; border-collapse:collapse;">
            <thead><tr style="background:#f1f5f9; text-align:left;"><th style="padding:8px;">Area</th><th style="padding:8px;">Charge ৳</th><th style="padding:8px;">Minimum ৳</th><th style="padding:8px;">Status</th><th style="padding:8px;">Save</th></tr></thead>
            <tbody>
            <?php foreach ( $areas as $a ) : ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td style="padding:8px; font-weight:600;"><?php echo esc_html( $a->area_name ); ?></td>
                    <td colspan="4" style="padding:0;">
                        <form method="post" action="<?php echo esc_url( $base ); ?>" style="display:flex; gap:10px; align-items:center; padding:6px 8px; margin:0; flex-wrap:wrap;">
                            <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
                            <input type="hidden" name="online_action" value="update_area">
                            <input type="hidden" name="area_id" value="<?php echo intval( $a->id ); ?>">
                            <input type="number" name="charge" step="0.01" min="0" value="<?php echo esc_attr( $a->charge ); ?>" style="width:120px; padding:5px 8px;">
                            <input type="number" name="min_order" step="0.01" min="0" value="<?php echo esc_attr( $a->min_order ); ?>" style="width:140px; padding:5px 8px;">
                            <select name="status" style="padding:5px 8px;">
                                <option value="active" <?php selected( $a->status, 'active' ); ?>>Active</option>
                                <option value="inactive" <?php selected( $a->status, 'inactive' ); ?>>Inactive</option>
                            </select>
                            <button type="submit" class="button">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $areas ) ) : ?>
                <tr><td colspan="5" style="padding:14px; text-align:center; color:#94a3b8;">No delivery area yet. Add the areas you deliver to.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ( ! empty( $services['self_service'] ) && $r && $r->online_token ) :
        $self_url = add_query_arg( [ 't' => $r->online_token, 'self' => 1 ], home_url( '/online-order/' ) );
        $self_qr  = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' . rawurlencode( $self_url ); ?>
    <div class="qrrs-card" style="padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">Self-service QR (whole restaurant)</h3>
        <p style="font-size:13px; color:#64748b; margin:0 0 12px;">Customers scan, order and get a token number. They pay at the counter.</p>
        <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
            <img src="<?php echo esc_url( $self_qr ); ?>" alt="Self-service QR" width="180" height="180" style="border:1px solid #e2e8f0; border-radius:10px; padding:6px; background:#fff;">
            <a href="<?php echo esc_url( $self_qr ); ?>" target="_blank" rel="noopener" download class="button">⬇ Download / open QR</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="qrrs-card" style="padding:16px 20px; margin-bottom:20px;">
        <strong>Rooms</strong> are managed in the <em>Rooms</em> menu on the left.
    </div>

    <div class="qrrs-card" style="padding:20px;">
        <h3 style="margin-top:0;">Private link</h3>
        <p style="color:#64748b; font-size:13px; margin:0 0 10px;">Regenerate only if the link was shared by mistake. Old QR codes will stop working.</p>
        <form method="post" action="<?php echo esc_url( $base ); ?>" onsubmit="return confirm('Create a new link? Old QR codes will stop working.');">
            <?php wp_nonce_field( 'qrrs_online_save', 'online_nonce' ); ?>
            <input type="hidden" name="online_action" value="regen_token">
            <button type="submit" class="button" style="color:#dc2626;">Create new link</button>
        </form>
    </div>
    <?php
}