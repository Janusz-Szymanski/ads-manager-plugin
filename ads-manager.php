<?php
/*
Plugin Name: Zewnętrzne Ogłoszenia (Ads Manager)
Description: Ads Manager
Version: 0.0.1
Author: Janusz Szymański
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Autoload Composer (Stripe PHP, itp.)
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}
global $ads_manager_db_version;
$ads_manager_db_version = '1.0';

register_activation_hook( __FILE__, function() {
    global $wpdb, $ads_manager_db_version;
    $charset_collate = $wpdb->get_charset_collate();
    $table_ads = $wpdb->prefix . 'ads';
    $table_payments = $wpdb->prefix . 'ads_payments';
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    $sql1 = <<<SQL
CREATE TABLE IF NOT EXISTS {$table_ads} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT(20) UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  content LONGTEXT NOT NULL,
  category_slug VARCHAR(200) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expiration_date DATETIME NOT NULL,
  status VARCHAR(20) DEFAULT 'active',
  PRIMARY KEY (id)
) {$charset_collate};
SQL;
    $sql2 = <<<SQL
CREATE TABLE IF NOT EXISTS {$table_payments} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  ad_id BIGINT(20) UNSIGNED NOT NULL,
  user_id BIGINT(20) UNSIGNED NOT NULL,
  stripe_session_id VARCHAR(255),
  amount DECIMAL(10,2),
  currency VARCHAR(10) DEFAULT 'PLN',
  payment_status VARCHAR(50) DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) {$charset_collate};
SQL;
    dbDelta( $sql1 );
    dbDelta( $sql2 );
    if ( ! get_role( 'ads_user' ) ) {
        add_role( 'ads_user', 'Ads User', array( 'read' => true ) );
    }
    add_option( 'ads_manager_db_version', $ads_manager_db_version );
} );

// enqueue frontend assets (styles + JS)
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style( 'ads-frontend-css', plugin_dir_url( __FILE__ ) . 'assets/css/ads-frontend.css', array(), '2.6.0' );
    wp_enqueue_script( 'ads-frontend-js', plugin_dir_url( __FILE__ ) . 'assets/js/ads-frontend.js', array(), '2.6.0', true );
    wp_localize_script( 'ads-frontend-js', 'adsManager', array(
        'root' => esc_url_raw( rest_url( 'ads/v1' ) ),
        'nonce' => wp_create_nonce( 'wp_rest' ),
    ) );
} );

// admin assets
add_action( 'admin_enqueue_scripts', function() {
    wp_enqueue_style( 'ads-admin-css', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), '2.6.0' );
    wp_enqueue_script( 'ads-admin-js', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', array('jquery'), '2.6.0', true );
    wp_localize_script( 'ads-admin-js', 'adsManagerAdmin', array( 'ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ads_manager_admin') ) );
} );

// allow cookie-based REST auth for logged-in frontend users
add_filter( 'rest_authentication_errors', function( $result ) {
    if ( true === $result || is_wp_error( $result ) ) {
        return $result;
    }
    if ( is_user_logged_in() ) {
        return true;
    }
    return $result;
}, 20 );

// include admin panel and helpers
add_action('plugins_loaded', function(){
    require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
    require_once plugin_dir_path(__FILE__) . 'includes/frontend-shortcodes.php';
    require_once plugin_dir_path(__FILE__) . 'includes/admin-panel.php';
    require_once plugin_dir_path(__FILE__) . 'includes/rest-api.php';
});
