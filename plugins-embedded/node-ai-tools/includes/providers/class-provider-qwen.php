<?php
/** Legacy Qwen API/class and option names remain supported. @package Node_AI_Tools */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-provider-openai.php';

class Node_AI_Provider_Qwen extends Node_AI_Provider_OpenAI {
	public const DEFAULT_ENDPOINT = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';
	public const DEFAULT_MODEL = 'qwen-plus';

	public function __construct() {
		parent::__construct( 'node_ai_qwen', 'dashscope' );
	}

	public function get_label(): string {
		return 'Qwen';
	}
}
