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

$g      = WC()->payment_gateways()->payment_gateways()['hth_appotapay'];
$secret = $g->get_option( 'secret_key' );
$call   = function ( string $method, ...$args ) use ( $g ) {
	$m = new ReflectionMethod( $g, $method );
	$m->setAccessible( true );
	return $m->invoke( $g, ...$args );
};
$px = $call( 'prefix' );

// 0. Tiền tố mã đơn.
$check( 'Tiền tố hiện tại hợp lệ: ' . $px, (bool) preg_match( '/^[A-Z]{2,6}$/', $px ) );
$check( 'Tiền tố nhập chữ thường: tự viết hoa', 'ABC' === $g->validate_order_prefix_field( 'order_prefix', ' abc ' ) );
$check( 'Tiền tố có số hoặc dấu: giữ giá trị cũ', $px === $g->validate_order_prefix_field( 'order_prefix', 'A1-' ) );

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
$check( 'Chữ ký đúng: nhận', null !== $client->verify_callback( $data, hash_hmac( 'sha256', $data, $secret ) ) );
$check( 'Chữ ký sai: từ chối', null === $client->verify_callback( $data, str_repeat( '0', 64 ) ) );
$check( 'Thiếu chữ ký: từ chối', null === $client->verify_callback( $data, null ) );
$check( 'Data bị sửa: từ chối', null === $client->verify_callback( $data . 'x', hash_hmac( 'sha256', $data, $secret ) ) );
$empty = new HTH_AppotaPay_Client( 'P', 'K', '', true );
$check( 'Khoá rỗng: từ chối cả chữ ký tự ký bằng khoá rỗng', null === $empty->verify_callback( $data, hash_hmac( 'sha256', $data, '' ) ) );
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

$with_ref = function ( string $status = 'pending', array $props = [] ) use ( $new_order ) {
	$o   = $new_order();
	$ref = $px . $o->get_id() . 'T1';
	$o->update_meta_data( '_hth_appotapay_refs', [ $ref ] );
	$o->set_props( $props );
	$o->set_status( $status );
	$o->save();
	return [ $o->get_id(), $ref ];
};
$asked = []; // mã tham chiếu đã tra trong lượt
$state = []; // mã tham chiếu => success | error; còn lại API báo pending
$mock  = function ( $m, $url ) use ( &$asked, &$state ) {
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
$check( 'Lượt sau tra tới đơn chưa được tra', in_array( $g3[1], $second, true ) && ! in_array( $g1[1], $second, true ) );

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

// Chưa nhập khoá: không gọi API.
$saved               = $g->settings['api_key'];
$g->settings['api_key'] = '';
$asked               = [];
$g->reconcile_recent();
$g->settings['api_key'] = $saved;
$check( 'Chưa cấu hình khoá: không tra', [] === $asked );

$cleanup();

WP_CLI::line( implode( "\n", $results ) );
$fails = count( array_filter( $results, fn( $l ) => 0 === strpos( $l, 'FAIL' ) ) );
WP_CLI::line( sprintf( '%d/%d đạt', count( $results ) - $fails, count( $results ) ) );
