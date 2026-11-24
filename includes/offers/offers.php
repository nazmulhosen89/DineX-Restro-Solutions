<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Happy hour / offers.
 *  - Offer types: percent off, fixed amount off per item.
 *  - Days of the week, a time window (can cross midnight), optional date range.
 *  - Applies to all items, or to selected items.
 *  - The price is worked out on the server when the order is saved, so the browser cannot change it.
 *  - Among the offers that apply, the one giving the biggest discount is used.
 */

function qrrs_offers_user_can() {
    return function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
}

function qrrs_offer_day_names() {
    return [ 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' ];
}

/**
 * Unit price after an offer, for a line of $qty units.
 *  - percent / fixed: the same for every unit.
 *  - buyget (X buy, Y free): in every (X+Y) units, Y are free, so the average unit price drops.
 */
function qrrs_offer_unit_price( $type, $amount, $get_qty, $unit, $qty = 1 ) {
    $unit = (float) $unit;
    $qty  = max( 1, (int) $qty );
    if ( 'buyget' === $type ) {
        $x = max( 1, (int) $amount );
        $y = max( 0, (int) $get_qty );
        $free    = (int) floor( $qty / ( $x + $y ) ) * $y;
        $payable = max( 0, $qty - $free );
        return round( $unit, 2 );   // paid items keep their price; the free items come as separate lines
    }
    if ( 'percent' === $type ) return round( max( 0, $unit - $unit * ( (float) $amount / 100 ) ), 2 );
    return round( max( 0, $unit - min( (float) $amount, $unit ) ), 2 );
}

/** Is this offer active at this moment (site time)? */
function qrrs_offer_is_active_now( $offer, $now_ts = null ) {
    // wp_date() applies the site timezone itself, so it needs the real (UTC) time
    $now_ts = $now_ts ?: time();
    $today  = wp_date( 'Y-m-d', $now_ts );
    if ( ! empty( $offer->date_from ) && $today < $offer->date_from ) return false;
    if ( ! empty( $offer->date_to ) && $today > $offer->date_to ) return false;

    // an empty days value means every day (it must not become Sunday)
    $days_raw = array_filter( array_map( 'trim', explode( ',', (string) $offer->days ) ), 'strlen' );
    $days = array_filter( array_map( 'intval', $days_raw ), fn( $d ) => $d >= 0 && $d <= 6 );
    if ( ! empty( $days ) && ! in_array( intval( wp_date( 'w', $now_ts ) ), $days, true ) ) return false;

    $now   = wp_date( 'H:i', $now_ts );
    $start = substr( (string) $offer->start_time, 0, 5 );
    $end   = substr( (string) $offer->end_time, 0, 5 );
    if ( '' === $start || '' === $end ) return true;      // no time window: all day
    if ( $start <= $end ) return $now >= $start && $now < $end;
    return $now >= $start || $now < $end;                  // crosses midnight, e.g. 22:00 to 02:00
}

/** The offer that gives the biggest discount for this item now, as [type, amount], or null. */
function qrrs_offer_applies_to_line( $o, $item_id, $size_ids ) {
    $applies = ( 'all' === $o->scope );
    if ( ! $applies ) {
        $ids = array_filter( array_map( 'intval', explode( ',', (string) $o->item_ids ) ) );
        $applies = in_array( intval( $item_id ), $ids, true );
    }
    if ( ! $applies ) return false;
    $vids = array_filter( array_map( 'intval', explode( ',', (string) $o->variant_ids ) ) );
    if ( empty( $vids ) ) return true;                       // whole item
    return count( array_intersect( $vids, (array) $size_ids ) ) > 0;   // only the chosen variants
}

function qrrs_best_offer_for_item( $rid, $item_id, $base_price, $size_ids = [], $qty = 1 ) {
    global $wpdb;
    $base = round( (float) $base_price, 2 );
    $offers = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_offers WHERE restaurant_id = %d AND is_active = 1", intval( $rid )
    ) );
    $best = null; $best_disc = 0.0;
    foreach ( $offers as $o ) {
        if ( ! qrrs_offer_applies_to_line( $o, $item_id, $size_ids ) || ! qrrs_offer_is_active_now( $o ) ) continue;
        $disc = $base - qrrs_offer_unit_price( $o->offer_type, $o->amount, $o->get_qty, $base, $qty );
        if ( $disc > $best_disc ) { $best_disc = $disc; $best = [ 'type' => $o->offer_type, 'amount' => (float) $o->amount, 'get_qty' => (int) $o->get_qty, 'name' => $o->name ]; }
    }
    return $best;
}

/** Offers that are limited to some variants of this item, so the waiter page can price the chosen size. */
function qrrs_variant_offers_for_item( $rid, $item_id ) {
    global $wpdb;
    $out = [];
    $offers = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_offers WHERE restaurant_id = %d AND is_active = 1 AND variant_ids <> ''", intval( $rid )
    ) );
    foreach ( $offers as $o ) {
        if ( ! qrrs_offer_is_active_now( $o ) ) continue;
        $ids = array_filter( array_map( 'intval', explode( ',', (string) $o->item_ids ) ) );
        if ( 'items' === $o->scope && ! in_array( intval( $item_id ), $ids, true ) ) continue;
        $out[] = [
            'ids'    => array_values( array_filter( array_map( 'intval', explode( ',', (string) $o->variant_ids ) ) ) ),
            'type'   => $o->offer_type,
            'amount' => (float) $o->amount,
            'get_qty' => (int) $o->get_qty,
            'name'   => $o->name,
        ];
    }
    return $out;
}

/**
 * Price of one unit of a menu item for an order, after the best active offer.
 *  $base_price is the menu price. The result never goes below 0.
 */
function qrrs_offer_price_for_order_item( $order_id, $item_id, $base_price, $size_ids_csv = '', $qty = 1 ) {
    global $wpdb;
    $base = round( (float) $base_price, 2 );
    $rid  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT restaurant_id FROM {$wpdb->prefix}qrrs_orders WHERE id = %d", intval( $order_id ) ) );
    if ( $rid <= 0 ) return $base;
    $size_ids = array_filter( array_map( 'intval', explode( ',', (string) $size_ids_csv ) ) );

    $offers = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_offers WHERE restaurant_id = %d AND is_active = 1", $rid
    ) );
    $best = qrrs_best_offer_for_item( $rid, $item_id, $base, $size_ids, $qty );
    if ( ! $best ) return $base;
    return qrrs_offer_unit_price( $best['type'], $best['amount'], $best['get_qty'], $base, $qty );
}

add_action( 'admin_post_qrrs_offer_save', function () {
    if ( ! is_user_logged_in() || ! qrrs_offers_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    check_admin_referer( 'qrrs_offer_save' );
    $rid   = intval( qrrs_inventory_get_active_restaurant_id() );
    $name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    $type  = in_array( $_POST['offer_type'] ?? '', [ 'fixed', 'buyget' ], true ) ? $_POST['offer_type'] : 'percent';
    $gq    = max( 0, intval( $_POST['get_qty'] ?? 0 ) );
    $fitem = intval( $_POST['free_item_id'] ?? 0 );
    $amt   = round( (float) ( $_POST['amount'] ?? 0 ), 2 );
    if ( 'buyget' === $type && ( intval( $amt ) < 1 || $gq < 1 ) ) {
        wp_safe_redirect( add_query_arg( [ 'tab' => 'settings', 'set' => 'offers', 'of_status' => 'bad' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
    }
    $days  = array_filter( array_map( 'intval', (array) ( $_POST['days'] ?? [] ) ), fn( $d ) => $d >= 0 && $d <= 6 );
    $start = preg_match( '/^\d{2}:\d{2}$/', $_POST['start_time'] ?? '' ) ? $_POST['start_time'] : '';
    $end   = preg_match( '/^\d{2}:\d{2}$/', $_POST['end_time'] ?? '' ) ? $_POST['end_time'] : '';
    $from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['date_from'] ?? '' ) ? $_POST['date_from'] : null;
    $to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['date_to'] ?? '' ) ? $_POST['date_to'] : null;
    $scope = ( ( $_POST['scope'] ?? '' ) === 'items' ) ? 'items' : 'all';
    $ids   = array_filter( array_map( 'intval', (array) ( $_POST['item_ids'] ?? [] ) ) );
    $vids  = array_filter( array_map( 'intval', (array) ( $_POST['variant_ids'] ?? [] ) ) );
    $back  = add_query_arg( [ 'tab' => 'settings', 'set' => 'offers' ], home_url( '/restaurant-dashboard/' ) );

    if ( '' === trim( $name ) || $amt <= 0 || ( 'percent' === $type && $amt > 100 )) {
        wp_safe_redirect( add_query_arg( 'of_status', 'bad', $back ) ); exit;
    }
    if ( 'items' === $scope && empty( $ids ) ) {
        wp_safe_redirect( add_query_arg( 'of_status', 'no_items', $back ) ); exit;
    }
    $wpdb->insert( $wpdb->prefix . 'qrrs_offers', [
        'restaurant_id' => $rid, 'name' => substr( $name, 0, 150 ), 'offer_type' => $type, 'amount' => $amt, 'get_qty' => $gq, 'free_item_id' => $fitem,
        'days' => implode( ',', $days ), 'start_time' => $start ?: null, 'end_time' => $end ?: null,
        'date_from' => $from, 'date_to' => $to, 'scope' => $scope,
        'item_ids' => implode( ',', $ids ), 'variant_ids' => implode( ',', $vids ), 'is_active' => 1,
        'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
    ] );
    qrrs_audit( 'offer_added', 'offer', (int) $wpdb->insert_id, [ 'name' => $name, 'type' => $type, 'amount' => $amt ], $rid );
    wp_safe_redirect( add_query_arg( 'of_status', 'saved', $back ) ); exit;
} );

add_action( 'admin_post_qrrs_offer_delete', function () {
    if ( ! is_user_logged_in() || ! qrrs_offers_user_can() ) wp_die( 'Not allowed.' );
    global $wpdb;
    $id = intval( $_POST['offer_id'] ?? 0 );
    check_admin_referer( 'qrrs_offer_delete_' . $id );
    $rid = intval( qrrs_inventory_get_active_restaurant_id() );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}qrrs_offers WHERE id = %d AND restaurant_id = %d", $id, $rid ) );
    if ( $row ) {
        $wpdb->delete( $wpdb->prefix . 'qrrs_offers', [ 'id' => $id ] );
        qrrs_audit( 'offer_deleted', 'offer', $id, [ 'name' => $row->name ], $rid );
    }
    wp_safe_redirect( add_query_arg( [ 'tab' => 'settings', 'set' => 'offers', 'of_status' => 'deleted' ], home_url( '/restaurant-dashboard/' ) ) ); exit;
} );

function qrrs_offers_render( $rid ) {
    if ( ! qrrs_offers_user_can() ) {
        echo '<div class="qrrs-card" style="padding:20px;">Offers are for managers and admins.</div>';
        return;
    }
    global $wpdb;
    $rid = intval( $rid );
    $st  = sanitize_key( $_GET['of_status'] ?? '' );
    $msgs = [ 'saved' => [ 'ok', 'Offer saved.' ], 'deleted' => [ 'ok', 'Offer removed.' ],
              'bad' => [ 'err', 'Check the name and the amount (percent cannot be more than 100).' ],
              'no_items' => [ 'err', 'Choose at least one item for this offer.' ] ];
    if ( isset( $msgs[ $st ] ) ) {
        [ $k, $txt ] = $msgs[ $st ];
        echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:8px; margin-bottom:14px;">' . esc_html( $txt ) . '</div>';
    }
    $offers = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}qrrs_offers WHERE restaurant_id = %d ORDER BY id DESC", $rid ) );
    $items  = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_name FROM {$wpdb->prefix}qrrs_items WHERE restaurant_id = %d ORDER BY item_name", $rid ) );
    $rid_for_groups = $rid;
    $inp = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; box-sizing:border-box;';
    $names = qrrs_offer_day_names();
    ?>
    <div>
        <table style="width:100%; border-collapse:collapse; font-size:14px; margin-bottom:22px;">
            <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                <th style="padding:10px;">Offer</th><th style="padding:10px;">Discount</th><th style="padding:10px;">Days</th><th style="padding:10px;">Time</th><th style="padding:10px;">Applies to</th><th style="padding:10px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $offers as $o ) :
                $dl = array_map( fn( $d ) => $names[ intval( $d ) ] ?? '', array_filter( explode( ',', (string) $o->days ), 'strlen' ) );
                $on = qrrs_offer_is_active_now( $o ); ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:10px; font-weight:600;"><?php echo esc_html( $o->name ); ?> <?php if ( $on ) : ?><span style="background:#dcfce7; color:#166534; font-size:11px; padding:2px 8px; border-radius:6px; margin-left:6px;">Active now</span><?php endif; ?></td>
                    <td style="padding:10px;"><?php echo 'buyget' === $o->offer_type ? 'Buy ' . intval( $o->amount ) . ' get ' . intval( $o->get_qty ) . ' free' : ( 'percent' === $o->offer_type ? esc_html( rtrim( rtrim( $o->amount, '0' ), '.' ) ) . '% off' : esc_html( number_format( (float) $o->amount, 2 ) ) . ' ৳ off' ); ?></td>
                    <td style="padding:10px;"><?php echo $dl ? esc_html( implode( ' ', $dl ) ) : 'Every day'; ?></td>
                    <td style="padding:10px;"><?php echo ( $o->start_time && $o->end_time ) ? esc_html( substr( $o->start_time, 0, 5 ) . ' – ' . substr( $o->end_time, 0, 5 ) ) : 'All day'; ?></td>
                    <td style="padding:10px;"><?php echo 'all' === $o->scope ? 'All items' : count( array_filter( explode( ',', (string) $o->item_ids ) ) ) . ' item(s)'; ?></td>
                    <td style="padding:10px; text-align:right;">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Remove this offer?');" style="margin:0;">
                            <input type="hidden" name="action" value="qrrs_offer_delete">
                            <input type="hidden" name="offer_id" value="<?php echo intval( $o->id ); ?>">
                            <?php wp_nonce_field( 'qrrs_offer_delete_' . intval( $o->id ) ); ?>
                            <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:13px;">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $offers ) ) : ?><tr><td colspan="6" style="padding:16px; text-align:center; color:#94a3b8;">No offers yet.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <style>
        .qo-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:22px;box-shadow:0 2px 10px rgba(15,23,42,.05);}
        .qo-step{display:flex;align-items:center;gap:10px;margin:18px 0 10px;font-size:14px;font-weight:800;color:#0f172a;}
        .qo-step:first-child{margin-top:0;}
        .qo-num{width:24px;height:24px;border-radius:50%;background:#0f172a;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;}
        .qo-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;}
        .qo-card{position:relative;cursor:pointer;border:2px solid #e2e8f0;border-radius:12px;padding:14px;background:#fff;transition:all .15s;}
        .qo-card input{position:absolute;opacity:0;pointer-events:none;}
        .qo-card b{display:block;font-size:14px;color:#0f172a;margin-bottom:2px;}
        .qo-card span{font-size:12px;color:#64748b;}
        .qo-card.on{border-color:#16a34a;background:#f0fdf4;box-shadow:0 0 0 3px rgba(22,163,74,.12);}
        .qo-input{padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;background:#fff;width:100%;box-sizing:border-box;}
        .qo-input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);}
        .qo-label{font-size:12px;font-weight:700;color:#334155;display:block;margin-bottom:5px;}
        .qo-panel{background:#fff7ed;border:1px dashed #fb923c;border-radius:14px;padding:16px;margin-top:12px;}
        .qo-panel-title{font-size:13px;font-weight:800;color:#9a3412;margin-bottom:12px;}
        .qo-grid3{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}
        .qo-search{margin-bottom:10px;}
        .qo-items{display:flex;flex-wrap:wrap;gap:8px;max-height:300px;overflow-y:auto;padding:4px;}
        .qo-chip{cursor:pointer;border:1.5px solid #cbd5e1;border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;color:#334155;background:#fff;user-select:none;transition:all .12s;}
        .qo-chip input{display:none;}
        .qo-chip.on{background:#0f172a;border-color:#0f172a;color:#fff;}
        .qo-sizes{width:100%;display:none;flex-wrap:wrap;gap:6px;padding:8px 0 4px 0;}
        .qo-sizes.show{display:flex;}
        .qo-sizes .qo-chip{font-size:12px;padding:5px 12px;background:#f8fafc;}
        .qo-sizes .qo-chip.on{background:#2563eb;border-color:#2563eb;}
        .qo-note{font-size:12px;color:#64748b;margin-top:6px;}
        .qo-summary{background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;border-radius:14px;padding:16px 18px;margin-top:20px;}
        .qo-summary .t{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;}
        .qo-summary .v{font-size:16px;font-weight:700;color:#fff;}
        .qo-save{margin-top:16px;padding:13px 26px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:12px;font-weight:800;font-size:15px;cursor:pointer;box-shadow:0 4px 10px rgba(22,163,74,.3);}
        .qo-save:hover{filter:brightness(1.05);}
        </style>

        <div class="qo-wrap">
            <div style="font-size:18px;font-weight:800;color:#0f172a;">Create an offer</div>
            <div style="font-size:13px;color:#64748b;margin-top:2px;">Fill in the steps. The summary at the bottom shows what the offer will do.</div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="qrrs-offer-form">
                <input type="hidden" name="action" value="qrrs_offer_save">
                <?php wp_nonce_field( 'qrrs_offer_save' ); ?>

                <div class="qo-step"><span class="qo-num">1</span> Offer name</div>
                <input type="text" name="name" class="qo-input" required maxlength="150" placeholder="e.g. Kabab & Naan Deal" id="qo-name">

                <div class="qo-step"><span class="qo-num">2</span> Offer type</div>
                <div class="qo-cards" id="qo-types">
                    <label class="qo-card on" data-type="percent"><input type="radio" name="offer_type" value="percent" checked><b>Percent off</b><span>e.g. 10% off the price</span></label>
                    <label class="qo-card" data-type="fixed"><input type="radio" name="offer_type" value="fixed"><b>Fixed amount off</b><span>e.g. ৳20 off each item</span></label>
                    <label class="qo-card" data-type="buyget"><input type="radio" name="offer_type" value="buyget"><b>Buy X, get Y free</b><span>e.g. buy 2, get 1 free</span></label>
                </div>

                <div id="qo-plain" style="margin-top:12px;">
                    <label class="qo-label" id="qo-plain-label">Discount (%)</label>
                    <input type="number" name="amount" id="qo-plain-amount" class="qo-input" step="0.01" min="0.01" placeholder="e.g. 10" style="max-width:240px;">
                </div>

                <div id="qo-buy" class="qo-panel" style="display:none;">
                    <div class="qo-panel-title">Buy X, get Y free</div>
                    <div class="qo-grid3">
                        <div>
                            <label class="qo-label">Buy (X)</label>
                            <input type="number" name="amount" id="qo-x" class="qo-input" min="1" step="1" placeholder="e.g. 2" disabled>
                        </div>
                        <div>
                            <label class="qo-label">Get free (Y)</label>
                            <input type="number" name="get_qty" id="qo-y" class="qo-input" min="1" step="1" placeholder="e.g. 1" disabled>
                        </div>
                        <div>
                            <label class="qo-label">Free item</label>
                            <select name="free_item_id" id="qo-free" class="qo-input" disabled>
                                <option value="0">Same item as the purchase</option>
                                <?php foreach ( $items as $it ) : ?><option value="<?php echo intval( $it->id ); ?>" data-name="<?php echo esc_attr( $it->item_name ); ?>"><?php echo esc_html( $it->item_name ); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="qo-note" id="qo-buy-note"></div>
                </div>

                <div class="qo-step"><span class="qo-num">3</span> Which items</div>
                <div class="qo-cards" id="qo-scopes" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    <label class="qo-card on" data-scope="all"><input type="radio" name="scope" value="all" checked><b>All items</b><span>Applies to every item on the menu</span></label>
                    <label class="qo-card" data-scope="items"><input type="radio" name="scope" value="items"><b>Selected items</b><span>Pick the items below</span></label>
                </div>

                <div id="qo-items-box" style="display:none; margin-top:12px;">
                    <input type="text" id="qo-search" class="qo-input qo-search" placeholder="Search items…">
                    <div class="qo-items" id="qo-items">
                        <?php foreach ( $items as $it ) :
                            $vals = [];
                            if ( function_exists( 'qrrs_option_groups_for_item' ) ) {
                                foreach ( qrrs_option_groups_for_item( $rid, intval( $it->id ) ) as $g ) {
                                    if ( 'size' !== $g->group_type ) continue;
                                    foreach ( (array) $g->values as $v ) $vals[] = $v;
                                }
                            } ?>
                            <div style="display:contents;" class="qo-item-wrap" data-name="<?php echo esc_attr( strtolower( $it->item_name ) ); ?>">
                                <label class="qo-chip" data-item="<?php echo intval( $it->id ); ?>">
                                    <input type="checkbox" name="item_ids[]" value="<?php echo intval( $it->id ); ?>"> <?php echo esc_html( $it->item_name ); ?>
                                </label>
                                <?php if ( ! empty( $vals ) ) : ?>
                                <div class="qo-sizes" data-sizes-for="<?php echo intval( $it->id ); ?>">
                                    <span class="qo-note" style="width:100%;margin:0;">Only these sizes (none selected = whole item):</span>
                                    <?php foreach ( $vals as $v ) : ?>
                                        <label class="qo-chip"><input type="checkbox" name="variant_ids[]" value="<?php echo intval( $v->id ); ?>"> <?php echo esc_html( $v->value_name ); ?></label>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="qo-note" id="qo-count"><?php echo empty( $items ) ? 'No items yet.' : ''; ?></div>
                </div>

                <div class="qo-step"><span class="qo-num">4</span> When <span style="font-weight:500;color:#64748b;">(optional)</span></div>
                <div class="qo-items" id="qo-days" style="gap:6px;">
                    <?php foreach ( $names as $d => $label ) : ?>
                        <label class="qo-chip"><input type="checkbox" name="days[]" value="<?php echo $d; ?>"> <?php echo $label; ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="qo-note">No day selected = every day.</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px;">
                    <div><label class="qo-label">From time</label><input type="time" name="start_time" class="qo-input"></div>
                    <div><label class="qo-label">To time</label><input type="time" name="end_time" class="qo-input"></div>
                    <div><label class="qo-label">From date</label><input type="date" name="date_from" class="qo-input"></div>
                    <div><label class="qo-label">To date</label><input type="date" name="date_to" class="qo-input"></div>
                </div>
                <div class="qo-note">Empty time = all day. Empty dates = no date limit.</div>

                <div class="qo-summary">
                    <div class="t">Summary</div>
                    <div class="v" id="qo-summary">Fill in the offer above</div>
                </div>

                <button type="submit" class="qo-save">Save offer</button>
            </form>

            <script>
            (function () {
                var form = document.getElementById('qrrs-offer-form');
                if (!form) return;
                function $(id) { return document.getElementById(id); }
                function all(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }
                var plainBox = $('qo-plain'), buyBox = $('qo-buy'), plainAmt = $('qo-plain-amount'), plainLabel = $('qo-plain-label');
                var x = $('qo-x'), y = $('qo-y'), free = $('qo-free'), buyNote = $('qo-buy-note');
                var itemsBox = $('qo-items-box'), search = $('qo-search'), count = $('qo-count'), summary = $('qo-summary');

                function type() { var c = document.querySelector('input[name="offer_type"]:checked'); return c ? c.value : 'percent'; }
                function scope() { var c = document.querySelector('input[name="scope"]:checked'); return c ? c.value : 'all'; }
                function selectedItems() {
                    return all('input[name="item_ids[]"]:checked').map(function (c) {
                        return c.closest('.qo-item-wrap').querySelector('.qo-chip').textContent.trim();
                    });
                }

                function refresh() {
                    var t = type(), isBuy = t === 'buyget';
                    plainBox.style.display = isBuy ? 'none' : 'block';
                    buyBox.style.display = isBuy ? 'block' : 'none';
                    plainAmt.disabled = isBuy; x.disabled = !isBuy; y.disabled = !isBuy; free.disabled = !isBuy;
                    plainLabel.textContent = t === 'fixed' ? 'Discount per item (৳)' : 'Discount (%)';
                    all('#qo-types .qo-card').forEach(function (c) { c.classList.toggle('on', c.getAttribute('data-type') === t); });

                    var sc = scope();
                    all('#qo-scopes .qo-card').forEach(function (c) { c.classList.toggle('on', c.getAttribute('data-scope') === sc); });
                    itemsBox.style.display = sc === 'items' ? 'block' : 'none';

                    var picked = selectedItems();
                    count.textContent = sc === 'items' ? (picked.length ? picked.length + ' item(s) selected' : 'Tick the items this offer applies to') : '';

                    // sizes show only for ticked items
                    all('.qo-item-wrap').forEach(function (w) {
                        var on = w.querySelector('input[name="item_ids[]"]').checked;
                        var sz = w.querySelector('.qo-sizes');
                        if (sz) sz.classList.toggle('show', on);
                        w.querySelector('.qo-chip').classList.toggle('on', on);
                    });
                    all('.qo-items .qo-chip').forEach(function (c) {
                        if (c.querySelector('input[type="checkbox"]') && c.querySelector('input[name="days[]"]')) {}
                    });
                    all('#qo-days .qo-chip').forEach(function (c) { c.classList.toggle('on', c.querySelector('input').checked); });
                    all('.qo-sizes .qo-chip').forEach(function (c) { c.classList.toggle('on', c.querySelector('input').checked); });

                    // buy-get note
                    if (isBuy) {
                        var xv = parseInt(x.value, 10), yv = parseInt(y.value, 10);
                        var fName = free.value === '0' ? 'the same item' : free.options[free.selectedIndex].text;
                        buyNote.textContent = (xv && yv)
                            ? 'Buy ' + xv + ', get ' + yv + ' free (' + fName + '). Example: buy ' + (xv + yv) + ' and pay for ' + xv + '.'
                            : 'Enter X and Y to see the rule here.';
                    }

                    // summary
                    var parts = [];
                    if (t === 'percent') parts.push((plainAmt.value || '?') + '% off');
                    else if (t === 'fixed') parts.push('৳' + (plainAmt.value || '?') + ' off per item');
                    else parts.push('Buy ' + (x.value || '?') + ', get ' + (y.value || '?') + ' free');
                    if (sc === 'all') parts.push('on all items');
                    else parts.push('on ' + (picked.length ? picked.join(', ') : 'selected items'));
                    if (isBuy) {
                        var fn = free.value === '0' ? 'same item' : free.options[free.selectedIndex].text;
                        parts.push('free: ' + fn);
                    }
                    var days = all('input[name="days[]"]:checked').map(function (c) { return c.parentNode.textContent.trim(); });
                    parts.push(days.length ? 'on ' + days.join(', ') : 'every day');
                    var st = form.querySelector('input[name="start_time"]').value, en = form.querySelector('input[name="end_time"]').value;
                    parts.push(st && en ? 'from ' + st + ' to ' + en : 'all day');
                    var nm = $('qo-name').value.trim();
                    summary.textContent = (nm ? nm + ': ' : '') + parts.join(' · ');
                }

                all('input[name="offer_type"], input[name="scope"]').forEach(function (r) { r.addEventListener('change', refresh); });
                form.addEventListener('input', refresh);
                form.addEventListener('change', refresh);
                search.addEventListener('input', function () {
                    var q = search.value.trim().toLowerCase();
                    all('.qo-item-wrap').forEach(function (w) { w.style.display = (!q || w.getAttribute('data-name').indexOf(q) !== -1) ? 'contents' : 'none'; });
                });
                refresh();
            })();
            </script>
        </div>
    </div>
    <?php
}

/**
 * Buy X get Y offer for an item (whole item or chosen variants): name, X, Y and the free item.
 * The free item is the same item when none is chosen in the offer.
 */
function qrrs_buyget_for_item( $rid, $item_id, $size_ids = [] ) {
    global $wpdb;
    $offers = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_offers WHERE restaurant_id = %d AND is_active = 1 AND offer_type = 'buyget' ORDER BY id DESC",
        intval( $rid )
    ) );
    foreach ( $offers as $o ) {
        if ( ! qrrs_offer_applies_to_line( $o, $item_id, $size_ids ) || ! qrrs_offer_is_active_now( $o ) ) continue;
        $fid   = intval( $o->free_item_id ) > 0 ? intval( $o->free_item_id ) : intval( $item_id );
        $fname = $wpdb->get_var( $wpdb->prepare( "SELECT item_name FROM {$wpdb->prefix}qrrs_items WHERE id = %d", $fid ) );
        if ( ! $fname ) continue;
        return [ 'name' => $o->name, 'amount' => (int) $o->amount, 'get_qty' => (int) $o->get_qty, 'free_item_id' => $fid, 'free_name' => $fname ];
    }
    return null;
}

/** Names of all active offers that cover the whole item now (for the card). */
function qrrs_offer_names_for_item( $rid, $item_id ) {
    global $wpdb;
    $names = [];
    $offers = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}qrrs_offers WHERE restaurant_id = %d AND is_active = 1 AND (variant_ids IS NULL OR variant_ids = '') ORDER BY id",
        intval( $rid )
    ) );
    foreach ( $offers as $o ) {
        if ( ! qrrs_offer_applies_to_line( $o, $item_id, [] ) || ! qrrs_offer_is_active_now( $o ) ) continue;
        $names[] = $o->name;
    }
    return array_values( array_unique( $names ) );
}

