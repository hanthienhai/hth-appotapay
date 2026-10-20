<?php
/**
 * Bộ thử cho plugin, chạy bằng WP-CLI trên site local:
 *   studio wp eval-file wp-content/plugins/hth-appotapay/tests/run.php
 * Mọi lệnh gọi AppotaPay đều bị giả lập qua pre_http_request, không gọi mạng thật.
 * Tạo đơn thử rồi xoá khi xong. Không chạy trên site thật.
 */

defined( 'WP_CLI' ) || exit;

$results = [];
$check   = function ( string $name, bool $ok ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name;
};

$mock = null; // fn( string $method, string $url, array $args ): array|WP_Error
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$mock ) {
	if ( false === strpos( $url, 'appotapay.com' ) ) {
		return $pre;
	}
	$r = $mock ? $mock( $args['method'] ?? 'GET', $url, $args ) : new WP_Error( 'mock', 'no mock' );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	return [ 'headers' => [], 'body' => wp_json_encode( $r[1] ), 'response' => [ 'code' => $r[0], 'message' => '' ], 'cookies' => [], 'filename' => null ];
}, 10, 3 );

$g    = WC()->payment_gateways()->payment_gateways()['hth_appotapay'];
// WOOCS nhớ tiền tệ hiện tại trong transient theo IP; trong WP-CLI mọi lượt chạy dùng chung một khoá, nên ép về VND trước khi tạo đơn thử.
global $WOOCS;
if ( is_object( $WOOCS ) && 'VND' !== $WOOCS->current_currency ) {
	$WOOCS->set_currency( 'VND' );
}
$call = function ( string $method, ...$args ) use ( $g ) {
	$m = new ReflectionMethod( $g, $method );
	$m->setAccessible( true );
	return $m->invoke( $g, ...$args );
};
$secret = $call( 'credential', 'secret_key' );
$px     = $call( 'prefix' );

// 0. Tiền tố mã đơn.
$check( 'Tiền tố hiện tại hợp lệ: ' . $px, (bool) preg_match( '/^[A-Z]{2,6}$/', $px ) );
$check( 'Tiền tố nhập chữ thường: tự viết hoa', 'ABC' === $g->validate_order_prefix_field( 'order_prefix', ' abc ' ) );
$check( 'Tiền tố có số hoặc dấu: giữ giá trị cũ', $px === $g->validate_order_prefix_field( 'order_prefix', 'A1-' ) );
foreach ( HTH_AppotaPay_Gateway::CONSTANTS as $k => $c ) {
	if ( defined( $c ) ) {
		$check( "Hằng số $c đã đặt: trang cài đặt không in giá trị DB của $k", '' === $g->get_option( $k ) );
	}
}

$product = wc_get_products( [ 'limit' => 1, 'status' => 'publish', 'type' => 'simple', 'stock_status' => 'instock' ] )[0];
$orders  = [];
$new_order = function () use ( $product, &$orders ) {
	$o = wc_create_order();
	$o->add_product( $product, 1 );
	$o->set_billing_email( 'appotapay-test@example.test' );
	$o->set_payment_method( 'hth_appotapay' );
	$o->calculate_totals();
	$o->update_meta_data( '_hth_appotapay_test', '1' );
	$o->save();
	$orders[] = $o->get_id();
	return $o;
};
$txn_ok = function ( $ref, $amount, $txn, $extra = [] ) {
	return [ 200, [
		'transaction'      => array_merge( [ 'transactionId' => $txn, 'status' => 'success', 'orderAmount' => $amount, 'currency' => 'VND', 'paymentMethod' => 'ATM', 'bankCode' => 'MB' ], $extra ),
		'partnerReference' => [ 'order' => [ 'id' => $ref ] ],
	] ];
};

// 1. Tạo giao dịch: link thanh toán phải là https trên appotapay.com.
$o    = $new_order();
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APX1' ], 'payment' => [ 'url' => 'https://evil.example/pay' ] ] ];
$r    = $g->process_payment( $o->get_id() );
$check( 'Từ chối link thanh toán ngoài appotapay.com', 'failure' === $r['result'] );
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APX1' ], 'payment' => [ 'url' => 'http://payment.dev.appotapay.com/x' ] ] ];
$check( 'Từ chối link thanh toán không HTTPS', 'failure' === $g->process_payment( $o->get_id() )['result'] );
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APX1' ], 'payment' => [ 'url' => 'https://payment.dev.appotapay.com/x' ] ] ];
$r    = $g->process_payment( $o->get_id() );
$check( 'Nhận link https://payment.dev.appotapay.com', 'success' === $r['result'] );
$refs = wc_get_order( $o->get_id() )->get_meta( '_hth_appotapay_refs' );
$check( 'Mã tham chiếu không trùng, không có phần tử rỗng', count( $refs ) === count( array_unique( array_filter( $refs ) ) ) && count( $refs ) === 3 );
$check( 'Mã tham chiếu chỉ gồm chữ và số', (bool) preg_match( '/^[A-Za-z0-9]+$/', end( $refs ) ) );
$mock = fn() => new WP_Error( 'timeout', 'timeout' );
$check( 'Mất mạng khi tạo giao dịch: báo lỗi, không gửi lại', 'failure' === $g->process_payment( $o->get_id() )['result'] );

// Client: không theo redirect, timeout riêng từng lệnh, log che link thanh toán và thông tin thẻ.
$logs = [];
$lc   = new HTH_AppotaPay_Client( 'P', 'K', 'S', true, function ( $m ) use ( &$logs ) {
	$logs[] = $m;
} );
$seen = [];
$mock = function ( $m, $url, $args ) use ( &$seen ) {
	$seen = $args;
	return [ 200, [] ];
};
$lc->create_payment( [], 'vi' );
$check( 'Tạo giao dịch: không theo redirect, chờ tối đa 15 giây', 0 == $seen['redirection'] && 15 == $seen['timeout'] );
$lc->get_transaction_by_partner_ref( 'X' );
$check( 'Tra giao dịch: không theo redirect, chờ tối đa 10 giây', 0 == $seen['redirection'] && 10 == $seen['timeout'] );
$lc->get_refund_by_partner_ref( 'X' );
$check( 'Tra lệnh hoàn: chờ tối đa 10 giây', 10 == $seen['timeout'] );
$lc->refund( [] );
$check( 'Hoàn tiền: giữ 30 giây', 30 == $seen['timeout'] );

$logs = [];
$mock = fn() => [ 200, [
	'transaction' => [ 'transactionId' => 'APL1', 'status' => 'success' ],
	'payment'     => [ 'url' => 'https://payment.dev.appotapay.com/secret-link-xyz' ],
	'cardInfo'    => [ 'cardNumber' => '970422xxxx4047', 'cardName' => 'ARTURO MOEN' ],
	'tokenInfo'   => [ 'token' => 'tok_abc', 'number' => '400000-1091', 'holder_name' => 'NGUYEN VAN A' ],
] ];
$lc->create_payment( [], 'vi' );
$all = implode( "\n", $logs );
$check( 'Log che link thanh toán, số thẻ, tên chủ thẻ, token', false === strpos( $all, 'secret-link' ) && false === strpos( $all, '4047' ) && false === strpos( $all, 'ARTURO' ) && false === strpos( $all, '1091' ) && false === strpos( $all, 'NGUYEN' ) && false === strpos( $all, 'tok_abc' ) );
$check( 'Log vẫn ghi mã giao dịch và trạng thái', false !== strpos( $all, 'APL1' ) && false !== strpos( $all, 'success' ) );
$logs = [];
$mock = fn() => [ 200, '<html>bad gateway</html>' ];
[ $lc_code, $lc_body ] = $lc->create_payment( [], 'vi' );
$check( 'Body không phải JSON: trả null, log ghi rõ', 200 === $lc_code && null === $lc_body && false !== strpos( implode( '', $logs ), 'không phải JSON' ) );

// 2. Đối soát: chỉ xác nhận khi API báo success, khớp số tiền, mã đơn và tiền tệ.
$o     = $new_order();
$ref   = $px . $o->get_id() . 'T1000';
$o->update_meta_data( '_hth_appotapay_refs', [ $ref ] );
$o->save();
$total = (int) $o->get_total();

$mock = fn() => [ 200, [ 'transaction' => [ 'status' => 'pending' ] ] ];
$check( 'API báo pending: không xác nhận', 'pending' === $call( 'reconcile', wc_get_order( $o->get_id() ), $ref ) && ! wc_get_order( $o->get_id() )->is_paid() );
$mock = fn() => $txn_ok( $ref, $total - 1000, 'APA1' );
$check( 'Lệch số tiền: chuyển tạm giữ, không xác nhận', 'error' === $call( 'reconcile', wc_get_order( $o->get_id() ), $ref ) && 'on-hold' === wc_get_order( $o->get_id() )->get_status() );
$mock = fn() => $txn_ok( $ref, $total, 'APA1', [ 'currency' => 'USD' ] );
$check( 'Sai tiền tệ: không xác nhận', 'error' === $call( 'reconcile', wc_get_order( $o->get_id() ), $ref ) && ! wc_get_order( $o->get_id() )->is_paid() );
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APA1', 'status' => 'success', 'orderAmount' => $total, 'currency' => 'VND' ], 'partnerReference' => [ 'order' => [ 'id' => $px . '999T1' ] ] ] ];
$check( 'Sai mã đơn trong phản hồi: không xác nhận', 'error' === $call( 'reconcile', wc_get_order( $o->get_id() ), $ref ) && ! wc_get_order( $o->get_id() )->is_paid() );

// Khoá kẹt từ một tiến trình chết: quá 60 giây thì tự gỡ.
update_option( 'hth_appotapay_lock_' . $o->get_id(), time() - 120, false );
$mock = fn() => $txn_ok( $ref, $total, 'APA1' );
$call( 'reconcile', wc_get_order( $o->get_id() ), $ref );
$paid = wc_get_order( $o->get_id() );
$check( 'Khoá kẹt quá 60 giây: vẫn xác nhận được đơn', $paid->is_paid() && 'APA1' === $paid->get_transaction_id() );
$check( 'Khoá được gỡ sau khi xong', false === get_option( 'hth_appotapay_lock_' . $o->get_id() ) );

update_option( 'hth_appotapay_lock_' . $o->get_id(), time(), false );
$o2 = $new_order();
$o2->update_meta_data( '_hth_appotapay_refs', [ $px . $o2->get_id() . 'T1' ] );
$o2->save();
update_option( 'hth_appotapay_lock_' . $o2->get_id(), time(), false );
$mock = fn() => $txn_ok( $px . $o2->get_id() . 'T1', (int) $o2->get_total(), 'APB1' );
$call( 'reconcile', wc_get_order( $o2->get_id() ), $px . $o2->get_id() . 'T1' );
$check( 'Khoá còn mới: tiến trình thứ hai không xác nhận chồng', ! wc_get_order( $o2->get_id() )->is_paid() );
delete_option( 'hth_appotapay_lock_' . $o->get_id() );
delete_option( 'hth_appotapay_lock_' . $o2->get_id() );

// Khoá ghi thẳng vào wp_options bằng INSERT IGNORE: lấy hai lần liền thì lần hai bị từ chối.
global $wpdb;
$lock_key = 'hth_appotapay_lock_' . $o2->get_id();
$check( 'Lấy khoá lần đầu: được', true === $call( 'lock', $o2->get_id() ) );
$check( 'Lấy khoá lần hai khi khoá còn mới: bị từ chối', false === $call( 'lock', $o2->get_id() ) );
$lock_row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $lock_key ) );
$check( 'Khoá nằm trong wp_options, không autoload, mang giờ hiện tại', $lock_row && in_array( $lock_row->autoload, [ 'no', 'off' ], true ) && (int) $lock_row->option_value >= time() - 5 );
$wpdb->update( $wpdb->options, [ 'option_value' => (string) ( time() - 120 ) ], [ 'option_name' => $lock_key ] );
$check( 'Khoá kẹt quá 60 giây: giành lại được bằng UPDATE', true === $call( 'lock', $o2->get_id() ) );
$check( 'Giành lại xong thì khoá lại mới: lần sau bị từ chối', false === $call( 'lock', $o2->get_id() ) );
$call( 'unlock', $o2->get_id() );
$check( 'Gỡ khoá: option biến mất', false === get_option( $lock_key ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $lock_key ) ) );

// Kết quả lạ cho đơn đã trả: chỉ ghi chú, không kéo đơn về tạm giữ.
$mock = fn() => $txn_ok( $ref, $total - 1000, 'APA1' );
$call( 'reconcile', wc_get_order( $o->get_id() ), $ref );
$check( 'Kết quả lệch trên đơn đã trả: giữ trạng thái đã thanh toán', wc_get_order( $o->get_id() )->is_paid() );

// Gọi lại nhiều lần (IPN gửi lại): không thêm ghi chú thanh toán thứ hai.
$mock         = fn() => $txn_ok( $ref, $total, 'APA1' );
$notes_before = count( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ) );
$call( 'reconcile', wc_get_order( $o->get_id() ), $ref );
$check( 'IPN gửi lại: không xử lý lần hai', count( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ) ) === $notes_before );

// Khách trả hai lần (hai tab): lần thứ hai phải để lại cảnh báo, một lần thôi.
$ref2 = $px . $o->get_id() . 'T2000';
$o    = wc_get_order( $o->get_id() );
$o->update_meta_data( '_hth_appotapay_refs', [ $ref, $ref2 ] );
$o->save();
$mock = fn() => $txn_ok( $ref2, $total, 'APA2' );
$call( 'reconcile', wc_get_order( $o->get_id() ), $ref2 );
$call( 'reconcile', wc_get_order( $o->get_id() ), $ref2 );
$warn = array_filter( wc_get_order_notes( [ 'order_id' => $o->get_id() ] ), fn( $n ) => false !== strpos( $n->content, 'APA2' ) && false !== strpos( $n->content, 'CẢNH BÁO' ) );
$check( 'Thanh toán trùng: có đúng một cảnh báo cần hoàn', 1 === count( $warn ) );
$check( 'Thanh toán trùng: không đổi mã giao dịch gốc', 'APA1' === wc_get_order( $o->get_id() )->get_transaction_id() );

// 3. Callback: chữ ký, mã đơn lạ, khoá rỗng.
$client = new HTH_AppotaPay_Client( 'P', 'K', $secret, true );
$data   = base64_encode( wp_json_encode( [ 'partnerReference' => [ 'order' => [ 'id' => $ref ] ] ] ) );
$sig    = hash_hmac( 'sha256', $data, $secret );
$now    = (string) time();
$check( 'Chữ ký đúng: nhận', null !== $client->verify_callback( $data, $sig, $now ) );
$check( 'Chữ ký sai: từ chối', null === $client->verify_callback( $data, str_repeat( '0', 64 ), $now ) );
$check( 'Thiếu chữ ký: từ chối', null === $client->verify_callback( $data, null, $now ) );
$check( 'Data bị sửa: từ chối', null === $client->verify_callback( $data . 'x', $sig, $now ) );
$empty = new HTH_AppotaPay_Client( 'P', 'K', '', true );
$check( 'Khoá rỗng: từ chối cả chữ ký tự ký bằng khoá rỗng', null === $empty->verify_callback( $data, hash_hmac( 'sha256', $data, '' ), $now ) );

// Trường time: callback cũ quá 24 giờ không còn là vé xem đơn; nhận Unix giây (chuỗi hoặc số như IPN) và mili giây.
$check( 'Callback time cách 23 giờ: nhận', null !== $client->verify_callback( $data, $sig, (string) ( time() - 23 * HOUR_IN_SECONDS ) ) );
$check( 'Callback time cũ quá 24 giờ: từ chối dù chữ ký đúng', null === $client->verify_callback( $data, $sig, (string) ( time() - 25 * HOUR_IN_SECONDS ) ) );
$check( 'Callback time ở tương lai quá 24 giờ: từ chối', null === $client->verify_callback( $data, $sig, (string) ( time() + 25 * HOUR_IN_SECONDS ) ) );
$check( 'Callback thiếu time: từ chối', null === $client->verify_callback( $data, $sig, '' ) && null === $client->verify_callback( $data, $sig, null ) );
$check( 'Callback time không phải số: từ chối', null === $client->verify_callback( $data, $sig, '2024-09-11T11:32:58' ) && ! HTH_AppotaPay_Client::is_fresh( [ 'x' ] ) );
$check( 'Callback time là số JSON (IPN): nhận', null !== $client->verify_callback( $data, $sig, time() ) );
$check( 'Callback time dạng mili giây: nhận', HTH_AppotaPay_Client::is_fresh( (string) ( time() * 1000 ) ) && ! HTH_AppotaPay_Client::is_fresh( (string) ( ( time() - 25 * HOUR_IN_SECONDS ) * 1000 ) ) );
$from_ref = new ReflectionMethod( HTH_AppotaPay_Gateway::class, 'order_from_ref' );
$from_ref->setAccessible( true );
$check( 'Mã đơn hợp lệ của đơn khác: từ chối', null === $from_ref->invoke( $g, $px . $o->get_id() . 'T9999' ) );
$check( 'Mã đơn sai định dạng: từ chối', null === $from_ref->invoke( $g, $px . $o->get_id() . 'T1000x' ) );

// 4. Hoàn tiền: mất mạng thì không gửi lệnh mới mù quáng.
$sent = [];
$mock = function ( $m, $url, $args ) use ( &$sent ) {
	$sent[] = $m . ' ' . $url;
	return new WP_Error( 'timeout', 'timeout' );
};
$r = $g->process_refund( $o->get_id(), 50000, 'test' );
$inflight = wc_get_order( $o->get_id() )->get_meta( '_hth_appotapay_refund_inflight' );
$check( 'Hoàn tiền mất mạng: báo lỗi và nhớ lệnh đang treo', is_wp_error( $r ) && ! empty( $inflight['ref'] ) );

$sent = [];
$mock = function ( $m, $url ) use ( &$sent ) {
	$sent[] = $m . ' ' . $url;
	return false !== strpos( $url, '/refund/' ) ? [ 200, [ 'status' => 'success', 'refundId' => 'R1' ] ] : [ 200, [ 'status' => 'pending' ] ];
};
$r = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'Lệnh treo thật ra đã hoàn: không gửi lệnh mới', is_wp_error( $r ) && 1 === count( $sent ) && 0 === strpos( $sent[0], 'GET' ) );
$check( 'Lệnh treo được gỡ sau khi tra', '' === wc_get_order( $o->get_id() )->get_meta( '_hth_appotapay_refund_inflight' ) );

$o = wc_get_order( $o->get_id() );
$o->update_meta_data( '_hth_appotapay_refund_inflight', [ 'ref' => $px . 'R1T1', 'amount' => 50000 ] );
$o->save();
$sent = [];
$mock = function ( $m, $url ) use ( &$sent ) {
	$sent[] = $m . ' ' . $url;
	return false !== strpos( $url, '/refund/' ) ? [ 404, [ 'errorCode' => 36, 'message' => 'not found' ] ] : [ 200, [ 'status' => 'pending', 'refundId' => 'R2' ] ];
};
$r = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'Lệnh treo không tồn tại (lỗi 36): gửi lệnh mới', true === $r && 2 === count( $sent ) );

$o = wc_get_order( $o->get_id() );
$o->update_meta_data( '_hth_appotapay_refund_inflight', [ 'ref' => $px . 'R1T1', 'amount' => 50000 ] );
$o->save();
$sent = [];
$mock = function ( $m, $url ) use ( &$sent ) {
	$sent[] = $m . ' ' . $url;
	return new WP_Error( 'timeout', 'timeout' );
};
$r = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'Chưa tra được lệnh treo: không gửi lệnh mới', is_wp_error( $r ) && 1 === count( $sent ) );
$check( 'Hoàn dưới 1.000đ: từ chối', is_wp_error( $g->process_refund( $o->get_id(), 500, 'x' ) ) );

// Lệnh hoàn gặp 5xx hoặc body không phải JSON: AppotaPay có thể đã nhận lệnh, phải nhớ lệnh treo như khi mất mạng.
$clear_inflight = function () use ( $o ) {
	$o = wc_get_order( $o->get_id() );
	$o->delete_meta_data( '_hth_appotapay_refund_inflight' );
	$o->save();
};
$inflight_ref = fn() => (string) ( wc_get_order( $o->get_id() )->get_meta( '_hth_appotapay_refund_inflight' )['ref'] ?? '' );
$clear_inflight();
$sent = [];
$mock = function ( $m, $url ) use ( &$sent ) {
	$sent[] = $m . ' ' . $url;
	return [ 504, null ];
};
$r = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'Hoàn tiền gặp 504 không có JSON: báo lỗi và nhớ lệnh treo', is_wp_error( $r ) && '' !== $inflight_ref() );
$sent = [];
$mock = function ( $m, $url ) use ( &$sent ) {
	$sent[] = $m . ' ' . $url;
	return false !== strpos( $url, '/refund/' ) ? [ 200, [ 'status' => 'success', 'refundId' => 'R3' ] ] : [ 200, [ 'status' => 'pending', 'refundId' => 'R4' ] ];
};
$r = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'Sau 504, lệnh treo thật ra đã được nhận: chỉ GET tra, không POST lệnh mới', is_wp_error( $r ) && 1 === count( $sent ) && 0 === strpos( $sent[0], 'GET' ) );

$clear_inflight();
$mock = fn() => [ 500, [ 'errorCode' => 500, 'message' => 'Internal Server Error' ] ];
$r    = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'Hoàn tiền gặp 500 có JSON: vẫn nhớ lệnh treo', is_wp_error( $r ) && '' !== $inflight_ref() );

$clear_inflight();
$mock = fn() => [ 200, '<html>bad gateway</html>' ];
$r    = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'Hoàn tiền trả 200 nhưng body không phải JSON: nhớ lệnh treo', is_wp_error( $r ) && '' !== $inflight_ref() );

$clear_inflight();
$mock = fn() => [ 400, [ 'errorCode' => 12, 'message' => 'Số tiền vượt quá giao dịch gốc' ] ];
$r    = $g->process_refund( $o->get_id(), 50000, 'test' );
$check( 'AppotaPay từ chối rõ ràng (4xx có JSON): không nhớ lệnh treo', is_wp_error( $r ) && '' === $inflight_ref() );

// 5. Sandbox trên site production: khách không thấy cổng, quản trị viên vẫn thấy.
$prev = wp_get_current_user()->ID;
wp_set_current_user( 0 );
$check( 'Môi trường hiện tại: ' . wp_get_environment_type(), true );
if ( 'production' === wp_get_environment_type() ) {
	$check( 'Sandbox + production + khách: ẩn cổng', ! $g->is_available() );
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) $admins[0] );
	$check( 'Sandbox + production + quản trị viên: hiện cổng', $g->is_available() );
} else {
	$check( 'Sandbox + local + khách: hiện cổng', $g->is_available() );
}
wp_set_current_user( $prev );

// Dọn đơn thử.
$cleanup = function () use ( &$orders ) {
	foreach ( $orders as $id ) {
		wc_get_order( $id )->delete( true );
	}
	$orders = [];
};
$cleanup();

// 6. Tra lại định kỳ: đơn kẹt "chờ thanh toán" khi cả IPN lẫn trang trả về đều hỏng.
hth_appotapay_unschedule_reconcile();
$check( 'Tắt plugin: gỡ lịch tra lại', ! as_has_scheduled_action( HTH_APPOTAPAY_RECONCILE_HOOK ) );
hth_appotapay_schedule_reconcile();
hth_appotapay_schedule_reconcile();
$jobs = as_get_scheduled_actions( [ 'hook' => HTH_APPOTAPAY_RECONCILE_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ] );
$job  = reset( $jobs );
$check( 'Bật plugin: đúng một lịch, 10 phút một lần', 1 === count( $jobs ) && 600 === $job->get_schedule()->get_recurrence() );

// admin_init chỉ kiểm lịch khi phiên bản đổi, để không query Action Scheduler ở mọi request wp-admin.
hth_appotapay_unschedule_reconcile();
update_option( 'hth_appotapay_version', HTH_APPOTAPAY_VERSION, false );
hth_appotapay_admin_init();
$check( 'admin_init khi phiên bản đã ghi: không đụng tới lịch', ! as_has_scheduled_action( HTH_APPOTAPAY_RECONCILE_HOOK ) );
delete_option( 'hth_appotapay_version' );
hth_appotapay_admin_init();
$check( 'admin_init khi phiên bản mới: đăng ký bù lịch và ghi phiên bản', as_has_scheduled_action( HTH_APPOTAPAY_RECONCILE_HOOK ) && HTH_APPOTAPAY_VERSION === get_option( 'hth_appotapay_version' ) );

$with_ref = function ( string $status = 'pending', array $props = [] ) use ( $new_order, $px ) {
	$o   = $new_order();
	$ref = $px . $o->get_id() . 'T1';
	$o->update_meta_data( '_hth_appotapay_refs', [ $ref ] );
	$o->set_props( $props );
	$o->set_status( $status );
	$o->save();
	// Kho cổ điển (HPOS tắt): wp_update_post luôn ghi post_modified = bây giờ, nên phải đặt mốc quá khứ thẳng vào bảng posts.
	if ( isset( $props['date_modified'] ) ) {
		global $wpdb;
		$ts = (int) $props['date_modified'];
		$wpdb->update( $wpdb->posts, [ 'post_modified' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $ts ) ], [ 'ID' => $o->get_id() ] );
		clean_post_cache( $o->get_id() );
	}
	return [ $o->get_id(), $ref ];
};
$asked = []; // mã tham chiếu đã tra trong lượt
$state = []; // mã tham chiếu => success | error; còn lại API báo pending
$mock  = function ( $m, $url ) use ( &$asked, &$state, $px ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	$ref     = $q['referenceId'] ?? '';
	$asked[] = $ref;
	if ( 'success' === ( $state[ $ref ] ?? '' ) && preg_match( '/^' . $px . '(\d+)T/', $ref, $m ) ) {
		return [ 200, [
			'transaction'      => [ 'transactionId' => 'AP' . $ref, 'status' => 'success', 'orderAmount' => (int) wc_get_order( (int) $m[1] )->get_total(), 'currency' => 'VND' ],
			'partnerReference' => [ 'order' => [ 'id' => $ref ] ],
		] ];
	}
	return [ 200, [ 'transaction' => [ 'status' => $state[ $ref ] ?? 'pending', 'errorMessage' => 'Thẻ bị từ chối' ] ] ];
};
$run = function () use ( &$asked ) {
	$asked = [];
	do_action( HTH_APPOTAPAY_RECONCILE_HOOK );
	return $asked;
};

// Giới hạn số đơn mỗi lượt; đơn vừa tra xuống cuối hàng nên đơn khác tới lượt sau.
$g1 = $with_ref( 'pending', [ 'date_modified' => time() - 3 * HOUR_IN_SECONDS ] );
$g2 = $with_ref( 'pending', [ 'date_modified' => time() - 2 * HOUR_IN_SECONDS ] );
$g3 = $with_ref( 'pending', [ 'date_modified' => time() - HOUR_IN_SECONDS ] );
$limit = fn() => 2;
add_filter( 'hth_appotapay_reconcile_limit', $limit );
$first  = $run();
$second = $run();
remove_filter( 'hth_appotapay_reconcile_limit', $limit );
$check( 'Mỗi lượt chỉ tra tới giới hạn số đơn', [ $g1[1], $g2[1] ] === $first && count( $second ) <= 2 );
// Sau lượt 1, g1 và g2 bị đẩy xuống cuối hàng nên g3 (chưa tra) phải đứng đầu lượt 2; ô còn lại là g1 hoặc g2 tuỳ cách DB xếp khi hoà giờ.
$check( 'Lượt sau tra tới đơn chưa được tra', 2 === count( $second ) && $g3[1] === $second[0] );

[ $paid_id, $paid_ref ]         = $with_ref();
[ $cancel_id, $cancel_ref ]     = $with_ref( 'cancelled' );
[ $failed_id, $failed_ref ]     = $with_ref( 'failed' );
[ $old_id, $old_ref ]           = $with_ref( 'pending', [ 'date_created' => time() - 25 * HOUR_IN_SECONDS ] );
[ $bacs_id, $bacs_ref ]         = $with_ref( 'pending', [ 'payment_method' => 'bacs' ] );
[ $done_id, $done_ref ]         = $with_ref( 'processing' );
[ $fail_id, $fail_ref ]         = $with_ref();
$state = [ $paid_ref => 'success', $cancel_ref => 'success', $failed_ref => 'success', $old_ref => 'success', $bacs_ref => 'success', $done_ref => 'success', $fail_ref => 'error' ];

$asked1 = $run();
$asked2 = $run();
$paid   = wc_get_order( $paid_id );
$check( 'Đơn chờ thanh toán đã trả: được xác nhận', $paid->is_paid() && 'AP' . $paid_ref === $paid->get_transaction_id() );
$check( 'Đơn bị huỷ khi hết giờ giữ hàng nhưng đã trả: được xác nhận', wc_get_order( $cancel_id )->is_paid() );
$check( 'Đơn ở trạng thái lỗi nhưng đã trả: được xác nhận', wc_get_order( $failed_id )->is_paid() );
$check( 'Đơn tạo quá 24 giờ: không tra', ! in_array( $old_ref, array_merge( $asked1, $asked2 ), true ) && ! wc_get_order( $old_id )->is_paid() );
$check( 'Đơn của cổng khác: không tra', ! in_array( $bacs_ref, array_merge( $asked1, $asked2 ), true ) && ! wc_get_order( $bacs_id )->is_paid() );
$check( 'Đơn đã thanh toán: không tra', ! in_array( $done_ref, array_merge( $asked1, $asked2 ), true ) );
$check( 'Đơn đã xác nhận: lượt sau không tra lại', ! in_array( $paid_ref, $asked2, true ) );
$fail_notes = array_filter( wc_get_order_notes( [ 'order_id' => $fail_id ] ), fn( $n ) => false !== strpos( $n->content, 'thất bại' ) );
$check( 'Giao dịch thất bại: tra một lần, ghi chú một lần', in_array( $fail_ref, $asked1, true ) && ! in_array( $fail_ref, $asked2, true ) && 1 === count( $fail_notes ) );
$check( 'Giao dịch thất bại: đơn vẫn chờ thanh toán để khách trả lại', 'pending' === wc_get_order( $fail_id )->get_status() );

// Khoá chung với IPN: đơn đang được IPN xử lý thì lượt định kỳ không xác nhận chồng.
[ $busy_id, $busy_ref ] = $with_ref();
$state[ $busy_ref ]     = 'success';
update_option( 'hth_appotapay_lock_' . $busy_id, time(), false );
$run();
$check( 'Đơn đang bị khoá bởi IPN: không xác nhận chồng', ! wc_get_order( $busy_id )->is_paid() );
delete_option( 'hth_appotapay_lock_' . $busy_id );
$run();
$check( 'Gỡ khoá xong: lượt sau xác nhận được', wc_get_order( $busy_id )->is_paid() );

// Mỗi đơn chỉ tra 2 mã mới nhất; mã tạo quá 2 giờ mà vẫn pending thì coi là hết hạn, không tra nữa.
$o        = $new_order();
$older    = $px . $o->get_id() . 'T' . ( time() - 4 * HOUR_IN_SECONDS ) . '111';
$expired  = $px . $o->get_id() . 'T' . ( time() - 3 * HOUR_IN_SECONDS ) . '222';
$fresh    = $px . $o->get_id() . 'T' . ( time() - 60 ) . '333';
$o->update_meta_data( '_hth_appotapay_refs', [ $older, $expired, $fresh ] );
$o->set_status( 'pending' );
$o->save();
$asked1 = $run();
$asked2 = $run();
$check( 'Chỉ tra 2 mã mới nhất của đơn', in_array( $fresh, $asked1, true ) && in_array( $expired, $asked1, true ) && ! in_array( $older, $asked1, true ) );
$check( 'Mã quá 2 giờ vẫn pending: ghi vào final, lượt sau không tra', in_array( $expired, (array) wc_get_order( $o->get_id() )->get_meta( '_hth_appotapay_final_refs' ), true ) && ! in_array( $expired, $asked2, true ) );
$check( 'Mã mới vẫn pending: lượt sau tra tiếp', in_array( $fresh, $asked2, true ) && ! in_array( $fresh, (array) wc_get_order( $o->get_id() )->get_meta( '_hth_appotapay_final_refs' ), true ) );
$state[ $expired ] = 'success';
$run();
$check( 'Mã đã hết hạn không được tra lại dù API đổi ý', ! wc_get_order( $o->get_id() )->is_paid() );

// Quá ngân sách thời gian: dừng lượt sau đơn đang tra, đơn còn lại tới lượt sau.
$budget = fn() => 0;
add_filter( 'hth_appotapay_reconcile_budget', $budget );
$asked  = $run();
remove_filter( 'hth_appotapay_reconcile_budget', $budget );
$asked_orders = array_unique( array_map( function ( $r ) use ( $px ) {
	return preg_match( '/^' . $px . '(\d+)T/', $r, $m ) ? $m[1] : '';
}, $asked ) );
$check( 'Ngân sách thời gian 0 giây: chỉ tra đúng một đơn rồi dừng', 1 === count( $asked_orders ) && count( $asked ) <= 2 );

// Chưa nhập khoá: không gọi API.
$saved               = $g->settings['api_key'];
$g->settings['api_key'] = '';
$asked               = [];
$g->reconcile_recent();
$g->settings['api_key'] = $saved;
$check( 'Chưa cấu hình khoá: không tra', [] === $asked );

$cleanup();

// 7. Tiền tệ khác VND: quy đổi theo tỷ giá WOOCS, hiện cổng "Pay in VND", gửi/đối chiếu/hoàn đều bằng VND.
global $WOOCS;
$check( 'WOOCS có mặt với tiền tệ gốc VND (các ca dưới cần)', is_object( $WOOCS ) && 'VND' === $WOOCS->default_currency );
$usd = (float) $WOOCS->get_currencies()['USD']['rate'];
$php = (float) $WOOCS->get_currencies()['PHP']['rate'];
$check( 'Tỷ giá: VND = 1, USD/PHP từ bảng WOOCS, tiền tệ lạ = null', 1.0 === HTH_AppotaPay_Gateway::rate( 'VND' ) && $usd === HTH_AppotaPay_Gateway::rate( 'USD' ) && $php === HTH_AppotaPay_Gateway::rate( 'PHP' ) && null === HTH_AppotaPay_Gateway::rate( 'XXX' ) );
// Giá PHP khách thấy đã làm tròn 1 chữ số nên quy về VND có thể lệch vài đồng so với giá niêm yết (4.284.500 ₫ → 10.579 ₱ → 4.284.495 ₫).
$check( 'Quy đổi làm tròn tới đồng: 7.616 PHP → 3.084.480 ₫, 10.579 PHP → 4.284.495 ₫', [ 3084480, $php ] === HTH_AppotaPay_Gateway::to_vnd( 7616, 'PHP' ) && 4284495 === HTH_AppotaPay_Gateway::to_vnd( 10579, 'PHP' )[0] );
$rate_f = fn( $rate, $cur ) => 'XXX' === $cur ? 0.001 : $rate;
add_filter( 'hth_appotapay_vnd_rate', $rate_f, 10, 2 );
$check( 'Filter hth_appotapay_vnd_rate cấp tỷ giá cho tiền tệ ngoài WOOCS', 1000000 === HTH_AppotaPay_Gateway::to_vnd( 1000, 'XXX' )[0] );
remove_filter( 'hth_appotapay_vnd_rate', $rate_f );

// WOOCS quyết định get_woocommerce_currency() qua filter ưu tiên 9999 của nó, nên đổi bằng set_currency() rồi trả về VND ở cuối mục.
$WOOCS->set_currency( 'USD' );
$check( 'Tiền tệ hiện tại USD có tỷ giá: hiện cổng, tiêu đề là ô "khi tiền tệ khác VND"', $g->is_available() && $g->get_option( 'title_foreign' ) === $g->get_title() );
$saved_tf                    = $g->settings['title_foreign'] ?? null;
$g->settings['title_foreign'] = '';
$check( 'Ô tiêu đề để trống: ẩn cổng với USD', ! $g->is_available() );
null === $saved_tf ? ( function () use ( $g ) { unset( $g->settings['title_foreign'] ); } )() : $g->settings['title_foreign'] = $saved_tf;
$WOOCS->set_currency( 'XXX' );
$check( 'Tiền tệ không có trong WOOCS: ẩn cổng', ! $g->is_available() );
$WOOCS->set_currency( 'VND' );
$check( 'VND: vẫn hiện cổng với tiêu đề thường', 'VND' === get_woocommerce_currency() && $g->is_available() && $g->get_option( 'title' ) === $g->get_title() );

$new_foreign = function ( string $cur, float $total ) use ( $new_order ) {
	$o = $new_order();
	$o->set_currency( $cur );
	$o->set_total( $total );
	$o->save();
	return wc_get_order( $o->get_id() );
};
$o   = $new_foreign( 'USD', 144 );
$vnd = HTH_AppotaPay_Gateway::to_vnd( 144, 'USD' )[0];
// Giả lập trang order-pay của đơn này (khách trả lại đơn cũ): tiền tệ hiện tại là VND nhưng đơn là USD.
global $wp;
$wp->query_vars['order-pay'] = $o->get_id();
set_query_var( 'order-pay', $o->get_id() );
$check( 'Trang order-pay không có khoá đơn đúng: không đọc đơn, mô tả thường', $g->get_option( 'description' ) === $g->get_description() );
$_GET['key'] = $o->get_order_key();
$desc = $g->get_description();
$check( 'Trang order-pay đơn USD: hiện cổng dù tiền tệ hiện tại là VND', $g->is_available() && $g->get_option( 'title_foreign' ) === $g->get_title() );
$check( 'Mô tả điền {vnd} và {total}, không còn placeholder', false !== strpos( $desc, HTH_AppotaPay_Gateway::format_vnd( $vnd ) ) && false !== strpos( $desc, '144' ) && false === strpos( $desc, '{' ) );
unset( $wp->query_vars['order-pay'], $_GET['key'] );
set_query_var( 'order-pay', null );
$check( 'Rời trang order-pay: mô tả thường', $g->get_option( 'description' ) === $g->get_description() );

$body = null;
$mock = function ( $m, $url, $args ) use ( &$body ) {
	$body = is_array( $args['body'] ?? null ) ? $args['body'] : json_decode( (string) ( $args['body'] ?? '' ), true );
	return [ 200, [ 'transaction' => [ 'transactionId' => 'APU1' ], 'payment' => [ 'url' => 'https://payment.dev.appotapay.com/x' ] ] ];
};
$r = $g->process_payment( $o->get_id() );
$o = wc_get_order( $o->get_id() );
$check( 'Đơn USD: gửi AppotaPay số VND quy đổi, tiền tệ VND', 'success' === $r['result'] && $vnd === (int) ( $body['transaction']['amount'] ?? 0 ) && 'VND' === ( $body['transaction']['currency'] ?? '' ) );
$check( 'Đơn USD: ghi số VND và tỷ giá vào đơn', $vnd === (int) $o->get_meta( '_hth_appotapay_vnd' ) && $usd === (float) $o->get_meta( '_hth_appotapay_rate' ) );
$notes = wc_get_order_notes( [ 'order_id' => $o->get_id() ] );
$check( 'Ghi chú nêu số VND thu và tỷ giá', false !== strpos( $notes[0]->content, 'Thu ' ) && false !== strpos( $notes[0]->content, 'USD' ) );
$refs = (array) $o->get_meta( '_hth_appotapay_refs' );
$ref  = end( $refs );
$mock = fn() => $txn_ok( $ref, 144, 'TXU0' );
$check( 'API báo 144 (số USD) thay vì số VND: không xác nhận, tạm giữ', 'error' === $call( 'reconcile', $o, $ref ) && 'on-hold' === wc_get_order( $o->get_id() )->get_status() );
$mock = fn() => $txn_ok( $ref, $vnd, 'TXU1' );
$check( 'API báo đúng số VND đã lưu: xác nhận đơn USD', 'success' === $call( 'reconcile', wc_get_order( $o->get_id() ), $ref ) && wc_get_order( $o->get_id() )->is_paid() );

$o = $new_foreign( 'PHP', 7616 );
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APP1' ], 'payment' => [ 'url' => 'https://payment.dev.appotapay.com/x' ] ] ];
$g->process_payment( $o->get_id() );
$o = wc_get_order( $o->get_id() );
$check( 'Đơn PHP 7.616: ghi 3.084.480 ₫', 3084480 === (int) $o->get_meta( '_hth_appotapay_vnd' ) );
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APP1' ], 'payment' => [ 'url' => 'https://payment.dev.appotapay.com/x' ] ] ];
$rate_small = fn( $rate, $cur ) => 'PHP' === $cur ? 100.0 : $rate; // 1 PHP = 0,01 ₫ → đơn dưới 1.000 ₫
add_filter( 'hth_appotapay_vnd_rate', $rate_small, 10, 2 );
$check( 'Giới hạn 1.000 ₫ kiểm trên số VND quy đổi', 'failure' === $g->process_payment( $o->get_id() )['result'] );
remove_filter( 'hth_appotapay_vnd_rate', $rate_small );
$cur_none = fn( $rate, $cur ) => 'PHP' === $cur ? null : $rate;
add_filter( 'hth_appotapay_vnd_rate', $cur_none, 10, 2 );
$check( 'Mất tỷ giá lúc bấm thanh toán: từ chối, không gọi API', 'failure' === $g->process_payment( $o->get_id() )['result'] );
remove_filter( 'hth_appotapay_vnd_rate', $cur_none );

// Hoàn tiền đơn USD: số WooCommerce đưa vào là USD, lệnh gửi AppotaPay là VND.
$o = $new_foreign( 'USD', 144 );
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APU2' ], 'payment' => [ 'url' => 'https://payment.dev.appotapay.com/x' ] ] ];
$g->process_payment( $o->get_id() );
$o = wc_get_order( $o->get_id() );
$o->payment_complete( 'TXU2' );
$sent = [];
$mock = function ( $m, $url, $args ) use ( &$sent ) {
	$b      = is_array( $args['body'] ?? null ) ? $args['body'] : json_decode( (string) ( $args['body'] ?? '' ), true );
	$sent[] = [ $m, $url, $b ];
	return 'POST' === $m ? [ 200, [ 'status' => 'success', 'refundId' => 'RU' . count( $sent ) ] ] : [ 404, [ 'errorCode' => 36 ] ];
};
$part = (int) round( 50 / $usd );
$r    = $g->process_refund( $o->get_id(), 50, 'test' );
$led  = wc_get_order( $o->get_id() )->get_meta( '_hth_appotapay_refunded' );
$check( 'Hoàn 50 USD: gửi lệnh hoàn ' . number_format( $part ) . ' ₫, ghi sổ', true === $r && $part === (int) ( $sent[0][2]['amount'] ?? 0 ) && 'VND' === ( $sent[0][2]['currency'] ?? '' ) && 50.0 === (float) $led['amount'] && $part === (int) $led['vnd'] );
$sent = [];
$r    = $g->process_refund( $o->get_id(), 94, 'test' );
$check( 'Hoàn nốt 94 USD: hoàn đúng phần VND còn lại, không lệch làm tròn', true === $r && ( $vnd - $part ) === (int) ( $sent[0][2]['amount'] ?? 0 ) );
$r = $g->process_refund( $o->get_id(), 10, 'test' );
$check( 'Đã hoàn hết: từ chối, không gửi lệnh', is_wp_error( $r ) && 1 === count( $sent ) );
$o = $new_foreign( 'USD', 144 );
$mock = fn() => [ 200, [ 'transaction' => [ 'transactionId' => 'APU3' ], 'payment' => [ 'url' => 'https://payment.dev.appotapay.com/x' ] ] ];
$g->process_payment( $o->get_id() );
$o = wc_get_order( $o->get_id() );
$o->payment_complete( 'TXU3' );
$sent = [];
$mock = function ( $m, $url, $args ) use ( &$sent ) {
	$b      = is_array( $args['body'] ?? null ) ? $args['body'] : json_decode( (string) ( $args['body'] ?? '' ), true );
	$sent[] = [ $m, $url, $b ];
	return 'POST' === $m ? [ 200, [ 'status' => 'success', 'refundId' => 'RU9' ] ] : [ 404, [ 'errorCode' => 36 ] ];
};
$r = $g->process_refund( $o->get_id(), 144, 'test' );
$check( 'Hoàn trọn đơn USD một lần: đúng số VND đã thu', true === $r && $vnd === (int) ( $sent[0][2]['amount'] ?? 0 ) );
$WOOCS->set_currency( 'VND' );
$cleanup();

// 8. Gỡ plugin: uninstall.php xoá option cài đặt, phiên bản và khoá lẻ. Sao lưu rồi trả lại để site thử vẫn chạy.
$settings_backup = get_option( 'woocommerce_hth_appotapay_settings' );
update_option( 'hth_appotapay_lock_999999', time(), false );
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', true );
include dirname( __DIR__ ) . '/uninstall.php';
$check( 'uninstall.php: xoá option cài đặt, phiên bản, khoá lẻ và lịch', false === get_option( 'woocommerce_hth_appotapay_settings' ) && false === get_option( 'hth_appotapay_version' ) && false === get_option( 'hth_appotapay_lock_999999' ) && ! as_has_scheduled_action( HTH_APPOTAPAY_RECONCILE_HOOK ) );
false !== $settings_backup && update_option( 'woocommerce_hth_appotapay_settings', $settings_backup, 'yes' );
hth_appotapay_admin_init();
$check( 'Trả lại cài đặt và lịch sau khi thử uninstall', $settings_backup === get_option( 'woocommerce_hth_appotapay_settings' ) && as_has_scheduled_action( HTH_APPOTAPAY_RECONCILE_HOOK ) );

WP_CLI::line( implode( "\n", $results ) );
$fails = count( array_filter( $results, fn( $l ) => 0 === strpos( $l, 'FAIL' ) ) );
WP_CLI::line( sprintf( '%d/%d đạt', count( $results ) - $fails, count( $results ) ) );
