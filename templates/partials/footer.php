<?php
$footer_res_name = ! empty( $res_name ) ? $res_name : ( ! empty( $restaurant->restaurant_name ) ? $restaurant->restaurant_name : '' );
$footer_rid      = ! empty( $active_res_id ) ? intval( $active_res_id ) : ( isset( $restaurant->id ) ? intval( $restaurant->id ) : 0 );
$footer_message  = ( function_exists( 'qrrs_printer_get' ) && $footer_rid > 0 ) ? qrrs_printer_get( $footer_rid )['footer'] : 'Thank You for Visiting!';
?>
<div class="pos-footer" style="text-align:center; margin-top:20px; font-size:12px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #444;">
    <div style="border-top: 1px dashed #666; margin-bottom:10px; width: 100%;"></div>

    <?php if ( '' !== trim( (string) $footer_message ) ) : ?>
    <p style="margin:4px 0; font-size:13px; letter-spacing: 0.5px; color: #555;"><?php echo nl2br( esc_html( $footer_message ) ); ?></p>
    <?php endif; ?>

    <p style="margin:4px 0; color: #000;">Developed for <?php echo esc_html( $footer_res_name ); ?></p>

    <p style="margin:4px 0; font-size:11px; color:#666; font-style: italic;">
        Proudly powered by <span style="color:#000; font-weight:bold; font-style:normal;">DineX Restro Solutions</span>
    </p>
</div>