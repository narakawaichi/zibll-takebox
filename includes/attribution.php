<?php
/**
 * 署名、许可提示与取证水印
 *
 * @package    Zibll_TakeBox
 * @author     依岸归 <narakawaichi@hoarfall.cn>
 * @copyright  Copyright (c) 2026, 依岸归
 * @license    GPL-2.0-or-later
 * @link       https://nav.hoarfall.cn/
 *
 * 本文件是自由软件，依据 GPL v2 或更高版本发布。
 * 二次修改或再分发时，必须保留以上署名与许可声明。
 */

defined('ABSPATH') || exit;

/* ── 指纹：插件标识|作者邮箱|版本 的 sha256 前 16 位（原创在先的证据） ── */
if (!defined('HOARFALL_FINGERPRINT')) {
    define('HOARFALL_FINGERPRINT', 'fb3b6c2a6ca4cb1d');
}
if (!defined('HOARFALL_PLUGIN_FILE')) {
    define('HOARFALL_PLUGIN_FILE', dirname(__DIR__) . '/zibll-takebox.php');
}
if (!defined('HOARFALL_PLUGIN_NAME')) {
    define('HOARFALL_PLUGIN_NAME', '接管匣 TakeBox');
}

/* ── 1. 插件列表页署名（最难被删的位置） ── */
if (!function_exists('hoarfall_plugin_row_meta')) {
    add_filter('plugin_row_meta', 'hoarfall_plugin_row_meta', 10, 2);
    function hoarfall_plugin_row_meta($links, $file)
    {
        if ($file !== plugin_basename(HOARFALL_PLUGIN_FILE)) {
            return $links;
        }
        $links[] = '<a href="https://nav.hoarfall.cn/" target="_blank">作者官网</a>';
        $links[] = '<a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank">GPL v2 许可</a>';
        $links[] = '<span style="color:#b32d2e">二次分发须保留原作者署名</span>';
        return $links;
    }
}

/* ── 2. 后台页脚署名（只在本插件相关页面） ── */
if (!function_exists('hoarfall_admin_footer_text')) {
    add_filter('admin_footer_text', 'hoarfall_admin_footer_text');
    function hoarfall_admin_footer_text($text)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'hoarfall') === false) {
            return $text;
        }
        return '感谢使用 <strong>' . esc_html(HOARFALL_PLUGIN_NAME) . '</strong> · 由 '
            . '<a href="https://nav.hoarfall.cn/about" target="_blank">依岸归</a> 开发'
            . ' · 基于 GPL v2 发布，二次分发请保留署名';
    }
}

/* ── 3. 取证水印 ── */
if (!function_exists('hoarfall_render_watermark')) {
    add_action('admin_footer', 'hoarfall_render_watermark', 99);
    add_action('wp_footer', 'hoarfall_render_watermark', 99);
    function hoarfall_render_watermark()
    {
        echo "\n<!-- " . HOARFALL_PLUGIN_NAME . ' fp:' . HOARFALL_FINGERPRINT . " -->\n";
    }
}
