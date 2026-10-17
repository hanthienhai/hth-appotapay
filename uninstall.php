<?php
/**
 * Chạy khi xoá plugin trong wp-admin: dọn option cài đặt (có thể chứa Secret Key nếu không dùng hằng số)
 * và các khoá theo đơn còn sót. Meta trên đơn hàng (_hth_appotapay_*) giữ lại vì là lịch sử giao dịch.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_hth_appotapay_settings' );
delete_option( 'hth_appotapay_version' );

// Khoá lẻ do tiến trình chết để lại. Xoá qua delete_option() để object cache cũng được dọn.
global $wpdb;
$hth_appotapay_locks = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'hth_appotapay_lock_' ) . '%' ) );
foreach ( $hth_appotapay_locks as $hth_appotapay_lock ) {
	delete_option( $hth_appotapay_lock );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hth_appotapay_reconcile', [], 'hth-appotapay' );
}
