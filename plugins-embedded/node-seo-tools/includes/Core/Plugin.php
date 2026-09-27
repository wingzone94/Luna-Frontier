<?php
/**
 * Luna SEO Tools bootstrap.
 *
 * @package Node_SEO_Tools
 */

namespace Node\SEO\Tools\Core;

use Node\SEO\Tools\Share\Image_Generator;
use Node\SEO\Tools\Share\Meta_Output;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		Image_Generator::instance();
		Meta_Output::instance();
	}
}
