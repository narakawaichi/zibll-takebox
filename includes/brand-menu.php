<?php
/**
 * 依岸归品牌层：统一后台菜单与视觉
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

/**
 * 依岸归品牌层（Hoarfall Brand Layer）
 * ─────────────────────────────────────────────────────────────
 * 所有「依岸归出品」插件共用同一份实现，提供四件事：
 *
 *   1) 统一后台顶级菜单「Hoarfall 工具」（多插件共存只注册一次）
 *   2) 品牌总览页：自动扫描已激活的依岸归插件并列出设置入口
 *   3) 后台视觉统一：
 *        · CSF 面板横幅品牌化（与子主题 assets/css/admin-brand.css 规则一致）
 *        · 原生表单页的品牌横幅（.hoarfall-banner）
 *        · 页内分级导航（.hoarfall-nav，仿子主题设置页观感）
 *   4) 原生兼容：不依赖 zibll 主题；CSF 缺失时页面照常可用，不会白屏或致命错误。
 *
 * ── 复用规则 ─────────────────────────────────────────────────
 *   · 本文件在每个插件里各带一份，内容完全一致。
 *   · 定位方式自适配：样式表按「同级 assets/」→「上一级 assets/」顺序探测，
 *     因此 brand-menu.php 放在插件根目录、includes/ 或 inc/ 下都能找到样式。
 *   · 多插件同时激活时，靠 function_exists / defined / static 三重守卫去重，
 *     菜单、样式 handle 都只会注册一次，不会重复也不会冲突。
 *
 * ── 后台样式覆盖的边界（重要）─────────────────────────────────
 *   本样式只改「外观」，不碰父主题或 CSF 框架文件，主题/插件更新不会丢。
 *   CSF 部分与子主题 admin-brand.css 保持一致：浅色渐变必须配套压深文字，
 *   否则父主题原本为深色渐变设的白字会读不出来。
 *
 * @package Hoarfall
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ── 品牌常量（所有依岸归插件共用同一组值）──────────────────── */
if (!defined('HOARFALL_MENU_SLUG')) {
    define('HOARFALL_MENU_SLUG', 'hoarfall-tools');
}
if (!defined('HOARFALL_BRAND_VERSION')) {
    define('HOARFALL_BRAND_VERSION', '1.0.0');
}
if (!defined('HOARFALL_AUTHOR_NAME')) {
    define('HOARFALL_AUTHOR_NAME', '依岸归');
}
if (!defined('HOARFALL_AUTHOR_SITE')) {
    define('HOARFALL_AUTHOR_SITE', 'https://nav.hoarfall.cn/');
}

/* ── 1. 顶级菜单与总览页 ────────────────────────────────────── */
if (!function_exists('hoarfall_ensure_plugin_api')) {
    function hoarfall_ensure_plugin_api()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
}

if (!function_exists('hoarfall_register_top_menu')) {
    /**
     * 注册「Hoarfall 工具」顶级菜单。
     * 必须挂 admin_menu：插件加载阶段 pluggable.php 未就绪，
     * add_menu_page() 内部会走 current_user_can() -> wp_get_current_user()，直接调用会致命错误。
     */
    function hoarfall_register_top_menu()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        add_menu_page(
            'Hoarfall 工具',
            'Hoarfall 工具',
            'manage_options',
            HOARFALL_MENU_SLUG,
            'hoarfall_dashboard_page',
            'dashicons-admin-generic',
            58
        );
    }
}

if (!function_exists('hoarfall_known_pages')) {
    /**
     * 插件目录名 => 设置页 slug 映射。
     * 用于总览页给出「设置」直达链接（各插件 slug 与 TextDomain 并不总是一致，
     * 故用目录名做键，比按 TextDomain 猜测可靠）。
     */
    function hoarfall_known_pages()
    {
        return array(
            '子比子主题-zibll-child-主题'          => 'zibll_child_options',
            '板凳会（仿Discord）-zib-chat-插件'    => 'zibchat-settings',
            '接管匣TakeBox-zibll-takebox-插件'     => 'zibll-takebox',
            '子比多平台文章同步-zibll-post-sync-插件' => 'zibll-post-sync',
            '站点群发邮件-zibll-bulk-email-插件'    => 'zibll-bulk-email',
            '网盘地址加密保护-netdisk-protect-插件' => 'netdisk-protect',
            '云上卧室-external-image-host-插件'    => 'eih-settings',
            '二次元画廊-ac-anime-gallery-插件'      => 'ac-anime-gallery',
            '依岸归自定义代码-zibll-custom-code-插件' => 'zibll-custom-code',
            'ZibllSiteCode-zibll-site-code-插件'    => 'zibll-site-code',
            'HoarfallSEO修复工具-hf-seo-fixer-插件' => 'hf-seo-fixer',
            '依岸归投喂插件-touwei-plugin-插件'     => 'touwei-admin',
        );
    }
}

if (!function_exists('hoarfall_dashboard_page')) {
    function hoarfall_dashboard_page()
    {
        hoarfall_ensure_plugin_api();

        $map      = hoarfall_known_pages();
        $active   = (array) get_option('active_plugins', array());
        $all      = function_exists('get_plugins') ? get_plugins() : array();
        $rows     = array();

        foreach ($all as $file => $data) {
            if (!in_array($file, $active, true)) {
                continue;
            }
            $author = isset($data['Author']) ? (string) $data['Author'] : '';
            $uri    = isset($data['PluginURI']) ? (string) $data['PluginURI'] : '';

            $is_mine = (false !== strpos($author, '依岸归'))
                || (false !== stripos($uri, 'hoarfall'))
                || (false !== stripos($author, 'hoarfall'));

            if (!$is_mine) {
                continue;
            }

            $dir  = basename(dirname($file));
            $page = isset($map[$dir]) ? $map[$dir] : '';

            $rows[] = array(
                'name'    => isset($data['Name']) ? $data['Name'] : $file,
                'version' => isset($data['Version']) ? $data['Version'] : '',
                'desc'    => isset($data['Description']) ? $data['Description'] : '',
                'page'    => $page,
            );
        }

        echo '<div class="wrap">';
        hoarfall_admin_banner(
            'Hoarfall 工具',
            '依岸归出品插件与子主题的统一控制台'
        );

        if (empty($rows)) {
            echo '<p>未检测到已激活的依岸归插件。</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:960px;">';
            echo '<thead><tr><th style="width:24%;">插件</th><th style="width:10%;">版本</th>'
                . '<th>说明</th><th style="width:12%;">操作</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($r['name']) . '</strong></td>';
                echo '<td>' . esc_html($r['version']) . '</td>';
                echo '<td>' . esc_html(wp_strip_all_tags($r['desc'])) . '</td>';
                echo '<td>';
                if ($r['page']) {
                    echo '<a class="button button-small" href="'
                        . esc_url(admin_url('admin.php?page=' . $r['page'])) . '">设置</a>';
                } else {
                    echo '<span style="color:#646970;">—</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<p style="margin-top:16px;color:#646970;">'
            . '每个插件的全部后台页面都收纳在本菜单下；插件列表里的「设置」链接同样可直达。</p>';
        echo '</div>';
    }
}

/* ── 2. 后台样式统一 ────────────────────────────────────────── */
if (!function_exists('hoarfall_brand_css_url')) {
    /**
     * 探测品牌样式表位置，兼容 brand-menu.php 位于
     * 插件根目录 / includes/ / inc/ 三种层级。
     */
    function hoarfall_brand_css_url()
    {
        $dir = plugin_dir_path(__FILE__);

        $candidates = array(
            $dir . 'assets/css/hoarfall-admin.css',
            dirname(rtrim($dir, '/\\')) . '/assets/css/hoarfall-admin.css',
        );

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $base = wp_normalize_path(WP_PLUGIN_DIR);
                $rel  = str_replace($base, '', wp_normalize_path($path));
                return plugins_url(ltrim($rel, '/'));
            }
        }

        return '';
    }
}

if (!function_exists('hoarfall_enqueue_admin_brand')) {
    /**
     * 统一输出后台品牌样式。
     * 只在真正的后台界面加载；handle 固定，多插件同时激活时 WordPress 自动去重，
     * 全后台只会输出一次样式表。
     */
    function hoarfall_enqueue_admin_brand()
    {
        if (!is_admin() || !is_user_logged_in()) {
            return;
        }
        if (!apply_filters('hoarfall_admin_brand_enabled', true)) {
            return;
        }

        $url = hoarfall_brand_css_url();
        if (!$url) {
            return;
        }

        wp_enqueue_style(
            'hoarfall-admin-brand',
            $url,
            array(),
            HOARFALL_BRAND_VERSION
        );
    }
}

if (!function_exists('hoarfall_admin_banner')) {
    /**
     * 原生页面品牌横幅（CSF 面板自带横幅，无需调用本函数）。
     */
    function hoarfall_admin_banner($title, $subtitle = '')
    {
        echo '<div class="hoarfall-banner">';
        echo '<h1 class="hoarfall-banner__title">' . esc_html($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="hoarfall-banner__sub">' . esc_html($subtitle) . '</p>';
        }
        echo '</div>';
    }
}

if (!function_exists('hoarfall_admin_nav')) {
    /**
     * 页内分级导航（仿子主题设置页观感）。
     *
     * @param array  $groups  形如：
     *   array(
     *     array('label' => '聊天室', 'items' => array('zibchat-settings' => '基础设置',
     *                                                   'zibchat-channels' => '频道管理')),
     *     array('label' => '安全',   'items' => array('zibchat-bans' => '封禁管理')),
     *   )
     * @param string $current 当前页面 slug，用于高亮。
     */
    function hoarfall_admin_nav($groups, $current)
    {
        if (empty($groups) || !is_array($groups)) {
            return;
        }

        echo '<nav class="hoarfall-nav">';
        foreach ($groups as $group) {
            if (!empty($group['label'])) {
                echo '<div class="hoarfall-nav__group">' . esc_html($group['label']) . '</div>';
            }
            if (empty($group['items']) || !is_array($group['items'])) {
                continue;
            }
            foreach ($group['items'] as $slug => $label) {
                $active = ($slug === $current) ? ' is-active' : '';
                printf(
                    '<a class="hoarfall-nav__link%s" href="%s">%s</a>',
                    esc_attr($active),
                    esc_url(admin_url('admin.php?page=' . $slug)),
                    esc_html($label)
                );
            }
        }
        echo '</nav>';
    }
}

if (!function_exists('hoarfall_page_open')) {
    /**
     * 打开一个「横幅 + 左侧分级导航 + 右侧内容」的标准页面骨架。
     */
    function hoarfall_page_open($title, $subtitle, $groups, $current)
    {
        echo '<div class="wrap hoarfall-wrap">';
        hoarfall_admin_banner($title, $subtitle);
        echo '<div class="hoarfall-page">';
        hoarfall_admin_nav($groups, $current);
        echo '<div class="hoarfall-content">';
    }
}

if (!function_exists('hoarfall_page_close')) {
    function hoarfall_page_close()
    {
        echo '</div></div></div>';
    }
}

/* ── 3. 钩子注册（多插件只挂一次）────────────────────────────── */
if (!defined('HOARFALL_BRAND_HOOKED')) {
    define('HOARFALL_BRAND_HOOKED', true);

    add_action('admin_menu', 'hoarfall_ensure_plugin_api', 4);
    add_action('admin_menu', 'hoarfall_register_top_menu', 5);

    // 99：排在 CSF 自身样式之后，确保覆盖生效
    add_action('admin_enqueue_scripts', 'hoarfall_enqueue_admin_brand', 99);
}
