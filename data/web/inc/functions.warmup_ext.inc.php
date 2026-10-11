<?php
/**
 * mailcow-dockerized-Max — 收件人账号操作（IMAP / SMTP）
 *
 * 用途：养号要「像真人一样互动」，就必须能站在**收件人**的角度做事：
 *   1. 验证账号可用（IMAP 登录成功）
 *   2. 判断我们发出去的邮件落到了收件箱还是垃圾箱  ← 这是"垃圾箱率"的唯一可信来源
 *   3. 把落进垃圾箱的邮件标记为非垃圾 / 移回收件箱  ← 对 Gmail 是强正向信号
 *   4. 用收件人身份回一封邮件                      ← 最强互动信号
 *
 * 安全约束（都是硬要求，别放宽）：
 *   · 主机名必须通过严格白名单式校验后才允许拼进 IMAP 邮箱串 ——
 *     `imap_open()` 的第一个参数是 IMAP 命令片段，用户可控的话等于命令注入。
 *   · 绝不把密码写进日志或异常信息。
 *   · 所有网络调用都设超时，避免拖死 php-fpm worker（本模块在 web-worker 池里跑）。
 */

// IMAP / SMTP 超时（秒）—— 宁可失败也不要占住 worker
if (!defined('WWE_TIMEOUT'))      { define('WWE_TIMEOUT', 12); }
if (!defined('WWE_SMTP_TIMEOUT')) { define('WWE_SMTP_TIMEOUT', 20); }

/**
 * 主机名校验：只允许普通主机名/IPv4，杜绝把 `}`, `{`, `/` 等 IMAP 串分隔符带进去。
 */
function wwe_valid_host($h) {
  $h = strtolower(trim((string)$h));
  if ($h === '' || strlen($h) > 253) { return ''; }
  if (!preg_match('/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/', $h)) { return ''; }
  if (strpos($h, '..') !== false) { return ''; }
  return $h;
}

function wwe_valid_port($p, $default) {
  $p = (int)$p;
  return ($p >= 1 && $p <= 65535) ? $p : $default;
}

/**
 * 构造 imap_open 的邮箱串。
 * @param string $folder 要打开的文件夹；空 = 只连服务器不开具体目录
 */
function wwe_mailbox_string($rcpt, $folder = 'INBOX') {
  $host = wwe_valid_host($rcpt['imap_host']);
  if ($host === '') { return ''; }
  $port = wwe_valid_port($rcpt['imap_port'], 993);
  $ref  = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}';
  return $ref . ($folder === null ? '' : $folder);
}

/**
 * 打开收件人邮箱。
 * @return array array($conn|false, $error)
 */
function wwe_connect($rcpt, $folder = 'INBOX') {
  if (!function_exists('imap_open')) {
    return array(false, 'PHP 未安装 imap 扩展');
  }
  $mb = wwe_mailbox_string($rcpt, $folder);
  if ($mb === '') {
    return array(false, 'IMAP 主机名不合法');
  }
  @imap_timeout(IMAP_OPENTIMEOUT, WWE_TIMEOUT);
  @imap_timeout(IMAP_READTIMEOUT, WWE_TIMEOUT);
  @imap_timeout(IMAP_WRITETIMEOUT, WWE_TIMEOUT);

  $conn = @imap_open($mb, (string)$rcpt['email'], (string)$rcpt['password'], 0, 1);
  if ($conn === false) {
    // imap_errors() 里可能含服务器返回的细节，但不含密码
    $errs = @imap_errors();
    $msg  = is_array($errs) && $errs ? implode('; ', array_slice($errs, 0, 3)) : 'IMAP 登录失败';
    @imap_alerts();
    return array(false, mb_substr($msg, 0, 200));
  }
  return array($conn, '');
}

/**
 * 找出「垃圾邮件」文件夹的真实名字。
 * **不能硬编码 `[Gmail]/Spam`** —— Gmail 的文件夹名是**本地化**的
 * （中文账号下是 `[Gmail]/垃圾邮件`）。必须按 IMAP 的 `\Spam` 属性去找。
 */
function wwe_spam_folder($conn) {
  $ref = '{x}';  // 只取属性，名字已经由 imap_getmailboxes 返回完整路径
  $list = @imap_getmailboxes($conn, '', '*');
  if (!is_array($list)) { return ''; }
  foreach ($list as $m) {
    if (!empty($m->attributes) && ((int)$m->attributes & LATT_SPAM)) {
      return (string)$m->name;
    }
  }
  return '';
}

/**
 * 在收件箱与垃圾箱里按 Message-ID 找一封邮件。
 * @return array array('state' => 'inbox'|'spam'|'missing', 'uid' => int, 'folder' => string)
 */
function wwe_find_message($conn, $messageId, $spamFolder = '') {
  $mid = trim((string)$messageId);
  if ($mid === '') { return array('state' => 'missing', 'uid' => 0, 'folder' => ''); }
  // 去掉尖括号，IMAP HEADER 搜索用裸串更稳
  $bare = trim($mid, " \t<>");

  $probe = function ($folder) use ($conn, $bare) {
    if ($folder !== '' && !@imap_reopen($conn, $folder)) { return array(0, ''); }
    $crit = 'HEADER Message-ID "' . addcslashes($bare, '"\\') . '"';
    $uids = @imap_search($conn, $crit, SE_UID);
    if (!is_array($uids) || empty($uids)) { return array(0, $folder); }
    return array((int)$uids[0], $folder);
  };

  list($uid, $f) = $probe('INBOX');
  if ($uid > 0) { return array('state' => 'inbox', 'uid' => $uid, 'folder' => $f); }

  if ($spamFolder !== '') {
    list($uid, $f) = $probe($spamFolder);
    if ($uid > 0) { return array('state' => 'spam', 'uid' => $uid, 'folder' => $f); }
  }
  return array('state' => 'missing', 'uid' => 0, 'folder' => '');
}

/**
 * 把落进垃圾箱的邮件标记为非垃圾并移回收件箱。
 * 对 Gmail 来说，"Not spam / Move to inbox" 是最强的正向信号之一。
 * @return array array($ok, $msg)
 */
function wwe_rescue_from_spam($rcpt, $messageId) {
  list($conn, $err) = wwe_connect($rcpt, 'INBOX');
  if ($conn === false) { return array(false, $err); }
  try {
    $spam = wwe_spam_folder($conn);
    if ($spam === '') { return array(false, '该账号没有垃圾邮件文件夹'); }

    $found = wwe_find_message($conn, $messageId, $spam);
    if ($found['state'] !== 'spam') {
      return array(false, $found['state'] === 'inbox' ? '邮件本来就在收件箱' : '在垃圾箱里找不到这封邮件');
    }
    if (!@imap_reopen($conn, $spam)) { return array(false, '无法打开垃圾邮件文件夹'); }

    // 先清标记，再移动 —— 顺序反了 Gmail 会保留 \Seen 之外的垃圾标记
    @imap_clearflag_full($conn, (string)$found['uid'], "\\Seen", ST_UID);
    $moved = @imap_mail_move($conn, (string)$found['uid'], 'INBOX', CP_UID);
    if (!$moved) {
      $e = @imap_errors();
      return array(false, '移动失败: ' . mb_substr(is_array($e) ? implode('; ', array_slice($e, 0, 2)) : '未知', 0, 160));
    }
    @imap_expunge($conn);
    return array(true, '已从垃圾箱移回收件箱');
  } finally {
    @imap_close($conn);
  }
}

/**
 * 用收件人身份发一封回复（最强的"互动"信号）。
 * @return array array($ok, $msg)
 */
function wwe_send_reply($rcpt, $to, $subject, $body, $inReplyTo = '') {
  $autoload = __DIR__ . '/lib/vendor/autoload.php';
  if (file_exists($autoload)) { require_once $autoload; }
  if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    return array(false, 'PHPMailer 不可用');
  }
  $host = wwe_valid_host($rcpt['smtp_host']);
  if ($host === '') { return array(false, 'SMTP 主机名不合法'); }
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return array(false, '收件地址不合法'); }

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->Port       = wwe_valid_port($rcpt['smtp_port'], 465);
    $mail->SMTPAuth   = true;
    $mail->Username   = (string)$rcpt['email'];
    $mail->Password   = (string)$rcpt['password'];
    // 465 = 隐式 TLS；587 = STARTTLS。两者都用加密，绝不裸发。
    $mail->SMTPSecure = ($mail->Port === 465)
      ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
      : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = WWE_SMTP_TIMEOUT;
    $mail->XMailer    = '';
    $mail->setFrom((string)$rcpt['email'], '');
    $mail->addAddress($to);
    if ($inReplyTo !== '') { $mail->addCustomHeader('In-Reply-To', $inReplyTo); }
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->send();
    return array(true, '已回复');
  } catch (Exception $e) {
    // 错误信息里可能含 SMTP 细节，但**不含密码**（PHPMailer 不回显密码）
    return array(false, mb_substr((string)($mail->ErrorInfo ?: $e->getMessage()), 0, 200));
  }
}

/**
 * 扫描收件箱里来自指定发件人的邮件（用于识别"对方回复了我们"）。
 * @return int 条数
 */
function wwe_count_from($conn, $fromEmail, $sinceDays = 14) {
  if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) { return 0; }
  if (!@imap_reopen($conn, 'INBOX')) { return 0; }
  $since = date('d-M-Y', time() - max(1, (int)$sinceDays) * 86400);
  $crit  = 'FROM "' . addcslashes($fromEmail, '"\\') . '" SINCE "' . $since . '"';
  $uids  = @imap_search($conn, $crit, SE_UID);
  return is_array($uids) ? count($uids) : 0;
}
