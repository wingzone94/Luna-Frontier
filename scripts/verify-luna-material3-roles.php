<?php

declare(strict_types=1);

/**
 * Verify Luna Frontier dynamic color roles without booting WordPress.
 *
 * This checks the actual PHP role builder used by the theme. WordPress hooks and
 * helpers are stubbed because this script only exercises pure color functions.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!class_exists('WP_Post')) {
    class WP_Post {}
}

if (!function_exists('add_action')) {
    function add_action(...$args): void {}
}

if (!function_exists('sanitize_hex_color')) {
    function sanitize_hex_color(string $color): string {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '';
    }
}

require_once __DIR__ . '/../inc/luna/dynamic-color.php';

$seeds = [
    'brand' => '#ff9900',
    'red' => '#cc3333',
    'orange' => '#cc6633',
    'yellow' => '#cccc33',
    'lime' => '#66cc33',
    'green' => '#33aa66',
    'cyan' => '#33cccc',
    'sky' => '#3388cc',
    'blue' => '#3333cc',
    'purple' => '#7733cc',
    'magenta' => '#cc33aa',
    'pink' => '#cc3366',
    'neutral' => '#777777',
    'black' => '#111111',
    'white' => '#f7f7f7',
];

$checks = [
    ['--lc-on-primary', '--lc-primary', 4.5],
    ['--lc-on-secondary', '--lc-secondary', 4.5],
    ['--lc-on-primary-container', '--lc-primary-container', 4.5],
    ['--lc-on-surface', '--lf-paper', 7.0],
    ['--lc-accent', '--lf-paper', 4.5],
    ['--lc-accent', '--lc-primary-container', 4.5],
];

$failures = [];
$rows = [];

foreach ($seeds as $name => $seed) {
    $roles = luna_frontier_build_roles($seed);

    foreach (['light', 'dark'] as $scheme) {
        foreach ($checks as [$fg, $bg, $min]) {
            $ratio = luna_frontier_contrast_ratio($roles[$scheme][$fg], $roles[$scheme][$bg]);
            $rows[] = sprintf(
                '%-7s %-5s %-28s on %-24s %5.2f',
                $name,
                $scheme,
                $fg,
                $bg,
                $ratio
            );

            if ($ratio < $min) {
                $failures[] = sprintf('%s %s %s/%s = %.2f < %.2f', $name, $scheme, $fg, $bg, $ratio, $min);
            }
        }
    }
}

if (getenv('LF_VERBOSE')) {
    echo implode(PHP_EOL, $rows) . PHP_EOL;
}

printf("Luna Material 3 role contrast checks: %d checks, %d failures\n", count($rows), count($failures));

foreach ($failures as $failure) {
    echo '- ' . $failure . PHP_EOL;
}

exit($failures ? 1 : 0);
