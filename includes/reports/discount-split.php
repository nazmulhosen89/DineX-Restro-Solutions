<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Discount and split-payment report (manager and admin).
 *  - Discounts: every paid bill with a discount in the period, and the total given away.
 *  - Split payments: bills paid with more than one method, with the amount of each method.
 */

function qrrs_dsr_user_can() {
    return function_exists( 'qrrs_inventory_can_manage' ) && qrrs_inventory_can_manage();
}

function qrrs_discount_split_render( $rid ) {
    if ( ! qrrs_dsr_user_can() ) {
        echo '<div class="qrrs-card" style="padding:20px;">This report is for managers and admins.</div>';
        return;
    }
    global $wpdb;
    $p   = $wpdb->prefix;
    $rid = intval( $rid );
    $today = current_time( 'Y-m-d' );
    $from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['dsr_from'] ?? '' ) ? $_GET['dsr_from'] : $today;
    $to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['dsr_to'] ?? '' )   ? $_GET['dsr_to']   : $today;
    if ( $to < $from ) { $to = $from; }
    $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
    $base  = add_query_arg( [ 'tab' => 'discount-split' ], home_url( '/restaurant-dashboard/' ) );

    $discounts = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, table_name, created_at, grand_total, discount_amount, final_total
         FROM {$p}qrrs_orders
         WHERE restaurant_id = %d AND payment_status = 'paid' AND discount_amount > 0
           AND DATE(created_at) BETWEEN %s AND %s
         ORDER BY id DESC",
        $rid, $from, $to
    ) );
    $disc_total = 0.0; $disc_base = 0.0;
    foreach ( $discounts as $d ) { $disc_total += (float) $d->discount_amount; $disc_base += (float) $d->grand_total; }

    $splits = $wpdb->get_results( $wpdb->prepare(
        "SELECT o.id, o.table_name, o.created_at, o.final_total, o.grand_total
         FROM {$p}qrrs_orders o
         JOIN {$p}qrrs_payments pl ON pl.order_id = o.id
         WHERE o.restaurant_id = %d AND o.payment_status = 'paid' AND DATE(o.created_at) BETWEEN %s AND %s
         GROUP BY o.id
         HAVING COUNT(DISTINCT pl.method) > 1
         ORDER BY o.id DESC",
        $rid, $from, $to
    ) );
    $names = qrrs_payment_methods();
    $split_total = 0.0;
    foreach ( $splits as $sp ) {
        $sp->lines = $wpdb->get_results( $wpdb->prepare(
            "SELECT method, amount, reference FROM {$p}qrrs_payments WHERE order_id = %d ORDER BY id ASC", intval( $sp->id )
        ) );
        $split_total += (float) ( $sp->final_total ?: $sp->grand_total );
    }
    ?>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; max-width:960px;">
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:20px; color:#1e293b;">Discounts &amp; split payments</h3>
            <form method="get" style="margin:0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="tab" value="discount-split">
                <label style="font-size:13px; color:#64748b;">From</label>
                <input type="date" name="dsr_from" value="<?php echo esc_attr( $from ); ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                <label style="font-size:13px; color:#64748b;">To</label>
                <input type="date" name="dsr_to" value="<?php echo esc_attr( $to ); ?>" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px;">
                <button type="submit" style="padding:8px 14px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Show</button>
            </form>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:22px;">
            <div style="background:#fff7ed; border-radius:10px; padding:14px;"><small style="color:#9a3412;">Discounted bills</small><div style="font-size:22px; font-weight:800;"><?php echo count( $discounts ); ?></div></div>
            <div style="background:#fff7ed; border-radius:10px; padding:14px;"><small style="color:#9a3412;">Total discount given</small><div style="font-size:22px; font-weight:800; color:#c2410c;"><?php echo $money( $disc_total ); ?></div></div>
            <div style="background:#eff6ff; border-radius:10px; padding:14px;"><small style="color:#1e40af;">Split bills</small><div style="font-size:22px; font-weight:800;"><?php echo count( $splits ); ?></div></div>
            <div style="background:#eff6ff; border-radius:10px; padding:14px;"><small style="color:#1e40af;">Split bills total</small><div style="font-size:22px; font-weight:800;"><?php echo $money( $split_total ); ?></div></div>
        </div>

        <h4 style="margin:0 0 8px; font-size:15px; color:#1e293b;">Discounts</h4>
        <div style="overflow-x:auto; margin-bottom:24px;">
        <table style="width:100%; border-collapse:collapse; font-size:14px;">
            <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                <th style="padding:10px;">Bill</th><th style="padding:10px;">Table</th><th style="padding:10px;">Date</th>
                <th style="padding:10px; text-align:right;">Before</th><th style="padding:10px; text-align:right;">Discount</th><th style="padding:10px; text-align:right;">Paid</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $discounts as $d ) : ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:10px;">#<?php echo intval( $d->id ); ?></td>
                    <td style="padding:10px;"><?php echo esc_html( $d->table_name ); ?></td>
                    <td style="padding:10px;"><?php echo esc_html( substr( $d->created_at, 0, 16 ) ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $money( $d->grand_total ); ?></td>
                    <td style="padding:10px; text-align:right; color:#c2410c; font-weight:600;">− <?php echo $money( $d->discount_amount ); ?></td>
                    <td style="padding:10px; text-align:right;"><?php echo $money( $d->final_total ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $discounts ) ) : ?><tr><td colspan="6" style="padding:16px; text-align:center; color:#94a3b8;">No discounts in this period.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <h4 style="margin:0 0 8px; font-size:15px; color:#1e293b;">Split payments</h4>
        <?php if ( empty( $splits ) ) : ?>
            <p style="color:#94a3b8; font-size:14px;">No split payments in this period.</p>
        <?php endif; ?>
        <?php foreach ( $splits as $sp ) : ?>
            <div style="border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin-bottom:10px;">
                <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; font-size:14px;">
                    <strong>Bill #<?php echo intval( $sp->id ); ?> · <?php echo esc_html( $sp->table_name ); ?></strong>
                    <span style="color:#64748b;"><?php echo esc_html( substr( $sp->created_at, 0, 16 ) ); ?> · Total <strong style="color:#1e293b;"><?php echo $money( $sp->final_total ?: $sp->grand_total ); ?></strong></span>
                </div>
                <div style="margin-top:6px; font-size:13px;">
                    <?php foreach ( $sp->lines as $l ) : ?>
                        <span style="display:inline-block; background:#f1f5f9; border-radius:6px; padding:3px 8px; margin:2px 4px 2px 0;">
                            <?php echo esc_html( $names[ $l->method ] ?? $l->method ); ?><?php echo $l->reference ? ' · ' . esc_html( $l->reference ) : ''; ?>: <strong><?php echo $money( $l->amount ); ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Sales report extra block: money by payment method, split bills, refunds and discounts
 * for any date range (one day or several days).
 */
function qrrs_sales_methods_html( $rid, $from, $to ) {
    global $wpdb;
    $p = $wpdb->prefix;
    $rid = intval( $rid );
    $names = qrrs_payment_methods();
    
    // Custom rounding rule: .50 or more rounds up (+1), less than .50 drops fractional part.
    $custom_round = function( $val ) {
        $val = (float) $val;
        $integer_part = floor( $val );
        $fraction = $val - $integer_part;
        return $fraction >= 0.5 ? ceil( $val ) : floor( $val );
    };

    $m = [];
    $add = function ( $key, $n, $total ) use ( &$m, $custom_round ) {
        if ( ! isset( $m[ $key ] ) ) $m[ $key ] = [ 'n' => 0, 'total' => 0.0 ];
        $m[ $key ]['n'] += (int) $n;
        $m[ $key ]['total'] += (float) $total;
    };

    foreach ( $wpdb->get_results( $wpdb->prepare(
        "SELECT method, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM {$p}qrrs_payments
         WHERE restaurant_id = %d AND DATE(created_at) BETWEEN %s AND %s GROUP BY method", $rid, $from, $to
    ) ) as $r ) { $add( $r->method, $r->n, $r->total ); }

    foreach ( $wpdb->get_results( $wpdb->prepare(
        "SELECT COALESCE(NULLIF(o.payment_method,''),'cash') AS method, COUNT(*) AS n,
                COALESCE(SUM(COALESCE(o.final_total,o.grand_total)),0) AS total
         FROM {$p}qrrs_orders o
         WHERE o.restaurant_id = %d AND o.payment_status = 'paid' AND COALESCE(o.payment_method,'') <> 'credit' AND DATE(o.created_at) BETWEEN %s AND %s
           AND NOT EXISTS (SELECT 1 FROM {$p}qrrs_payments pl WHERE pl.order_id = o.id)
         GROUP BY method", $rid, $from, $to
    ) ) as $r ) { $add( $r->method, $r->n, $r->total ); }

    uasort( $m, function ( $a, $b ) { return $b['total'] <=> $a['total']; } );

    $split_bills = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM (SELECT order_id FROM {$p}qrrs_payments WHERE restaurant_id = %d
         AND DATE(created_at) BETWEEN %s AND %s GROUP BY order_id HAVING COUNT(DISTINCT method) > 1) x",
        $rid, $from, $to
    ) );

    $disc = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(discount_amount),0) AS total FROM {$p}qrrs_orders
         WHERE restaurant_id = %d AND payment_status = 'paid' AND discount_amount > 0 AND DATE(created_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );

    $ref = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount),0) AS total FROM {$p}qrrs_orders
         WHERE restaurant_id = %d AND payment_status = 'refunded' AND DATE(refunded_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );

    // Money formatter incorporating the custom rounding rule
    $money = function ( $v ) use ( $custom_round ) {
        $rounded = $custom_round( (float) $v );
        return number_format( $rounded, 0 ) . ' ৳'; // Since it rounds to whole numbers per rule, or keep 2 decimals if needed, but rule says ".50 er besi hole add kore 1 korte and .50 er kom hole bad diye dite" which means rounding to integer!
    };

    $gross = 0.0; 
    foreach ( $m as $row ) { 
        $gross += $row['total']; 
    }
    $net_collected = $gross - (float) $ref->total;
    $credit_given = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM {$p}qrrs_dues WHERE restaurant_id = %d AND order_id > 0 AND DATE(created_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );

    // Helper to get SVG icon based on payment method or card type
    $get_icon = function( $type ) {
        switch( strtolower($type) ) {
            case 'cash':
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>';
            case 'bkash':
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>';
            case 'card':
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>';
            case 'rocket':
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>';
            case 'discount':
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>';
            case 'split':
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>';
            case 'refunds':
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>';
            default:
                return '<svg style="width:20px;height:20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>';
        }
    };

    ob_start(); ?>
    <div style="margin-top:24px;">
        <!-- <h4 style="margin:0 0 16px; font-size:16px; color:#1e293b; font-weight:700;">Payment methods &amp; breakdown (<?php echo esc_html( $from ); ?> to <?php echo esc_html( $to ); ?>)</h4> -->
        
        <!-- Cards Grid Layout -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">
            
            <?php foreach ( $m as $key => $row ) : 
                $method_name = $names[ $key ] ?? ( 'split' === $key ? 'Split (old)' : ucfirst( $key ) );
                $card_color = '#2563eb';
                $bg_color = '#eff6ff';
                $border_color = '#bfdbfe';
                if(strtolower($key) === 'cash') { $card_color = '#059669'; $bg_color = '#ecfdf5'; $border_color = '#a7f3d0'; }
                elseif(strtolower($key) === 'bkash') { $card_color = '#db2777'; $bg_color = '#fdf2f8'; $border_color = '#fbcfe8'; }
                elseif(strtolower($key) === 'rocket') { $card_color = '#7c3aed'; $bg_color = '#f5f3ff'; $border_color = '#ddd6fe'; }
            ?>
                <div style="background: <?php echo $bg_color; ?>; border: 1px solid <?php echo $border_color; ?>; border-radius: 14px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: all 0.2s;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 13px; font-weight: 700; color: <?php echo $card_color; ?>; text-transform: uppercase; letter-spacing: 0.5px;">
                            <?php echo esc_html( $method_name ); ?>
                        </span>
                        <div style="color: <?php echo $card_color; ?>; background: #ffffff; padding: 6px; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <?php echo $get_icon( $key ); ?>
                        </div>
                    </div>
                    <div style="font-size: 13px; color: #475569; margin-bottom: 4px;">
                        Payments: <strong style="font-weight: 700; color: #1e293b;"><?php echo intval( $row['n'] ); ?></strong>
                    </div>
                    <div style="font-size: 20px; font-weight: 800; color: <?php echo $card_color; ?>;">
                        <?php echo $money( $row['total'] ); ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ( empty( $m ) ) : ?>
                <div style="grid-column: 1 / -1; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; text-align: center; color: #94a3b8;">
                    No payments in this period.
                </div>
            <?php endif; ?>

            <!-- Discount Card -->
            <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: 14px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; font-weight: 700; color: #c2410c; text-transform: uppercase; letter-spacing: 0.5px;">Discount</span>
                    <div style="color: #c2410c; background: #ffffff; padding: 6px; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <?php echo $get_icon( 'discount' ); ?>
                    </div>
                </div>
                <div style="font-size: 13px; color: #9a3412; margin-bottom: 4px;">
                    Bills: <strong style="font-weight: 700; color: #7c2d12;"><?php echo intval( $disc->n ); ?></strong>
                </div>
                <div style="font-size: 20px; font-weight: 800; color: #c2410c;">
                    <?php echo $money( $disc->total ); ?>
                </div>
            </div>

            <!-- Split Bills Card -->
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 14px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; font-weight: 700; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">Split Bills</span>
                    <div style="color: #166534; background: #ffffff; padding: 6px; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <?php echo $get_icon( 'split' ); ?>
                    </div>
                </div>
                <div style="font-size: 13px; color: #15803d; margin-bottom: 4px;">
                    Count: <strong style="font-weight: 700; color: #14532d;"><?php echo intval( $split_bills ); ?></strong>
                </div>
                <div style="font-size: 20px; font-weight: 800; color: #166534;">
                    <?php echo intval( $split_bills ); ?> Bills
                </div>
            </div>

            <!-- Refunds Card -->
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 14px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px;">Refunds</span>
                    <div style="color: #991b1b; background: #ffffff; padding: 6px; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <?php echo $get_icon( 'refunds' ); ?>
                    </div>
                </div>
                <div style="font-size: 13px; color: #b91c1c; margin-bottom: 4px;">
                    Count: <strong style="font-weight: 700; color: #7f1d1d;"><?php echo intval( $ref->n ); ?></strong>
                </div>
                <div style="font-size: 20px; font-weight: 800; color: #dc2626;">
                    − <?php echo $money( $ref->total ); ?>
                </div>
            </div>

            <!-- Net Collected Card -->
            <div style="background: #1e293b; border: 2px solid #0f172a; border-radius: 14px; padding: 18px; color: #ffffff;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 13px; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.5px;">Net Collected</span>
                    <div style="color: #0f172a; background: #38bdf8; padding: 6px; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <?php echo $get_icon( 'net' ); ?>
                    </div>
                </div>
                <div style="font-size: 13px; color: #94a3b8; margin-bottom: 4px;">
                    Final Revenue
                </div>
                <div style="font-size: 20px; font-weight: 800; color: #ffffff;">
                    <?php echo $money( $net_collected ); ?>
                </div>
                <div style="font-size: 12px; color: #fdba74; margin-top: 8px;">
                    Credit given (বাকিতে, not in cash): <strong><?php echo $money( $credit_given ); ?></strong>
                </div>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}