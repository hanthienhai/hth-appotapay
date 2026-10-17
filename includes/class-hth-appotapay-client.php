<?php
/**
 * Gọi API AppotaPay v2 (payment gateway).
 *
 * Theo skill appotapay-auth + appotapay-payment (docs.appotapay.com, đối chiếu bản live 15/10/2026):
 * - JWT HS256 tạo mới mỗi request, header có cty "appotapay-api;v=1", jti = API_KEY-<unix time>.
 * - Callback v2: signature = HMAC_SHA256(data thô, SECRET_KEY), so sánh hằng thời gian.
 */

defined( 'ABSPATH' ) || exit;

class HTH_AppotaPay_Client {

	const SANDBOX_URL    = 'https://gateway.dev.appotapay.com';
	const PRODUCTION_URL = 'https://gateway.appotapay.com';

	/** Callback được nhận trong khoảng này quanh giờ máy chủ. Lượt tra lại định kỳ đã phủ 24 giờ nên không mất đơn nào. */
	const CALLBACK_MAX_AGE = DAY_IN_SECONDS;

	private string $partner_code;
	private string $api_key;
	private string $secret_key;
	private string $base_url;
	private $logger;

	public function __construct( string $partner_code, string $api_key, string $secret_key, bool $sandbox, ?callable $logger = null ) {
		$this->partner_code = $partner_code;
		$this->api_key      = $api_key;
		$this->secret_key   = $secret_key;
		$this->base_url     = $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
		$this->logger       = $logger;
	}

	public function is_configured(): bool {
		return '' !== $this->partner_code && '' !== $this->api_key && '' !== $this->secret_key;
	}

	private static function b64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private function jwt(): string {
		$now     = time();
		$header  = [ 'typ' => 'JWT', 'alg' => 'HS256', 'cty' => 'appotapay-api;v=1' ];
		$payload = [
			'iss'     => $this->partner_code,
			'jti'     => $this->api_key . '-' . $now,
			'api_key' => $this->api_key,
			'exp'     => $now + 300,
		];
		$input = self::b64url( wp_json_encode( $header ) ) . '.' . self::b64url( wp_json_encode( $payload ) );
		return $input . '.' . self::b64url( hash_hmac( 'sha256', $input, $this->secret_key, true ) );
	}

	/**
	 * @param int $timeout Giây chờ phản hồi. Lệnh chạy lúc khách đang bấm (tạo giao dịch) và lệnh tra định kỳ
	 *                     cần ngắn để không giữ worker PHP; lệnh hoàn tiền giữ 30 giây.
	 * @return array{0:int,1:array|null} [HTTP code (0 = lỗi mạng), body đã decode (null nếu không phải JSON object)]
	 */
	private function request( string $method, string $path, ?array $body = null, string $lang = 'vi', int $timeout = 30 ): array {
		$args = [
			'method'      => $method,
			'timeout'     => $timeout,
			// API JSON không có lý do chuyển hướng; không theo redirect để JWT trong header không đi sang host khác.
			'redirection' => 0,
			'headers'     => [
				'X-APPOTAPAY-AUTH' => $this->jwt(),
				'Content-Type'     => 'application/json',
				'X-Request-ID'     => wp_generate_uuid4(),
				'X-Language'       => 'en' === $lang ? 'en' : 'vi',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $res ) ) {
			$this->log( "$method $path lỗi mạng: " . $res->get_error_message() );
			return [ 0, null ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $this->logger ) {
			$this->log( "$method $path → HTTP $code " . ( is_array( $data ) ? wp_json_encode( self::redact( $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : 'body không phải JSON: ' . mb_substr( $raw, 0, 300 ) ) );
		}
		return [ $code, is_array( $data ) ? $data : null ];
	}

	/** Che link thanh toán và thông tin thẻ trước khi ghi log: ai đọc được log cũng không mở được link trả tiền của khách. */
	private static function redact( array $data ): array {
		if ( isset( $data['payment']['url'] ) ) {
			$data['payment']['url'] = '[đã che]';
		}
		// Tên trường theo tài liệu /payment/payment-status: cardInfo.cardNumber, cardInfo.cardName, tokenInfo.number, tokenInfo.holder_name.
		array_walk_recursive( $data, function ( &$value, $key ) {
			if ( in_array( $key, [ 'cardNumber', 'cardName', 'cardHolder', 'number', 'holder_name', 'token' ], true ) ) {
				$value = '[đã che]';
			}
		} );
		return $data;
	}

	/** POST /api/v2/orders/payment — chạy lúc khách bấm "Đặt hàng", chờ tối đa 15 giây. */
	public function create_payment( array $body, string $lang ): array {
		return $this->request( 'POST', '/api/v2/orders/payment', $body, $lang, 15 );
	}

	/** GET /api/v2/orders/transaction?type=PARTNER_ORDER_ID — lệnh tra, 10 giây. */
	public function get_transaction_by_partner_ref( string $partner_ref ): array {
		return $this->request( 'GET', '/api/v2/orders/transaction?' . http_build_query( [
			'referenceId' => $partner_ref,
			'type'        => 'PARTNER_ORDER_ID',
		] ), null, 'vi', 10 );
	}

	/** POST /api/v2/transaction/refund */
	public function refund( array $body ): array {
		return $this->request( 'POST', '/api/v2/transaction/refund', $body );
	}

	/** GET /api/v2/transaction/refund/{partnerRefId}?type=PARTNER_ORDER_ID — lệnh tra, 10 giây. */
	public function get_refund_by_partner_ref( string $partner_ref ): array {
		return $this->request( 'GET', '/api/v2/transaction/refund/' . rawurlencode( $partner_ref ) . '?type=PARTNER_ORDER_ID', null, 'vi', 10 );
	}

	/**
	 * Trường time của callback có còn mới không. Tài liệu /payment/result ghi time là chuỗi "thời gian phản hồi",
	 * ví dụ IPN là Unix giây (1726029178); nhận thêm dạng mili giây (13 chữ số) phòng khi AppotaPay đổi.
	 * Không có time, không phải số, hay lệch quá CALLBACK_MAX_AGE so với time() thì coi là cũ.
	 */
	public static function is_fresh( $time ): bool {
		if ( is_int( $time ) ) {
			$time = (string) $time;
		}
		if ( ! is_string( $time ) || ! preg_match( '/^\d{1,13}$/', $time ) ) {
			return false;
		}
		$t = (int) $time;
		if ( $t > 99999999999 ) {
			$t = intdiv( $t, 1000 );
		}
		return abs( time() - $t ) <= self::CALLBACK_MAX_AGE;
	}

	/**
	 * Kiểm chữ ký callback (IPN hoặc redirect) rồi giải mã data.
	 * Callback cũ (xem is_fresh) cũng bị từ chối, để một cặp data/signature không thành vé xem đơn mãi mãi.
	 *
	 * @return array|null Payload đã giải mã, null nếu chữ ký sai hoặc callback quá cũ.
	 */
	public function verify_callback( $data, $signature, $time ): ?array {
		// Khoá rỗng thì ai cũng tự ký được: từ chối hẳn.
		if ( '' === $this->secret_key || ! is_string( $data ) || ! is_string( $signature ) || '' === $data || ! self::is_fresh( $time ) ) {
			return null;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $data, $this->secret_key ), $signature ) ) {
			return null;
		}
		$payload = json_decode( (string) base64_decode( $data, true ), true );
		return is_array( $payload ) ? $payload : null;
	}

	private function log( string $msg ): void {
		if ( $this->logger ) {
			call_user_func( $this->logger, $msg );
		}
	}
}
