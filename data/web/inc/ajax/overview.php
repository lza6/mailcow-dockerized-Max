<?php
/**
 * mailcow-dockerized-Max — 域名总览 AJAX 端点
 *
 * 一个页面看全：每个域的真实收信/认证/中继/养号状态，以及每个邮箱账号。
 * 所有写操作都会写入 admin_audit_log（审计）。
 *
 * 性能设计（重要）：
 *   页面加载**只读数据库 + redis 缓存**，绝不在加载时做 DNS 查询——
 *   16 个域逐个查 DNS 会让首屏等十几秒。
 *   DNS 类检查（MX/SPF/DKIM/DMARC）由「体检」动作触发后写入缓存，
 *   页面只展示缓存结果；没体检过的域显示「未检测」。
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.audit.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/functions.overview.inc.php';

header('Content-Type: application/json; charset=utf-8');

// ---- 权限：仅管理员 ----
if (!isset($_SESSION['mailcow_cc_role']) || $_SESSION['mailcow_cc_role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'access denied'), JSON_UNESCAPED_UNICODE);
  exit;
}

$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

$write_actions = array(
  'add_domain', 'add_mailbox', 'reset_mailbox_password',
  'toggle_mailbox', 'check_domain', 'check_all',
  // reveal_secret 本身不改数据，但它会把明文密钥下发到浏览器并写审计，
  // 按「有副作用的动作」对待：拒绝 API 会话 + 强制 POST（防被动的 GET 触发）。
  'reveal_secret',
);

// 写操作一律拒绝 API key 会话。
//
// 说明：mailcow 的 API key 分 ro / rw，但本页新增的这几个写动作在上游权限表里
// 没有对应权限位，无法可靠区分调用方是 ro 还是 rw。与其猜测，不如全部拒绝：
// 需要写操作请用浏览器会话——框架的 session_check() 会强制校验 CSRF。
// （原先这里还跟了一段 `$is_write && $is_api_session` 的 CSRF 校验分支，
//   但上面的拒绝已经 exit，该分支永远不可达，属于死代码，已删除。）
if (isset($_SESSION['mailcow_cc_api']) && $_SESSION['mailcow_cc_api'] === true
    && in_array($action, $write_actions, true)) {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'message' => 'API key sessions cannot perform write operations on this endpoint'), JSON_UNESCAPED_UNICODE);
  exit;
}

/*
 * 写动作显式要求「本次请求确实通过了框架的 CSRF 校验」。
 *
 * sessions.inc.php 的真实行为（读源码确认，不是推测）：
 *   校验**通过** → unset($_POST['csrf_token'])（其余字段保留）
 *                 且 $_SESSION['CSRF']['TOKEN'] = 新随机值（轮换）
 *   校验**失败** → return false，随后调用方执行 $_POST = array()（整个清空）
 *
 * 所以判据只能用「框架留下了什么」，**不能用 token 值比对**：
 *   会话里的 token 在校验通过的那一刻已经被换成新值，此刻再拿客户端提交的旧值
 *   去比一定不相等 —— 第一版就是这么写的，实测把**所有合法写请求**都 403 掉了。
 *
 * 两道判据：
 *   ① 客户端确实提交过 token（在 $_REQUEST 里找 —— 成功时 $_POST 里的已被 unset）
 *   ② $_POST 没有被清空 —— 说明框架放行了
 *
 * 为什么需要这道检查（2026-10-11 生产实测）：
 *   「CSRF 失败 → 清空参数 → 动作自然失效」只对**带参**动作成立。
 *   check_all 不读任何参数，修复前用伪造 token 打过去会照常执行：
 *     实测 → HTTP 200 {"ok":true,"checked":16}，审计新增一条 domain.check_all。
 */
if (in_array($action, $write_actions, true)) {
  if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    http_response_code(405);
    ov_json(array('ok' => false, 'message' => '该动作仅接受 POST'));
  }
  if (!isset($_REQUEST['csrf_token']) || (string)$_REQUEST['csrf_token'] === '') {
    http_response_code(403);
    ov_json(array('ok' => false, 'message' => '缺少 CSRF token，请刷新页面后重试'));
  }
  if (empty($_POST)) {
    http_response_code(403);
    ov_json(array('ok' => false, 'message' => 'CSRF 校验失败，请刷新页面后重试'));
  }
}

function ov_json($data) {
  // mailcow 每次 POST 通过校验后会轮换 CSRF token，必须回传否则前端第二次写必 403
  if (!isset($data['csrf']) && isset($_SESSION['CSRF']['TOKEN'])) {
    $data['csrf'] = $_SESSION['CSRF']['TOKEN'];
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ov_post($k, $d = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }

/**
 * 判定 mailbox() 的调用结果。
 *
 * mailcow 的 mailbox() 失败时**不抛异常**，而是 return false 并把原因写进
 * $_SESSION['return'][].msg（源码里约 50 处如此）。调用方若不检查返回值，
 * 就会把"没做成"当成"做成了"——既误导管理员，也会往审计里写假记录。
 *
 * @return array array($ok, $msg)
 */
function ov_mailbox_result($result) {
  $msgs = array();
  if (isset($_SESSION['return']) && is_array($_SESSION['return'])) {
    foreach ($_SESSION['return'] as $r) {
      if (isset($r['msg']) && $r['msg'] !== '') { $msgs[] = (string)$r['msg']; }
    }
    // 取完即清，避免污染后续判定
    unset($_SESSION['return']);
  }
  if ($result === false) {
    return array(false, $msgs ? implode('; ', array_unique($msgs)) : 'mailbox() 返回 false');
  }
  if (is_array($result) && isset($result['error']) && $result['error'] !== '') {
    return array(false, (string)$result['error']);
  }
  return array(true, '');
}

/**
 * 串行化「针对同一目标对象」的写操作，并对死锁自动重试。
 *
 * 背景（2026-10-11 生产压测实测，不是推测）：
 *   8 个管理员会话并发对**同一个邮箱**做 toggle 时，MariaDB 报
 *     SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying
 *     to get lock; try restarting transaction
 *   100 次写入里出现 1 次失败 —— 管理员点了按钮却报错。
 *
 * 根因：mailcow 的 mailbox('edit','mailbox') 内部会连续更新 mailbox / alias /
 * quota2 / sogo 视图等多张表，并发下锁顺序交错，必然死锁；这是上游行为，
 * 我们无法在不侵入 mailcow 核心的前提下改它的锁顺序。
 *
 * 两道防线：
 *   1) GET_LOCK 按目标串行 —— 同一目标的写排队，从根上消除这类死锁；
 *   2) 死锁重试 —— 不同目标之间仍可能间接死锁，识别到就退避重试。
 *
 * @param PDO      $pdo      数据库句柄
 * @param string   $lock_key 目标标识（如邮箱地址）；相同 key 之间互斥
 * @param callable $fn       真正执行写入的闭包，返回 mailbox() 的返回值
 * @return array array($ok, $msg)
 */
function ov_write($pdo, $lock_key, $fn) {
  $lock  = 'mailcow_ov_' . md5((string)$lock_key);
  $got   = false;
  $tries = 4;
  try {
    $got = (bool)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 5)")->fetchColumn();
  } catch (Exception $e) {
    // 拿不到锁也继续：重试逻辑仍然能兜住死锁，不能因为锁服务异常就拒绝写入
    $got = false;
  }
  $last = '未知错误';
  try {
    for ($try = 0; $try < $tries; $try++) {
      list($ok, $msg) = ov_mailbox_result($fn());
      if ($ok) { return array(true, ''); }
      $last = $msg;
      // 只有死锁/锁等待超时才重试；参数错误、配额冲突等重试无意义
      if (!preg_match('/deadlock|serialization failure|lock wait timeout|\b(1213|1205)\b/i', $msg)) {
        return array(false, $msg);
      }
      usleep(100000 * ($try + 1));   // 100 / 200 / 300 ms 退避
    }
    return array(false, $last . '（已重试 ' . $tries . ' 次）');
  } finally {
    if ($got) {
      try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")")->fetchColumn(); } catch (Exception $e) {}
    }
  }
}

/**
 * 统一的「未预期异常」出口。
 *
 * 为什么不把 $e->getMessage() 直接回给浏览器 / 写进审计：
 *   PDOException 的消息里含 SQL 片段、表名与列名；它既会经响应回显，
 *   也会经 admin_audit_log.detail 再渲染回页面上（审计页就是本页）。
 *   完整信息只进 error_log，浏览器与审计里只留追踪码，需要时按码去容器日志查。
 */
function ov_fatal($pdo, $action, $target, $e) {
  $trace = bin2hex(random_bytes(4));
  $type  = get_class($e);
  error_log('overview.php [' . $action . '][' . $trace . '] ' . $type . ': ' . $e->getMessage());
  try {
    audit_log($pdo, $action, 'error', $target, 'fail', $type . ' trace=' . $trace);
  } catch (Exception $x) {}
  ov_json(array('ok' => false, 'message' => '内部错误，请稍后重试（追踪码 ' . $trace . '）'));
}

function ov_valid_domain($d) {  if ($d === '' || strlen($d) > 253) return false;
  if (preg_match('/[\r\n\t,;@\s]/', $d)) return false;
  return (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $d);
}
function ov_valid_email($e) {
  if ($e === '' || strlen($e) > 254) return false;
  if (preg_match('/[\r\n\t,;]/', $e)) return false;
  return (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

/** 本机主机名（用于判断 MX 是否指向我们自己） */
function ov_hostname() {
  return strtolower(trim((string)getenv('MAILCOW_HOSTNAME')));
}

/**
 * 对单个域做轻量检查（MX / SPF / DKIM / DMARC），结果写 redis 缓存 1 小时。
 * 只在「体检」动作里调用，不在页面加载时调用。
 */
function ov_run_check($pdo, $redis, $domain) {
  $host = ov_hostname();
  $r = array('domain' => $domain, 'checked_at' => date('Y-m-d H:i:s'));

  // MX
  $mxs = array();
  $res = @dns_get_record($domain, DNS_MX);
  if (is_array($res)) {
    foreach ($res as $x) {
      if (!empty($x['target'])) $mxs[] = strtolower(rtrim($x['target'], '.'));
    }
  }
  $r['mx'] = $mxs;
  $r['mx_ok'] = in_array($host, $mxs, true);

  // SPF
  $spf = null;
  $res = @dns_get_record($domain, DNS_TXT);
  if (is_array($res)) {
    foreach ($res as $x) {
      $t = isset($x['txt']) ? $x['txt'] : '';
      if (stripos($t, 'v=spf1') === 0) { $spf = $t; break; }
    }
  }
  $r['spf'] = $spf;
  // SPF 判定标准（原实现只去找一个已下线的 `include:...serv00...`，
  // serv00 回收后该特征在所有域上都不存在，于是「没有 SPF 的域」也会被判 ok）。
  // 现改为三条硬标准：
  //   1) 存在 v=spf1 记录
  //   2) 授权了本机（mx / a:本机主机名 / include:本机主机名）
  //   3) 以 all 机制收尾（~/-/+/ ? 任一）
  $r['spf_ok'] = false;
  if ($spf !== null) {
    $spf_l = strtolower($spf);
    $covers_us = (bool)preg_match('/(?:^|\s)mx(?:\s|:|$)/', $spf_l)
              || ($host !== '' && strpos($spf_l, ':' . $host) !== false);
    $has_all = (bool)preg_match('/[~\-+?]all(?:\s|$)/', $spf_l);
    $r['spf_ok'] = $covers_us && $has_all;
  }

  // DKIM（从 redis 取 selector）
  $sel = null;
  try { $sel = $redis ? $redis->hGet('DKIM_SELECTORS', $domain) : null; } catch (Exception $e) { $sel = null; }
  $r['dkim_selector'] = $sel;
  $r['dkim_ok'] = false;
  if ($sel) {
    $name = $sel . '._domainkey.' . $domain;
    $res = @dns_get_record($name, DNS_TXT);
    if (is_array($res)) {
      foreach ($res as $x) {
        // 长 TXT 会被 DNS 拆成多段（255 字节一段）。dns_get_record 在部分平台
        // 给出 entries 数组而非拼好的 txt，不拼回去会让 p= 的值被截断。
        $t = isset($x['txt']) ? $x['txt'] : '';
        if (isset($x['entries']) && is_array($x['entries'])) { $t = implode('', $x['entries']); }
        if (stripos($t, 'v=DKIM1') === false) { continue; }
        // 关键：撤销后的公钥是 "v=DKIM1; p="（p 为空）。只要出现 p= 就算通过的话，
        // 已撤销的 key 会被判成「已发布」——必须取出 p 的值并确认非空。
        if (preg_match('/\bp\s*=\s*([A-Za-z0-9+\/\s=]+)/i', str_replace('"', '', $t), $m)
            && trim($m[1]) !== '') {
          $r['dkim_ok'] = true;
          break;
        }
      }
    }
  }

  // DMARC
  $dmarc = null;
  $res = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
  if (is_array($res)) {
    foreach ($res as $x) {
      $t = isset($x['txt']) ? $x['txt'] : '';
      if (stripos($t, 'v=DMARC1') === 0) { $dmarc = $t; break; }
    }
  }
  $r['dmarc'] = $dmarc;
  $r['dmarc_ok'] = $dmarc !== null;

  if ($redis) {
    try { $redis->setex('OV_CHECK/' . $domain, 3600, json_encode($r)); } catch (Exception $e) {}
  }
  return $r;
}

try {
  switch ($action) {

    // ================= 总览（只读，纯 DB + 缓存，不含 DNS 实时查询） =================
    case 'overview': {
      global $redis;
      $ov = overview_build($pdo, $redis, true);
      $ov['audit'] = audit_recent($pdo, 30);
      $ov['stats']['audit_count'] = audit_count($pdo);
      ov_json($ov);
    }

    // ================= 体检单个域 =================
    case 'check_domain': {
      global $redis;
      $d = strtolower(ov_post('domain'));
      if (!ov_valid_domain($d)) {
        audit_log($pdo, 'domain.check', 'domain', $d, 'fail', 'invalid domain');
        ov_json(array('ok' => false, 'message' => 'invalid domain'));
      }
      try {
        $r = ov_run_check($pdo, $redis, $d);
        $errs = array();
        if (!$r['mx_ok'])    $errs[] = 'MX 未指向本机';
        if (!$r['spf_ok'])   $errs[] = 'SPF 缺失、未授权本机或缺少 all 机制';
        if (!$r['dkim_ok'])  $errs[] = 'DKIM 公钥未发布';
        if (!$r['dmarc_ok']) $errs[] = 'DMARC 缺失';
        audit_log($pdo, 'domain.check', 'domain', $d, empty($errs) ? 'ok' : 'fail',
                  empty($errs) ? 'all pass' : implode('; ', $errs));
        ov_json(array('ok' => true, 'result' => $r));
      } catch (Exception $e) {
        ov_fatal($pdo, 'domain.check', $d, $e);
      }
    }

    // ================= 体检全部 =================
    case 'check_all': {
      global $redis;
      // POST 与 CSRF 已在入口统一校验，这里只做「开销冷却」。
      //
      // check_all 会对全部活跃域做**实时** DNS（每域 4 次）并刷新 RDAP
      // （单域 curl 超时 12s），是本站点最重的动作。没有冷却时重复点击
      // （或同站点伪造请求）可以反复触发，把 php-fpm worker 占满。
      // 用一个 20 秒的 redis 锁把重复触发折叠掉。
      if ($redis) {
        try {
          if (!$redis->setnx('OV_CHECKALL_LOCK', '1')) {
            $ttl = (int)$redis->ttl('OV_CHECKALL_LOCK');
            ov_json(array('ok' => false,
                          'message' => '体检刚刚执行过，请 ' . max(1, $ttl) . ' 秒后再试'));
          }
          $redis->expire('OV_CHECKALL_LOCK', 20);
        } catch (Exception $e) {
          // redis 异常不阻断体检本身
        }
      }
      $rows = $pdo->query("SELECT `domain` FROM `domain` WHERE `active`='1' ORDER BY `domain` ASC")->fetchAll(PDO::FETCH_ASSOC);
      $n = 0; $bad = 0; $errs = array();
      foreach ($rows as $x) {
        try {
          // 体检时顺便刷新域龄（写缓存 24h），之后页面首屏就能直接读缓存
          ov_domain_age_days($redis, $x['domain'], false);
          $r = ov_run_check($pdo, $redis, $x['domain']);
          $n++;
          if (!$r['mx_ok'] || !$r['spf_ok'] || !$r['dkim_ok'] || !$r['dmarc_ok']) $bad++;
        } catch (Exception $e) {
          // 不静默吞掉：逐域记录原因，避免"一个都没跑成"却被报成 ok
          $errs[] = $x['domain'] . ': ' . $e->getMessage();
        }
      }
      $total = count($rows);
      if ($n === 0 && $total > 0) {
        $msg = '全部域名体检失败：' . implode('; ', array_slice($errs, 0, 3));
        audit_log($pdo, 'domain.check_all', 'domain', '', 'fail', mb_substr($msg, 0, 250));
        ov_json(array('ok' => false, 'message' => $msg, 'checked' => 0, 'total' => $total));
      }
      audit_log($pdo, 'domain.check_all', 'domain', '', empty($errs) ? 'ok' : 'fail',
                'checked=' . $n . '/' . $total . ' abnormal=' . $bad
                . ($errs ? ('; errors=' . count($errs)) : ''));
      ov_json(array('ok' => true, 'checked' => $n, 'total' => $total,
                    'abnormal' => $bad, 'errors' => $errs));
    }

    // ================= 新增域名 =================
    case 'add_domain': {
      $d = strtolower(ov_post('domain'));
      if (!ov_valid_domain($d)) {
        audit_log($pdo, 'domain.add', 'domain', $d, 'fail', 'invalid domain');
        ov_json(array('ok' => false, 'message' => 'invalid domain'));
      }
      $exists = $pdo->prepare("SELECT COUNT(*) FROM `domain` WHERE `domain` = :d");
      $exists->execute(array(':d' => $d));
      if ((int)$exists->fetchColumn() > 0) {
        audit_log($pdo, 'domain.add', 'domain', $d, 'fail', 'already exists');
        ov_json(array('ok' => false, 'message' => '域名已存在'));
      }
      try {
        // mailcow 的 mailbox('add','domain') 有一串硬校验，必须同时满足：
        //   defquota > 0、maxquota > 0、defquota <= maxquota、maxquota <= quota
        // 任一条不满足就 return false（如 defquota_empty / mailbox_quota_exceeds_domain_quota）。
        // 而 mailbox() 失败不抛异常、只 return false，所以这里既要有合理默认值，
        // 也必须检查返回值，否则会出现「接口报成功、库里没数据、审计记假 ok」。
        // 默认值对齐本项目已有域的实际配置（见 domain 表）。
        $aliases  = (int)ov_post('aliases', '400');
        $mboxes   = (int)ov_post('mailboxes', '10');
        $defquota = (int)ov_post('defquota', '3072');     // 单邮箱默认配额 MB
        $maxquota = (int)ov_post('maxquota', '10240');    // 单邮箱最大配额 MB
        $dquota   = (int)ov_post('quota', '10240');       // 域总配额 MB

        if ($defquota <= 0) { $defquota = 3072; }
        if ($maxquota <= 0) { $maxquota = 10240; }
        if ($maxquota < $defquota) { $maxquota = $defquota; }
        // 域总配额必须 >= 单邮箱最大配额，否则 mailcow 直接拒绝
        if ($dquota < $maxquota) { $dquota = $maxquota; }

        $desc = ov_post('description');
        list($ok, $msg) = ov_write($pdo, 'domain:' . $d, function () use ($d, $desc, $aliases, $mboxes, $defquota, $maxquota, $dquota) {
          return mailbox('add', 'domain', array(
            'domain'           => $d,
            'description'      => $desc,
            'aliases'          => $aliases,
            'mailboxes'        => $mboxes,
            'defquota'         => $defquota,
            'maxquota'         => $maxquota,
            'quota'            => $dquota,
            'active'           => 1,
            'gal'              => 0,
            'backupmx'         => 0,
            'relay_all_recipients' => 0,
            'relay_unknown_only'   => 0,
            'dkim_selector'    => 'dkim',
            'key_size'         => 2048,
            'template'         => 0,
            'restart_sogo'     => 0,
          ));
        });
        if (!$ok) {
          audit_log($pdo, 'domain.add', 'domain', $d, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '添加失败：' . $msg));
        }
        audit_log($pdo, 'domain.add', 'domain', $d, 'ok',
                  'created; defquota=' . $defquota . ' maxquota=' . $maxquota);
        ov_json(array('ok' => true, 'result' => true));
      } catch (Exception $e) {
        ov_fatal($pdo, 'domain.add', $d, $e);
      } catch (Error $e) {
        ov_fatal($pdo, 'domain.add', $d, $e);
      }
    }

    // ================= 新增邮箱 =================
    case 'add_mailbox': {
      $user = strtolower(ov_post('username'));
      // 密码**不做 trim**：ov_post() 会 trim，而 " Abc12345 " 这种带首尾空格的密码
      // 会被静默改写，管理员按自己记录的值登录会失败，且界面与审计都看不出差异。
      $pw   = isset($_POST['password']) ? (string)$_POST['password'] : '';
      if (!ov_valid_email($user)) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', 'invalid address');
        ov_json(array('ok' => false, 'message' => '邮箱地址不合法'));
      }
      $parts = explode('@', $user);
      $dom = $parts[1];
      $dchk = $pdo->prepare("SELECT COUNT(*) FROM `domain` WHERE `domain` = :d");
      $dchk->execute(array(':d' => $dom));
      if ((int)$dchk->fetchColumn() === 0) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', 'domain not in mailcow');
        ov_json(array('ok' => false, 'message' => '该域名不在 mailcow 中，请先添加域名'));
      }
      if (strlen($pw) < 8) {
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', 'password too short');
        ov_json(array('ok' => false, 'message' => '密码至少 8 位'));
      }
      try {
        $name  = ov_post('name');
        $quota = (int)ov_post('quota', '0');
        list($ok, $msg) = ov_write($pdo, 'mailbox:' . $user, function () use ($parts, $dom, $name, $quota, $pw) {
          return mailbox('add', 'mailbox', array(
            'local_part'      => $parts[0],
            'domain'          => $dom,
            'name'            => $name,
            'quota'           => $quota,
            'password'        => $pw,
            'password2'       => $pw,
            'active'          => 1,
            'force_pw_update' => 0,
            'tls_enforce_in'  => 0,
            'tls_enforce_out' => 0,
          ));
        });
        if (!$ok) {
          audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '添加失败：' . $msg));
        }
        // 审计里绝不记录密码
        audit_log($pdo, 'mailbox.add', 'mailbox', $user, 'ok', 'created; quota=' . $quota);
        ov_json(array('ok' => true, 'result' => true));
      } catch (Exception $e) {
        ov_fatal($pdo, 'mailbox.add', $user, $e);
      } catch (Error $e) {
        ov_fatal($pdo, 'mailbox.add', $user, $e);
      }
    }

    // ================= 重置邮箱密码 =================
    case 'reset_mailbox_password': {
      $user = strtolower(ov_post('username'));
      $pw   = isset($_POST['password']) ? (string)$_POST['password'] : '';   // 同上：密码不 trim
      if (!ov_valid_email($user)) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', 'invalid address');
        ov_json(array('ok' => false, 'message' => '邮箱地址不合法'));
      }
      // 未提供密码则现场生成 16 位随机密码（只在响应里返回一次）
      $generated = false;
      if ($pw === '') {
        $pw = bin2hex(random_bytes(8));
        $generated = true;
      }
      if (strlen($pw) < 8) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', 'password too short');
        ov_json(array('ok' => false, 'message' => '密码至少 8 位'));
      }
      $chk = $pdo->prepare("SELECT COUNT(*) FROM `mailbox` WHERE `username` = :u");
      $chk->execute(array(':u' => $user));
      if ((int)$chk->fetchColumn() === 0) {
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', 'no such mailbox');
        ov_json(array('ok' => false, 'message' => '邮箱不存在'));
      }
      try {
        // 注意：**不要**在这里传 active=1 —— 那会把已停用的邮箱静默重新启用，
        // 而按钮的语义只是「重置密码」。是否启用应由管理员显式决定。
        list($ok, $msg) = ov_write($pdo, 'mailbox:' . $user, function () use ($user, $pw) {
          return mailbox('edit', 'mailbox', array(
            'username' => $user,
            'password' => $pw,
            'password2'=> $pw,
          ));
        });
        if (!$ok) {
          audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '重置失败：' . $msg));
        }
        // 审计只记"改了"，绝不记密码本身
        audit_log($pdo, 'mailbox.reset_pw', 'mailbox', $user, 'ok',
                  $generated ? 'generated random password' : 'set to provided password');
        ov_json(array('ok' => true, 'generated' => $generated, 'password' => $pw));
      } catch (Exception $e) {
        ov_fatal($pdo, 'mailbox.reset_pw', $user, $e);
      } catch (Error $e) {
        ov_fatal($pdo, 'mailbox.reset_pw', $user, $e);
      }
    }

    // ================= 启用/停用邮箱 =================
    case 'toggle_mailbox': {
      $user = strtolower(ov_post('username'));
      if (!ov_valid_email($user)) {
        // 与其他写动作保持一致：非法参数同样留痕，否则「反复提交非法地址」
        // 在这套审计里是完全隐形的
        audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'fail', 'invalid address');
        ov_json(array('ok' => false, 'message' => '邮箱地址不合法'));
      }
      $st = $pdo->prepare("SELECT `active` FROM `mailbox` WHERE `username` = :u");
      $st->execute(array(':u' => $user));
      $cur = $st->fetchColumn();
      if ($cur === false) {
        audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'fail', 'no such mailbox');
        ov_json(array('ok' => false, 'message' => '邮箱不存在'));
      }
      $new = ((int)$cur === 1) ? 0 : 1;
      try {
        list($ok, $msg) = ov_write($pdo, 'mailbox:' . $user, function () use ($user, $new) {
          return mailbox('edit', 'mailbox', array('username' => $user, 'active' => $new));
        });
        if (!$ok) {
          audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'fail', $msg);
          ov_json(array('ok' => false, 'message' => '操作失败：' . $msg));
        }
        audit_log($pdo, 'mailbox.toggle', 'mailbox', $user, 'ok', 'active=' . $new);
        ov_json(array('ok' => true, 'active' => $new));
      } catch (Exception $e) {
        ov_fatal($pdo, 'mailbox.toggle', $user, $e);
      }
    }

    // ================= 读取明文密钥（必须 POST + 落审计） =================
    case 'reveal_secret': {
      // 管理员本就能在 mailcow 的中继页看到中继密码，所以「能看」不是新暴露；
      // 但中继 Key 一旦泄露可以对外发信，因此要求显式点击 + 每次落审计，
      // 让「谁、什么时候、看过哪把密钥」有据可查。
      // POST 与 CSRF 已在入口统一校验。
      $kind = strtolower(ov_post('kind'));
      $out = '';
      $target = '';
      if ($kind === 'resend') {
        $id = (int)ov_post('id', '0');
        if ($id <= 0) { ov_json(array('ok' => false, 'message' => '参数错误')); }
        $st = $pdo->prepare("SELECT `api_key`,`label` FROM `resend_accounts` WHERE `id` = :i LIMIT 1");
        $st->execute(array(':i' => $id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $target = 'resend_account#' . $id;
        if ($row) { $out = (string)$row['api_key']; $target .= ' ' . $row['label']; }
      } elseif ($kind === 'cf') {
        $st = $pdo->prepare("SELECT `svalue` FROM `resend_pool_settings` WHERE `skey` = 'cf_api_token' LIMIT 1");
        $st->execute();
        $out = (string)$st->fetchColumn();
        $target = 'cloudflare_api_token';
      } else {
        ov_json(array('ok' => false, 'message' => 'unknown kind'));
      }
      if ($out === '') {
        audit_log($pdo, 'secret.reveal', 'secret', $target, 'fail', 'not configured');
        ov_json(array('ok' => false, 'message' => '未配置或不存在'));
      }
      // 审计只记「看过了」，**绝不记密钥内容**
      audit_log($pdo, 'secret.reveal', 'secret', $target, 'ok',
                'plaintext disclosed to admin (content not logged)');
      ov_json(array('ok' => true, 'value' => $out));
    }

    default:
      http_response_code(400);
      ov_json(array('ok' => false, 'message' => 'unknown action'));
  }
}
catch (Exception $e) {
  $trace = bin2hex(random_bytes(4));
  error_log('overview.php error [' . $trace . ']: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(array('ok' => false, 'message' => 'internal error', 'trace' => $trace), JSON_UNESCAPED_UNICODE);
  exit;
}
