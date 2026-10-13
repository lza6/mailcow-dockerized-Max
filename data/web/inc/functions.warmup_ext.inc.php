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
 *
 * 另外**拒绝内网地址**：收件人主机是管理员填的，若允许任意 host:port，
 * 就等于让 php-fpm 变成内网端口探测器（它会拿着已保存的密码去连目标）。
 * 正常场景只可能是公网邮件服务商（Gmail/Outlook/自建域）。
 */
function wwe_valid_host($h) {
  $h = strtolower(trim((string)$h));
  if ($h === '' || strlen($h) > 253) { return ''; }
  if (!preg_match('/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/', $h)) { return ''; }
  if (strpos($h, '..') !== false) { return ''; }

  // 内网 / 回环 / 链路本地 / 容器网段 —— 一律拒绝
  if (preg_match('/^(localhost|.*\.local|.*\.internal|.*\.localhost)$/', $h)) { return ''; }
  if (filter_var($h, FILTER_VALIDATE_IP)) {
    if (!filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return '';
    }
  }
  // 主机名恰好是裸网段名（无点）也拒掉，如 `mysql`、`redis`
  if (strpos($h, '.') === false) { return ''; }
  return $h;
}

/** 端口白名单：只允许标准 IMAP/SMTP 端口，避免被拿去探测内网任意端口 */
function wwe_valid_port($p, $default) {
  $p = (int)$p;
  static $allow = array(143, 993, 587, 465, 25, 2525);
  return in_array($p, $allow, true) ? $p : (int)$default;
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

  /**
   * 重试**只针对连接层面的失败**（`[CLOSED] IMAP connection broken`、超时等）。
   *
   * 实测 Gmail 会毫无规律地掐断 IMAP 连接：同样的凭据，几秒之后重连就正常。
   * 不重试的话，一次偶发断连就会让这个收件人整轮判定失败 —— 而且
   * `warmup_check_deliveries` 在连接失败时是直接 `continue` 的，
   * 连 `last_check` 都不更新，界面上完全看不出发生过什么。
   *
   * **认证失败绝不重试**：那说明凭据不对、或账号被 Google 临时限流。
   * 继续猛敲只会把限流拖得更久。
   */
  $waits = array(0, 2, 5);
  $msg = 'IMAP 登录失败';
  foreach ($waits as $wait) {
    if ($wait > 0) { sleep($wait); }
    $conn = @imap_open($mb, (string)$rcpt['email'], (string)$rcpt['password'], 0, 1);
    if ($conn !== false) { return array($conn, ''); }

    $errs = @imap_errors();
    @imap_alerts();
    if (is_array($errs) && $errs) { $msg = implode('; ', array_slice($errs, 0, 3)); }
    if (stripos($msg, 'AUTHENTICATIONFAILED') !== false
        || stripos($msg, 'Invalid credentials') !== false) {
      break;
    }
  }
  return array(false, mb_substr($msg, 0, 200));
}


/**
 * 在收件箱与垃圾箱里找一封养号邮件。
 *
 * 优先用 `$token`（写进 Subject 的唯一标记，见 warmup_new_token）——
 * 这是**唯一可靠**的锚点：
 *
 *   1. **Message-ID 不能用。** 出站走中继时中继会重写它：实测 Resend 会把
 *      我们生成的 `<xxx@域>` 换成 `<...@send.域>` / `<...@email.amazonses.com>`。
 *      于是拿本地记的 message_id 去收件人邮箱反查**永远找不到**，
 *      投递判定恒为 unknown —— 而且不报任何错。
 *   2. **`HEADER` 搜索不能用。** 拦路的是 **PHP 自己**，不是服务器：
 *      `imap_search()` 只接受固定一批 criterion，`HEADER` 与 Gmail 的
 *      `X-GM-RAW` 都不在其中，实测直接返回
 *      `Unknown search criterion: HEADER` / `... X-GM-RAW`，
 *      请求根本没发到服务器。旧注释写「Gmail 支持 HEADER」是错的。
 *   3. **`TEXT` 也不能用。** 实测 Gmail 的 TEXT 搜索匹配不到 Message-ID 字符串。
 *   4. **`SUBJECT` 能用，而且快**（实测 ~0.7s）。Subject 不会被中继改写。
 *
 * 没有 token 的历史行（功能上线前发的）退回 `SINCE` + 读头精确比对。
 *
 * @param string $ref   IMAP 命名前缀（wwe_ref() 的返回值）。**必须传** ——
 *        imap_reopen() 要的是**带前缀的完整邮箱名**，只给裸串 `'INBOX'`
 *        会重开失败，函数会静默返回 0（实测踩过：垃圾箱那级因为传的是
 *        带前缀的完整名所以能命中，收件箱这级永远找不到）。
 * @param string $token 唯一标记；为空则走 Message-ID 兜底路径。
 * @return array array('state' => 'inbox'|'spam'|'missing', 'uid' => int, 'folder' => string)
 */
function wwe_find_message($conn, $messageId, $spamFolder, $ref, $token = '') {
  $mid = trim((string)$messageId);
  $tok = trim((string)$token);
  if ($mid === '' && $tok === '') { return array('state' => 'missing', 'uid' => 0, 'folder' => ''); }
  $bare = strtolower(trim($mid, " \t<>"));

  $lookup = function ($folder) use ($conn, $bare, $tok) {
    if ($folder !== '' && !@imap_reopen($conn, $folder)) { return 0; }

    // ① 首选：按唯一 token 搜 Subject（服务端索引，一次往返）
    if ($tok !== '') {
      $r = @imap_search($conn, 'SUBJECT "' . addcslashes($tok, '"\\') . '"', SE_UID);
      @imap_errors();
      if (is_array($r) && $r) { return (int)$r[0]; }
      return 0;
    }

    /**
     * ② 兜底（仅历史行）：按日期列出最近 14 天，只读最近 40 封的头精确比对。
     *
     * 一次取回候选邮件的头，再本地比对 —— **不要逐封 imap_headerinfo**：
     * 逐封就是一次网络往返，而 imap_fetch_overview() 用一个 UID 列表一次
     * 拿完（含 message_id），往返次数从 O(n) 降到 1。
     *
     * ⚠️ 这个 40 的上限直接决定耗时：实测 Gmail 的 imap_fetch_overview 约
     * 1.3 秒/封（300 封实测 108 秒）。绝不要调大 —— 之前是 300，
     * 一本 16 封邮件就要跑近一小时，check 永远跑不完。
     */
    $verify = function ($uids, $limit) use ($conn, $bare) {
      $uids = array_slice(array_values((array)$uids), 0, $limit);
      if (empty($uids)) { return 0; }
      $ov = @imap_fetch_overview($conn, implode(',', $uids), FT_UID);
      if (!is_array($ov)) { return 0; }
      foreach ($ov as $o) {
        if (!isset($o->message_id)) { continue; }
        if (strtolower(trim((string)$o->message_id, " \t<>")) === $bare) {
          return (int)$o->uid;
        }
      }
      return 0;
    };
    $since = date('d-M-Y', time() - 14 * 86400);
    $r3 = @imap_search($conn, 'SINCE "' . $since . '"', SE_UID);
    @imap_errors();
    if (is_array($r3) && $r3) {
      // 从最新往前找（新邮件更可能是我们刚发的）
      $hit = $verify(array_reverse($r3), 40);
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
function wwe_rescue_from_spam($rcpt, $messageId, $token = '') {
  list($conn, $err) = wwe_connect($rcpt, 'INBOX');
  if ($conn === false) { return array(false, $err); }
  try {
    $spam = wwe_spam_folder($conn, wwe_ref($rcpt));
    if ($spam === '') { return array(false, '该账号没有垃圾邮件文件夹'); }

    $found = wwe_find_message($conn, $messageId, $spam, wwe_ref($rcpt), $token);
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
 * 检查收件人是否**主动回过信**：在它自己的「已发送」里找发给某个我们方发件人的邮件。
 *
 * 为什么看 Sent 而不是 INBOX：我们要确认的是"对方回了我们"，
 * 那封信在对方的 Sent 里、在我们的发件箱里。
 *
 * @param string $sentFolder 「已发送」文件夹的完整名（由 wwe_sent_folder 找到）
 * @return array array($ok, $count, $latestAt)
 */
function wwe_check_peer_reply($conn, $sentFolder, $toEmail, $sinceDays = 30) {
  if ($sentFolder === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    return array(false, 0, '');
  }
  if (!@imap_reopen($conn, $sentFolder)) { return array(false, 0, ''); }
  $since = date('d-M-Y', time() - max(1, (int)$sinceDays) * 86400);
  $uids = @imap_search($conn, 'TO "' . addcslashes($toEmail, '"\\') . '" SINCE "' . $since . '"', SE_UID);
  @imap_errors();
  if (!is_array($uids) || empty($uids)) { return array(true, 0, ''); }

  $latest = '';
  // 只读**最新一封**的头就够了（UID 升序 → 最后一封最新）。
  // 原来一次读 100 封，在 Gmail 上要两分钟 —— 而这里只需要一个时间戳。
  $uids = array_values($uids);
  $ov = @imap_fetch_overview($conn, (string)end($uids), FT_UID);
  if (is_array($ov)) {
    foreach ($ov as $o) {
      if (!empty($o->date)) {
        $ts = strtotime($o->date);
        if ($ts && ($latest === '' || $ts > strtotime($latest))) { $latest = date('Y-m-d H:i:s', $ts); }
      }
    }
  }
  return array(true, count($uids), $latest);
}

/**
 * 找出「已发送」文件夹。
 * 和垃圾箱一样**不能硬编码名字**（Gmail 中文账号下是 `[Gmail]/已发邮件`），
 * 按常见名字精确匹配 + 子串兜底。
 */
function wwe_sent_folder($conn, $ref) {
  $folders = wwe_list_folders($conn, $ref);
  if (empty($folders)) { return ''; }
  static $exact = array(
    'sent', 'sent items', 'sent messages', 'sent mail', 'sent-mail', 'sentitems',
    'gesendet', 'éléments envoyés', 'enviados', 'inviati', 'verzonden',
    '已发送', '已寄郵件', '寄件備份', '已發送', '送信済み', '보낸 편지함',
  );
  $cands = array();
  foreach ($folders as $f) {
    $leaf = mb_strtolower(wwe_folder_leaf($f), 'UTF-8');
    if ($leaf !== '') { $cands[$f] = $leaf; }
  }
  foreach ($cands as $full => $leaf) {
    if (in_array($leaf, $exact, true)) { return $full; }
  }
  foreach ($cands as $full => $leaf) {
    if (strpos($leaf, 'sent') !== false || mb_strpos($leaf, '发送', 0, 'UTF-8') !== false
        || mb_strpos($leaf, '寄件', 0, 'UTF-8') !== false) { return $full; }
  }
  return '';
}
