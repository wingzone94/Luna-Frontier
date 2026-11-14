<?php
/**
 * Scheduler locking, asynchronous publication and RSS regressions.
 */
class Node_Scheduler_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__ ) . '/inc/scheduler.php';
        delete_option( 'node_missed_schedule_lock' );
    }

    public function tear_down(): void {
        delete_option( 'node_missed_schedule_lock' );
        parent::tear_down();
    }

    public function test_lock_is_retained_and_expired_lock_can_be_acquired(): void {
        node_check_missed_schedules();
        $expires = get_option( 'node_missed_schedule_lock' );
        $this->assertGreaterThan( time(), (int) $expires );
        node_check_missed_schedules();
        $this->assertSame( $expires, get_option( 'node_missed_schedule_lock' ) );
        update_option( 'node_missed_schedule_lock', (string) ( time() - 1 ) );
        node_check_missed_schedules();
        $this->assertGreaterThan( time(), (int) get_option( 'node_missed_schedule_lock' ) );
    }

    public function test_competing_insert_does_not_scan_and_expiry_allows_publication(): void {
        global $wpdb;
        $id = self::factory()->post->create( array(
            'post_status' => 'future',
            'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
        ) );
        $wpdb->update( $wpdb->posts, array( 'post_date' => '2000-01-01 00:00:00' ), array( 'ID' => $id ) );
        clean_post_cache( $id );
        $competitor_expiry = time() + 30;
        $insert_competitor = static function ( $sql ) use ( $wpdb, $competitor_expiry ) {
            if ( str_starts_with( $sql, "INSERT IGNORE INTO $wpdb->options" ) ) {
                $wpdb->insert( $wpdb->options, array(
                    'option_name' => 'node_missed_schedule_lock',
                    'option_value' => (string) $competitor_expiry,
                    'autoload' => 'off',
                ) );
            }
            return $sql;
        };
        add_filter( 'query', $insert_competitor );
        try {
            node_check_missed_schedules();
            $this->assertSame( 'future', get_post_status( $id ) );
            wp_cache_delete( 'notoptions', 'options' );
            $this->assertSame( (string) $competitor_expiry, get_option( 'node_missed_schedule_lock' ) );
        } finally {
            remove_filter( 'query', $insert_competitor );
        }
        update_option( 'node_missed_schedule_lock', (string) ( time() - 1 ) );
        node_check_missed_schedules();
        $this->assertSame( 'publish', get_post_status( $id ) );
        $this->assertNotFalse( wp_next_scheduled( 'node_ai_auto_generate_summary', array( $id ) ) );
        wp_clear_scheduled_hook( 'node_ai_auto_generate_summary', array( $id ) );
    }

    public function test_ajax_and_json_requests_do_not_acquire_lock(): void {
        add_filter( 'wp_doing_ajax', '__return_true' );
        try {
            node_check_missed_schedules();
            $this->assertFalse( get_option( 'node_missed_schedule_lock' ) );
        } finally {
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? null;
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        try {
            node_check_missed_schedules();
            $this->assertFalse( get_option( 'node_missed_schedule_lock' ) );
        } finally {
            if ( null === $accept ) {
                unset( $_SERVER['HTTP_ACCEPT'] );
            } else {
                $_SERVER['HTTP_ACCEPT'] = $accept;
            }
        }
    }

    public function test_publication_queues_one_immediate_job_without_http(): void {
        $this->assertTrue( class_exists( 'Node_AI_Tools' ) );
        $id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => 'Summary source.' ) );
        $calls = 0;
        $block_http = static function () use ( &$calls ) {
            ++$calls;
            return new WP_Error( 'unexpected_http' );
        };
        add_filter( 'pre_http_request', $block_http );
        try {
            $before = time();
            wp_publish_post( $id );
            node_on_post_published( 'publish', 'draft', get_post( $id ) );
            $timestamp = wp_next_scheduled( 'node_ai_auto_generate_summary', array( $id ) );
            $this->assertGreaterThanOrEqual( $before, $timestamp );
            $this->assertLessThanOrEqual( time(), $timestamp );
            $count = 0;
            foreach ( _get_cron_array() as $events ) {
                foreach ( $events['node_ai_auto_generate_summary'] ?? array() as $event ) {
                    if ( array( $id ) === $event['args'] ) {
                        ++$count;
                    }
                }
            }
            $this->assertSame( 1, $count );
            $this->assertSame( 0, $calls );
            $this->assertSame( '', get_post_meta( $id, '_node_ai_summary', true ) );
            $this->assertNotFalse( has_action( 'node_ai_auto_generate_summary', array( Node_AI_Tools::instance(), 'run_auto_generate_ai_summary' ) ) );
            $this->assertNotFalse( has_action( 'wp_ajax_node_generate_ai_summary', 'node_ai_ajax_generate_summary' ) );
        } finally {
            remove_filter( 'pre_http_request', $block_http );
            wp_clear_scheduled_hook( 'node_ai_auto_generate_summary', array( $id ) );
        }
    }

    public function test_existing_summary_is_not_queued(): void {
        $id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
        update_post_meta( $id, '_node_ai_summary', 'Existing summary' );
        wp_publish_post( $id );
        $this->assertFalse( wp_next_scheduled( 'node_ai_auto_generate_summary', array( $id ) ) );
    }

    public function test_rss_is_parseable_with_xml_delimiters(): void {
        $id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'A & B < C ]]> &copy;' ) );
        update_post_meta( $id, '_node_ai_summary', 'A & B ]]> tail' );
        $link = static fn( $url, $post ) => $post->ID === $id ? home_url( '/?p=' . $id . '&source=x' ) : $url;
        add_filter( 'post_link', $link, 10, 2 );
        $title = static fn( $text, $post_id ) => $post_id === $id ? 'A & B < C ]]> &copy;' : $text;
        add_filter( 'the_title', $title, 10, 2 );
        try {
            ob_start();
            node_render_x_rss_feed();
            $xml = ob_get_clean();
            $previous = libxml_use_internal_errors( true );
            $rss = simplexml_load_string( $xml );
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
            $this->assertNotFalse( $rss, print_r( $errors, true ) );
            $this->assertCount( 0, $errors );
            $this->assertStringContainsString( 'A & B < C ]]> ©', (string) $rss->channel->item[0]->title );
            $this->assertStringContainsString( '&source=x', (string) $rss->channel->item[0]->link );
            $this->assertStringContainsString( 'A & B ]]> tail', (string) $rss->channel->item[0]->description );
        } finally {
            remove_filter( 'post_link', $link, 10 );
            remove_filter( 'the_title', $title, 10 );
            wp_clear_scheduled_hook( 'node_ai_auto_generate_summary', array( $id ) );
        }
    }

    public function test_rss_sanitizes_malformed_blog_charset(): void {
        $malformed = static fn() => "UTF-8\r\nX-Injected: bad\" onclick=\"1";
        add_filter( 'option_blog_charset', $malformed );
        try {
            ob_start();
            node_render_x_rss_feed();
            $xml = ob_get_clean();
            $this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $xml );
            $this->assertStringNotContainsString( 'X-Injected', $xml );
        } finally {
            remove_filter( 'option_blog_charset', $malformed );
        }
    }
}
