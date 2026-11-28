<?php
/**
 * Node Connect Webhook送信。
 *
 * cron（node_connect_deliver）から呼ばれ、Discord Incoming Webhook へ
 * wp_remote_post する。タイムアウト5秒・失敗時は最大2回再送（計3試行）。
 *
 * @package Node_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Connect_Webhook_Sender {

	public const TIMEOUT      = 5;
	public const MAX_ATTEMPTS = 3;

	/**
	 * 再送間隔（秒）。試行回数に応じて広げる。
	 */
	private const RETRY_DELAYS = [ 60, 300 ];

	/**
	 * cron コールバック。設定から宛先を解決して1件送信する。
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function deliver( string $event, array $payload, int $webhook_index, int $attempt ): void {
		$webhooks = Node_Connect_Event_Bus::get_webhooks();
		$webhook  = $webhooks[ $webhook_index ] ?? null;

		// キュー投入後に設定が消えた/止められた場合は静かに破棄する。
		if ( null === $webhook || '' === $webhook['url'] || ! $webhook['enabled'] ) {
			return;
		}
		if ( ! Node_Connect_Event_Bus::is_enabled() || Node_Connect_Event_Bus::is_paused() ) {
			return;
		}

		$body   = Node_Connect_Discord_Formatter::format( $event, $payload );
		$result = self::post( $webhook['url'], $body );

		Node_Connect_Delivery_Log::add(
			[
				'event'   => $event,
				'label'   => '' !== $webhook['label'] ? $webhook['label'] : sprintf( 'Webhook %d', $webhook_index + 1 ),
				'status'  => $result['status'],
				'ok'      => $result['ok'],
				'attempt' => $attempt,
			]
		);

		if ( ! $result['ok'] && $attempt < self::MAX_ATTEMPTS ) {
			$delay = self::RETRY_DELAYS[ $attempt - 1 ] ?? 300;
			wp_schedule_single_event(
				time() + $delay,
				Node_Connect_Event_Bus::CRON_HOOK,
				[ $event, $payload, $webhook_index, $attempt + 1 ]
			);
		}
	}

	/**
	 * 設定画面の接続テスト用に同期送信する。
	 *
	 * @return array{ok: bool, status: string}
	 */
	public static function send_test( int $webhook_index ): array {
		$webhooks = Node_Connect_Event_Bus::get_webhooks();
		$webhook  = $webhooks[ $webhook_index ] ?? null;

		if ( null === $webhook || '' === $webhook['url'] ) {
			return [
				'ok'     => false,
				'status' => 'URL未設定',
			];
		}

		$result = self::post( $webhook['url'], Node_Connect_Discord_Formatter::format_test() );

		Node_Connect_Delivery_Log::add(
			[
				'event'   => 'test',
				'label'   => '' !== $webhook['label'] ? $webhook['label'] : sprintf( 'Webhook %d', $webhook_index + 1 ),
				'status'  => $result['status'],
				'ok'      => $result['ok'],
				'attempt' => 1,
			]
		);

		return $result;
	}

	/**
	 * Discord 公式 Webhook URL か検証する（SSRF 防止）。
	 */
	public static function is_allowed_webhook_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if ( 'https' !== $scheme || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return false;
		}

		$allowed_hosts = [
			'discord.com',
			'discordapp.com',
			'canary.discord.com',
			'ptb.discord.com',
		];

		if ( ! in_array( $host, $allowed_hosts, true ) ) {
			return false;
		}

		return str_starts_with( $path, '/api/webhooks/' ) && strlen( $path ) > strlen( '/api/webhooks/' );
	}

	/**
	 * @param array<string, mixed> $body Discord Webhook ペイロード。
	 * @return array{ok: bool, status: string}
	 */
	private static function post( string $url, array $body ): array {
		if ( ! self::is_allowed_webhook_url( $url ) ) {
			return [
				'ok'     => false,
				'status' => 'Invalid webhook URL',
			];
		}

		$response = wp_safe_remote_post(
			$url,
			[
				'timeout'            => self::TIMEOUT,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => [ 'Content-Type' => 'application/json' ],
				'body'               => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'     => false,
				'status' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return [
			'ok'     => $code >= 200 && $code < 300,
			'status' => 'HTTP ' . $code,
		];
	}
}
