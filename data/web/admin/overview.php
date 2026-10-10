<?php
/**
 * mailcow-dockerized-Max — 域名总览（后台首页）
 *
 * 一个页面看全全部域名的真实状态与邮箱账号，并提供一键操作。
 *
 * 性能：首屏数据在**服务端直接构建并内联**到页面里（window.__ov_initial），
 *      打开即有内容，不再等一次 AJAX 往返，也不会闪现「加载中…」。
 *      AJAX 仅用于「刷新」与各项操作。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/triggers.admin.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.audit.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.overview.inc.php';

protect_route(['admin']);

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/header.inc.php';
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

$js_minifier->add('/web/js/site/overview.js');

// ---- 首屏数据：服务端构建，直接内联 ----
global $redis;
$ov = overview_build($pdo, $redis, true);
$ov['audit'] = audit_recent($pdo, 30);
$ov['stats']['audit_count'] = audit_count($pdo);

$template = 'overview.twig';
$template_data = [
  'lang_admin'    => json_encode($lang['admin']),
  // 用 JSON_HEX_TAG 等把 < > & ' " 全部转义，避免提前闭合 <script>
  'ov_initial'    => json_encode($ov, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                   | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
];

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/footer.inc.php';
