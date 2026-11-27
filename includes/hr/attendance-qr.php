<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * QR attendance.
 *  - The manager's Attendance page shows a QR code. A new QR is made every 55 seconds.
 *  - Each QR is valid for 60 seconds and works once.
 *  - The staff member logs in, opens Attendance and scans the QR with the camera.
 *  - First scan of the day = check in. Second scan = check out. Times are the server's time.
 */

define( 'QRRS_ATT_TTL', 60 );
define( 'QRRS_ATT_PREFIX', 'QRRS-ATT:' );

/** Manager: make a new one-time token for this restaurant. */
add_action( 'wp_ajax_qrrs_att_token', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() || ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) {
        wp_send_json_error( 'Only a manager can show the attendance QR.' );
    }
    $rid = intval( qrrs_inventory_get_active_restaurant_id() );
    if ( $rid <= 0 ) wp_send_json_error( 'Select a restaurant first.' );
    $token = wp_generate_password( 24, false, false );
    set_transient( 'qrrs_att_' . $token, $rid, QRRS_ATT_TTL );
    wp_send_json_success( [ 'token' => $token, 'ttl' => QRRS_ATT_TTL ] );
} );

/** Staff: scan a QR and record check in or check out. */
add_action( 'wp_ajax_qrrs_att_scan', function () {
    check_ajax_referer( 'qr_order_nonce', 'security' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in again.' );
    global $wpdb;
    $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
    $rid   = $token ? get_transient( 'qrrs_att_' . $token ) : false;
    if ( ! $rid ) wp_send_json_error( 'This QR has expired. Scan the new QR.' );
    delete_transient( 'qrrs_att_' . $token ); // one scan per QR
    $rid = intval( $rid );

    $me = function_exists( 'qrrs_my_staff_row' ) ? qrrs_my_staff_row( $rid ) : null;
    if ( ! $me ) wp_send_json_error( 'Your account is not linked to this restaurant.' );
    if ( ! function_exists( 'qrrs_attendance_enabled' ) || ! qrrs_attendance_enabled( $rid ) ) wp_send_json_error( 'Attendance is not on for this restaurant.' );

    $now   = current_time( 'mysql' );
    $today = current_time( 'Y-m-d' );
    $open  = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_attendance WHERE staff_id = %d AND work_date = %s ORDER BY id DESC LIMIT 1", $me->id, $today ) );

    if ( $open && empty( $open->check_out ) ) {
        $wpdb->update( $wpdb->prefix . 'qrrs_attendance', [ 'check_out' => $now, 'source' => 'qr' ], [ 'id' => $open->id ] );
        wp_send_json_success( [ 'msg' => 'Checked out at ' . wp_date( 'h:i A', strtotime( $now ) ) . '. Have a good rest!' ] );
    }
    if ( $open ) wp_send_json_error( 'You have already checked out today.' );

    $wpdb->insert( $wpdb->prefix . 'qrrs_attendance', [
        'restaurant_id' => $rid, 'staff_id' => $me->id, 'work_date' => $today, 'check_in' => $now, 'source' => 'qr' ] );
    wp_send_json_success( [ 'msg' => 'Checked in at ' . wp_date( 'h:i A', strtotime( $now ) ) . '.' ] );
} );

/** Manager panel: the QR code that staff scan. Shown only to a manager or admin. */
function qrrs_att_manager_qr_render( $restaurant_id ) {
    if ( ! function_exists( 'qrrs_inventory_can_manage' ) || ! qrrs_inventory_can_manage() ) return;
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:22px; margin-bottom:14px; display:flex; gap:22px; align-items:center; flex-wrap:wrap;">
        <div>
            <div style="font-size:16px; font-weight:800; color:#0f172a;">Attendance QR</div>
            <div style="font-size:13px; color:#64748b; margin:6px 0 10px; max-width:320px;">Show this on the counter screen. Staff open Attendance on their login and scan it. A new QR comes every minute.</div>
            <div id="att-qr-info" style="font-size:13px; color:#334155; font-weight:700;">Loading QR…</div>
        </div>
        <canvas id="att-qr-canvas" style="border:1px solid #e2e8f0; border-radius:12px; background:#fff;"></canvas>
    </div>
    <script>
    (function () {
        var cfg = { ajax: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce: <?php echo wp_json_encode( wp_create_nonce( 'qr_order_nonce' ) ); ?> };
        var canvas = document.getElementById('att-qr-canvas'), info = document.getElementById('att-qr-info');
        // draw the QR: the library if it loaded, otherwise an image from a QR service
        function draw(text, done) {
            if (window.QRCode && QRCode.toCanvas) {
                QRCode.toCanvas(canvas, text, { width: 260, margin: 1 }, function (err) { done(err); });
                return;
            }
            var img = new Image();
            img.width = 260; img.height = 260;
            img.onload = function () { canvas.parentNode.replaceChild(img, canvas); img.id = 'att-qr-canvas'; done(null); };
            img.onerror = function () { done('No QR picture could be loaded. Check the internet connection.'); };
            img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=1&data=' + encodeURIComponent(text);
        }
        function ensureLib(cb) {
            if (window.QRCode) { cb(); return; }
            var s = document.createElement('script');
            s.src = 'https://unpkg.com/qrcode@1.5.3/build/qrcode.min.js';
            s.onload = cb; s.onerror = cb;
            document.head.appendChild(s);
        }
        function load() {
            fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'qrrs_att_token', security: cfg.nonce }) })
                .then(function (r) { return r.text().then(function (t) { return { status: r.status, text: t }; }); })
                .then(function (res) {
                    var j = null;
                    try { j = JSON.parse(res.text); } catch (e) {}
                    if (!j) { info.textContent = 'Server did not answer (HTTP ' + res.status + '): ' + res.text.replace(/<[^>]*>/g, ' ').trim().slice(0, 160); setTimeout(load, 8000); return; }
                    if (!j.success) { info.textContent = j.data || 'Could not create the QR.'; setTimeout(load, 8000); return; }
                    draw(<?php echo wp_json_encode( QRRS_ATT_PREFIX ); ?> + j.data.token, function (err) {
                        info.textContent = err ? ('QR could not be drawn: ' + err) : 'Valid for 60 seconds. Refreshing…';
                    });
                    setTimeout(load, 55000);
                })
                .catch(function (e) { info.textContent = 'Browser could not reach the server: ' + (e && e.message ? e.message : e); setTimeout(load, 8000); });
        }
        ensureLib(function () { load(); });
    })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <?php
}

/** Staff panel: open the camera and scan the QR. */
function qrrs_att_scanner_render( $restaurant_id ) {
    $rid = intval( $restaurant_id );
    $me  = function_exists( 'qrrs_my_staff_row' ) ? qrrs_my_staff_row( $rid ) : null;
    if ( ! $me || ! function_exists( 'qrrs_attendance_enabled' ) || ! qrrs_attendance_enabled( $rid ) ) return;
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:22px; max-width:520px; margin-bottom:14px;">
        <div style="font-size:16px; font-weight:800; color:#0f172a;">Scan the attendance QR</div>
        <div style="font-size:13px; color:#64748b; margin:6px 0 14px;">Point the camera at the QR on the counter screen.</div>
        <button type="button" id="att-scan-btn" style="padding:14px 22px; border:none; border-radius:12px; background:#2563eb; color:#fff; font-weight:800; font-size:15px; cursor:pointer;">Open camera &amp; scan</button>
        <div id="att-scan-box" style="display:none; margin-top:14px;">
            <video id="att-scan-video" playsinline muted style="width:100%; max-width:420px; border-radius:12px; background:#000;"></video>
            <div><button type="button" id="att-scan-stop" style="margin-top:10px; padding:8px 14px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; cursor:pointer;">Close camera</button></div>
        </div>
        <div id="att-scan-msg" style="margin-top:12px; font-size:14px; font-weight:700;"></div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <script src="https://unpkg.com/jsqr@1.4.0/dist/jsQR.js"></script>
    <script>
    (function () {
        var cfg = { ajax: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce: <?php echo wp_json_encode( wp_create_nonce( 'qr_order_nonce' ) ); ?> };
        var btn = document.getElementById('att-scan-btn'), stop = document.getElementById('att-scan-stop');
        var box = document.getElementById('att-scan-box'), video = document.getElementById('att-scan-video'), msg = document.getElementById('att-scan-msg');
        var stream = null, timer = null, busy = false;
        function say(t, ok) { msg.textContent = t; msg.style.color = ok ? '#166534' : '#991b1b'; }
        function close() {
            if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
            if (timer) { clearInterval(timer); timer = null; }
            box.style.display = 'none';
        }
        function send(token) {
            fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'qrrs_att_scan', token: token, security: cfg.nonce }) })
                .then(function (r) { return r.text(); })
                .then(function (txt) {
                    var j = null; try { j = JSON.parse(txt); } catch (e) {}
                    if (!j) { say('Server did not answer: ' + txt.replace(/<[^>]*>/g, ' ').trim().slice(0, 160), false); busy = false; return; }
                    if (j && j.success) { say(j.data.msg, true); close(); }
                    else { say((j && j.data) ? j.data : 'Scan failed. Try again.', false); busy = false; }
                })
                .catch(function (e) { say('Browser could not reach the server: ' + (e && e.message ? e.message : e), false); busy = false; });
        }
        function start() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { say('Camera is not available on this device.', false); return; }
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
                stream = s; box.style.display = 'block'; video.srcObject = s; video.play();
                var detector = ('BarcodeDetector' in window) ? new BarcodeDetector({ formats: ['qr_code'] }) : null;
                var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d');
                say('Looking for the QR…', true);
                timer = setInterval(function () {
                    if (busy || video.readyState < 2) return;
                    var pick = function (text) {
                        if (text && text.indexOf('<?php echo QRRS_ATT_PREFIX; ?>') === 0) { busy = true; send(text.substring(<?php echo strlen( QRRS_ATT_PREFIX ); ?>)); }
                    };
                    if (detector) {
                        detector.detect(video).then(function (codes) { if (codes.length) pick(codes[0].rawValue); }).catch(function () {});
                    } else if (window.jsQR) {
                        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        var q = jsQR(img.data, img.width, img.height);
                        if (q) pick(q.data);
                    }
                }, 400);
            }).catch(function () { say('Camera permission is needed to scan.', false); });
        }
        btn.addEventListener('click', start);
        stop.addEventListener('click', close);
    })();
    </script>
    <?php
}