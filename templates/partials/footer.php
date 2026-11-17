<?php
$footer_res_name = ! empty( $res_name ) ? $res_name : ( ! empty( $restaurant->restaurant_name ) ? $restaurant->restaurant_name : '' );
?>
<div class="pos-footer" style="text-align:center; margin-top:20px; font-size:12px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #444;">
    <div style="border-top: 1px dashed #666; margin-bottom:10px; width: 100%;"></div>

    <p style="margin:4px 0; font-size:13px; letter-spacing: 0.5px; color: #555;">Thank You for Visiting!</p>

    <?php if ( $footer_res_name ) : ?>
    <p style="margin:4px 0; color: #000;">Developed for <?php echo esc_html( $footer_res_name ); ?></p>
    <?php endif; ?>

    <p style="margin:4px 0; font-size:11px; color:#666; font-style: italic;">
        Proudly powered by <span style="color:#000; font-weight:bold; font-style:normal;">DineX Restro Solutions</span>
    </p>
</div>