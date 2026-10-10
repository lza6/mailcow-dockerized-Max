<?php
/**
 * mailcow-dockerized-Max — 域名总览（后台首页）
 *
 * 一个页面看全全部域名的真实状态与邮箱账号，并提供一键操作。
 * 与系统「配置」页分开，避免所有功能都挤在一排标签里。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/triggers.admin.inc.php';

protect_route(['admin']);

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/header.inc.php';
$_SESSION['return_to'] = $_SERVER['REQUEST_URI'];

$js_minifier->add('/web/js/site/overview.js');

// ---- 页面渲染所需的模板变量（必须设，否则 footer 无法渲染）----
$template = 'overview.twig';
$template_data = [
  'lang_admin' => json_encode($lang['admin']),
];

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/footer.inc.php';
