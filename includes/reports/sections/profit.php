<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( function_exists( 'qrrs_pl_render' ) ) {
    qrrs_pl_render( qrrs_inventory_get_active_restaurant_id() );
}