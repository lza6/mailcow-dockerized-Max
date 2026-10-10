<?php
/**
 * mailcow-dockerized-Max — 邮件投递链路追踪（Delivery Trace）
 *
 * 管理员输入 Message-ID 或收件人地址，本端点从 postfix 日志中聚合该封邮件的
 * 完整投递链路（接收 → 入队 → 中继 → 结果），按时间升序返回，
 * 用于排查「为何退信 / 延迟 / 被拒」。
 *
 * 安全约定（与 inc/ajax/warmup.php 保持一致）：
 *   1. 仅 mailcow_cc_role === 'admin' 可调用，否则 403 JSON。
 *   2. 本端点只有只读动作，动作名走白名单；未知动作一律 400。
 *      不做写操作故不涉及 CSRF；将来若新增写动作，必须照 warmup.php 补 CSRF 校验。
 *   3. 日志内容一律以「纯文本」返回，后端不拼接任何 HTML。
 *      XSS 防护由前端 data/web/js/site/delivery-trace.js 的 esc() 统一负责
 *      （日志里的邮箱地址、主题均属用户可控内容）。
 *   4. 输入做白名单校验：拒绝换行 / 制表 / 分号 / 空白等字符，并限长。
 *   5. 日志读取只经 functions.inc.php 的 get_logs()，行数有硬上限（DT_MAX_LINES）。
 *
 * 已知限制（不在本文件内伪造）：
 *   cf-bridge 的日志行（RELAY OK / RELAY FAIL）在本部署中**无法**从 Web UI 读取：
 *     - data/Dockerfiles/dockerapi/main.py 没有 logs 路由；
 *     - data/web/inc/functions.docker.inc.php 的 docker() 也没有 'logs' 分支
 *       （container_ctrl.php 里的 logs 分支在本版本是死代码）；
 *     - cf-bridge 未出现在 docker-compose.yml 中，php-fpm 容器也没有挂 docker socket。
 *   引入这些需要新增挂载/基础设施，超出本次改动范围，故本端点只聚合 postfix 日志，
 *   并在响应中通过 cf_bridge.available = false 显式告知前端（UI 上如实标注）。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';

header('Content-Type: application/json; charset=utf-8');

// ---- 权限：仅管理员（与 warmup.php 完全一致） ----
if (!isset($_SESSION['mailcow_cc_role']) || $_SESSION['mailcow_cc_role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'access denied'), JSON_UNESCAPED_UNICODE);
  exit;
}

// ---- 权限：只读 API 会话标记（本端点无写动作，仅用于与其他模块保持一致） ----
$is_api_session = isset($_SESSION['mailcow_cc_api']) && $_SESSION['mailcow_cc_api'] === true;

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

// 只读动作白名单：不在名单内的一律拒绝。
$read_actions = array('query', 'recent');
if (!in_array($action, $read_actions, true)) {
  http_response_code(400);
  echo json_encode(array('ok' => false, 'message' => 'unknown action'), JSON_UNESCAPED_UNICODE);
  exit;
}

define('DT_DEFAULT_LINES', 1000);   // 与 vars.inc.php 的 $LOG_LINES 默认值一致
define('DT_MIN_LINES', 50);
define('DT_MAX_LINES', 2000);       // 硬上限：防止一次拉取过多日志导致超时
define('DT_RECENT_MAX', 200);       // 「最近日志」浏览返回条数上限
define('DT_MAX_MATCHES', 500);      // 单次返回的链路条目上限
define('DT_QUERY_MIN_LEN', 6);      // 查询串最小长度：避免单字符模糊匹配把日志全捞出来

function dt_json($data) {
  // 与 warmup.php 的 wu_json() 同一约定：mailcow 的 session_check() 在每次 POST
  // 通过校验后会轮换 $_SESSION['CSRF']['TOKEN']，页面注入的 window.csrf_token 会过期。
  // 本端点只走 GET（不触发轮换），仍回传当前 token，使前端可以和其他后台模块
  // 共用同一套「响应里更新 token」的逻辑，不会拿到陈旧值。
  if (!isset($data['csrf']) && isset($_SESSION['CSRF']['TOKEN'])) {
    $data['csrf'] = $_SESSION['CSRF']['TOKEN'];
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function dt_param($key, $default = '') {
  if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
    return trim((string)$_REQUEST[$key]);
  }
  return $default;
}

/**
 * 校验并归一化 lines 参数（必须显式传值，且有上下限）
 *
 * @return int|false
 */
function dt_lines($raw) {
  if ($raw === '' || $raw === null) {
    return DT_DEFAULT_LINES;
  }
  if (!is_numeric($raw)) {
    return false;
  }
  $n = (int)$raw;
  if ($n < DT_MIN_LINES || $n > DT_MAX_LINES) {
    return false;
  }
  return $n;
}

/**
 * 校验并归一化 Message-ID。
 * 允许带或不带尖括号，返回去掉尖括号后的字符串（postfix 日志里 message-id=<...>，
 * 用去括号后的值做子串匹配可同时命中两种写法）。
 *
 * @return string|false
 */
function dt_normalize_message_id($raw) {
  $s = trim((string)$raw);
  if ($s === '') {
    return '';
  }
  if (strlen($s) >= 2 && $s[0] === '<' && substr($s, -1) === '>') {
    $s = substr($s, 1, -1);
  }
  if ($s === '' || strlen($s) > 250) {
    return false;
  }
  // 仅允许 msg-id 的可见字符；显式排除 < > " \ 空白 ; 与所有控制字符
  if (!preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.@\[\]-]+$/', $s)) {
    return false;
  }
  if (strlen($s) < DT_QUERY_MIN_LEN) {
    return false;
  }
  return $s;
}

/**
 * 校验收件人邮箱（与 warmup.php 的 wu_valid_email() 同一标准）
 */
function dt_valid_email($raw) {
  $s = trim((string)$raw);
  if ($s === '' || strlen($s) > 254) {
    return false;
  }
  if (preg_match('/[\r\n\t,;\s]/', $s)) {
    return false;
  }
  return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}

/**
 * 从 postfix 日志正文中取队列 ID。
 * postfix 的 $MESSAGE 形如 "4E8A1B2C3D: message-id=<...>"。
 *
 * 格式依据：本部署未设置 enable_long_queue_ids（data/conf/postfix/main.cf 无该项，
 * 即 Postfix 默认的短队列 ID = 十六进制）；mailcow 自身也按十六进制校验队列 ID
 * （data/Dockerfiles/dockerapi/modules/DockerApi.py:87 `^[0-9a-fA-F]+$`）。
 * 仅认十六进制可天然排除 NOQUEUE / statistics: / warning: 等非队列 ID 前缀。
 */
function dt_qid($message) {
  if (preg_match('/^([0-9A-Fa-f]{5,20}):/', trim($message), $m)) {
    return $m[1];
  }
  return '';
}

/**
 * 解析单行日志的「阶段 / 状态 / 中继目标」。
 *
 * stage: submit(接收入队) | relay(中继投递) | other
 *        注意：退信行**不**从 relay 挪走，它同时会被标为 is_result（见 dt_do_query），
 *        否则「中继」步骤会显示 0 行，且会丢掉 relay_failed 标记。
 * state: info | sent | deferred | bounced | expired | rejected | hold | discarded
 * relay_attempt: 该行是否是 postfix 的「中继/投递尝试」（smtp/lmtp/relay/virtual/local）
 */
function dt_parse_line($program, $message) {
  $p = strtolower($program);
  $m = strtolower($message);

  $state = 'info';
  if (preg_match('/\bstatus=(sent|deferred|bounced|expired|rejected|hold|discarded)\b/', $m, $sm)) {
    $state = $sm[1];
  }
  elseif (strpos($m, 'reject:') !== false) {
    $state = 'rejected';
  }

  $stage = 'other';
  $relay_attempt = false;
  if (strpos($p, 'smtpd') !== false || strpos($p, 'submission') !== false
       || strpos($p, 'cleanup') !== false || strpos($p, 'pickup') !== false
       || strpos($p, 'qmgr') !== false) {
    $stage = 'submit';
  }
  elseif (strpos($p, 'smtp') !== false || strpos($p, 'lmtp') !== false
       || strpos($p, 'relay') !== false || strpos($p, 'virtual') !== false
       || strpos($p, 'local') !== false) {
    $stage = 'relay';
    $relay_attempt = true;
  }

  $relay = '';
  if (preg_match('/\brelay=([^\s,]+)/', $message, $rm)) {
    $relay = $rm[1];
  }
  elseif (preg_match('/\bnext-hop=([^\s,]+)/', $message, $nm)) {
    $relay = $nm[1];
  }

  return array(
    'stage'         => $stage,
    'state'         => $state,
    'relay'         => $relay,
    'relay_attempt' => $relay_attempt,
    'is_result'     => (strpos($p, 'bounce') !== false
                        || in_array($state, array('bounced', 'expired', 'rejected', 'discarded'), true)),
  );
}

/**
 * 严重度排序，用于把多行状态聚合成一个步骤徽标
 */
function dt_severity($state) {
  $rank = array(
    'info' => 0, 'sent' => 1, 'hold' => 2, 'deferred' => 3,
    'rejected' => 4, 'discarded' => 4, 'bounced' => 5, 'expired' => 5,
  );
  return isset($rank[$state]) ? $rank[$state] : 0;
}

function dt_worse($a, $b) {
  return (dt_severity($b) > dt_severity($a)) ? $b : $a;
}

/**
 * 通过 get_logs() 读取 postfix 日志并归一化。
 * 只复用现成的日志函数，不自行读文件、不调 docker。
 *
 * @return array [entries(新→旧), error|null]
 */

/**
 * 读取 cf-bridge 的日志（由桥容器写到宿主目录，php-fpm 只读挂载到 /cf-bridge-logs）。
 *
 * 为什么不用 docker logs：php-fpm 容器**没有** docker socket（这是正确的安全设计，
 * 给了等于给 Web 应用主机 root 权限），dockerapi 也没有 logs 路由。
 * 因此改为让桥主动写文件、php-fpm 只读读取。
 *
 * @return array array($entries, $error)
 */
function dt_load_bridge_entries($lines) {
  $file = '/cf-bridge-logs/cf-bridge.log';
  if (!is_readable($file)) {
    return array(array(), 'cf-bridge 日志文件不可读（' . $file . '）：请确认桥容器已挂载日志目录并设置了 LOG_FILE');
  }
  $lines = max(1, (int)$lines);

  // 只从文件**尾部**读，不把整个文件读进内存。
  // 原实现用 file() 整读再 array_slice —— 注释写着「避免大文件拖慢」，
  // 但内存早在读取那一刻就吃满了：5MB ≈ 35k 行 ≈ 10–15MB PHP 数组，
  // 并发读时成倍放大，而实际只用到最后 $lines 行。
  $fh = @fopen($file, 'rb');
  if ($fh === false) {
    return array(array(), 'cf-bridge 日志打开失败');
  }
  $size = (int)@filesize($file);
  // 按每行约 400 字节估算需要回退多少，留足余量；至少回退 4KB
  $back  = max(4096, $lines * 400);
  $start = max(0, $size - $back);
  if ($start > 0) { fseek($fh, $start); }
  $buf = stream_get_contents($fh);
  fclose($fh);
  if ($buf === false) {
    return array(array(), 'cf-bridge 日志读取失败');
  }
  // 从中间切入时第一行可能是残行，丢弃
  if ($start > 0) {
    $nl = strpos($buf, "\n");
    if ($nl !== false) { $buf = substr($buf, $nl + 1); }
  }
  $raw = preg_split('/\r\n|\n|\r/', $buf);
  $raw = array_values(array_filter($raw, function ($l) { return trim((string)$l) !== ''; }));
  if (count($raw) > $lines) {
    $raw = array_slice($raw, -$lines);
  }
  $out = array();
  foreach ($raw as $line) {
    $line = trim((string)$line);
    if ($line === '') { continue; }
    $state = 'info';
    if (strpos($line, 'RELAY OK') !== false)        { $state = 'sent'; }
    elseif (strpos($line, 'RELAY FAIL') !== false)  { $state = 'failed'; }
    elseif (strpos($line, 'WARNING') !== false)     { $state = 'warn'; }
    elseif (strpos($line, 'ERROR') !== false)       { $state = 'failed'; }
    $out[] = array('raw' => $line, 'state' => $state, 'source' => 'cf-bridge');
  }
  return array($out, null);
}

function dt_load_entries($lines) {
  $logs = get_logs('postfix-mailcow', $lines);
  if (!is_array($logs)) {
    return array(array(), 'postfix 日志不可读（redis 中 POSTFIX_MAILLOG 为空或不可访问）');
  }
  $entries = array();
  foreach ($logs as $row) {
    if (!is_array($row)) {
      continue;
    }
    $msg = isset($row['message']) ? (string)$row['message'] : '';
    if ($msg === '') {
      continue;
    }
    // syslog-ng 的 format-json 产出 time 为 unix 秒（可能被识别为数字或字符串）
    $raw_time = isset($row['time']) ? $row['time'] : '';
    $ts = is_numeric($raw_time) ? (int)$raw_time : (int)strtotime((string)$raw_time);
    $entries[] = array(
      'ts'       => $ts,
      'time_str' => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '',
      'program'  => isset($row['program']) ? (string)$row['program'] : '',
      'priority' => isset($row['priority']) ? (string)$row['priority'] : '',
      'message'  => $msg,
    );
  }
  return array($entries, null);
}

/**
 * 判断一行日志是否命中查询串。
 *
 * 必须做**词边界**匹配，否则纯子串匹配会误报 ——
 * 例如查询 "r@dest.com" 会命中日志里的 "other@dest.com"，
 * 从而把一封无关邮件的队列拉进链路，直接毁掉排查结论。
 *
 * 查询串已由 dt_normalize_message_id()/dt_valid_email() 限定为 ASCII 白名单字符，
 * 且此处用 preg_quote() 转义，不存在把输入当正则解释的风险。
 */
function dt_needle_hit($haystack, $needle) {
  $re = '/(?<![A-Za-z0-9._%+\-])' . preg_quote($needle, '/') . '(?![A-Za-z0-9.\-])/i';
  return (@preg_match($re, $haystack) === 1);
}

/**
 * @param int $lines 已校验的行数
 * @param string $mid 已归一化的 Message-ID（'' 表示未填）
 * @param string $rcpt 已校验的收件人（'' 表示未填）
 */
function dt_do_query($lines, $mid, $rcpt) {
  list($entries, $err) = dt_load_entries($lines);

  // 查询串：Message-ID 与收件人任一命中即算种子行。
  $needles = array();
  if ($mid !== '') {
    $needles[] = $mid;
  }
  if ($rcpt !== '') {
    $needles[] = $rcpt;
  }

  $matched   = array();   // 命中的索引
  $seed_qids = array();   // 命中行所属的 postfix 队列 ID
  foreach ($entries as $i => $e) {
    $hit = false;
    foreach ($needles as $n) {
      if (dt_needle_hit($e['message'], $n)) {
        $hit = true;
        break;
      }
    }
    if (!$hit) {
      continue;
    }
    $matched[$i] = true;
    $qid = dt_qid($e['message']);
    if ($qid !== '') {
      $seed_qids[$qid] = true;
    }
  }

  // 展开：同队列 ID 的所有行都属于同一封邮件（Message-ID 只出现在 cleanup 行，
  // 必须靠队列 ID 把后续的 qmgr/smtp/bounce 行串起来）。
  $timeline = array();
  $truncated = false;
  foreach ($entries as $i => $e) {
    $qid = dt_qid($e['message']);
    $is_match = isset($matched[$i]);
    $is_related = ($qid !== '' && isset($seed_qids[$qid]));
    if (!$is_match && !$is_related) {
      continue;
    }
    if (count($timeline) >= DT_MAX_MATCHES) {
      $truncated = true;
      break;
    }
    $parsed = dt_parse_line($e['program'], $e['message']);
    $timeline[] = array(
      'ts'          => $e['ts'],
      'time_str'    => $e['time_str'],
      'program'     => $e['program'],
      'priority'    => $e['priority'],
      'qid'         => $qid,
      'stage'       => $parsed['stage'],
      'state'       => $parsed['state'],
      'relay'       => $parsed['relay'],
      'is_match'    => $is_match,
      'is_result'   => $parsed['is_result'],
      // 「relay failed」= postfix 的中继/投递尝试出现失败类状态
      'relay_failed'=> ($parsed['relay_attempt']
                        && in_array($parsed['state'], array('deferred', 'bounced', 'expired', 'rejected'), true)),
      'matched_by'  => $is_match ? 'query' : 'queue-id',
      'message'     => $e['message'],
    );
  }

  // 按时间升序（redis 里是新→旧）
  usort($timeline, function ($a, $b) {
    if ($a['ts'] === $b['ts']) {
      return strcmp($a['message'], $b['message']);
    }
    return ($a['ts'] < $b['ts']) ? -1 : 1;
  });

  // ---- 聚合：计数 / 最终判定 / 三阶段徽标 ----
  /** 三阶段徽标：submit / relay 按行自然归类；result 由终态行（退信类）聚合，
   *  因此同一行可能同时计入 relay 与 result，两者不是互斥划分。 */
  $counts = array();
  $stages = array(
    'submit' => array('count' => 0, 'state' => 'info'),
    'relay'  => array('count' => 0, 'state' => 'info'),
    'result' => array('count' => 0, 'state' => 'info'),
  );
  $last_state = '';
  $relay_targets = array();
  foreach ($timeline as $ln) {
    $st = $ln['state'];
    $counts[$st] = (isset($counts[$st]) ? $counts[$st] : 0) + 1;
    if ($st !== 'info') {
      $last_state = $st;
    }
    if (isset($stages[$ln['stage']])) {
      $stages[$ln['stage']]['count']++;
      $stages[$ln['stage']]['state'] = dt_worse($stages[$ln['stage']]['state'], $st);
    }
    if ($ln['is_result']) {
      $stages['result']['count']++;
      $stages['result']['state'] = dt_worse($stages['result']['state'], $st);
    }
    if ($ln['relay'] !== '') {
      $relay_targets[$ln['relay']] = true;
    }
  }

  $verdict = ($last_state !== '') ? $last_state : 'unknown';
  // 出现过退信/过期即判为退信，即使之后还有别的行
  if (isset($counts['bounced']) || isset($counts['expired'])) {
    $verdict = 'bounced';
  }
  // 结果阶段的徽标以最终判定为准
  $stages['result']['state'] = ($verdict === 'unknown') ? $stages['result']['state'] : $verdict;

  return array(
    'ok'            => true,
    'cf_bridge'     => (function () use ($lines, $mid, $rcpt) {
      list($bentries, $berr) = dt_load_bridge_entries($lines);
      // 若提供了筛选条件，则只保留命中该 Message-ID / 收件人的桥日志行
      if ($mid !== '' || $rcpt !== '') {
        $filtered = array();
        foreach ($bentries as $e) {
          $hit = false;
          if ($mid !== ''  && stripos($e['raw'], $mid)  !== false) { $hit = true; }
          if ($rcpt !== '' && stripos($e['raw'], $rcpt) !== false) { $hit = true; }
          if ($hit) { $filtered[] = $e; }
        }
        $bentries = $filtered;
      }
      return array(
        'available' => ($berr === null),
        'message'   => ($berr === null ? '' : $berr),
        'matched'   => count($bentries),
        'entries'   => array_slice($bentries, -50),
      );
    })(),
    'query'         => array('message_id' => $mid, 'recipient' => $rcpt),
    'lines'         => $lines,
    'scanned'       => count($entries),
    'match_count'   => count($timeline),
    'truncated'     => $truncated,
    'verdict'       => $verdict,
    'counts'        => $counts,
    'stages'        => $stages,
    'relay_targets' => array_keys($relay_targets),
    'timeline'      => $timeline,
    'recent'        => array_slice($entries, 0, DT_RECENT_MAX),
    'error'         => $err,
  );
}

try {
  switch ($action) {

    // ============ 链路查询 ============
    case 'query': {
      $raw_mid  = dt_param('message_id');
      $raw_rcpt = dt_param('recipient');

      if ($raw_mid === '' && $raw_rcpt === '') {
        dt_json(array('ok' => false, 'message' => '请至少填写 Message-ID 或收件人邮箱'));
      }

      $mid = dt_normalize_message_id($raw_mid);
      if ($mid === false) {
        dt_json(array('ok' => false, 'message' => 'Message-ID 格式不合法（不允许尖括号以外的特殊字符/空白，长度需在 6-250 之间）'));
      }

      $rcpt = '';
      if ($raw_rcpt !== '') {
        if (!dt_valid_email($raw_rcpt)) {
          dt_json(array('ok' => false, 'message' => '收件人邮箱格式不合法'));
        }
        $rcpt = $raw_rcpt;
      }

      $lines = dt_lines(dt_param('lines'));
      if ($lines === false) {
        dt_json(array('ok' => false, 'message' => 'lines 参数不合法（需为 ' . DT_MIN_LINES . '-' . DT_MAX_LINES . ' 的整数）'));
      }

      dt_json(dt_do_query($lines, $mid, $rcpt));
    }

    // ============ 最近日志浏览（不筛选，供人工翻看） ============
    case 'recent': {
      $lines = dt_lines(dt_param('lines'));
      if ($lines === false) {
        dt_json(array('ok' => false, 'message' => 'lines 参数不合法（需为 ' . DT_MIN_LINES . '-' . DT_MAX_LINES . ' 的整数）'));
      }
      list($entries, $err) = dt_load_entries($lines);
      dt_json(array(
        'ok'        => true,
        'lines'     => $lines,
        'scanned'   => count($entries),
        'available' => ($err === null),
        'error'     => $err,
        'recent'    => array_slice($entries, 0, DT_RECENT_MAX),
      ));
    }
  }
}
catch (Exception $e) {
  // 不把内部细节（含日志内容/路径）回传给前端，只给一个可对日志的追踪号
  $trace = bin2hex(random_bytes(4));
  error_log('delivery_trace.php error [' . $trace . ']: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(array('ok' => false, 'message' => 'internal error', 'trace' => $trace), JSON_UNESCAPED_UNICODE);
  exit;
}
