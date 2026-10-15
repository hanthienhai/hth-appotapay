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
	 * @return array{0:int,1:array|null} [HTTP code (0 = lỗi mạng), body đã decode]
	 */
	private function request( string $method, string $path, ?array $body = null, string $lang = 'vi' ): array {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
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
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$this->log( "$method $path → HTTP $code " . wp_remote_retrieve_body( $res ) );
		return [ $code, is_array( $data ) ? $data : null ];
	}

	/** POST /api/v2/orders/payment */
	public function create_payment( array $body, string $lang ): array {
		return $this->request( 'POST', '/api/v2/orders/payment', $body, $lang );
	}

	/** GET /api/v2/orders/transaction?type=PARTNER_ORDER_ID */
	public function get_transaction_by_partner_ref( string $partner_ref ): array {
		return $this->request( 'GET', '/api/v2/orders/transaction?' . http_build_query( [
			'referenceId' => $partner_ref,
			'type'        => 'PARTNER_ORDER_ID',
		] ) );
	}

	/** POST /api/v2/transaction/refund */
	public function refund( array $body ): array {
		return $this->request( 'POST', '/api/v2/transaction/refund', $body );
	}

	/** GET /api/v2/transaction/refund/{partnerRefId}?type=PARTNER_ORDER_ID */
	public function get_refund_by_partner_ref( string $partner_ref ): array {
		return $this->request( 'GET', '/api/v2/transaction/refund/' . rawurlencode( $partner_ref ) . '?type=PARTNER_ORDER_ID' );
	}

	/**
	 * Kiểm chữ ký callback (IPN hoặc redirect) rồi giải mã data.
	 *
	 * @return array|null Payload đã giải mã, null nếu chữ ký sai.
	 */
	public function verify_callback( $data, $signature ): ?array {
		// Khoá rỗng thì ai cũng tự ký được: từ chối hẳn.
		if ( '' === $this->secret_key || ! is_string( $data ) || ! is_string( $signature ) || '' === $data ) {
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
