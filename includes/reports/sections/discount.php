<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Discounts & split payments, shown as a section of the Reports page
if ( function_exists( 'qrrs_discount_split_render' ) ) {
    qrrs_discount_split_render( qrrs_inventory_get_active_restaurant_id() );
}