<?php
if ( ! defined( 'ABSPATH' ) ) exit;


class QRRS_Auth {

    public function __construct() {
        add_action( 'init', [ $this, 'register_custom_roles' ] );
        add_action( 'init', [ $this, 'handle_frontend_login' ] );
        add_action( 'admin_init', [ $this, 'block_admin_access' ] );
        add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_for_staff' ] );
    }


    public function register_custom_roles() {
        $roles = [
            'qr_manager' => [
                'name' => __( 'Restaurant Manager', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true, 'upload_files' => true ]
            ],
            'qr_waiter' => [
                'name' => __( 'Waiter', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true ]
            ],
            'qr_kitchen' => [
                'name' => __( 'Kitchen Staff', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true ]
            ],
            'qr_cashier' => [
                'name' => __( 'Cashier', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true ]
            ],
            'qr_restaurant_admin' => [
                'name' => __( 'Restaurant Admin', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true ]
            ],
            'qr_support' => [
                'name' => __( 'Support Staff', 'qr-restaurant-system' ),
                'caps' => [ 'read' => true ]
            ]
        ];

        foreach ( $roles as $role_id => $role_data ) {
            if ( ! get_role( $role_id ) ) {
                add_role( $role_id, $role_data['name'], $role_data['caps'] );
            }
        }
    }

    public function handle_frontend_login() {
        if ( isset( $_POST['qrrs_login_nonce'] ) && wp_verify_nonce( $_POST['qrrs_login_nonce'], 'qrrs_login_action' ) ) {

            $creds = [
                'user_login'    => sanitize_text_field( $_POST['log'] ),
                'user_password' => $_POST['pwd'],
                'remember'      => isset( $_POST['rememberme'] ) ? true : false,
            ];

            $user = wp_signon( $creds, is_ssl() );

            if ( ! is_wp_error( $user ) ) {
                $this->redirect_user_by_role( $user );
            } else {
                wp_redirect( add_query_arg( 'login', 'failed', home_url( '/restaurant-login/' ) ) );
                exit;
            }
        }
    }

    public function redirect_user_by_role( $user ) {
        $user_roles = (array) $user->roles;

        // every management role and the support role land on the restaurant dashboard
        $dashboard_roles = [ 'administrator', 'qr_manager', 'qr_restaurant_admin', 'qr_cashier', 'qr_support' ];

        if ( array_intersect( $dashboard_roles, $user_roles ) ) {
            wp_redirect( home_url( '/restaurant-dashboard/' ) );
        } elseif ( in_array( 'qr_waiter', $user_roles, true ) ) {
            wp_redirect( home_url( '/waiter-dashboard/' ) );
        } elseif ( in_array( 'qr_kitchen', $user_roles, true ) ) {
            wp_redirect( home_url( '/kitchen-dashboard/' ) );
        } else {
            wp_redirect( home_url( '/restaurant-dashboard/' ) );
        }
        exit;
    }


    public static function has_permission( $required_role ) {
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url( '/restaurant-login/' ) );
            exit;
        }

        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;

        if ( in_array( 'administrator', $user_roles, true ) || in_array( $required_role, $user_roles, true ) ) {
            return true;
        }

        wp_die( __( 'Access Denied: Your status does not allow access to this section', 'qr-restaurant-system' ) );
    }


    public static function is_admin_only() {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( __( 'This action is restricted to the Administrator / Restaurant only.', 'qr-restaurant-system' ) );
        }
    }

    public function block_admin_access() {
        if ( wp_doing_ajax() || wp_doing_cron() ) return;
        // form posts from the restaurant pages must still reach their handlers
        $page = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
        if ( in_array( $page, [ 'admin-post.php', 'admin-ajax.php', 'async-upload.php', 'media-upload.php' ], true ) ) return;

        if ( is_admin() && ! current_user_can( 'administrator' ) ) {
            // a restaurant user who opens wp-admin goes back to the restaurant dashboard, not the home page
            $is_system = ! empty( array_intersect(
                [ 'qr_manager', 'qr_waiter', 'qr_kitchen', 'qr_cashier', 'qr_restaurant_admin', 'qr_support' ],
                (array) wp_get_current_user()->roles
            ) );
            wp_redirect( $is_system ? home_url( '/restaurant-dashboard/' ) : home_url() );
            exit;
        }
    }

    public function hide_admin_bar_for_staff() {
        return current_user_can( 'administrator' );
    }
}

new QRRS_Auth();