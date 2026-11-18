<?php

declare(strict_types=1);
/**
 * The template for displaying comments
 * Reverted to 0.7.x logic with Material 3 styling.
 */

if ( post_password_required() ) {
    return;
}

/**
 * Custom Comment Callback
 */
if ( ! function_exists( 'node_comment_callback' ) ) :
function node_comment_callback($comment, $args, $depth) {
    ?>
    <li <?php comment_class('m3-comment-item'); ?> id="comment-<?php comment_ID(); ?>">
        <div class="m3-comment-bubble-container">
            <div class="m3-comment-avatar-wrap">
                <?php echo get_avatar($comment, 48); ?>
            </div>
            
            <div class="m3-comment-card">
                <header class="m3-comment-card__header">
                    <span class="m3-comment-card__author"><?php comment_author_link(); ?></span>
                    <time class="m3-comment-card__date" datetime="<?php comment_time('c'); ?>">
                        <?php printf(esc_html__('%1$s %2$s', 'node'), get_comment_date(), get_comment_time()); ?>
                    </time>
                </header>
                
                <div class="m3-comment-card__content entry-content">
                    <?php if ($comment->comment_approved == '0') : ?>
                        <p class="m3-comment-card__moderation">承認待ちです</p>
                    <?php endif; ?>
                    <?php comment_text(); ?>
                </div>
                
                <div class="m3-comment-card__actions">
                    <?php comment_reply_link(array_merge($args, array(
                        'depth'     => $depth,
                        'max_depth' => $args['max_depth']
                    ))); ?>
                </div>
            </div>
        </div>
    </li>
    <?php
}
endif;
?>

<div id="comments" class="comments-area m3-comments-section">

    <?php if ( have_comments() ) : ?>
        <h2 class="comments-title">
            <?php
            $node_comment_count = get_comments_number();
            printf(
                esc_html( _n( '%s 件のコメント', '%s 件のコメント', $node_comment_count, 'node' ) ),
                number_format_i18n( $node_comment_count )
            );
            ?>
        </h2>

        <ol class="comment-list m3-comment-list">
            <?php
            wp_list_comments(
                array(
                    'style'       => 'ol',
                    'short_ping'  => true,
                    'avatar_size' => 48,
                    'callback'    => 'node_comment_callback'
                )
            );
            ?>
        </ol>

        <?php the_comments_navigation(); ?>
    <?php endif; ?>

    <?php if ( ! comments_open() && get_comments_number() && post_type_supports( get_post_type(), 'comments' ) ) : ?>
        <p class="no-comments"><?php esc_html_e( 'コメントは受け付けていません', 'node' ); ?></p>
    <?php endif; ?>

    <?php
    // Form Setup
    $node_commenter = wp_get_current_commenter();
    $node_req       = get_option( 'require_name_email' );
    $node_html_req  = ( $node_req ? " required='required'" : '' );

    $node_fields = array(
        'author' => '<div class="m3-textfield">
                        <label for="author" class="m3-textfield__label">' . esc_html__( 'お名前', 'node' ) . ( $node_req ? ' *' : ' (任意)' ) . '</label>
                        <input id="author" name="author" type="text" class="m3-textfield__input" value="' . esc_attr( $node_commenter['comment_author'] ) . '"' . $node_html_req . ' placeholder="ななしさん">
                    </div>',
        'email'  => '<div class="m3-textfield">
                        <label for="email" class="m3-textfield__label">' . esc_html__( 'メールアドレス', 'node' ) . ( $node_req ? ' *' : ' (非公開)' ) . '</label>
                        <input id="email" name="email" type="email" class="m3-textfield__input" value="' . esc_attr( $node_commenter['comment_author_email'] ) . '"' . $node_html_req . ' placeholder="user@example.com">
                    </div>',
        'url'    => '<div class="m3-textfield">
                        <label for="url" class="m3-textfield__label">' . esc_html__( 'ウェブサイト', 'node' ) . '</label>
                        <input id="url" name="url" type="url" class="m3-textfield__input" value="' . esc_attr( $node_commenter['comment_author_url'] ) . '" placeholder="https://...">
                    </div>',
    );

    $node_comment_field = '
        <div class="m3-textfield m3-textfield--textarea">
            <label for="comment" class="m3-textfield__label">' . _x( 'コメント内容', 'noun', 'node' ) . ' *</label>
            <textarea id="comment" name="comment" class="m3-textfield__input" placeholder="温かいコメントをお待ちしております..." required aria-required="true"></textarea>
        </div>';

    // WordPress の翻訳とリンクを使い、ログイン案内の後で行を分ける。
    $node_space_required_note = static function ( $message ) {
        $required_note = wp_required_field_message();
        return $required_note ? str_replace( $required_note, '<br>' . $required_note, $message ) : $message;
    };
    add_filter( 'comment_form_logged_in', $node_space_required_note );

    comment_form( array(
        'title_reply'          => esc_html__( 'コメントを投稿する', 'node' ),
        'label_submit'         => esc_html__( '送信する', 'node' ),
        'fields'               => $node_fields,
        'comment_field'        => $node_comment_field,
        'class_form'           => 'm3-comment-form',
        'submit_button'        => '<button name="%1$s" type="submit" id="%2$s" class="m3-button m3-button--filled m3-comment-submit-btn" disabled>%4$s<span class="material-symbols-outlined" data-icon="send"></span></button>',
        'title_reply_before'   => '<h3 id="reply-title" class="comment-reply-title m3-title-medium">',
        'title_reply_after'    => '</h3>',
    ) );
    remove_filter( 'comment_form_logged_in', $node_space_required_note );
    ?>

</div>