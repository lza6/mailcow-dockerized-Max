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
 * IMAP 命名前缀。**必须给 imap_list / imap_getmailboxes 传它** ——
 * 实测：reference 传空串时 imap_getmailboxes() 直接返回 false（什么都不列），
 * 传了正确前缀才返回全部文件夹。
 */
function wwe_ref($rcpt) {
  $host = wwe_valid_host($rcpt['imap_host']);
  if ($host === '') { return ''; }
  $port = wwe_valid_port($rcpt['imap_port'], 993);
  return '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}';
}

/**
 * 构造 imap_open 的邮箱串。
 * @param string $folder 要打开的文件夹；null = 只连服务器不开具体目录
 */
function wwe_mailbox_string($rcpt, $folder = 'INBOX') {
  $ref = wwe_ref($rcpt);
  if ($ref === '') { return ''; }
  return $ref . ($folder === null ? '' : $folder);
}

/** 列出全部文件夹（返回完整名字，形如 `{host:993/imap/ssl/novalidate-cert}Junk`） */
function wwe_list_folders($conn, $ref) {
  if ($conn === false || $ref === '') { return array(); }
  $l = @imap_list($conn, $ref, '*');
  if (!is_array($l)) {
    $g = @imap_getmailboxes($conn, $ref, '*');
    if (is_array($g)) {
      $l = array();
      foreach ($g as $m) { $l[] = (string)$m->name; }
    } else {
      return array();
    }
  }
  return $l;
}

/** 取文件夹的「最后一段」名字：`{...}Junk` / `{...}[Gmail]/垃圾邮件` → 末段 */
function wwe_folder_leaf($full) {
  $s = (string)$full;
  $i = strrpos($s, '}');
  if ($i !== false) { $s = substr($s, $i + 1); }
  $j = strrpos($s, '/');
  if ($j !== false) { $s = substr($s, $j + 1); }
  return $s;
}

/**
 * 找出「垃圾邮件」文件夹的真实名字。
 *
 * ⚠️ 这里有两个坑，都踩过：
 *
 * 1. **PHP 的 imap 扩展不暴露 IMAP SPECIAL-USE 属性** —— 常量表里只有
 *    LATT_NOINFERIORS / LATT_NOSELECT / LATT_MARKED / LATT_UNMARKED /
 *    LATT_REFERRAL / LATT_HASCHILDREN / LATT_HASNOCHILDREN，
 *    **没有 `LATT_SPAM`**（我第一版用了它，PHP 8 下直接致命错误）。
 *    所以拿不到 `\Spam` 属性，只能按**名字**识别。
 *
 * 2. 名字必须**先精确匹配多语言常见名**，再做子串兜底 ——
 *    否则 `[Gmail]/All Mail` 之类含 "mail" 的会被误判。Gmail 的垃圾箱名还是
 *    **本地化**的（中文账号下是 `[Gmail]/垃圾邮件`），所以中文名必须在内。
 *
 * @return string 完整文件夹名；找不到返回 ''
 */
function wwe_spam_folder($conn, $ref) {
  $folders = wwe_list_folders($conn, $ref);
  if (empty($folders)) { return ''; }

  // 精确匹配表（覆盖主流服务商与常见语言）
  static $exact = array(
    'junk', 'spam', 'junk email', 'junk e-mail', 'junk-e-mail', 'junkemail',
    'bulk mail', 'bulk', 'spam folder', 'spamverdacht', 'indésirables',
    'correo no deseado', 'posta indesiderata',
    '垃圾邮件', '垃圾箱', '垃圾筒', '垃圾郵件', '廣告郵件', '广告邮件',
    'スパム', '迷惑メール', '스팸', '정크',
  );
  // 子串兜底（顺序即优先级）
  static $sub = array('spam', 'junk', '垃圾', 'スパム', '스팸');

  $cands = array();
  foreach ($folders as $f) {
    $leaf = mb_strtolower(wwe_folder_leaf($f), 'UTF-8');
    if ($leaf === '') { continue; }
    $cands[$f] = $leaf;
  }
  // 1) 精确匹配
  foreach ($cands as $full => $leaf) {
    // 去掉 Gmail 那种前缀后比较，如 "[gmail]/spam" → 已由 leaf 处理
    if (in_array($leaf, $exact, true)) { return $full; }
  }
  // 2) 子串兜底（跳过显然不是垃圾箱的）
  foreach ($cands as $full => $leaf) {
    if (strpos($leaf, 'all mail') !== false || strpos($leaf, 'archive') !== false) { continue; }
    foreach ($sub as $s) {
      if (mb_strpos($leaf, $s, 0, 'UTF-8') !== false) { return $full; }
    }
  }
  return '';
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
 * 在收件箱与垃圾箱里按 Message-ID 找一封邮件。
 * @return array array('state' => 'inbox'|'spam'|'missing', 'uid' => int, 'folder' => string)
 */
/**
 * 在收件箱与垃圾箱里按 Message-ID 找一封邮件。
 *
 * @param string $ref IMAP 命名前缀（wwe_ref() 的返回值）。**必须传** ——
 *        imap_reopen() 要的是**带前缀的完整邮箱名**，只给裸串 `'INBOX'`
 *        会重开失败，函数会静默返回 0（实测踩过：垃圾箱那级因为传的是
 *        带前缀的完整名所以能命中，收件箱这级永远找不到）。
 * @return array array('state' => 'inbox'|'spam'|'missing', 'uid' => int, 'folder' => string)
 */
function wwe_find_message($conn, $messageId, $spamFolder, $ref) {
  $mid = trim((string)$messageId);
  if ($mid === '') { return array('state' => 'missing', 'uid' => 0, 'folder' => ''); }
  $bare = strtolower(trim($mid, " \t<>"));

  /**
   * 在一个文件夹里找这封邮件，返回 uid（找不到 0）。
   *
   * 为什么是「三级尝试 + 逐封读头核对」，而不是信一个搜索命令：
   *
   * 1. **`HEADER` 搜索并非到处都支持。** 实测本项目的 Dovecot 直接返回
   *    `Unknown search criterion: HEADER`（字段名加不加引号都一样），Gmail 则支持。
   * 2. **`TEXT` 也不可靠。** 同样的 Dovecot 上，对 Junk 里的邮件 TEXT 能命中，
   *    对 INBOX 里的另一封却命中不到（全文检索的分词行为不一致）。
   * 3. 所以最后必须有一个**不依赖任何搜索语义**的兜底：按日期枚举 + 读头精确比对。
   *    收件箱是我们自己控制的、要查的又只有最近发出的邮件，代价可以接受。
   *
   * 无论哪一级命中，最终都以「读回的 Message-ID 头是否精确相等」为准 ——
   * 这样既不会被正文里的巧合字符串骗到，也不会因服务器不支持某个搜索词而失效。
   */
  $lookup = function ($folder) use ($conn, $bare) {
    if ($folder !== '' && !@imap_reopen($conn, $folder)) { return 0; }

    $verify = function ($uids, $limit) use ($conn, $bare) {
      $checked = 0;
      foreach ((array)$uids as $u) {
        if ($checked++ >= $limit) { break; }
        $no = @imap_msgno($conn, (int)$u);
        if (!$no) { continue; }
        $h = @imap_headerinfo($conn, $no);
        if (!$h) { continue; }
        if (strtolower(trim((string)$h->message_id, " \t<>")) === $bare) { return (int)$u; }
      }
      return 0;
    };

    // ① HEADER 精确搜索（Gmail 等支持它的服务器上最快）
    $r = @imap_search($conn, 'HEADER Message-ID "' . addcslashes($bare, '"\\') . '"', SE_UID);
    @imap_errors();
    if (is_array($r) && $r) {
      $hit = $verify($r, 30);
      if ($hit > 0) { return $hit; }
    }

    // ② TEXT 全文搜索（部分服务器支持；结果仍要读头核对）
    $r2 = @imap_search($conn, 'TEXT "' . addcslashes($bare, '"\\') . '"', SE_UID);
    @imap_errors();
    if (is_array($r2) && $r2) {
      $hit = $verify($r2, 30);
      if ($hit > 0) { return $hit; }
    }

    // ③ 兜底：列出最近 N 天的邮件逐封比对（不依赖任何搜索语义）
    $since = date('d-M-Y', time() - 14 * 86400);
    $r3 = @imap_search($conn, 'SINCE "' . $since . '"', SE_UID);
    @imap_errors();
    if (is_array($r3) && $r3) {
      // 从最新往前找（新邮件更可能是我们刚发的）
      $uids = array_reverse($r3);
      $hit = $verify($uids, 300);
      if ($hit > 0) { return $hit; }
    }
    return 0;
  };

  $inboxFull = ($ref !== '') ? ($ref . 'INBOX') : 'INBOX';
  $uid = $lookup($inboxFull);
  if ($uid > 0) { return array('state' => 'inbox', 'uid' => $uid, 'folder' => $inboxFull); }

  if ($spamFolder !== '') {
    $uid = $lookup($spamFolder);
    if ($uid > 0) { return array('state' => 'spam', 'uid' => $uid, 'folder' => $spamFolder); }
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
    $spam = wwe_spam_folder($conn, wwe_ref($rcpt));
    if ($spam === '') { return array(false, '该账号没有垃圾邮件文件夹'); }

    $found = wwe_find_message($conn, $messageId, $spam, wwe_ref($rcpt));
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
