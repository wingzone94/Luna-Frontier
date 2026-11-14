<?php
/**
 * ソート可能テーブル
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'node_render_sort_table_block' ) ) {
    function node_render_sort_table_block($attributes) {
        $data = $attributes['data'] ?? [];
        if (empty($data)) return '<p>テーブルデータがありません。</p>';

        ob_start();
        ?>
        <div class="m3-sort-table-wrapper m3-table-container">
            <table class="m3-sort-table m3-sortable-table" data-sort-enabled="true">
                <thead>
                    <tr>
                        <?php foreach ($data[0] as $cell) : ?>
                            <th><?php echo esc_html($cell); ?> <span class="material-symbols-outlined" data-icon="unfold_more"></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 1; $i < count($data); $i++) : ?>
                        <tr>
                            <?php foreach ($data[$i] as $cell) : ?>
                                <td><?php echo esc_html($cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
