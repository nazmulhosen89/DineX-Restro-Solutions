<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alerts shown at the top of the Orders board:
 *  - Low stock: inventory items at or below their minimum level.
 *  - Big orders: today's orders at or above the big-order amount.
 *    The amount is set by the option "qrrs_big_order_amount" (default 2000 ৳).
 */
function qrrs_alerts_html( $restaurant_id ) {
    global $wpdb;
    $rid = intval( $restaurant_id );
    if ( $rid <= 0 ) return '';
    $p = $wpdb->prefix;
    $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';

    $low = $wpdb->get_results( $wpdb->prepare(
        "SELECT item_name, current_stock, min_stock_level FROM {$p}qrrs_inventory_items
         WHERE restaurant_id = %d AND status = 'active' AND current_stock <= min_stock_level
         ORDER BY (min_stock_level - current_stock) DESC LIMIT 6",
        $rid
    ) );
    $low_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}qrrs_inventory_items
         WHERE restaurant_id = %d AND status = 'active' AND current_stock <= min_stock_level",
        $rid
    ) );

    $threshold = (float) get_option( 'qrrs_big_order_amount', 2000 );
    // The order's amount: the bill total if it exists, otherwise the sum of its items
    $amount = "COALESCE(NULLIF(o.final_total,0), NULLIF(o.grand_total,0), (SELECT COALESCE(SUM(oi.price * oi.quantity),0) FROM {$p}qrrs_order_items oi WHERE oi.order_id = o.id))";
    $big = $wpdb->get_results( $wpdb->prepare(
        "SELECT o.id, o.table_name, {$amount} AS total FROM {$p}qrrs_orders o
         WHERE o.restaurant_id = %d AND DATE(o.created_at) = %s AND o.order_status NOT IN ('cancelled')
           AND {$amount} >= %f
         ORDER BY o.id DESC LIMIT 6",
        $rid, current_time( 'Y-m-d' ), $threshold
    ) );

    if ( $low_count === 0 && empty( $big ) ) return '';
    $stock_url = add_query_arg( [ 'tab' => 'inventory', 'inv_section' => 'low-stock' ], home_url( '/restaurant-dashboard/' ) );

    ob_start(); ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:12px; margin-bottom:16px;">
        <?php if ( $low_count > 0 ) : ?>
        <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:12px; padding:12px 14px;">
            <div style="font-weight:800; color:#92400e;">⚠ Low stock: <?php echo intval( $low_count ); ?> item(s)</div>
            <div style="font-size:13px; color:#78350f; margin-top:6px;">
                <?php echo esc_html( implode( ', ', array_map( fn( $i ) => $i->item_name . ' (' . rtrim( rtrim( number_format( (float) $i->current_stock, 3 ), '0' ), '.' ) . ')', $low ) ) ); ?>
            </div>
            <a href="<?php echo esc_url( $stock_url ); ?>" style="font-size:12px; color:#b45309; font-weight:700;">Open low stock →</a>
        </div>
        <?php endif; ?>
        <?php if ( ! empty( $big ) ) : ?>
        <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:12px; padding:12px 14px;">
            <div style="font-weight:800; color:#991b1b;">🔔 Big order (≥ <?php echo esc_html( $money( $threshold ) ); ?>): <?php echo count( $big ); ?> today</div>
            <div style="font-size:13px; color:#7f1d1d; margin-top:6px;">
                <?php foreach ( $big as $b ) : ?>
                    <div>#<?php echo intval( $b->id ); ?> · <?php echo esc_html( $b->table_name ); ?> · <?php echo esc_html( $money( $b->total ) ); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/** Names of the ingredients of this order that are at or below minimum stock. */
function qrrs_order_low_stock_items( $order_id ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT i.item_name
         FROM {$p}qrrs_order_items oi
         JOIN {$p}qrrs_orders o ON o.id = oi.order_id
         JOIN {$p}qrrs_recipes r ON r.menu_item_id = oi.item_id AND r.restaurant_id = o.restaurant_id AND r.status = 'active'
         JOIN {$p}qrrs_recipe_items ri ON ri.recipe_id = r.id
         JOIN {$p}qrrs_inventory_items i ON i.id = ri.inventory_item_id
         WHERE oi.order_id = %d AND i.status = 'active' AND i.current_stock <= i.min_stock_level",
        intval( $order_id )
    ) );
    return array_values( array_filter( (array) $rows ) );
}

/** Is an amount a big order? */
function qrrs_is_big_order( $amount ) {
    return (float) $amount >= (float) get_option( 'qrrs_big_order_amount', 2000 );
}

/** Is this menu item low on stock (linked stock item or any recipe ingredient)? */
function qrrs_item_is_low( $item_id, $restaurant_id ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $direct = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}qrrs_items it
         JOIN {$p}qrrs_inventory_items i ON i.id = it.stock_item_id
         WHERE it.id = %d AND it.stock_item_id > 0 AND i.status = 'active' AND i.current_stock <= i.min_stock_level",
        intval( $item_id )
    ) );
    if ( $direct > 0 ) return true;
    $recipe = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}qrrs_recipes r
         JOIN {$p}qrrs_recipe_items ri ON ri.recipe_id = r.id
         JOIN {$p}qrrs_inventory_items i ON i.id = ri.inventory_item_id
         WHERE r.menu_item_id = %d AND r.restaurant_id = %d AND r.status = 'active'
           AND i.status = 'active' AND i.current_stock <= i.min_stock_level",
        intval( $item_id ), intval( $restaurant_id )
    ) );
    return $recipe > 0;
}