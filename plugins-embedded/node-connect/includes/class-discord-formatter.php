<?php
/**
 * Node Connect Discord フォーマッタ。
 *
 * Nodeイベント + ペイロードを Discord Incoming Webhook の Embed 形式へ変換する。
 * 通知先追加（Slack等）は1.3では行わないが、イベント基盤側は本クラスに依存しない。
 *
 * @package Node_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Connect_Discord_Formatter {

	private const BRAND_COLOR = 0xFF9900;

	private const EVENT_HEADINGS = [
		Node_Connect_Event_Bus::EVENT_POST_PUBLISHED       => '📰 新しい記事が公開されました',
		Node_Connect_Event_Bus::EVENT_POST_UPDATED         => '🔄 記事が更新されました',
		Node_Connect_Event_Bus::EVENT_POST_UNPUBLISHED     => '🔒 記事が非公開になりました',
		Node_Connect_Event_Bus::EVENT_POST_DELETED         => '🗑️ 記事が削除されました',
		Node_Connect_Event_Bus::EVENT_AI_SUMMARY_COMPLETED => '✨ AI要約の生成が完了しました',
		Node_Connect_Event_Bus::EVENT_FACT_CHECK_COMPLETED => '🔍 ファクトチェックが完了しました',
		Node_Connect_Event_Bus::EVENT_AI_FAILED            => '⚠️ AI処理が失敗しました',
		Node_Connect_Event_Bus::EVENT_NODE_UPDATED         => '🚀 Luna Frontier がアップデートされました',
		Node_Connect_Event_Bus::EVENT_MAINTENANCE_START    => '🚧 メンテナンスを開始しました',
		Node_Connect_Event_Bus::EVENT_MAINTENANCE_END      => '✅ メンテナンスが終了しました',
	];

	/**
	 * イベントを Webhook 送信ボディへ変換する。
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function format( string $event, array $payload ): array {
		$heading = self::EVENT_HEADINGS[ $event ] ?? $event;
		if ( Node_Connect_Event_Bus::EVENT_POST_PUBLISHED === $event && ! empty( $payload['scheduled'] ) ) {
			$heading = '⏰ 予約記事が公開されました';
		}

		$embed = [
			'title'       => (string) ( $payload['title'] ?? get_bloginfo( 'name' ) ),
			'description' => mb_substr( (string) ( $payload['excerpt'] ?? ( $payload['message'] ?? '' ) ), 0, 300 ),
			'color'       => self::resolve_color( (string) ( $payload['series_color'] ?? '' ) ),
			'timestamp'   => gmdate( 'c' ),
			'footer'      => [ 'text' => get_bloginfo( 'name' ) . ' — Luna Connect' ],
		];

		// 削除・非公開の記事はURLを付けてもリンク切れになるため公開系イベントのみ付ける。
		$linkable = in_array(
			$event,
			[
				Node_Connect_Event_Bus::EVENT_POST_PUBLISHED,
				Node_Connect_Event_Bus::EVENT_POST_UPDATED,
				Node_Connect_Event_Bus::EVENT_AI_SUMMARY_COMPLETED,
				Node_Connect_Event_Bus::EVENT_FACT_CHECK_COMPLETED,
			],
			true
		);
		if ( $linkable && ! empty( $payload['permalink'] ) ) {
			$embed['url'] = (string) $payload['permalink'];
		}

		if ( ! empty( $payload['image'] ) && $linkable ) {
			$embed['image'] = [ 'url' => (string) $payload['image'] ];
		}

		$fields = [];
		if ( ! empty( $payload['author'] ) ) {
			$fields[] = [
				'name'   => '投稿者',
				'value'  => (string) $payload['author'],
				'inline' => true,
			];
		}
		if ( ! empty( $payload['date'] ) ) {
			$fields[] = [
				'name'   => '公開日時',
				'value'  => (string) $payload['date'],
				'inline' => true,
			];
		}
		if ( ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
			$fields[] = [
				'name'   => 'カテゴリ',
				'value'  => implode( ' / ', array_map( 'strval', $payload['categories'] ) ),
				'inline' => true,
			];
		}
		if ( ! empty( $payload['series'] ) ) {
			$fields[] = [
				'name'   => 'シリーズ',
				'value'  => (string) $payload['series'],
				'inline' => true,
			];
		}
		if ( ! empty( $payload['eta'] ) ) {
			$fields[] = [
				'name'   => '復旧予定',
				'value'  => (string) $payload['eta'],
				'inline' => true,
			];
		}
		if ( array_key_exists( 'has_ai_summary', $payload ) ) {
			$fields[] = [
				'name'   => 'AI要約',
				'value'  => $payload['has_ai_summary'] ? 'あり' : 'なし',
				'inline' => true,
			];
		}
		if ( [] !== $fields ) {
			$embed['fields'] = $fields;
		}

		return [
			'username' => 'Luna Connect',
			'content'  => $heading,
			'embeds'   => [ $embed ],
		];
	}

	/**
	 * 接続テスト用のEmbed。
	 *
	 * @return array<string, mixed>
	 */
	public static function format_test(): array {
		return [
			'username' => 'Luna Connect',
			'content'  => '🔧 接続テスト',
			'embeds'   => [
				[
					'title'       => get_bloginfo( 'name' ),
					'description' => 'Luna Connect からのテスト通知です。この通知が届いていれば設定は正常です。',
					'color'       => self::BRAND_COLOR,
					'timestamp'   => gmdate( 'c' ),
					'footer'      => [ 'text' => get_bloginfo( 'name' ) . ' — Luna Connect' ],
				],
			],
		];
	}

	/**
	 * シリーズのプライマリカラー（#RRGGBB）→ なければブランドオレンジ。
	 */
	private static function resolve_color( string $hex ): int {
		if ( preg_match( '/^#?([0-9a-fA-F]{6})$/', $hex, $m ) ) {
			return (int) hexdec( $m[1] );
		}
		return self::BRAND_COLOR;
	}
}
