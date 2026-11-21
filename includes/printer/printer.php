<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Printer settings (per restaurant), stored as JSON in pos_printer_settings.
 *  - Paper width (58mm or 80mm), copies, footer message.
 *  - Automatic printing of KOT and bill.
 *  - Connection: system printer, USB printer (Windows name) or LAN printer (IP and port).
 *  - Cash drawer connected or not.
 *  The Windows POS app reads these settings, so the customer sets everything here only.
 *  Old values (thermal_80mm, thermal_58mm, browser, network) still work.
 */

function qrrs_printer_defaults() {
    return [
        'paper'     => '80',
        'copies'    => 1,
        'footer'    => 'Thank You for Visiting!',
        'auto_kot'  => 0,
        'auto_bill' => 0,
        'conn'      => 'system',
        'usb_name'  => '',
        'ip'        => '',
        'port'      => 9100,
        'drawer'    => 0,
    ];
}

function qrrs_printer_get( $restaurant_id ) {
    global $wpdb;
    $raw = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT pos_printer_settings FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", intval( $restaurant_id )
    ) );
    $d = qrrs_printer_defaults();
    if ( '' === $raw ) return $d;
    $j = json_decode( $raw, true );
    if ( is_array( $j ) ) {
        $m = array_merge( $d, $j );
        if ( 'browser' === $m['conn'] ) $m['conn'] = 'system';
        if ( 'network' === $m['conn'] ) $m['conn'] = 'lan';
        if ( ! in_array( $m['conn'], [ 'system', 'usb', 'lan' ], true ) ) $m['conn'] = 'system';
        return $m;
    }
    if ( false !== strpos( $raw, '58' ) ) $d['paper'] = '58';
    return $d;
}

/** Turns the submitted form into the stored JSON. */
function qrrs_printer_to_json( $d ) {
    $paper = ( isset( $d['printer_paper'] ) && '58' === $d['printer_paper'] ) ? '58' : '80';
    $conn  = $d['printer_conn'] ?? 'system';
    if ( ! in_array( $conn, [ 'system', 'usb', 'lan' ], true ) ) $conn = 'system';
    return wp_json_encode( [
        'paper'     => $paper,
        'copies'    => max( 1, min( 3, absint( $d['printer_copies'] ?? 1 ) ) ),
        'footer'    => substr( sanitize_textarea_field( $d['printer_footer'] ?? '' ), 0, 500 ),
        'auto_kot'  => isset( $d['printer_auto_kot'] ) ? 1 : 0,
        'auto_bill' => isset( $d['printer_auto_bill'] ) ? 1 : 0,
        'conn'      => $conn,
        'usb_name'  => sanitize_text_field( $d['printer_usb_name'] ?? '' ),
        'ip'        => sanitize_text_field( $d['printer_ip'] ?? '' ),
        'port'      => max( 1, absint( $d['printer_port'] ?? 9100 ) ),
        'drawer'    => isset( $d['printer_drawer'] ) ? 1 : 0,
    ] );
}

/** The form fields, used on Create and Edit restaurant. */
function qrrs_printer_fields_html( $restaurant_id = 0 ) {
    $s   = qrrs_printer_get( $restaurant_id );
    $inp = 'width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box;';
    ob_start(); ?>
    <div style="border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-top:6px;">
        <strong style="font-size:15px; color:#1e293b;">Printer settings</strong>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-top:12px;">
            <div>
                <label style="font-size:12px; font-weight:700;">Paper width</label>
                <select name="printer_paper" style="<?php echo $inp; ?>">
                    <option value="80" <?php selected( $s['paper'], '80' ); ?>>80 mm</option>
                    <option value="58" <?php selected( $s['paper'], '58' ); ?>>58 mm</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px; font-weight:700;">Copies</label>
                <select name="printer_copies" style="<?php echo $inp; ?>">
                    <?php for ( $i = 1; $i <= 3; $i++ ) : ?><option value="<?php echo $i; ?>" <?php selected( (int) $s['copies'], $i ); ?>><?php echo $i; ?></option><?php endfor; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px; font-weight:700;">Printer connection</label>
                <select name="printer_conn" style="<?php echo $inp; ?>">
                    <option value="system" <?php selected( $s['conn'], 'system' ); ?>>System printer (any model)</option>
                    <option value="usb" <?php selected( $s['conn'], 'usb' ); ?>>USB printer</option>
                    <option value="lan" <?php selected( $s['conn'], 'lan' ); ?>>LAN / network printer</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px; font-weight:700;">Windows printer name (USB)</label>
                <input type="text" name="printer_usb_name" value="<?php echo esc_attr( $s['usb_name'] ); ?>" placeholder="e.g. POS-80 or XP-80C" style="<?php echo $inp; ?>">
            </div>
            <div>
                <label style="font-size:12px; font-weight:700;">Printer IP (LAN)</label>
                <input type="text" name="printer_ip" value="<?php echo esc_attr( $s['ip'] ); ?>" placeholder="192.168.1.50" style="<?php echo $inp; ?>">
            </div>
            <div>
                <label style="font-size:12px; font-weight:700;">Port (LAN)</label>
                <input type="number" name="printer_port" value="<?php echo esc_attr( $s['port'] ); ?>" style="<?php echo $inp; ?>">
            </div>
            <div style="grid-column:1 / -1;">
                <label style="font-size:12px; font-weight:700;">Footer message (bill and token, multiple lines allowed)</label>
                <textarea name="printer_footer" rows="4" maxlength="500" style="<?php echo $inp; ?> resize:vertical;"><?php echo esc_textarea( $s['footer'] ); ?></textarea>
                <div style="font-size:11px; color:#64748b; margin-top:4px;">Shown above "Developed for ..." and "Proudly powered by DineX Restro Solutions", which always stay at the bottom.</div>
            </div>
        </div>
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:12px; font-size:13px;">
            <label><input type="checkbox" name="printer_auto_kot" value="1" <?php checked( (int) $s['auto_kot'], 1 ); ?>> Print KOT automatically for new orders</label>
            <label><input type="checkbox" name="printer_auto_bill" value="1" <?php checked( (int) $s['auto_bill'], 1 ); ?>> Print bill automatically after settle</label>
            <label><input type="checkbox" name="printer_drawer" value="1" <?php checked( (int) $s['drawer'], 1 ); ?>> Cash drawer is connected to the printer</label>
        </div>
        <p style="font-size:12px; color:#64748b; margin:10px 0 0;">USB and LAN printing and the cash drawer work with the DineX POS app on the counter computer. "System printer" works with any printer through the browser.</p>
        <?php if ( intval( $restaurant_id ) > 0 ) : ?>
            <button type="button" onclick="qrrsTestPrint(<?php echo intval( $restaurant_id ); ?>)" style="margin-top:12px; background:#fff; border:1px solid #2563eb; color:#2563eb; padding:8px 14px; border-radius:8px; font-weight:600; cursor:pointer;">Test print</button>
        <?php endif; ?>
    </div>
    <?php if ( intval( $restaurant_id ) > 0 ) : ?>
    <script>
    function qrrsTestPrint(rid) {
        jQuery.post(qrrs_vars.ajax_url, { action: 'qrrs_printer_test', restaurant_id: rid, security: qrrs_vars.qr_nonce }, function (res) {
            if (res && res.success) {
                var w = window.open('', '_blank');
                w.document.write(res.data.html);
                w.document.close();
            } else {
                alert((res && res.data) ? res.data : 'Could not print the test page.');
            }
        });
    }
    </script>
    <?php endif;
    return ob_get_clean();
}

/** CSS for the paper width, used by every printed page. */
function qrrs_print_css( $paper ) {
    $p  = ( '58' === $paper ) ? '58' : '80';
    $cw = ( '58' === $p ) ? 52 : 72;
    return "@page { size: {$p}mm auto; margin: 2mm; } body { width: {$cw}mm; font-family: monospace; font-size: 13px; color:#000; }";
}

add_action( 'wp_ajax_qrrs_printer_test', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in again.' );
    global $wpdb;
    $rid  = intval( $_POST['restaurant_id'] ?? 0 );
    $s    = qrrs_printer_get( $rid );
    $name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT restaurant_name FROM {$wpdb->prefix}qrrs_restaurants WHERE id = %d", $rid ) );
    $html = '<html><head><meta charset="utf-8"><style>' . qrrs_print_css( $s['paper'] ) . ' .c{text-align:center}</style></head><body>'
        . '<div class="c"><strong>TEST PRINT</strong></div><div class="c">' . esc_html( $name ) . '</div>'
        . '<div class="c">Paper: ' . esc_html( $s['paper'] ) . ' mm</div><div class="c">' . esc_html( current_time( 'd M Y, h:i A' ) ) . '</div>'
        . '<hr><div>Item 1 ......... 100.00</div><div>Item 2 ......... 50.00</div><hr>'
        . '<div class="c">' . nl2br( esc_html( $s['footer'] ) ) . '</div>'
        . '<script>window.onload=function(){setTimeout(function(){window.print();},300);};</script></body></html>';
    wp_send_json_success( [ 'html' => $html ] );
} );