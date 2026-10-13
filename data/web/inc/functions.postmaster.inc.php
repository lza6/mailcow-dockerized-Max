<?php
/**
 * mailcow-dockerized-Max — Google Postmaster Tools 接线层（I/O）
 *
 * 职责边界：
 *   · 本文件只做**取数、写库、发请求**；
 *   · 所有判定逻辑（DNS 记录怎么构造、垃圾率算不算高、错误怎么解释）
 *     都在 `postmaster_lib.inc.php` 里，且有离线测试守着。
 *
 * 为什么要接这个：
 *   「邮件会不会进 Gmail 垃圾箱」在别处**只能靠猜**（看 SPF/DKIM 全 pass 却仍进垃圾箱，
 *   本项目实测过）。Postmaster Tools 是 Google 官方**唯一**公开域/IP 信誉与
 *   用户投诉率的渠道。这个模块把它的接入自动化：
 *     取验证 token → 写 DNS（复用 Cloudflare）→ 提交验证 → 按天拉健康数据。
 *
 * 两条路径，刻意都留着：
 *   A. **免授权**（立即可用）：用户在 postmaster.google.com 复制 Google 给的记录值，
 *      粘进来 → 我们写 DNS。不需要任何 OAuth 配置。
 *   B. **OAuth 全自动**（需用户先建 OAuth 客户端）：自动取 token、自动写 DNS、
 *      自动提交验证、自动拉健康数据。
 *   A 之所以不删：OAuth 那步必须用户本人在 Google 侧操作，A 让功能在用户
 *   授权之前就能把最容易出错的一环（DNS 记录格式）做对。
 *
 * 安全约束：
 *   · Client Secret 与 refresh_token 一律 `sb_encrypt()` 后入库，明文不出内存；
 *   · 任何密钥都不进日志、不进审计明细、不回前端；
 *   · OAuth 用 `state` 防重放（等价于这条链路上的 CSRF）；
 *   · 外部输入（域名）走既有的白名单式校验。
 */

require_once __DIR__ . '/postmaster_lib.inc.php';
// 复用 Cloudflare 写入与项目通用 KV 设置；CLI 下需要先补 DOCUMENT_ROOT
if (empty($_SERVER['DOCUMENT_ROOT'])) { $_SERVER['DOCUMENT_ROOT'] = '/web'; }
require_once __DIR__ . '/functions.resend_pool.inc.php';

if (!defined('PM_API_BASE'))    { define('PM_API_BASE', 'https://gmailpostmastertools.googleapis.com'); }
if (!defined('PM_SV_BASE'))     { define('PM_SV_BASE', 'https://www.googleapis.com/siteVerification/v1'); }
if (!defined('PM_OAUTH_AUTH'))  { define('PM_OAUTH_AUTH', 'https://accounts.google.com/o/oauth2/v2/auth'); }
if (!defined('PM_OAUTH_TOKEN')) { define('PM_OAUTH_TOKEN', 'https://oauth2.googleapis.com/token'); }
// 读信誉要 postmaster.readonly；取 token/提交验证要 siteverification。
// 两个一起要，用户只需授权一次。
if (!defined('PM_SCOPE')) {
  define('PM_SCOPE', 'https://www.googleapis.com/auth/postmaster.readonly '
                   . 'https://www.googleapis.com/auth/siteverification');
}
if (!defined('PM_ACCESS_TTL'))  { define('PM_ACCESS_TTL', 3000); }   // access_token 实际 3600s，留余量

/* ================================================================== *
 * 设置读写
 *
 * 直接复用 `resend_pool_settings` —— 这张 KV 表在本项目里事实上就是
 * **通用设置表**（没有第二张）。刻意不再建一张同构的表：两份 KV 更容易漂移。
 * 代价是命名上有点别扭，因此这里包一层 pm_ 前缀的函数，调用方不必知道底层。
 * ================================================================== */

function pm_setting_get($pdo, $key) {
  return resend_pool_setting_get($pdo, $key);
}

function pm_setting_set($pdo, $key, $value) {
  return resend_pool_setting_set($pdo, $key, $value);
}

/* ================================================================== *
 * OAuth
 * ================================================================== */

/** 是否已配置 OAuth 客户端（两步授权的前提）。 */
function pm_oauth_configured($pdo) {
  $id = (string)pm_setting_get($pdo, 'pm_oauth_client_id');
  $sc = (string)pm_setting_get($pdo, 'pm_oauth_client_secret_enc');
  return ($id !== '' && $sc !== '');
}

/** 是否已经拿到 refresh_token（即用户完成过授权）。 */
function pm_oauth_connected($pdo) {
  return (string)pm_setting_get($pdo, 'pm_oauth_refresh_token_enc') !== '';
}

/**
 * 授权码回调地址。
 *
 * **必须与 Google Cloud Console 里登记的完全一致**（协议、域名、路径一字不差），
 * 否则 Google 会直接拒绝并只回一个 `redirect_uri_mismatch`。
 *
 * 主机名取 `MAILCOW_HOSTNAME` 而**不是** `$_SERVER['HTTP_HOST']`：
 * Host 头是请求方可控的，用它拼出来的地址会被伪造，用户可能被引导到一个
 * 攻击者的回调地址上。兜底才用 HTTP_HOST。
 */
function pm_redirect_uri() {
  $host = trim((string)getenv('MAILCOW_HOSTNAME'));
  if ($host === '' && isset($_SERVER['HTTP_HOST'])) { $host = (string)$_SERVER['HTTP_HOST']; }
  $host = preg_replace('#^https?://#i', '', $host);
  $host = rtrim($host, '/');
  return 'https://' . $host . '/inc/ajax/postmaster.php';
}

/** 生成一次性 state（防重放），存进会话由调用方校验。 */
function pm_new_state() {
  return bin2hex(random_bytes(16));
}

/** 组装 Google 授权页地址（用户点「连接」时跳这里）。 */
function pm_auth_url($pdo, $state) {
  $q = array(
    'client_id'     => (string)pm_setting_get($pdo, 'pm_oauth_client_id'),
    'redirect_uri'  => pm_redirect_uri(),
    'response_type' => 'code',
    'scope'         => PM_SCOPE,
    'state'         => $state,
    // 必须带：Google 只在**首次**同意时返回 refresh_token。
    // 少了它，第二次授权拿回来的 body 里没有 refresh_token，
    // 表现成「明明授权成功却一直连不上」，且不报错。
    'access_type'   => 'offline',
    // 又要它、又不带 prompt 的话，重复授权时同样拿不到 refresh_token
    'prompt'        => 'consent',
    'include_granted_scopes' => 'true',
  );
  return PM_OAUTH_AUTH . '?' . http_build_query($q);
}

/** 低层 POST 表单（OAuth 的三个端点都是 form-encoded，不是 JSON）。 */
function pm_oauth_post(array $fields) {
  $ch = curl_init(PM_OAUTH_TOKEN);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($fields),
    CURLOPT_HTTPHEADER     => array('Content-Type: application/x-www-form-urlencoded'),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_USERAGENT      => 'mailcow-postmaster',
  ));
  $raw = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);
  if ($raw === false) { return array('ok' => false, 'http' => 0, 'data' => null, 'error' => $cerr ?: 'curl failed'); }
  $data = json_decode((string)$raw, true);
  if ($http >= 200 && $http < 300 && is_array($data)) {
    return array('ok' => true, 'http' => $http, 'data' => $data, 'error' => '');
  }
  $msg = is_array($data) ? (string)($data['error_description'] ?? $data['error'] ?? ('HTTP ' . $http)) : ('HTTP ' . $http);
  return array('ok' => false, 'http' => $http, 'data' => $data, 'error' => $msg);
}

/**
 * 用授权码换 refresh_token 并入库。
 * @return array array('ok'=>bool,'message'=>string,'email'=>string)
 */
function pm_oauth_exchange($pdo, $code) {
  if (!pm_oauth_configured($pdo)) {
    return array('ok' => false, 'message' => '尚未配置 OAuth 客户端', 'email' => '');
  }
  $secret = sb_decrypt((string)pm_setting_get($pdo, 'pm_oauth_client_secret_enc'));
  if ($secret === '') {
    return array('ok' => false, 'message' => 'Client Secret 解密失败（密钥可能已更换），请重新填写', 'email' => '');
  }
  $r = pm_oauth_post(array(
    'code'          => (string)$code,
    'client_id'     => (string)pm_setting_get($pdo, 'pm_oauth_client_id'),
    'client_secret' => $secret,
    'redirect_uri'  => pm_redirect_uri(),
    'grant_type'    => 'authorization_code',
  ));
  if (!$r['ok']) {
    return array('ok' => false, 'message' => '换取令牌失败：' . $r['error'], 'email' => '');
  }
  $rt = (string)($r['data']['refresh_token'] ?? '');
  if ($rt === '') {
    // 最常见的两种原因：没带 access_type=offline/prompt=consent，或用户没勾选全部权限
    return array('ok' => false,
                 'message' => 'Google 没有返回 refresh_token。请在授权页勾选全部权限后重试；'
                            . '若仍无，到 Google 账号的「第三方访问」里撤销本应用授权再连一次。',
                 'email' => '');
  }
  // ⚠️ 必须检查加密结果。sb_encrypt() 在保管箱不可用（密钥目录不可写/读不到）时
  //    返回**空串**，而 pm_setting_set(..., '') 是"删除该键"的语义 ——
  //    于是凭据根本没存进去，函数却返回 ok=true 让界面显示"已连接"。
  //    用户随后点同步只会得到"尚未连接"，前后矛盾且查不出原因。
  $enc = sb_encrypt($rt);
  if ($enc === '') {
    return array('ok' => false,
                 'message' => '凭据保管箱不可用，授权未能保存：请确认 /warmup-secrets 目录可读可写'
                            . '（这是养号功能存放应用专用密码的同一套保管箱）。',
                 'email' => '');
  }
  pm_setting_set($pdo, 'pm_oauth_refresh_token_enc', $enc);
  pm_setting_set($pdo, 'pm_oauth_connected_at', date('Y-m-d H:i:s'));

  // 顺带记下是哪个 Google 账号授权的 —— 排错时第一个要问的就是这个
  $email = '';
  $at = (string)($r['data']['access_token'] ?? '');
  if ($at !== '') {
    $ui = pm_http_json('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', null, $at);
    if ($ui['ok'] && is_array($ui['data'])) { $email = (string)($ui['data']['email'] ?? ''); }
  }
  pm_setting_set($pdo, 'pm_oauth_email', $email);
  return array('ok' => true, 'message' => '已连接', 'email' => $email);
}

/** 断开：清掉 refresh_token 与账号信息（Client ID/Secret 保留，便于重连）。 */
function pm_oauth_disconnect($pdo) {
  pm_setting_set($pdo, 'pm_oauth_refresh_token_enc', '');
  pm_setting_set($pdo, 'pm_oauth_email', '');
  pm_setting_set($pdo, 'pm_oauth_connected_at', '');
  return true;
}

/**
 * 取一个可用的 access_token。
 *
 * 每次调用都去换是浪费且会撞 Google 的限流，因此缓存进 redis。
 * **只缓存成功结果**：把一次偶发失败缓存 50 分钟，会让用户以为"授权坏了"。
 *
 * @return array array('ok'=>bool,'token'=>string,'error'=>string)
 */
function pm_access_token($pdo, $redis, $force = false) {
  if (!pm_oauth_connected($pdo)) {
    return array('ok' => false, 'token' => '', 'error' => '尚未连接 Google 账号');
  }
  $ck = 'PM_ACCESS_TOKEN';
  if (!$force && $redis) {
    try {
      $c = $redis->get($ck);
      if (is_string($c) && $c !== '') { return array('ok' => true, 'token' => $c, 'error' => ''); }
    } catch (Throwable $e) { /* 缓存不可用不影响主流程 */ }
  }
  $secret = sb_decrypt((string)pm_setting_get($pdo, 'pm_oauth_client_secret_enc'));
  $rt     = sb_decrypt((string)pm_setting_get($pdo, 'pm_oauth_refresh_token_enc'));
  if ($secret === '' || $rt === '') {
    return array('ok' => false, 'token' => '', 'error' => '凭据解密失败，请重新连接 Google 账号');
  }
  $r = pm_oauth_post(array(
    'client_id'     => (string)pm_setting_get($pdo, 'pm_oauth_client_id'),
    'client_secret' => $secret,
    'refresh_token' => $rt,
    'grant_type'    => 'refresh_token',
  ));
  if (!$r['ok']) {
    // invalid_grant = 用户撤销了授权，或 refresh_token 过期。这不是偶发故障，
    // 必须让用户看到，而不是反复重试。
    $e = (string)($r['data']['error'] ?? '');
    if ($e === 'invalid_grant') {
      // 光报错不够：连接态还留在库里，界面会一直显示「已连接」，
      // 而每次同步都必然失败 —— 用户看到的是自相矛盾的两个信号。
      // 如实断开，界面才会变成「未连接」并给出重新连接的入口。
      pm_oauth_disconnect($pdo);
      return array('ok' => false, 'token' => '',
                   'error' => '授权已被撤销或过期（invalid_grant），已自动断开，请重新连接 Google 账号');
    }
    return array('ok' => false, 'token' => '', 'error' => '获取访问令牌失败：' . $r['error']);
  }
  $tok = (string)($r['data']['access_token'] ?? '');
  if ($tok === '') { return array('ok' => false, 'token' => '', 'error' => 'Google 未返回 access_token'); }
  if ($redis) {
    try { $redis->setex($ck, PM_ACCESS_TTL, $tok); } catch (Throwable $e) {}
  }
  return array('ok' => true, 'token' => $tok, 'error' => '');
}

/* ================================================================== *
 * 通用 HTTP（JSON）
 * ================================================================== */

/**
 * 带 Bearer 的 JSON 请求。
 * @return array array('ok'=>bool,'http'=>int,'data'=>mixed,'error'=>string,'explain'=>?array)
 */
function pm_http_json($method, $url, $body, $token) {
  $ch = curl_init($url);
  $headers = array('Authorization: Bearer ' . $token, 'Accept: application/json');
  $opts = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'mailcow-postmaster',
  );
  if ($body !== null) {
    $headers[] = 'Content-Type: application/json';
    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  $opts[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $opts);
  $raw  = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($raw === false) {
    return array('ok' => false, 'http' => 0, 'data' => null,
                 'error' => $cerr ?: 'curl failed', 'explain' => null);
  }
  $data = json_decode((string)$raw, true);
  if ($http >= 200 && $http < 300) {
    return array('ok' => true, 'http' => $http, 'data' => $data, 'error' => '', 'explain' => null);
  }
  $errText = is_array($data)
    ? (string)($data['error']['message'] ?? $data['error_description'] ?? $data['error'] ?? ('HTTP ' . $http))
    : ('HTTP ' . $http);
  // 把 403/404/401 这类"长得一样但原因完全不同"的失败翻译成该去查什么
  return array('ok' => false, 'http' => $http, 'data' => $data, 'error' => $errText,
               'explain' => pm_explain_error($http, $raw));
}

/** 统一的「拿 token 再请求」包装。 */
function pm_api_call($pdo, $redis, $method, $url, $body = null) {
  $t = pm_access_token($pdo, $redis);
  if (!$t['ok']) {
    return array('ok' => false, 'http' => 0, 'data' => null,
                 'error' => $t['error'], 'explain' => null);
  }
  return pm_http_json($method, $url, $body, $t['token']);
}

/* ================================================================== *
 * Site Verification：取 token / 提交验证
 * ================================================================== */

/**
 * 让 Google 为该域签发一条验证 token。
 *
 * ⚠️ 这一步**能由 API 完成** —— 网上常见的说法是"验证码必须登录后手工获取"，
 * 那是 Postmaster **网页版**的流程；Site Verification API 的 getToken
 * 就是专门用来程序化签发的。
 *
 * 注意：token 是**按账号签发**的。换一个 Google 账号授权，同一个域拿到的
 * token 会不同 —— 所以「取过 token」这件事必须和「这是哪个账号」一起看。
 *
 * @return array array('ok'=>bool,'method'=>string,'token'=>string,'error'=>string,'explain'=>?array)
 */
function pm_get_token($pdo, $redis, $domain, $method = 'DNS_TXT') {
  $bad = array('ok' => false, 'method' => '', 'token' => '', 'error' => '', 'explain' => null);
  if (!is_valid_domain_name($domain)) { $bad['error'] = '域名格式无效'; return $bad; }
  $r = pm_api_call($pdo, $redis, 'POST', PM_SV_BASE . '/token', array(
    'site'               => array('type' => 'INET_DOMAIN', 'identifier' => strtolower($domain)),
    'verificationMethod' => $method,
  ));
  if (!$r['ok']) { $bad['error'] = $r['error']; $bad['explain'] = $r['explain']; return $bad; }
  $tok = (string)($r['data']['token'] ?? '');
  if ($tok === '') { $bad['error'] = 'Google 未返回 token'; return $bad; }
  return array('ok' => true, 'method' => (string)($r['data']['method'] ?? $method),
               'token' => $tok, 'error' => '', 'explain' => null);
}

/**
 * 请 Google 检查 DNS 里的验证记录并登记所有权。
 *
 * `http` 必须一并带出去：调用方要据它区分「再等等」与「等也没用」
 * （见 pm_verify_error_is_fatal）。丢掉它就只能靠匹配错误文案，脆弱得多。
 *
 * @return array array('ok'=>bool,'http'=>int,'error'=>string,'explain'=>?array)
 */
function pm_verify_domain($pdo, $redis, $domain, $method) {
  if (!is_valid_domain_name($domain)) { return array('ok' => false, 'http' => 0, 'error' => '域名格式无效', 'explain' => null); }
  $method = strtoupper(trim((string)$method));
  if ($method !== 'DNS_TXT' && $method !== 'DNS_CNAME') {
    return array('ok' => false, 'http' => 0, 'error' => '验证方式必须是 DNS_TXT 或 DNS_CNAME', 'explain' => null);
  }
  $url = PM_SV_BASE . '/webResource?verificationMethod=' . rawurlencode($method);
  $r = pm_api_call($pdo, $redis, 'POST', $url,
                   array('site' => array('type' => 'INET_DOMAIN', 'identifier' => strtolower($domain))));
  if ($r['ok']) { return array('ok' => true, 'http' => (int)$r['http'], 'error' => '', 'explain' => null); }
  return array('ok' => false, 'http' => (int)$r['http'], 'error' => $r['error'], 'explain' => $r['explain']);
}

/* ================================================================== *
 * Postmaster：域列表 / 按天统计
 * ================================================================== */

/** Postmaster 认得的域名单（即"已在该账号下验证过所有权"的域）。 */
function pm_list_registered($pdo, $redis) {
  $out = array();
  $r = pm_api_call($pdo, $redis, 'GET', PM_API_BASE . '/v1/domains');
  if (!$r['ok']) { return array('ok' => false, 'domains' => $out, 'error' => $r['error'], 'explain' => $r['explain']); }
  foreach ((array)($r['data']['domains'] ?? array()) as $d) {
    $name = (string)($d['name'] ?? '');              // 形如 domains/example.com
    $name = preg_replace('#^domains/#', '', $name);
    if ($name !== '') { $out[strtolower($name)] = $d; }
  }
  return array('ok' => true, 'domains' => $out, 'error' => '', 'explain' => null);
}

/**
 * 拉最近 N 天的流量统计。
 *
 * 参数形态是 gRPC transcoding：日期不是 ISO 字符串，而是
 * `startDate.year/month/day` 三个查询参数。startDate 与 endDate
 * **必须成对出现**，只给一个会被判 INVALID_ARGUMENT。
 */
function pm_fetch_stats($pdo, $redis, $domain, $days = 7) {
  if (!is_valid_domain_name($domain)) { return array('ok' => false, 'days' => array(), 'error' => '域名格式无效'); }
  $days = max(1, min(30, (int)$days));
  $end   = time() - 86400;                       // Google 的数据有 1~2 天延迟，从昨天往回取
  $start = $end - ($days - 1) * 86400;
  $q = array(
    'startDate.year'  => (int)date('Y', $start),
    'startDate.month' => (int)date('n', $start),
    'startDate.day'   => (int)date('j', $start),
    'endDate.year'    => (int)date('Y', $end),
    'endDate.month'   => (int)date('n', $end),
    'endDate.day'     => (int)date('j', $end),
    'pageSize'        => 100,
  );
  $url = PM_API_BASE . '/v1/domains/' . rawurlencode(strtolower($domain)) . '/trafficStats?' . http_build_query($q);
  $r = pm_api_call($pdo, $redis, 'GET', $url);
  if (!$r['ok']) { return array('ok' => false, 'days' => array(), 'error' => $r['error'], 'explain' => $r['explain']); }
  return array('ok' => true, 'days' => (array)($r['data']['trafficStats'] ?? array()),
               'error' => '', 'explain' => null);
}

/* ================================================================== *
 * DNS 写入（复用 Cloudflare 通道）
 * ================================================================== */

/**
 * 把我们构造好的记录写进 Cloudflare。
 *
 * 刻意复用 `resend_pool_write_dns()` 而不是自己再写一套 CF 调用：
 * 它已经处理了 zone 逐级查找、先 GET 再 PATCH（绕开 CF 的 duplicate-record 400）、
 * `proxied=false`。重复实现只会让两处慢慢漂移。
 *
 * ⚠️ 它的入参 `name` 是**相对标签**（空串/`@` 表示顶点），
 * 不是完整记录名 —— 正是 pm_dns_record() 返回的 `name` 字段。
 *
 * @return array array('ok'=>bool,'message'=>string)
 */
function pm_write_dns($pdo, $redis, $domain, array $rec) {
  if (empty($rec['ok'])) { return array('ok' => false, 'message' => (string)($rec['error'] ?? '记录无效')); }
  $res = resend_pool_write_dns($domain, array(array(
    'name'  => (string)$rec['name'],     // 相对标签
    'type'  => (string)$rec['type'],
    'value' => (string)$rec['content'],
  )));
  $written = (int)($res['written'] ?? 0);
  if ($written > 0) { return array('ok' => true, 'message' => 'DNS 记录已写入 Cloudflare'); }
  // skipped 不是错误，是"没做"（未配 CF Token / 域名不在 CF 托管）。
  // 必须把它和真正的失败区分开告诉用户，否则他会以为写进去了。
  if (!empty($res['skipped'])) {
    return array('ok' => false, 'message' => '未自动写入：' . (string)($res['reason'] ?? '未知原因')
                . '。请手工按界面显示的记录去 DNS 服务商处添加。');
  }
  return array('ok' => false, 'message' => '写入 Cloudflare 失败');
}

/* ================================================================== *
 * 本地状态
 * ================================================================== */

/** 取一行（不存在返回 null）。 */
function pm_row($pdo, $domain) {
  $st = $pdo->prepare("SELECT * FROM `postmaster_domains` WHERE `domain` = :d LIMIT 1");
  $st->execute(array(':d' => strtolower(trim((string)$domain))));
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ? $r : null;
}

/**
 * 没有就建一行，返回 id。
 *
 * 用 `INSERT ... ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)` 而不是
 * 「先 SELECT 再 INSERT」：后者在多标签页/连点/并发下会各自读到 0 行，
 * 于是同一个域被插成两条（domain 上有唯一键会拦下重复，但先查后插的两个请求
 * 会有一个撞唯一键报错，用户体验成"偶发失败"）。这条语句是原子的。
 */
function pm_ensure_row($pdo, $domain) {
  $domain = strtolower(trim((string)$domain));
  $st = $pdo->prepare("INSERT INTO `postmaster_domains` (`domain`) VALUES (:d)
                       ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)");
  $st->execute(array(':d' => $domain));
  $id = (int)$pdo->lastInsertId();
  if ($id > 0) { return $id; }
  $row = pm_row($pdo, $domain);
  return $row ? (int)$row['id'] : 0;
}

/**
 * 保存一条验证记录（免授权路径与 OAuth 路径共用）。
 *
 * @param string $token Google 给的 token 原文
 * @return array array('ok'=>bool,'message'=>string,'record'=>?array)
 */
function pm_save_record($pdo, $redis, $domain, $method, $token) {
  $domain = strtolower(trim((string)$domain));
  if (!is_valid_domain_name($domain)) { return array('ok' => false, 'message' => '域名格式无效', 'record' => null); }

  $rec = pm_dns_record($method, $token, $domain);
  if (!$rec['ok']) { return array('ok' => false, 'message' => $rec['error'], 'record' => null); }

  pm_ensure_row($pdo, $domain);
  $st = $pdo->prepare("UPDATE `postmaster_domains`
                          SET `verify_method` = :m, `verify_token` = :t,
                              `dns_name` = :n, `dns_type` = :ty, `dns_content` = :c,
                              `verify_status` = 'pending', `last_error` = ''
                        WHERE `domain` = :d");
  $st->execute(array(':m' => strtoupper($method), ':t' => (string)$token,
                     ':n' => (string)$rec['name'], ':ty' => (string)$rec['type'],
                     ':c' => (string)$rec['content'], ':d' => $domain));

  $w = pm_write_dns($pdo, $redis, $domain, $rec);
  if ($w['ok']) {
    $st = $pdo->prepare("UPDATE `postmaster_domains`
                            SET `verify_status` = 'dns_written', `dns_written_at` = NOW(0)
                          WHERE `domain` = :d");
    $st->execute(array(':d' => $domain));
  } else {
    // 没能自动写入不是失败 —— 记录已经算好并落库，用户照着界面手工加即可。
    // 状态留在 pending，错误里带上原因，界面会同时给出可复制的记录。
    $st = $pdo->prepare("UPDATE `postmaster_domains` SET `last_error` = :e WHERE `domain` = :d");
    $st->execute(array(':e' => mb_substr($w['message'], 0, 255), ':d' => $domain));
  }
  return array('ok' => true, 'message' => $w['ok'] ? '记录已写入并保存' : $w['message'], 'record' => $rec);
}

/**
 * 域列表（含把「本机 mailcow 里启用的域」与「本地 Postmaster 状态」合并后的视图）。
 * 界面上要能看出"哪些域还没接 Postmaster"。
 */
function pm_domain_list($pdo) {
  $rows = array();
  foreach ($pdo->query("SELECT * FROM `postmaster_domains` ORDER BY `domain` ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[strtolower($r['domain'])] = $r;
  }
  // 以 mailcow 的启用域为主表：没接 Postmaster 的也要列出来（否则用户不知道漏了哪个）
  $out = array();
  foreach ($pdo->query("SELECT `domain` FROM `domain` WHERE `active`='1' ORDER BY `domain` ASC")->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $dm = strtolower((string)$d['domain']);
    $out[] = pm_view_row($dm, isset($rows[$dm]) ? $rows[$dm] : null);
  }
  // 库里存在但 mailcow 里已删的域也保留（否则历史数据凭空消失）
  foreach ($rows as $dm => $r) {
    $found = false;
    foreach ($out as $o) { if ($o['domain'] === $dm) { $found = true; break; } }
    if (!$found) { $out[] = pm_view_row($dm, $r); }
  }
  return $out;
}

/** 把一行原始数据整理成前端要的形状。 */
function pm_view_row($domain, $r) {
  $health = null;
  if ($r && !empty($r['health_json'])) {
    $h = json_decode((string)$r['health_json'], true);
    if (is_array($h)) { $health = $h; }
  }
  return array(
    'domain'        => $domain,
    'verify_method' => $r ? (string)$r['verify_method'] : '',
    'verify_token'  => $r ? (string)$r['verify_token'] : '',
    'dns_name'      => $r ? (string)$r['dns_name'] : '',
    'dns_type'      => $r ? (string)$r['dns_type'] : '',
    'dns_content'   => $r ? (string)$r['dns_content'] : '',
    'dns_fqdn'      => $r ? (($r['dns_name'] === '') ? $domain : $r['dns_name'] . '.' . $domain) : '',
    'verify_status' => $r ? (string)$r['verify_status'] : 'unknown',
    'badge'         => pm_verify_badge($r ? $r['verify_status'] : ''),
    'registered'    => $r ? (int)$r['registered'] : 0,
    'verified_at'   => $r ? (string)($r['verified_at'] ?? '') : '',
    'dns_written_at'=> $r ? (string)($r['dns_written_at'] ?? '') : '',
    'health_synced_at' => $r ? (string)($r['health_synced_at'] ?? '') : '',
    'last_error'    => $r ? (string)$r['last_error'] : '',
    'health'        => $health,
  );
}

/** UI 首屏要的全部状态（一次调用拿完，避免前端串行打多个请求）。 */
function pm_overview_state($pdo, $redis) {
  $state = array(
    'oauth_configured' => pm_oauth_configured($pdo),
    'oauth_connected'  => pm_oauth_connected($pdo),
    'oauth_email'      => (string)pm_setting_get($pdo, 'pm_oauth_email'),
    'redirect_uri'     => pm_redirect_uri(),
    // CF 未配置时界面要直接说清楚"写 DNS 这一步得你手工做"，而不是让按钮静默失败
    'cf_ready'         => (resend_pool_cf_token($pdo, $redis) !== ''),
    'domains'          => pm_domain_list($pdo),
    'stats'            => array('total' => 0, 'verified' => 0, 'pending' => 0, 'health' => 0),
  );
  foreach ($state['domains'] as $d) {
    $state['stats']['total']++;
    if ($d['verify_status'] === 'verified') { $state['stats']['verified']++; }
    elseif ($d['verify_status'] !== 'unknown') { $state['stats']['pending']++; }
    if (!empty($d['health'])) { $state['stats']['health']++; }
  }
  return $state;
}

/** 记录一次失败，供界面显示"上次为什么没成"。 */
function pm_note_error($pdo, $domain, $msg) {
  $st = $pdo->prepare("UPDATE `postmaster_domains` SET `last_error` = :e WHERE `domain` = :d");
  $st->execute(array(':e' => mb_substr((string)$msg, 0, 255), ':d' => strtolower($domain)));
}

/* ================================================================== *
 * 批量同步（定时任务 / 界面上的「全部同步」）
 * ================================================================== */

/**
 * 一轮同步。做三件事，**每件都可独立失败**：
 *
 *   1. 拉一次 Postmaster 域列表，更新每行的 `registered`（该域是否已被 Google 认得）；
 *      —— 顺序上必须先做：它是判断"还要不要继续折腾验证"的依据。
 *   2. 对已在列表里的域拉按天统计，汇总后落 `health_json`；
 *   3. 对本地标记为 `dns_written` 的域，主动请 Google 复核一次
 *      （用户加完记录通常不会自己回去点「验证」）。
 *
 * 没连 OAuth 时直接返回 `connected=false`，**不当作错误** ——
 * 免授权路径（用户手工粘 token + 我们写 DNS）本来就不需要它。
 *
 * @return array array('connected'=>bool,'registered'=>int,'synced'=>int,'verified'=>int,
 *                     'failed'=>int,'errors'=>array(),'message'=>string)
 */
function pm_sync_all($pdo, $redis, $days = 7) {
  $out = array('connected' => false, 'registered' => 0, 'synced' => 0,
               'verified' => 0, 'failed' => 0, 'errors' => array(), 'message' => '');

  if (!pm_oauth_connected($pdo)) {
    $out['message'] = '尚未连接 Google 账号，跳过云端同步（不影响手工验证流程）';
    return $out;
  }
  $out['connected'] = true;

  // ---- 1) 域列表 ----
  $reg = array();
  $lst = pm_list_registered($pdo, $redis);
  if (!$lst['ok']) {
    $out['errors'][] = '获取 Postmaster 域列表失败：' . $lst['error'];
    // 列表拿不到就不要继续了：后面每一步都要用它判断权限，继续跑只会刷出一堆同样的错
    return $out;
  }
  $reg = $lst['domains'];
  $out['registered'] = count($reg);

  // ---- 2) 逐域拉统计 ----
  //
  // ⚠️ 必须用 pm_ensure_row() 先保证本地有行，**不能只靠 UPDATE**。
  //    某域可能已在 postmaster.google.com（或 Search Console）验证过、
  //    因而出现在 `GET /v1/domains` 列表里，但管理员从没在本界面点过任何按钮
  //    → 本地 `postmaster_domains` 里根本没有这一行。
  //    此时 UPDATE 影响 0 行、不报错，`synced++` 照常执行，
  //    于是界面显示"同步成功 N 个"，数据却一条都没落库、行也永远显示"未开始"。
  //    ——正是本模块注释里反复要避免的"看起来做完了其实没做"。
  foreach (pm_domain_list($pdo) as $d) {
    $domain = $d['domain'];
    $isReg  = isset($reg[$domain]);
    try { pm_ensure_row($pdo, $domain); } catch (Throwable $e) { /* 已存在即忽略 */ }
    try {
      $st = $pdo->prepare("UPDATE `postmaster_domains` SET `registered` = :r WHERE `domain` = :d");
      $st->execute(array(':r' => $isReg ? 1 : 0, ':d' => $domain));
    } catch (Throwable $e) { /* 行不存在时忽略 */ }

    if (!$isReg) { continue; }

    $s = pm_fetch_stats($pdo, $redis, $domain, $days);
    if (!$s['ok']) {
      $msg = $s['error'];
      if (!empty($s['explain'])) { $msg = $s['explain']['cause'] . '：' . $s['explain']['fix']; }
      $out['errors'][] = $domain . '：' . $msg;
      pm_note_error($pdo, $domain, $msg);
      continue;
    }
    $sum = pm_summarize_stats($s['days']);
    try {
      $st = $pdo->prepare("UPDATE `postmaster_domains`
                        SET `health_json` = :h, `health_synced_at` = NOW(0),
                            `verify_status` = 'verified', `last_error` = ''
                      WHERE `domain` = :d");
      $st->execute(array(':h' => json_encode($sum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                         ':d' => $domain));
      // 只有真的写进去了才算同步成功。行数 0 说明行又没了（并发删除），
      // 报成错误比报成成功诚实。
      if ($st->rowCount() > 0) {
        $out['synced']++;
      } else {
        $out['errors'][] = $domain . '：健康数据未能写入（本地行已不存在）';
      }
    } catch (Throwable $e) {
      $out['errors'][] = $domain . '：写入健康数据失败';
    }
  }

  // ---- 3) 复核待验证的域 ----
  // 只处理 dns_written：记录还没写进去的（pending）去复核必然是失败，
  // 白白消耗 Google 的配额并刷一堆错误。
  foreach ($pdo->query("SELECT `domain`, `verify_method` FROM `postmaster_domains`
                         WHERE `verify_status` = 'dns_written' ORDER BY `id` ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $v = pm_verify_domain($pdo, $redis, $row['domain'], $row['verify_method']);
    if ($v['ok']) {
      $pdo->prepare("UPDATE `postmaster_domains`
                        SET `verify_status` = 'verified', `verified_at` = NOW(0), `last_error` = ''
                      WHERE `domain` = :d")->execute(array(':d' => $row['domain']));
      $out['verified']++;
    } else {
      // 复核失败有三种完全不同的原因，**不能一律当成"再等等"**：
      //   (a) 域名尚未在 Cloud Console 注册 / 无权：重试一万次也不会好；
      //   (b) 该域已被**别的账号**验证过：Google 会拒绝把所有权交给本账号；
      //   (c) 记录还没生效（DNS 传播慢、或压根没加）：重试是对的。
      // 只有 (c) 值得挂着等。前两种必须落到 failed 并带上原因，
      // 否则界面永远停在「DNS 已写入，待 Google 确认」，
      // 用户照着提示反复点「验证」却不知道问题在别处 —— 本模块最要避免的形态。
      $vErr = (string)$v['error'];
      $fatal = pm_verify_error_is_fatal((int)($v['http'] ?? 0), $vErr);
      $msg = $vErr;
      if (!empty($v['explain'])) { $msg = $v['explain']['cause'] . '：' . $v['explain']['fix']; }
      if ($fatal) {
        $pdo->prepare("UPDATE `postmaster_domains`
                          SET `verify_status` = 'failed', `last_error` = :e
                        WHERE `domain` = :d")->execute(
          array(':e' => mb_substr($msg, 0, 255), ':d' => $row['domain']));
        $out['errors'][] = $row['domain'] . '：' . $msg;
        $out['failed'] = (int)$out['failed'] + 1;
      } else {
        // DNS 还没传播 / 记录写错，都会落到这里。**不置 failed** ——
        // DNS 传播本身就是慢的，标成失败会让界面误报，用户会去删记录重来。
        pm_note_error($pdo, $row['domain'], $msg);
      }
    }
  }

  return $out;
}
