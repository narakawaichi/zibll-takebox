<?php
/**
 * 媒体上传接管
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
 * 上传接管 / 删除同步（接管匣 TakeBox）
 * 在附件元数据生成后，把原图与所有尺寸同步到对象存储；删除附件时同步删除远端对象。
 * 全程用总开关 gate：未开启则不接管，但设置页仍可用。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 反向导入进行中标记：反向导入会先用 media_handle_sideload 把桶里的对象拉到本地并生成附件，
// 这会触发「上传接管」钩子把刚拉下来的文件又原样（或更糟：改名后）上传回桶，造成重复对象，
// 违背「反向导入不动桶内容」的约定。用此标记屏蔽该钩子，反向导入只做“本地登记已有远端对象”。
$zibll_takebox_reverse_importing = false;
if (!function_exists('zibll_takebox_set_reverse_importing')) {
    function zibll_takebox_set_reverse_importing($v)
    {
        global $zibll_takebox_reverse_importing;
        $zibll_takebox_reverse_importing = (bool) $v;
    }
    function zibll_takebox_is_reverse_importing()
    {
        global $zibll_takebox_reverse_importing;
        return !empty($zibll_takebox_reverse_importing);
    }
}

// 适配器工厂：根据当前 provider 返回实例（s3/r2 共用 SigV4；oss 待里程碑 4）
if (!function_exists('zibll_takebox_get_adapter')) {
    function zibll_takebox_get_adapter()
    {
        $provider = zibll_takebox_provider();
        if (!$provider) {
            return null;
        }
        $opts = zibll_takebox_get_option();
        if ('oss' === $provider) {
            if (class_exists('Zibll_Takebox_OSS_Adapter')) {
                return new Zibll_Takebox_OSS_Adapter($opts);
            }
            return null; // 里程碑 4
        }
        if (class_exists('Zibll_Takebox_S3_Adapter')) {
            return new Zibll_Takebox_S3_Adapter($opts);
        }
        return null;
    }
}

// 接管上传：附件元数据生成后，原图 + 全尺寸同步到对象存储
if (!function_exists('zibll_takebox_on_attachment_metadata')) {
    function zibll_takebox_on_attachment_metadata($metadata, $attachment_id)
    {
        // 防重入：WebP 转换会递归再调 wp_generate_attachment_metadata，
        // 内层调用已把 webp offload，外层再跑会重复上传（大文件会重复整段 multipart），
        // 用静态守卫保证同一附件同一请求只 offload 一次。
        static $done = array();
        if (!empty($done[$attachment_id])) {
            return $metadata;
        }
        if (!zibll_takebox_is_enabled()) {
            return $metadata;
        }
        // 反向导入进行中：刚从桶里拉下来的文件不要又上传回桶（避免重复/改名对象），直接放行
        if (zibll_takebox_is_reverse_importing()) {
            return $metadata;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return $metadata;
        }
        $done[$attachment_id] = true;
        zibll_takebox_upload_attachment($attachment_id, $metadata);
        return $metadata;
    }
}

// 把附件（原图 + 全尺寸）上传到对象存储并写入 meta。
// 既被上传接管钩子调用，也被同步引擎的 forward 方向复用（$metadata 为 null 时自动取已有元数据）。
if (!function_exists('zibll_takebox_upload_attachment')) {
    function zibll_takebox_upload_attachment($attachment_id, $metadata = null)
    {
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return false;
        }
        if (null === $metadata) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }
        if (empty($metadata) || !is_array($metadata)) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];
        $keys       = array();
        // 默认访问权限：读取设置（公开 public-read / 私有 private）；默认公开直链
        $acl        = zibll_takebox_get_option('default_acl', 'public-read');
        $acl        = in_array($acl, array('public-read', 'private'), true) ? $acl : 'public-read';

        // 原文件绝对路径：必须用 get_attached_file()，不能依赖 $metadata['file']。
        // 原因：图片/PDF 的 metadata 含 'file'（相对上传路径），但音频/视频的 metadata
        // 由 wp_read_audio/video_metadata 生成，只含 ID3/媒体信息、根本不含 'file' 键，
        // 原代码 if(!empty($metadata['file'])) 对音频/视频恒为 false → 原文件从不上传。
        $original_file = get_attached_file($attachment_id);
        $dir           = $original_file ? dirname($original_file) : $base_dir;

        // 原图/原文件
        if ($original_file && file_exists($original_file)) {
            $base = basename($original_file);
            $key  = $adapter->build_object_key($base, $attachment_id);
            $url  = $adapter->upload($original_file, $key, $acl);
            if ($url) {
                $keys['original'] = $key;
            }
        }

        // 各尺寸（图片/PDF 有；音频/视频无 sizes 则跳过）
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size) {
                if (empty($size['file'])) {
                    continue;
                }
                $abs  = $dir . '/' . $size['file'];
                $base = basename($size['file']);
                $key  = $adapter->build_object_key($base, $attachment_id);
                if (file_exists($abs)) {
                    $url = $adapter->upload($abs, $key, $acl);
                    if ($url) {
                        $keys['sizes'][$size_name] = $key;
                    }
                }
            }
        }

        if (!empty($keys)) {
            update_post_meta($attachment_id, '_zibll_takebox_keys', $keys);
            update_post_meta($attachment_id, '_zibll_takebox_object_key', $keys['original']);
            update_post_meta($attachment_id, '_zibll_takebox_provider', $adapter->provider_label());
            update_post_meta($attachment_id, '_zibll_takebox_region', $adapter->region());
            update_post_meta($attachment_id, '_zibll_takebox_bucket', $adapter->bucket());
            update_post_meta($attachment_id, '_zibll_takebox_acl', $acl);

            // 不保留本地副本：上传后删除本地文件（keep_local 默认开，故默认不删）
            if (!zibll_takebox_get_option('keep_local', 1)) {
                if ($original_file && file_exists($original_file)) {
                    @unlink($original_file);
                }
                if (!empty($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size) {
                        if (!empty($size['file']) && file_exists($dir . '/' . $size['file'])) {
                            @unlink($dir . '/' . $size['file']);
                        }
                    }
                }
            }
        }
        return !empty($keys);
    }
}
add_filter('wp_generate_attachment_metadata', 'zibll_takebox_on_attachment_metadata', 10, 2);

// 删除同步：WP 删除附件时同步删除远端对象
if (!function_exists('zibll_takebox_on_delete_attachment')) {
    function zibll_takebox_on_delete_attachment($attachment_id)
    {
        if (!zibll_takebox_is_enabled()) {
            return;
        }
        $keys = get_post_meta($attachment_id, '_zibll_takebox_keys', true);
        if (empty($keys) || !is_array($keys)) {
            return;
        }
        $adapter = zibll_takebox_get_adapter();
        if (!$adapter) {
            return;
        }
        if (!empty($keys['original'])) {
            $adapter->delete($keys['original']);
        }
        if (!empty($keys['sizes']) && is_array($keys['sizes'])) {
            foreach ($keys['sizes'] as $k) {
                $adapter->delete($k);
            }
        }
    }
}
add_action('delete_attachment', 'zibll_takebox_on_delete_attachment', 10, 1);
