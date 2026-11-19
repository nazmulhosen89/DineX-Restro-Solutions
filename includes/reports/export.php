<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Loads the shared export script (Excel and PDF) on the plugin's pages. */
add_action( 'wp_enqueue_scripts', function () {
    $ver = (string) @filemtime( QRRS_PATH . 'assets/js/qrrs-export.js' );
    wp_enqueue_script( 'qrrs-export', QRRS_URL . 'assets/js/qrrs-export.js', [], $ver ?: '1', true );
} );

/** Buttons for a report table. */
function qrrs_export_buttons( $table_id, $title, $period, $filename ) {
    ?>
    <div style="display:flex; gap:8px; justify-content:flex-end; margin-bottom:10px;">
        <button type="button" onclick="qrrsExportExcel('<?php echo esc_js( $table_id ); ?>', '<?php echo esc_js( $filename ); ?>')" style="background:#1a7431; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer;">Excel</button>
        <button type="button" onclick="qrrsExportPDF('<?php echo esc_js( $table_id ); ?>', '<?php echo esc_js( $title ); ?>', '<?php echo esc_js( $period ); ?>', '<?php echo esc_js( $filename ); ?>')" style="background:#c0392b; color:#fff; border:none; padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer;">PDF</button>
    </div>
    <?php
}