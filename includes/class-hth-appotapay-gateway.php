<?php
/**
 * Cổng thanh toán WooCommerce dùng hosted checkout của AppotaPay.
 *
 * Luồng: tạo giao dịch → chuyển khách sang payment.url → AppotaPay gọi IPN (chỉ khi thành công)
 * và đưa khách về redirectUrl. Cả hai đường đều kiểm chữ ký rồi gọi API tra trạng thái;
 * đơn chỉ được xác nhận khi API báo success, đúng mã đơn, đúng số tiền và đúng tiền tệ.
 *
 * AppotaPay chỉ thu VND. Đơn bằng tiền tệ khác (khách đổi tiền qua WOOCS) được quy về VND theo tỷ giá
 * trong bảng tiền tệ của WOOCS: vnd = tổng đơn / rate. Số VND này ghi vào đơn lúc bấm thanh toán và là
 * số duy nhất dùng để đối chiếu và hoàn tiền. Không đọc meta _woocs_order_rate vì WOOCS ghi đè nó mỗi lần trang "Cảm ơn" của đơn được mở, theo tiền tệ của người mở.
 *
 * Khoá có thể đặt trong wp-config.php (nên dùng khi chạy thật để khoá không nằm trong DB/bản sao lưu):
 *   define( 'HTH_APPOTAPAY_PARTNER_CODE', '...' );
 *   define( 'HTH_APPOTAPAY_API_KEY', '...' );
 *   define( 'HTH_APPOTAPAY_SECRET_KEY', '...' );
 */

defined( 'ABSPATH' ) || exit;

class HTH_AppotaPay_Gateway extends WC_Payment_Gateway {

	const META_REFS            = '_hth_appotapay_refs';
	const META_REFUND_INFLIGHT = '_hth_appotapay_refund_inflight';
	const META_DUPLICATE       = '_hth_appotapay_duplicate_txns';
	const META_FINAL_REFS      = '_hth_appotapay_final_refs';
	const META_CHECKED_AT      = '_hth_appotapay_checked_at';
	/** Số VND gửi AppotaPay và tỷ giá dùng lúc bấm thanh toán (1 đơn vị tiền tệ của đơn = 1/rate VND). */
	const META_VND             = '_hth_appotapay_vnd';
	const META_RATE            = '_hth_appotapay_rate';
	/** Sổ hoàn qua cổng: ['amount' => tổng đã hoàn theo tiền tệ đơn, 'vnd' => tổng VND đã hoàn], để hoàn nốt đúng phần còn lại và không vượt số đã thu. */
	const META_REFUNDED        = '_hth_appotapay_refunded';

	/** Số đơn tối đa mỗi lượt tra lại định kỳ, để không dồn lệnh gọi API. */
	const RECONCILE_LIMIT = 10;

	/** Số giây tối đa một lượt tra lại được chạy: quá thì dừng, đơn còn lại tới lượt sau. */
	const RECONCILE_BUDGET = 20;

	/** Mỗi đơn chỉ tra chừng này mã tham chiếu mới nhất; mã cũ hơn là các lần bấm thanh toán đã bỏ. */
	const RECONCILE_REFS = 2;

	/** Phiên thanh toán AppotaPay sống 30 phút; mã tạo quá 2 giờ mà vẫn pending thì chắc chắn đã hết hạn. */
	const REF_MAX_AGE = 2 * HOUR_IN_SECONDS;

	const CONSTANTS = [
		'partner_code' => 'HTH_APPOTAPAY_PARTNER_CODE',
		'api_key'      => 'HTH_APPOTAPAY_API_KEY',
		'secret_key'   => 'HTH_APPOTAPAY_SECRET_KEY',
	];

	private bool $debug;

	public function __construct() {
		$this->id                 = 'hth_appotapay';
		$this->method_title       = 'AppotaPay';
		$this->method_description = 'Thanh toán qua cổng AppotaPay: thẻ ATM, Visa/Master/JCB, ví điện tử, QR.';
		$this->has_fields         = false;
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->debug       = 'yes' === $this->get_option( 'debug' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_hth_appotapay_ipn', [ $this, 'handle_ipn' ] );
		add_action( 'woocommerce_api_hth_appotapay_return', [ $this, 'handle_return' ] );

		// Polylang (và Polylang for WooCommerce) dịch tiêu đề/mô tả cổng qua filter woocommerce_gateway_title/description;
		// hai ô cho tiền tệ khác VND phải đăng ký thì mới có trong Chuỗi dịch.
		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( 'AppotaPay: tiêu đề khi tiền tệ khác VND', (string) $this->get_option( 'title_foreign' ), 'HTH AppotaPay' );
			pll_register_string( 'AppotaPay: mô tả khi tiền tệ khác VND', (string) $this->get_option( 'description_foreign' ), 'HTH AppotaPay', true );
		}
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled'        => [
				'title'   => 'Bật/Tắt',
				'type'    => 'checkbox',
				'label'   => 'Bật thanh toán AppotaPay',
				'default' => 'no',
			],
			'title'          => [
				'title'   => 'Tiêu đề',
				'type'    => 'text',
				'default' => 'Thanh toán online qua AppotaPay',
			],
			'description'    => [
				'title'   => 'Mô tả',
				'type'    => 'textarea',
				'default' => 'Thanh toán bằng thẻ ATM nội địa, Visa/Master/JCB, ví điện tử hoặc QR.',
			],
			'title_foreign'  => [
				'title'       => 'Tiêu đề khi tiền tệ khác VND',
				'type'        => 'text',
				'default'     => 'Pay in VND',
				'description' => 'Khách đang xem giá bằng USD, PHP… (qua plugin đổi tiền tệ WOOCS) sẽ thấy tiêu đề này; đơn được thu bằng VND theo tỷ giá trong WOOCS. Để trống thì ẩn cổng với tiền tệ khác VND.',
			],
			'description_foreign' => [
				'title'       => 'Mô tả khi tiền tệ khác VND',
				'type'        => 'textarea',
				'default'     => 'You will be charged {vnd} by AppotaPay (≈ {total} at the store exchange rate). Your bank converts this amount to your currency.',
				'description' => '{vnd} = số VND sẽ thu, {total} = tổng đơn theo tiền tệ khách đang xem.',
			],
			'sandbox'        => [
				'title'       => 'Môi trường thử',
				'type'        => 'checkbox',
				'label'       => 'Dùng sandbox (gateway.dev.appotapay.com)',
				'default'     => 'yes',
				'description' => 'Bỏ chọn khi chạy thật và đổi sang bộ khoá production. Trên site production, chế độ sandbox chỉ hiện cổng cho quản trị viên.',
			],
			'partner_code'   => [
				'title' => 'Partner Code',
				'type'  => 'text',
			],
			'api_key'        => [
				'title' => 'API Key',
				'type'  => 'text',
			],
			'secret_key'     => [
				'title' => 'Secret Key',
				'type'  => 'password',
			],
			'order_prefix'   => [
				'title'       => 'Tiền tố mã đơn',
				'type'        => 'text',
				'default'     => 'HTH',
				'description' => '2–6 chữ cái, gắn trước mã đơn gửi AppotaPay. Mỗi site dùng chung một tài khoản AppotaPay phải có tiền tố riêng, nếu không mã đơn sẽ trùng nhau. Đổi tiền tố khi còn đơn đang chờ thì các đơn đó không tự cập nhật được nữa.',
			],
			'payment_method' => [
				'title'       => 'Phương thức',
				'type'        => 'select',
				'default'     => 'ALL',
				'options'     => [
					'ALL'     => 'Tất cả phương thức đã cấu hình',
					'ATM'     => 'Thẻ ATM / iBanking',
					'CC'      => 'Visa / Master / JCB',
					'EWALLET' => 'Ví điện tử',
					'VA'      => 'Chuyển khoản tài khoản ảo',
				],
				'description' => 'Khách chọn ngân hàng/ví trên trang của AppotaPay.',
			],
			'debug'          => [
				'title'       => 'Ghi log',
				'type'        => 'checkbox',
				'label'       => 'Ghi log request/response (WooCommerce → Trạng thái → Nhật ký, nguồn hth-appotapay)',
				'default'     => 'no',
				'description' => 'Link thanh toán, số thẻ và tên chủ thẻ được che trước khi ghi. Chỉ bật khi dò lỗi.',
			],
		];

		foreach ( self::CONSTANTS as $key => $const ) {
			if ( defined( $const ) ) {
				$this->form_fields[ $key ]['disabled']    = true;
				$this->form_fields[ $key ]['description'] = "Đang dùng hằng số $const trong wp-config.php.";
			}
		}
	}

	/** Khoá đã đặt bằng hằng số thì giá trị cũ trong DB không được in ra trang cài đặt (WooCommerce in cả giá trị ô password). */
	public function get_option( $key, $empty_value = null ) {
		if ( isset( self::CONSTANTS[ $key ] ) && defined( self::CONSTANTS[ $key ] ) ) {
			return '';
		}
		return parent::get_option( $key, $empty_value );
	}

	private function credential( string $key ): string {
		$const = self::CONSTANTS[ $key ];
		return defined( $const ) ? (string) constant( $const ) : trim( (string) $this->get_option( $key ) );
	}

	private function is_sandbox(): bool {
		return 'yes' === $this->get_option( 'sandbox' );
	}

	private function client(): HTH_AppotaPay_Client {
		return new HTH_AppotaPay_Client(
			$this->credential( 'partner_code' ),
			$this->credential( 'api_key' ),
			$this->credential( 'secret_key' ),
			$this->is_sandbox(),
			$this->debug ? [ $this, 'log' ] : null
		);
	}

	public function log( string $msg ): void {
		wc_get_logger()->info( $msg, [ 'source' => 'hth-appotapay' ] );
	}

	public function is_available() {
		if ( ! parent::is_available() || ! $this->client()->is_configured() ) {
			return false;
		}
		$currency = $this->context_currency();
		if ( 'VND' !== $currency && ( '' === trim( (string) $this->get_option( 'title_foreign' ) ) || null === self::rate( $currency ) ) ) {
			return false;
		}
		// Sandbox trên site thật: ai cũng trả được bằng thẻ test công khai. Chỉ để quản trị viên thử.
		if ( $this->is_sandbox() && 'production' === wp_get_environment_type() && ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Tỷ giá quy về VND của một tiền tệ: 1 VND = rate đơn vị tiền tệ đó (đúng quy ước của WOOCS, tiền tệ gốc là VND).
	 * Lấy từ bảng tiền tệ WOOCS, KHÔNG từ meta _woocs_order_rate của đơn (WOOCS ghi đè meta này ở mọi lượt mở trang "Cảm ơn", kể cả sai khoá đơn; thực tế sai ở khoảng 1/3 đơn).
	 * Site không có WOOCS có thể cung cấp tỷ giá qua filter hth_appotapay_vnd_rate. Trả null khi không quy đổi được.
	 */
	public static function rate( string $currency ): ?float {
		$rate = null;
		if ( 'VND' === $currency ) {
			$rate = 1.0;
		} else {
			global $WOOCS;
			if ( is_object( $WOOCS ) && method_exists( $WOOCS, 'get_currencies' ) && 'VND' === (string) ( $WOOCS->default_currency ?? '' ) ) {
				$r = (float) ( $WOOCS->get_currencies()[ $currency ]['rate'] ?? 0 );
				$rate = $r > 0 ? $r : null;
			}
		}
		$rate = apply_filters( 'hth_appotapay_vnd_rate', $rate, $currency );
		return is_numeric( $rate ) && (float) $rate > 0 ? (float) $rate : null;
	}

	/** Số VND phải thu cho một tổng tiền theo tiền tệ đã cho: [vnd, rate], hoặc null nếu không quy đổi được. */
	public static function to_vnd( float $total, string $currency ): ?array {
		$rate = self::rate( $currency );
		return null === $rate ? null : [ (int) round( $total / $rate ), $rate ];
	}

	/** Số VND đã gửi AppotaPay cho đơn; đơn tạo trước bản 0.3.0 (chỉ VND) không có meta thì lấy tổng đơn. */
	private static function order_vnd( WC_Order $order ): int {
		$vnd = (int) $order->get_meta( self::META_VND );
		return $vnd > 0 ? $vnd : (int) round( (float) $order->get_total() );
	}

	/**
	 * Tổng tiền và tiền tệ đang áp cho khách: đơn ở trang order-pay (khách trả lại đơn cũ) hoặc giỏ hàng ở checkout.
	 * Trả null khi không ở hai chỗ đó (trang cài đặt, wp-admin...).
	 *
	 * @return array{0: float, 1: string, 2: string}|null [tổng, tiền tệ, tổng đã định dạng]
	 */
	private static function context_total(): ?array {
		$order_id = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ? absint( get_query_var( 'order-pay' ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		// Chỉ tin đơn trong URL khi kèm đúng khoá, như WooCommerce làm trước khi vẽ form trả đơn; không thì không đọc tổng của đơn người khác.
		// phpcs:ignore WordPress.Security.NonceVerification
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( $order && '' !== $key && hash_equals( $order->get_order_key(), $key ) ) {
			return [ (float) $order->get_total(), $order->get_currency(), wp_strip_all_tags( $order->get_formatted_order_total() ) ];
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			// WooCommerce làm tròn tổng đơn theo số thập phân của cửa hàng khi tạo đơn (cửa hàng VND thường đặt 0,
			// nên đơn USD 15,18 được lưu thành 15). Tính VND trên số sẽ lưu để số hiện ở checkout đúng bằng số sẽ thu.
			$total = round( (float) WC()->cart->get_total( 'edit' ), wc_get_price_decimals() );
			return [ $total, get_woocommerce_currency(), wp_strip_all_tags( WC()->cart->get_total() ) ];
		}
		return null;
	}

	private function context_currency(): string {
		$ctx = self::context_total();
		return $ctx ? $ctx[1] : get_woocommerce_currency();
	}

	public static function format_vnd( int $vnd ): string {
		return number_format_i18n( $vnd ) . ' ₫';
	}

	/** Tiền tệ khác VND: tiêu đề/mô tả riêng, dịch qua Polylang rồi mới điền {vnd} {total}. */
	public function get_title() {
		if ( 'VND' === $this->context_currency() ) {
			return parent::get_title();
		}
		return apply_filters( 'woocommerce_gateway_title', (string) $this->get_option( 'title_foreign' ), $this->id );
	}

	public function get_description() {
		$ctx = self::context_total();
		if ( ! $ctx || 'VND' === $ctx[1] ) {
			return parent::get_description();
		}
		$text = (string) apply_filters( 'woocommerce_gateway_description', (string) $this->get_option( 'description_foreign' ), $this->id );
		$conv = self::to_vnd( $ctx[0], $ctx[1] );
		return strtr( $text, [ '{vnd}' => $conv ? self::format_vnd( $conv[0] ) : '', '{total}' => $ctx[2] ] );
	}

	private static function lang(): string {
		return function_exists( 'pll_current_language' ) && 'en' === pll_current_language() ? 'en' : 'vi';
	}

	/** Tiền tố mã đơn gửi AppotaPay: 2–6 chữ in hoa, riêng cho từng site. */
	private function prefix(): string {
		$p = strtoupper( (string) $this->get_option( 'order_prefix', 'HTH' ) );
		return preg_match( '/^[A-Z]{2,6}$/', $p ) ? $p : 'HTH';
	}

	public function validate_order_prefix_field( $key, $value ) {
		$value = strtoupper( trim( (string) $value ) );
		if ( ! preg_match( '/^[A-Z]{2,6}$/', $value ) ) {
			class_exists( 'WC_Admin_Settings' ) && WC_Admin_Settings::add_error( 'Tiền tố mã đơn phải gồm 2–6 chữ cái không dấu. Đã giữ giá trị cũ.' );
			return $this->prefix();
		}
		return $value;
	}

	/** Mỗi lần thử thanh toán cần một mã mới: AppotaPay báo lỗi 30 nếu trùng order.id. */
	private function new_ref( WC_Order $order, string $suffix = '' ): string {
		return $this->prefix() . $suffix . $order->get_id() . 'T' . time() . wp_rand( 100, 999 );
	}

	/** Lấy đơn từ mã tham chiếu, và chỉ nhận mã do chính đơn đó sinh ra. */
	private function order_from_ref( $ref ): ?WC_Order {
		if ( ! is_string( $ref ) || ! preg_match( '/^' . $this->prefix() . '(\d+)T\d+$/', $ref, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		if ( ! $order || ! in_array( $ref, (array) $order->get_meta( self::META_REFS ), true ) ) {
			return null;
		}
		return $order;
	}

	/** Mã tham chiếu mang giờ tạo: ...T<unix time><3 số ngẫu nhiên>. Trả 0 nếu không đọc được. */
	private static function ref_time( string $ref ): int {
		return preg_match( '/T(\d{9,10})\d{3}$/', $ref, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Khoá theo đơn, tự hết hạn sau 60 giây để một tiến trình chết giữa chừng không khoá đơn mãi mãi.
	 * Ghi thẳng vào wp_options vì add_option() đọc trước rồi mới ghi, hai tiến trình (IPN và trang khách quay về)
	 * có thể cùng lọt qua. INSERT IGNORE dựa vào UNIQUE KEY option_name nên chỉ một bên được 1 dòng.
	 */
	private static function lock( int $order_id ): bool {
		global $wpdb;
		$key = 'hth_appotapay_lock_' . $order_id;
		$now = time();
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string) $now ) );
		if ( 1 === $got ) {
			wp_cache_delete( $key, 'options' );
			return true;
		}
		// Khoá kẹt từ tiến trình chết: giành lại bằng một UPDATE có điều kiện, cũng nguyên tử.
		$took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %d", (string) $now, $key, $now - 60 ) );
		if ( 1 === $took ) {
			wp_cache_delete( $key, 'options' );
			return true;
		}
		return false;
	}

	private static function unlock( int $order_id ): void {
		delete_option( 'hth_appotapay_lock_' . $order_id );
	}

	public function process_payment( $order_id ) {
		$order    = wc_get_order( $order_id );
		$currency = $order->get_currency();
		$conv     = self::to_vnd( (float) $order->get_total(), $currency );
		if ( null === $conv ) {
			wc_add_notice( "AppotaPay không nhận thanh toán bằng $currency.", 'error' );
			return [ 'result' => 'failure' ];
		}
		[ $amount, $rate ] = $conv;

		if ( $amount < 1000 || $amount > 500000000 ) {
			wc_add_notice( 'AppotaPay chỉ nhận đơn từ 1.000đ đến 500.000.000đ.', 'error' );
			return [ 'result' => 'failure' ];
		}

		$ref = $this->new_ref( $order );
		$order->update_meta_data( self::META_REFS, array_merge( array_filter( (array) $order->get_meta( self::META_REFS ) ), [ $ref ] ) );
		// Số VND chốt ở lần bấm này; đối chiếu và hoàn tiền đều dùng số này, không tính lại từ tỷ giá sau.
		$order->update_meta_data( self::META_VND, $amount );
		$order->update_meta_data( self::META_RATE, $rate );
		$order->save();

		[ $code, $res ] = $this->client()->create_payment( [
			'transaction'      => [
				'amount'        => $amount,
				'currency'      => 'VND',
				'paymentMethod' => $this->get_option( 'payment_method', 'ALL' ),
				'action'        => 'PAY',
			],
			'partnerReference' => [
				'order'              => [
					'id'        => $ref,
					'info'      => mb_substr( 'Thanh toan don hang #' . $order->get_order_number() . ' - ' . get_bloginfo( 'name' ), 0, 150 ),
					'extraData' => '',
				],
				'notificationConfig' => [
					'notifyUrl'   => WC()->api_request_url( 'hth_appotapay_ipn' ),
					'redirectUrl' => WC()->api_request_url( 'hth_appotapay_return' ),
				],
			],
		], self::lang() );

		$url  = (string) ( $res['payment']['url'] ?? '' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 200 !== $code || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! preg_match( '/(^|\.)appotapay\.com$/', $host ) ) {
			$why = $res['message'] ?? ( 0 === $code ? 'không kết nối được AppotaPay' : ( 200 === $code ? "link thanh toán lạ: $host" : "HTTP $code" ) );
			$order->add_order_note( "AppotaPay: tạo giao dịch $ref thất bại — $why" );
			wc_add_notice( 'Chưa tạo được giao dịch AppotaPay, vui lòng thử lại hoặc chọn cách thanh toán khác.', 'error' );
			return [ 'result' => 'failure' ];
		}

		$note = sprintf( 'AppotaPay: tạo giao dịch %s (mã AppotaPay %s), chờ khách thanh toán.', $ref, $res['transaction']['transactionId'] ?? '?' );
		if ( 'VND' !== $currency ) {
			$note .= sprintf( ' Thu %s cho đơn %s %s (tỷ giá WOOCS: 1 %s = %s ₫).', self::format_vnd( $amount ), wc_format_decimal( $order->get_total(), wc_get_price_decimals() ), $currency, $currency, number_format_i18n( 1 / $rate, 2 ) );
		}
		$order->add_order_note( $note );
		return [ 'result' => 'success', 'redirect' => $url ];
	}

	/**
	 * Tra trạng thái qua API rồi mới cập nhật đơn. Gọi được nhiều lần (IPN gửi lại, khách tải lại trang).
	 *
	 * @return string success | pending | error | unknown
	 */
	private function reconcile( WC_Order $order, string $ref ): string {
		[ $code, $res ] = $this->client()->get_transaction_by_partner_ref( $ref );
		$t = $res['transaction'] ?? null;
		if ( 200 !== $code || ! is_array( $t ) ) {
			return 'unknown';
		}

		$status = (string) ( $t['status'] ?? '' );

		if ( 'error' === $status ) {
			$order->add_order_note( "AppotaPay: giao dịch $ref thất bại — " . ( $t['errorMessage'] ?? 'không rõ lý do' ) );
			return 'error';
		}
		if ( 'success' !== $status ) {
			return 'pending';
		}

		$expected = self::order_vnd( $order );
		$paid     = (int) ( $t['orderAmount'] ?? -1 );
		$txn      = (string) ( $t['transactionId'] ?? '' );

		if ( $paid !== $expected || 'VND' !== ( $t['currency'] ?? '' ) || ( $res['partnerReference']['order']['id'] ?? '' ) !== $ref || '' === $txn ) {
			$order->add_order_note( "AppotaPay: giao dịch $ref báo thành công nhưng không khớp đơn (số tiền $paid ≠ $expected, hoặc sai mã/tiền tệ). Cần kiểm tay." );
			// Đơn đã trả rồi thì giữ nguyên trạng thái, chỉ để lại ghi chú; đơn chưa trả thì tạm giữ chờ kiểm.
			if ( ! $order->is_paid() ) {
				$order->update_status( 'on-hold' );
			}
			return 'error';
		}

		// Khoá để IPN và trang trả về không cùng xác nhận một đơn hai lần.
		if ( ! self::lock( $order->get_id() ) ) {
			return 'success';
		}
		try {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $txn );
				$foreign = 'VND' !== $order->get_currency() ? sprintf( ' cho đơn %s %s', wc_format_decimal( $order->get_total(), wc_get_price_decimals() ), $order->get_currency() ) : '';
				$order->add_order_note( sprintf( 'AppotaPay: thanh toán thành công %s đ%s qua %s %s (mã %s).', number_format_i18n( $paid ), $foreign, $t['paymentMethod'] ?? '', $t['bankCode'] ?? '', $txn ) );
			} elseif ( $order->get_transaction_id() !== $txn && ! in_array( $txn, (array) $order->get_meta( self::META_DUPLICATE ), true ) ) {
				// Khách mở hai tab và trả cả hai lần: không được nuốt im lần trả thứ hai.
				$order->update_meta_data( self::META_DUPLICATE, array_merge( array_filter( (array) $order->get_meta( self::META_DUPLICATE ) ), [ $txn ] ) );
				$order->add_order_note( sprintf( 'AppotaPay: CẢNH BÁO — khách đã trả thêm %s đ cho đơn đã thanh toán (mã %s, mã đơn %s). Cần hoàn giao dịch này trên partner.appotapay.com.', number_format_i18n( $paid ), $txn, $ref ) );
				$order->save();
				$this->log( "Thanh toán trùng: đơn {$order->get_id()} giao dịch $txn" );
			}
		} finally {
			self::unlock( $order->get_id() );
		}
		return 'success';
	}

	/**
	 * Chạy 10 phút một lần qua Action Scheduler: tra lại các đơn AppotaPay chưa trả trong 24 giờ gần nhất,
	 * phòng khi khách đóng tab và cả 3 lần IPN đều không tới. Đơn huỷ cũng tra, vì WooCommerce tự huỷ
	 * đơn khi hết giờ giữ hàng dù khách có thể đã trả; payment_complete() nhận cả đơn huỷ và đơn lỗi. Dùng chung reconcile() (khoá và mọi kiểm tra) với IPN.
	 *
	 * Giữ lượt nhẹ: mỗi đơn chỉ tra RECONCILE_REFS mã mới nhất; mã quá REF_MAX_AGE mà vẫn pending thì coi là hết hạn
	 * (API AppotaPay không có trạng thái "hết hạn", pending mãi); quá RECONCILE_BUDGET giây thì dừng, đơn còn lại tới lượt sau.
	 */
	public function reconcile_recent(): void {
		if ( ! $this->client()->is_configured() ) {
			return;
		}
		// Xếp theo lần cập nhật cũ nhất: đơn vừa tra bị đẩy xuống cuối, nên nhiều đơn treo vẫn được tra lần lượt.
		$orders = wc_get_orders( [
			'payment_method' => $this->id,
			'status'         => [ 'pending', 'failed', 'cancelled' ],
			'date_created'   => '>' . ( time() - DAY_IN_SECONDS ),
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'limit'          => max( 1, (int) apply_filters( 'hth_appotapay_reconcile_limit', self::RECONCILE_LIMIT ) ),
		] );
		$deadline = microtime( true ) + max( 0, (float) apply_filters( 'hth_appotapay_reconcile_budget', self::RECONCILE_BUDGET ) );

		foreach ( $orders as $order ) {
			$final = array_filter( (array) $order->get_meta( self::META_FINAL_REFS ) );
			$refs  = array_slice( array_reverse( array_filter( (array) $order->get_meta( self::META_REFS ) ) ), 0, self::RECONCILE_REFS );
			foreach ( $refs as $ref ) {
				if ( in_array( $ref, $final, true ) ) {
					continue;
				}
				$state = $this->reconcile( $order, $ref );
				if ( 'success' === $state ) {
					break;
				}
				// Giao dịch thất bại không đổi kết quả nữa: nhớ lại để khỏi tra và ghi chú lặp mỗi 10 phút.
				// Mã tạo quá lâu mà vẫn pending: phiên đã hết hạn, cũng không đổi kết quả nữa.
				$created = self::ref_time( $ref );
				if ( 'error' === $state || ( 'pending' === $state && $created && time() - $created > self::REF_MAX_AGE ) ) {
					$final[] = $ref;
				}
			}
			$order = wc_get_order( $order->get_id() );
			if ( $final ) {
				$order->update_meta_data( self::META_FINAL_REFS, array_values( $final ) );
			}
			$order->update_meta_data( self::META_CHECKED_AT, time() );
			// Kho đơn cổ điển (HPOS tắt) không đổi post_modified khi chỉ lưu meta; ép đổi để đơn vừa tra xuống cuối hàng ở cả hai kiểu kho.
			$order->set_date_modified( time() );
			$order->save();

			if ( microtime( true ) > $deadline ) {
				$this->log( 'Tra lại định kỳ: quá ' . self::RECONCILE_BUDGET . ' giây, dừng lượt; đơn còn lại tới lượt sau.' );
				break;
			}
		}
	}

	/** IPN: AppotaPay POST JSON { data, signature, time }, chỉ gửi khi giao dịch thành công. */
	public function handle_ipn() {
		$body    = json_decode( (string) file_get_contents( 'php://input' ), true );
		$time    = $body['time'] ?? null;
		$payload = $this->client()->verify_callback( $body['data'] ?? null, $body['signature'] ?? null, $time );
		if ( null === $payload ) {
			$why = HTH_AppotaPay_Client::is_fresh( $time ) ? 'sai chữ ký' : 'quá cũ hoặc thiếu time (' . ( is_scalar( $time ) ? $time : gettype( $time ) ) . ')';
			$this->log( "IPN $why, từ " . WC_Geolocation::get_ip_address() );
			wp_send_json( [ 'status' => 'invalid signature' ], 400 );
		}

		$ref   = $payload['partnerReference']['order']['id'] ?? '';
		$order = $this->order_from_ref( $ref );
		if ( ! $order ) {
			// Chữ ký đúng nhưng không phải đơn của site này: báo ok để AppotaPay khỏi gửi lại.
			$this->log( "IPN cho mã lạ: $ref" );
			wp_send_json( [ 'status' => 'ok' ], 200 );
		}

		$state = $this->reconcile( $order, $ref );
		if ( 'unknown' === $state || 'pending' === $state ) {
			// IPN chỉ gửi khi đã thành công. Nếu API chưa báo xong hoặc không tra được,
			// trả lỗi để AppotaPay gửi lại sau 5 phút thay vì bỏ đơn kẹt ở "chờ thanh toán".
			wp_send_json( [ 'status' => 'retry' ], 503 );
		}
		wp_send_json( [ 'status' => 'ok' ], 200 );
	}

	/** Khách quay về từ AppotaPay: GET ?data=&signature=&time=. Chỉ để hiển thị, đơn vẫn chốt qua API. */
	public function handle_return() {
		// phpcs:disable WordPress.Security.NonceVerification
		$time = wp_unslash( $_GET['time'] ?? '' );
		if ( ! HTH_AppotaPay_Client::is_fresh( $time ) ) {
			// Link quay về cũ (lịch sử trình duyệt, referrer): không tra API, không đưa tới trang đơn kèm key.
			$this->log( 'Trang trả về: callback quá cũ hoặc thiếu time, IP ' . WC_Geolocation::get_ip_address() );
			wc_add_notice( 'Kết quả thanh toán này đã quá hạn. Nếu bạn đã trả, đơn sẽ tự cập nhật; nếu chưa, hãy đặt lại.', 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
		$payload = $this->client()->verify_callback( wp_unslash( $_GET['data'] ?? '' ), wp_unslash( $_GET['signature'] ?? '' ), $time );
		// phpcs:enable WordPress.Security.NonceVerification
		$order = $payload ? $this->order_from_ref( $payload['partnerReference']['order']['id'] ?? '' ) : null;

		if ( ! $order ) {
			$this->log( 'Trang trả về: chữ ký sai hoặc mã đơn lạ, IP ' . WC_Geolocation::get_ip_address() );
			wc_add_notice( 'Không xác minh được kết quả thanh toán AppotaPay.', 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$state = $this->reconcile( $order, $payload['partnerReference']['order']['id'] );

		if ( 'success' === $state ) {
			WC()->cart && WC()->cart->empty_cart();
			wp_safe_redirect( $this->get_return_url( $order ) );
		} elseif ( 'error' === $state ) {
			wc_add_notice( 'Thanh toán AppotaPay chưa thành công. Bạn có thể thử lại.', 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
		} else {
			$order->add_order_note( 'AppotaPay: khách đã quay về, giao dịch chưa có kết quả cuối.' );
			wc_add_notice( 'AppotaPay đang xử lý giao dịch. Đơn sẽ tự cập nhật khi có kết quả.', 'notice' );
			wp_safe_redirect( $this->get_return_url( $order ) );
		}
		exit;
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		$txn   = $order ? $order->get_transaction_id() : '';
		if ( ! $txn ) {
			return new WP_Error( 'appotapay_refund', 'Đơn chưa có mã giao dịch AppotaPay.' );
		}

		// $amount tính theo tiền tệ của đơn; AppotaPay hoàn bằng VND theo đúng tỷ giá đã thu.
		// Hoàn nốt phần còn lại của đơn thì hoàn đúng phần VND còn lại, khỏi lệch vì làm tròn; hoàn một phần thì không được vượt phần còn lại.
		// Dùng sổ riêng của plugin thay vì get_total_refunded() của WooCommerce, vì số đó có cache và đã gồm cả lần hoàn đang xử lý.
		$vnd_paid    = self::order_vnd( $order );
		$rate        = (float) $order->get_meta( self::META_RATE ) ?: 1.0;
		$ledger      = (array) $order->get_meta( self::META_REFUNDED );
		$done_amount = (float) ( $ledger['amount'] ?? 0 );
		$done_vnd    = (int) ( $ledger['vnd'] ?? 0 );
		$vnd_left    = max( 0, $vnd_paid - $done_vnd );
		$whole       = (float) $amount >= (float) $order->get_total() - $done_amount - 0.005;
		$value       = $whole ? $vnd_left : min( (int) round( (float) $amount / $rate ), $vnd_left );

		if ( $value < 1000 ) {
			return new WP_Error( 'appotapay_refund', 0 === $vnd_left ? 'Đã hoàn hết số VND AppotaPay thu của đơn này.' : 'AppotaPay chỉ hoàn từ 1.000đ trở lên.' );
		}

		// Lần hoàn trước bị mất kết nối: tra lệnh cũ trước, không gửi lệnh mới mù quáng (dễ hoàn hai lần).
		$inflight = $order->get_meta( self::META_REFUND_INFLIGHT );
		if ( is_array( $inflight ) && ! empty( $inflight['ref'] ) ) {
			[ $code, $res ] = $this->client()->get_refund_by_partner_ref( $inflight['ref'] );
			$status         = $res['status'] ?? '';
			if ( 200 === $code && in_array( $status, [ 'pending', 'processing', 'success' ], true ) ) {
				$order->delete_meta_data( self::META_REFUND_INFLIGHT );
				$order->add_order_note( sprintf( 'AppotaPay: lệnh hoàn %s đ trước đó (mã %s) thật ra đã được nhận, trạng thái %s. WooCommerce chưa ghi khoản này.', number_format_i18n( (int) $inflight['amount'] ), $inflight['ref'], $status ) );
				$order->save();
				return new WP_Error( 'appotapay_refund', sprintf( 'Lệnh hoàn %s đ trước đó đã được AppotaPay nhận. Đừng hoàn lại qua cổng; hãy ghi nhận khoản đó bằng "Hoàn tiền thủ công".', number_format_i18n( (int) $inflight['amount'] ) ) );
			}
			if ( 0 === $code || ( 200 !== $code && 36 !== (int) ( $res['errorCode'] ?? 0 ) ) ) {
				return new WP_Error( 'appotapay_refund', 'Chưa tra được kết quả lệnh hoàn trước đó. Thử lại sau ít phút.' );
			}
			// AppotaPay không có lệnh cũ (lỗi 36) hoặc lệnh cũ thất bại: an toàn để gửi lệnh mới.
			$order->delete_meta_data( self::META_REFUND_INFLIGHT );
		}

		$ref = $this->new_ref( $order, 'R' );
		$order->update_meta_data( self::META_REFUND_INFLIGHT, [ 'ref' => $ref, 'amount' => $value ] );
		$order->save();

		[ $code, $res ] = $this->client()->refund( [
			'partnerRefId'  => $ref,
			'transactionId' => $txn,
			'amount'        => $value,
			'currency'      => 'VND',
			'reason'        => mb_substr( $reason ?: 'Hoan tien don hang #' . $order->get_order_number(), 0, 100 ),
		] );

		// Mất mạng, lỗi phía máy chủ (5xx, kể cả 502/504 từ proxy) hay body không phải JSON: AppotaPay có thể đã nhận lệnh.
		// Giữ META_REFUND_INFLIGHT để lần hoàn sau tra lệnh này trước; chỉ xoá khi có phản hồi JSON hợp lệ 2xx/4xx.
		if ( 0 === $code || $code >= 500 || null === $res ) {
			$why = 0 === $code ? 'mất kết nối' : "AppotaPay trả HTTP $code" . ( null === $res ? ' không có JSON' : '' );
			$order->add_order_note( "AppotaPay: $why khi gửi lệnh hoàn $ref, chưa rõ kết quả. Lần hoàn sau plugin sẽ tra lệnh này trước." );
			return new WP_Error( 'appotapay_refund', 'AppotaPay không trả lời rõ ràng, chưa rõ đã hoàn hay chưa. Đừng làm gì thêm; thử lại sau ít phút để plugin tra kết quả.' );
		}

		$order->delete_meta_data( self::META_REFUND_INFLIGHT );
		$order->save();

		$status = $res['status'] ?? '';
		if ( 200 !== $code || ! in_array( $status, [ 'pending', 'processing', 'success' ], true ) ) {
			return new WP_Error( 'appotapay_refund', 'AppotaPay từ chối hoàn tiền: ' . ( $res['message'] ?? "HTTP $code" ) );
		}
		$order->update_meta_data( self::META_REFUNDED, [ 'amount' => $done_amount + (float) $amount, 'vnd' => $done_vnd + $value ] );
		$order->save();
		$foreign = 'VND' !== $order->get_currency() ? sprintf( ' (%s %s của đơn)', wc_format_decimal( $amount, wc_get_price_decimals() ), $order->get_currency() ) : '';
		$order->add_order_note( sprintf( 'AppotaPay: yêu cầu hoàn %s đ%s, trạng thái %s (mã hoàn %s, mã đối tác %s).', number_format_i18n( $value ), $foreign, $status, $res['refundId'] ?? '?', $ref ) );
		return true;
	}
}
