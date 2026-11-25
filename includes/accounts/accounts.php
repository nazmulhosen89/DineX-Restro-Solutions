<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accounts & HR menu (manager and admin) - Clean Modular Design.
 */

function qrrs_accounts_render( $rid ) {
    if ( ! function_exists( 'qrrs_pl_user_can' ) || ! qrrs_pl_user_can() ) {
        echo '<div class="qrrs-acc-restricted">
                <div class="qrrs-acc-restricted-icon">🔒</div>
                <h3>Restricted Access</h3>
                <p>Accounts &amp; HR is securely restricted for managers and admins.</p>
              </div>';
        return;
    }

    global $wpdb;
    $rid   = intval( $rid );
    $acc   = sanitize_key( $_GET['acc'] ?? '' );
    $tab   = in_array( $acc, [ 'hr', 'closing', 'suppliers', 'customers' ], true ) ? $acc : 'expenses';
    $base  = add_query_arg( [ 'tab' => 'accounts' ], home_url( '/restaurant-dashboard/' ) );
    $today = current_time( 'Y-m-d' );
    
    $tabs  = [
        'expenses'  => '💸 Expenses',
        'suppliers' => '🚚 Suppliers',
        'customers' => '🧾 Customer Due',
        'closing'   => '🔒 Daily Closing',
        'hr'        => '👥 HR Overview'
    ];
    ?>
    <div class="qrrs-acc-wrapper">
        
        <!-- Header Section -->
        <div class="qrrs-acc-header">
            <div>
                <h2>💼 Accounts &amp; HR Management</h2>
                <p>Monitor business expenses, supplier ledgers, customer dues, and staff operations.</p>
            </div>
        </div>

        <!-- Interactive Pill Navigation Tabs -->
        <div class="qrrs-acc-tabs">
            <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'acc', $key, $base ) ); ?>"
                   class="qrrs-acc-tab-btn <?php echo ( $tab === $key ) ? 'active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ( 'expenses' === $tab ) :
            $st   = sanitize_key( $_GET['pl_status'] ?? '' );
            $msgs = [
                'added'   => [ 'success', 'Expense successfully recorded.' ],
                'deleted' => [ 'success', 'Expense entry removed.' ],
                'bad'     => [ 'error', 'Please check the date, category, and amount.' ]
            ];
            if ( isset( $msgs[ $st ] ) ) :
                [ $type, $txt ] = $msgs[ $st ]; ?>
                <div class="qrrs-acc-alert <?php echo esc_attr( $type ); ?>">
                    <?php echo esc_html( $txt ); ?>
                </div>
            <?php endif;

            $from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['ex_from'] ?? '' ) ? $_GET['ex_from'] : current_time( 'Y-m-01' );
            $to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['ex_to'] ?? '' )   ? $_GET['ex_to']   : $today;
            if ( $to < $from ) $to = $from;

            $money = fn( $v ) => number_format( (float) $v, 2 ) . ' ৳';
            
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}qrrs_expenses WHERE restaurant_id = %d AND expense_date BETWEEN %s AND %s ORDER BY expense_date DESC, id DESC",
                $rid, $from, $to
            ) );
            
            $total = 0.0;
            foreach ( $rows as $r ) { $total += (float) $r->amount; }
            ?>
            
            <!-- Record New Expense Card -->
            <div class="qrrs-acc-card">
                <h3 class="qrrs-acc-card-title">➕ Record New Expense</h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="qrrs_add_expense">
                    <?php wp_nonce_field( 'qrrs_add_expense' ); ?>
                    
                    <div class="qrrs-acc-form-grid">
                        <div class="qrrs-acc-field">
                            <label>Date</label>
                            <input type="date" name="expense_date" value="<?php echo esc_attr( $today ); ?>" required>
                        </div>
                        
                        <div class="qrrs-acc-field">
                            <label>Category</label>
                            <select name="category" required>
                                <?php foreach ( qrrs_pl_expense_categories() as $c ) : ?>
                                    <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="qrrs-acc-field">
                            <label>Amount (৳)</label>
                            <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00">
                        </div>
                        
                        <div class="qrrs-acc-field qrrs-acc-field-wide">
                            <label>Note (Optional)</label>
                            <input type="text" name="note" maxlength="255" placeholder="e.g. Electricity bill, Kitchen supplies...">
                        </div>
                        
                        <div class="qrrs-acc-field-btn">
                            <button type="submit" class="qrrs-acc-btn-success">Save Expense</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Filter & Total Summary Bar -->
            <form method="get" class="qrrs-acc-filter-bar">
                <input type="hidden" name="tab" value="accounts">
                <input type="hidden" name="acc" value="expenses">
                
                <div class="qrrs-acc-filter-group">
                    <div class="qrrs-acc-field">
                        <label>From Date</label>
                        <input type="date" name="ex_from" value="<?php echo esc_attr( $from ); ?>">
                    </div>
                    
                    <div class="qrrs-acc-field">
                        <label>To Date</label>
                        <input type="date" name="ex_to" value="<?php echo esc_attr( $to ); ?>">
                    </div>
                    
                    <div class="qrrs-acc-filter-actions">
                        <button type="submit" class="qrrs-acc-btn-primary">Filter</button>
                        <a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'accounts', 'acc' => 'expenses' ], home_url( '/restaurant-dashboard/' ) ) ); ?>" class="qrrs-acc-btn-secondary">Reset</a>
                    </div>
                </div>
                
                <div class="qrrs-acc-total-box">
                    <span>Period Total</span>
                    <strong><?php echo $money( $total ); ?></strong>
                </div>
            </form>

            <!-- Expenses Ledger Table -->
            <div class="qrrs-acc-table-card">
                <div class="qrrs-acc-table-responsive">
                    <table class="qrrs-acc-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Note / Description</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( ! empty( $rows ) ) : ?>
                            <?php foreach ( $rows as $e ) : ?>
                                <tr>
                                    <td class="col-date"><?php echo esc_html( date( 'M j, Y', strtotime( $e->expense_date ) ) ); ?></td>
                                    <td>
                                        <span class="qrrs-acc-cat-badge"><?php echo esc_html( $e->category ); ?></span>
                                    </td>
                                    <td class="col-note"><?php echo esc_html( $e->note ?: '—' ); ?></td>
                                    <td class="col-amount text-right"><?php echo $money( $e->amount ); ?></td>
                                    <td class="text-right">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="inline-form" onsubmit="return confirm('Remove this expense entry?');">
                                            <input type="hidden" name="action" value="qrrs_delete_expense">
                                            <input type="hidden" name="expense_id" value="<?php echo intval( $e->id ); ?>">
                                            <?php wp_nonce_field( 'qrrs_delete_expense_' . intval( $e->id ) ); ?>
                                            <button type="submit" class="qrrs-acc-btn-danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="5" class="qrrs-acc-empty">
                                    <div class="empty-icon">💸</div>
                                    <div class="empty-title">No expenses recorded in this period</div>
                                    <div class="empty-subtitle">Add an expense above or adjust your date filter.</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ( 'customers' === $tab ) : ?>
            <?php if ( function_exists( 'qrrs_customers_due_render' ) ) { qrrs_customers_due_render( $rid ); } ?>
        <?php elseif ( 'suppliers' === $tab ) : ?>
            <?php if ( function_exists( 'qrrs_suppliers_render' ) ) { qrrs_suppliers_render( $rid ); } ?>
        <?php elseif ( 'closing' === $tab ) : ?>
            <?php if ( function_exists( 'qrrs_closing_render' ) ) { qrrs_closing_render( $rid ); } ?>

        <?php else : ?>
            <?php if ( function_exists( 'qrrs_hr_render' ) ) { qrrs_hr_render( $rid ); } ?>
        <?php endif; ?>
    </div>
    <?php
}