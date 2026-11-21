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
    $due_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT method, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM {$p}qrrs_due_payments
         WHERE restaurant_id = %d AND paid_on BETWEEN %s AND %s GROUP BY method ORDER BY total DESC",
        $rid, $from, $to
    ) );
    $sales_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(final_total,grand_total)),0) AS total FROM {$p}qrrs_orders
         WHERE restaurant_id = %d AND payment_status = 'paid' AND DATE(created_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );
    $credit_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM {$p}qrrs_dues
         WHERE restaurant_id = %d AND order_id > 0 AND DATE(created_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );
    $outstanding = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT (SELECT COALESCE(SUM(amount),0) FROM {$p}qrrs_dues WHERE restaurant_id = %d) - (SELECT COALESCE(SUM(amount),0) FROM {$p}qrrs_due_payments WHERE restaurant_id = %d)",
        $rid, $rid
    ) );
    $rounding_total = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(rounding_amount),0) FROM {$p}qrrs_orders
         WHERE restaurant_id = %d AND payment_status = 'paid' AND DATE(created_at) BETWEEN %s AND %s",
        $rid, $from, $to
    ) );
    // Bills whose money does not add up: total must equal payments + credit
    $mismatch = $wpdb->get_results( $wpdb->prepare(
        "SELECT o.id, o.final_total,
                COALESCE((SELECT SUM(pl.amount) FROM {$p}qrrs_payments pl WHERE pl.order_id = o.id),0) AS paid,
                COALESCE((SELECT SUM(d.amount) FROM {$p}qrrs_dues d WHERE d.order_id = o.id),0) AS credit
         FROM {$p}qrrs_orders o
         WHERE o.restaurant_id = %d AND o.payment_status = 'paid' AND DATE(o.created_at) BETWEEN %s AND %s
         HAVING ABS(o.final_total - paid - credit) > 0.5
         ORDER BY o.id",
        $rid, $from, $to
    ) );
    $due_total = 0.0; $due_count = 0;
    foreach ( $due_rows as $dr ) { $due_total += (float) $dr->total; $due_count += (int) $dr->n; }
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
        <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px;">

            <!-- 2. Collection, by method -->
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:18px;">
                <div style="font-size:13px; font-weight:700; color:#166534; text-transform:uppercase; letter-spacing:0.5px;">Collection</div>
                <?php foreach ( $m as $key => $row ) :
                    $method_name = $names[ $key ] ?? ( 'split' === $key ? 'Split (old)' : ucfirst( $key ) ); ?>
                    <div style="display:flex; justify-content:space-between; font-size:14px; color:#14532d; margin-top:8px;">
                        <span><?php echo esc_html( $method_name ); ?> <span style="color:#64748b; font-size:12px;">(<?php echo intval( $row['n'] ); ?>)</span></span>
                        <strong><?php echo $money( $row['total'] ); ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if ( empty( $m ) ) : ?><div style="font-size:13px; color:#64748b; margin-top:8px;">No collection in this period.</div><?php endif; ?>
                <div style="display:flex; justify-content:space-between; font-size:14px; color:#14532d; margin-top:10px; padding-top:10px; border-top:1px dashed #86efac;">
                    <span>Total collected</span><strong><?php echo $money( $gross ); ?></strong>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:13px; color:#b91c1c; margin-top:4px;">
                    <span>Less refunds</span><span>− <?php echo $money( $ref->total ); ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:13px; color:#475569; margin-top:4px;">
                    <span>Rounding (+/−)</span><span><?php echo ( $rounding_total >= 0 ? '+ ' : '− ' ) . $money( abs( $rounding_total ) ); ?></span>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:15px; font-weight:800; color:#14532d; margin-top:6px;">
                    <span>Net collected</span><span><?php echo $money( $net_collected ); ?></span>
                </div>
            </div>

            <!-- 3. Discount -->
            <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:14px; padding:18px;">
                <div style="font-size:13px; font-weight:700; color:#c2410c; text-transform:uppercase; letter-spacing:0.5px;">Discount</div>
                <div style="font-size:24px; font-weight:800; color:#c2410c; margin-top:10px;"><?php echo $money( $disc->total ); ?></div>
                <div style="font-size:13px; color:#9a3412; margin-top:6px;">Bills: <strong><?php echo intval( $disc->n ); ?></strong></div>
            </div>

            <!-- 4. Split Bills -->
            <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:14px; padding:18px;">
                <div style="font-size:13px; font-weight:700; color:#1e40af; text-transform:uppercase; letter-spacing:0.5px;">Split Bills</div>
                <div style="font-size:24px; font-weight:800; color:#1e3a8a; margin-top:10px;"><?php echo intval( $split_bills ); ?> Bills</div>
                <div style="font-size:13px; color:#1e40af; margin-top:6px;">Paid with more than one method</div>
            </div>

            <!-- 5. Refunds -->
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:14px; padding:18px;">
                <div style="font-size:13px; font-weight:700; color:#b91c1c; text-transform:uppercase; letter-spacing:0.5px;">Refunds</div>
                <div style="font-size:24px; font-weight:800; color:#b91c1c; margin-top:10px;">− <?php echo $money( $ref->total ); ?></div>
                <div style="font-size:13px; color:#991b1b; margin-top:6px;">Bills: <strong><?php echo intval( $ref->n ); ?></strong></div>
            </div>

            <!-- 6. Due & Collection -->
            <div style="background:#faf5ff; border:1px solid #e9d5ff; border-radius:14px; padding:18px;">
                <div style="font-size:13px; font-weight:700; color:#6b21a8; text-transform:uppercase; letter-spacing:0.5px;">Due &amp; Collection</div>
                <div style="font-size:13px; color:#6b21a8; margin-top:10px;">Due given (বাকি): <strong><?php echo intval( $credit_row->n ); ?></strong> · <strong><?php echo $money( $credit_row->total ); ?></strong></div>
                <div style="font-size:13px; color:#6b21a8; margin-top:6px;">Due collected (আদায়): <strong><?php echo intval( $due_count ); ?></strong> · <strong><?php echo $money( $due_total ); ?></strong></div>
                <?php foreach ( $due_rows as $dr ) : ?>
                    <div style="font-size:12px; color:#7e22ce; margin-top:3px;">&nbsp;&nbsp;<?php echo esc_html( $names[ $dr->method ] ?? $dr->method ); ?>: <?php echo $money( $dr->total ); ?></div>
                <?php endforeach; ?>
                <div style="font-size:13px; font-weight:700; color:#581c87; margin-top:10px; padding-top:8px; border-top:1px dashed #d8b4fe;">Total due outstanding: <?php echo $money( $outstanding ); ?></div>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}