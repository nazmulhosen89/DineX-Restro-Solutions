<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accounts & HR menu (manager and admin).
 *  - Accounts: expenses (rent, electricity, salary ...) are entered and listed here.
 *  - HR: staff overview. Attendance and salary tools are added next.
 */

function qrrs_accounts_render( $rid ) {
    if ( ! function_exists( 'qrrs_pl_user_can' ) || ! qrrs_pl_user_can() ) {
        echo '<div class="qrrs-card" style="padding:20px;">Accounts &amp; HR is for managers and admins.</div>';
        return;
    }
    global $wpdb;
    $rid   = intval( $rid );
    $acc   = sanitize_key( $_GET['acc'] ?? '' );
    $tab   = in_array( $acc, [ 'hr', 'closing', 'suppliers' ], true ) ? $acc : 'expenses';
    $base  = add_query_arg( [ 'tab' => 'accounts' ], home_url( '/restaurant-dashboard/' ) );
    $today = current_time( 'Y-m-d' );
    $tabs  = [ 'expenses' => '💸 Expenses', 'suppliers' => '🚚 Suppliers', 'closing' => '🔒 Daily closing', 'hr' => '👥 HR' ];
    ?>
    <div>
        <div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
            <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'acc', $key, $base ) ); ?>"
                   style="padding:9px 16px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; border:1px solid <?php echo $tab === $key ? '#2563eb' : '#cbd5e1'; ?>; background:<?php echo $tab === $key ? '#eff6ff' : '#fff'; ?>; color:<?php echo $tab === $key ? '#1d4ed8' : '#334155'; ?>;">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ( 'expenses' === $tab ) :
            $st = sanitize_key( $_GET['pl_status'] ?? '' );
            $msgs = [ 'added' => [ 'ok', 'Expense saved.' ], 'deleted' => [ 'ok', 'Expense removed.' ], 'bad' => [ 'err', 'Check the date, category and amount.' ] ];
            if ( isset( $msgs[ $st ] ) ) {
                [ $k, $txt ] = $msgs[ $st ];
                echo '<div style="background:' . ( 'ok' === $k ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ) . '; padding:10px 14px; border-radius:8px; margin-bottom:14px;">' . esc_html( $txt ) . '</div>';
            }
            $from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['ex_from'] ?? '' ) ? $_GET['ex_from'] : current_time( 'Y-m-01' );
            $to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['ex_to'] ?? '' )   ? $_GET['ex_to']   : $today;
            if ( $to < $from ) $to = $from;
            $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
            $inp   = 'padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px;';
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}qrrs_expenses WHERE restaurant_id = %d AND expense_date BETWEEN %s AND %s ORDER BY expense_date DESC, id DESC",
                $rid, $from, $to
            ) );
            $total = 0.0; foreach ( $rows as $r ) { $total += (float) $r->amount; }
            ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:18px;">
                <strong style="font-size:15px; color:#1e293b;">Add an expense</strong>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px;">
                    <input type="hidden" name="action" value="qrrs_add_expense">
                    <?php wp_nonce_field( 'qrrs_add_expense' ); ?>
                    <input type="date" name="expense_date" value="<?php echo esc_attr( $today ); ?>" required style="<?php echo $inp; ?>">
                    <select name="category" required style="<?php echo $inp; ?>">
                        <?php foreach ( qrrs_pl_expense_categories() as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option><?php endforeach; ?>
                    </select>
                    <input type="number" name="amount" step="0.01" min="0.01" required placeholder="Amount" style="<?php echo $inp; ?> width:130px;">
                    <input type="text" name="note" maxlength="255" placeholder="Note (optional)" style="<?php echo $inp; ?> flex:1; min-width:160px;">
                    <button type="submit" style="padding:9px 16px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Save expense</button>
                </form>
            </div>

            <form method="get" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
                <input type="hidden" name="tab" value="accounts">
                <input type="hidden" name="acc" value="expenses">
                <label style="font-size:13px; color:#64748b;">From</label><input type="date" name="ex_from" value="<?php echo esc_attr( $from ); ?>" style="<?php echo $inp; ?>">
                <label style="font-size:13px; color:#64748b;">To</label><input type="date" name="ex_to" value="<?php echo esc_attr( $to ); ?>" style="<?php echo $inp; ?>">
                <button type="submit" style="padding:9px 14px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">Show</button>
                <span style="margin-left:auto; font-size:14px; color:#334155;">Total: <strong><?php echo $money( $total ); ?></strong></span>
            </form>

            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                <thead><tr style="text-align:left; font-size:11px; text-transform:uppercase; color:#94a3b8; background:#f8fafc;">
                    <th style="padding:10px;">Date</th><th style="padding:10px;">Category</th><th style="padding:10px;">Note</th><th style="padding:10px; text-align:right;">Amount</th><th style="padding:10px;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $e ) : ?>
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:9px;"><?php echo esc_html( $e->expense_date ); ?></td>
                        <td style="padding:9px;"><?php echo esc_html( $e->category ); ?></td>
                        <td style="padding:9px; color:#64748b;"><?php echo esc_html( $e->note ?: '—' ); ?></td>
                        <td style="padding:9px; text-align:right;"><?php echo $money( $e->amount ); ?></td>
                        <td style="padding:9px; text-align:right;">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;" onsubmit="return confirm('Remove this expense?');">
                                <input type="hidden" name="action" value="qrrs_delete_expense">
                                <input type="hidden" name="expense_id" value="<?php echo intval( $e->id ); ?>">
                                <?php wp_nonce_field( 'qrrs_delete_expense_' . intval( $e->id ) ); ?>
                                <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:13px;">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $rows ) ) : ?><tr><td colspan="5" style="padding:14px; text-align:center; color:#94a3b8;">No expenses in this period.</td></tr><?php endif; ?>
                </tbody>
            </table>

        <?php elseif ( 'suppliers' === $tab ) : ?>
            <?php if ( function_exists( 'qrrs_suppliers_render' ) ) { qrrs_suppliers_render( $rid ); } ?>
        <?php elseif ( 'closing' === $tab ) : ?>
            <?php if ( function_exists( 'qrrs_closing_render' ) ) { qrrs_closing_render( $rid ); } ?>

        <?php else : 
            $staff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}qrrs_staff WHERE restaurant_id = %d AND status = 'active'", $rid ) );
            ?>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:18px;">
                <p style="margin:0 0 6px; font-size:15px; color:#1e293b;"><strong>Active staff:</strong> <?php echo $staff; ?></p>
                <p style="margin:0; font-size:13px; color:#64748b;">Staff list is in the Create Staff menu. Attendance, shift and salary tools will be added here next.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}