<?php
/**
 * Plugin Name: HTH AppotaPay
 * Plugin URI: https://github.com/hanthienhai/hth-appotapay
 * Description: Cổng thanh toán AppotaPay (hosted checkout v2) cho WooCommerce.
 * Version: 0.2.0
 * Author: hanthienhai
 * Author URI: https://github.com/hanthienhai
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires Plugins: woocommerce
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * Text Domain: hth-appotapay
 */

defined( 'ABSPATH' ) || exit;

define( 'HTH_APPOTAPAY_DIR', __DIR__ );
define( 'HTH_APPOTAPAY_RECONCILE_HOOK', 'hth_appotapay_reconcile' );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		// Chỉ hỗ trợ checkout shortcode cổ điển (trang thanh-toan của site đang dùng).
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
	}
} );

add_action( 'admin_notices', function () {
	$s = get_option( 'woocommerce_hth_appotapay_settings', [] );
	if ( 'production' !== wp_get_environment_type() || 'yes' !== ( $s['enabled'] ?? '' ) || 'yes' !== ( $s['sandbox'] ?? '' ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>AppotaPay đang ở chế độ sandbox trên site thật.</strong> Khách không thấy cổng này; chỉ quản trị viên thử được. Đổi sang khoá production rồi bỏ chọn "Môi trường thử" trước khi mở cho khách.</p></div>';
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once HTH_APPOTAPAY_DIR . '/includes/class-hth-appotapay-client.php';
	require_once HTH_APPOTAPAY_DIR . '/includes/class-hth-appotapay-gateway.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'HTH_AppotaPay_Gateway';
		return $gateways;
	} );

	add_action( HTH_APPOTAPAY_RECONCILE_HOOK, function () {
		$gateway = WC()->payment_gateways()->payment_gateways()['hth_appotapay'] ?? null;
		$gateway && $gateway->reconcile_recent();
	} );
} );

// Tra lại định kỳ các đơn còn chờ thanh toán (xem HTH_AppotaPay_Gateway::reconcile_recent).
function hth_appotapay_schedule_reconcile(): void {
	if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( HTH_APPOTAPAY_RECONCILE_HOOK, [], 'hth-appotapay' ) ) {
		as_schedule_recurring_action( time() + 10 * MINUTE_IN_SECONDS, 10 * MINUTE_IN_SECONDS, HTH_APPOTAPAY_RECONCILE_HOOK, [], 'hth-appotapay' );
	}
}

function hth_appotapay_unschedule_reconcile(): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( HTH_APPOTAPAY_RECONCILE_HOOK, [], 'hth-appotapay' );
	}
}

register_activation_hook( __FILE__, 'hth_appotapay_schedule_reconcile' );
register_deactivation_hook( __FILE__, 'hth_appotapay_unschedule_reconcile' );
// Chép đè bản mới lên plugin đang bật thì không chạy lại hook kích hoạt: tự đăng ký bù khi vào wp-admin.
add_action( 'admin_init', 'hth_appotapay_schedule_reconcile' );
