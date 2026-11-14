<?php
/**
 * CERO Z (年齢確認) ダイアログ
 *
 * @package Luminous_Interactivity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 年齢確認ダイアログのレンダリング
 */
function luminous_cero_z_render_dialog( int $post_id ): void {
	?>
	<dialog id="cero-z-dialog" class="m3-dialog">
		<div class="m3-dialog__container">
            <div class="m3-dialog__icon">
                <span class="material-symbols-outlined" data-icon="report_problem"></span>
            </div>
            <h2 class="m3-dialog__title">年齢制限の確認</h2>
            <div class="m3-dialog__content">
                <p>この記事には、CERO Z（18歳以上のみ対象）相当の表現が含まれている可能性があります。<br>あなたは18歳以上ですか？</p>
            </div>
            <div class="m3-dialog__actions">
                <button id="cero-z-decline" class="m3-button m3-button--text">いいえ（戻る）</button>
                <button id="cero-z-accept" class="m3-button m3-button--filled">はい（閲覧する）</button>
            </div>
		</div>
	</dialog>

    <style>
    .m3-dialog {
        border: none;
        border-radius: 28px;
        padding: 0;
        background: var(--md-sys-color-surface-container-high);
        color: var(--md-sys-color-on-surface);
        max-width: 320px;
        box-shadow: var(--m3-elevation-3);
    }
    .m3-dialog::backdrop {
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
    }
    .m3-dialog__container {
        padding: 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .m3-dialog__icon {
        margin-bottom: 16px;
        color: var(--md-sys-color-primary);
    }
    .m3-dialog__icon .material-symbols-outlined {
        font-size: 32px;
    }
    .m3-dialog__title {
        font-size: 1.5rem;
        font-weight: 800;
        margin: 0 0 16px 0;
    }
    .m3-dialog__content {
        font-size: 0.95rem;
        line-height: 1.6;
        margin-bottom: 24px;
        color: var(--md-sys-color-on-surface-variant);
    }
    .m3-dialog__actions {
        display: flex;
        gap: 8px;
        width: 100%;
        justify-content: flex-end;
    }
    </style>
	<?php
}
