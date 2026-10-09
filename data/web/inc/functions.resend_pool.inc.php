<?php
/**
 * mailcow-dockerized-Max — Resend 号池
 *
 * 目的：Resend 免费版单账号限 3 个域名。通过多账号组成「号池」，
 *       突破单账号限制，并让不同域名分散到不同的发起池。
 *
 * 设计要点：
 *   1. 号池中的每个账号对应一条 relayhosts 记录（hostname=smtp.resend.com:587，
 *      username=resend，password=API Key），从而复用 mailcow 原生的中继机制，
 *      无需改动 postfix 配置即可让发信真正走对应账号。
 *   2. resend_domains 记录「域名 → 账号」的映射，并缓存 Resend 侧返回的
 *      DNS 记录与验证状态。
 *   3. 自动分配：按 active 账号顺序，每个账号最多承载 RESEND_DOMAIN_LIMIT 个域名。
 *
 * 约束：
 *   - 只允许管理员调用（调用方需自行校验 mailcow_cc_role）
 *   - API Key 只用于调用 Resend 官方 API，不做任何转发
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/inc/prerequisites.inc.php';

// Resend 免费版单账号域名上限（付费后可由管理员在界面上覆盖）
define('RESEND_DOMAIN_LIMIT_DEFAULT', 3);
// Resend API 基地址
define('RESEND_API_BASE', 'https://api.resend.com');
// 号池使用的固定中继主机（Resend SMTP）
define('RESEND_RELAY_HOST', 'smtp.resend.com:587');

/**
 * 读取号池配置的域名上限（存于 redis，可由管理员调整）
 */
function resend_pool_domain_limit() {
  global $redis;
  $v = $redis->get('RESEND_POOL_DOMAIN_LIMIT');
  $n = $v !== false && $v !== null ? (int)$v : 0;
  return $n > 0 ? $n : RESEND_DOMAIN_LIMIT_DEFAULT;
}

function resend_pool_set_domain_limit($n) {
  global $redis;
  $n = max(1, min(1000, (int)$n));
  $redis->set('RESEND_POOL_DOMAIN_LIMIT', $n);
  return $n;
}

/**
 * 调用 Resend API
 *
 * @param string $api_key  账号的 API Key
 * @param string $method   HTTP 方法
 * @param string $path     路径，如 /domains
 * @param array|null $body 请求体
 * @return array{ok:bool, http:int, data:mixed, error:string}
 */
function resend_api_call($api_key, $method, $path, $body = null) {
  $ch = curl_init(RESEND_API_BASE . $path);
  $headers = array(
    'Authorization: Bearer ' . $api_key,
    'Content-Type: application/json',
    'Accept: application/json',
  );
  $opts = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_USERAGENT      => 'mailcow-resend-pool',
  );
  if ($body !== null) {
    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  curl_setopt_array($ch, $opts);

  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($resp === false) {
    return array('ok' => false, 'http' => 0, 'data' => null, 'error' => $cerr ?: 'curl failed');
  }
  $data = json_decode($resp, true);
  if ($http >= 200 && $http < 300) {
    return array('ok' => true, 'http' => $http, 'data' => $data, 'error' => '');
  }
  $msg = '';
  if (is_array($data)) {
    if (isset($data['message'])) {
      $msg = (string)$data['message'];
    } elseif (isset($data['error']['message'])) {
      $msg = (string)$data['error']['message'];
    }
  }
  return array('ok' => false, 'http' => $http, 'data' => $data, 'error' => $msg ?: ('HTTP ' . $http));
}

/**
 * 校验一个 API Key 是否可用（读域名列表）
 */
function resend_check_key($api_key) {
  $r = resend_api_call($api_key, 'GET', '/domains');
  if ($r['ok']) {
    $list = is_array($r['data']) && isset($r['data']['data']) && is_array($r['data']['data'])
      ? $r['data']['data'] : array();
    return array('ok' => true, 'message' => '有效，已用 ' . count($list) . ' 个域名', 'domains' => $list);
  }
  // 权限不足的 key 会返回 401 restricted_api_key，也视为「无效（权限不对）」
  return array('ok' => false, 'message' => $r['error'], 'domains' => array());
}

/**
 * 列出号池所有账号（不含明文 key）
 */
function resend_pool_accounts($with_key = false) {
  global $pdo;
  $stmt = $pdo->query("SELECT * FROM `resend_accounts` ORDER BY `active` DESC, `id` ASC");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // 统计每个账号已承载的域名数（仅统计 active 的）
  $cnt = array();
  $stmt2 = $pdo->query("SELECT `account_id`, COUNT(*) AS c FROM `resend_domains` WHERE `active` = 1 GROUP BY `account_id`");
  foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cnt[(int)$c['account_id']] = (int)$c['c'];
  }
  $limit = resend_pool_domain_limit();
  foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['active'] = (int)$r['active'];
    $r['daily_quota'] = (int)$r['daily_quota'];
    $r['domain_count'] = isset($cnt[$r['id']]) ? $cnt[$r['id']] : 0;
    $r['domain_limit'] = $limit;
    if (!$with_key) {
      $k = (string)$r['api_key'];
      $r['api_key_masked'] = $k === '' ? '' : (substr($k, 0, 8) . '…' . substr($k, -4));
      unset($r['api_key']);
    }
  }
  unset($r);
  return $rows;
}

/**
 * 列出所有域名映射（含账号信息）
 */
function resend_pool_domains() {
  global $pdo;
  $sql = "SELECT d.*, a.`label` AS account_label, a.`active` AS account_active
          FROM `resend_domains` d
          LEFT JOIN `resend_accounts` a ON a.`id` = d.`account_id`
          ORDER BY d.`domain` ASC";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['account_id'] = (int)$r['account_id'];
    $r['active'] = (int)$r['active'];
    $r['account_active'] = $r['account_active'] === null ? null : (int)$r['account_active'];
  }
  unset($r);
  return $rows;
}

/**
 * 确保号池账号有一条对应的 relayhosts 记录，返回 relayhosts.id
 * 复用 mailcow 原生中继机制，使发信真正走该账号
 */
function resend_pool_ensure_relayhost($account) {
  global $pdo;
  $id = (int)$account['id'];
  $key = (string)$account['api_key'];
  // 关键：必须按账号 id 区分 username，否则多个账号会共用同一条
  // relayhosts 记录、互相覆盖密码，导致「每账号独立发信」失效。
  // mailcow 的 mysql_sasl_passwd_maps_sender_dependent.cf 取的是
  // username/password 字段，因此 username 用 resend#<id> 即可各自独立。
  $username = 'resend#' . $id;
  $stmt = $pdo->prepare("SELECT `id` FROM `relayhosts` WHERE `hostname` = :h AND `username` = :u LIMIT 1");
  $stmt->execute(array(':h' => RESEND_RELAY_HOST, ':u' => $username));
  $found = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($found) {
    // 已存在则同步密码与启用状态
    $stmt = $pdo->prepare("UPDATE `relayhosts` SET `password` = :p, `active` = :a WHERE `id` = :id");
    $stmt->execute(array(':p' => $key, ':a' => (int)$account['active'], ':id' => (int)$found['id']));
    return (int)$found['id'];
  }
  $stmt = $pdo->prepare("INSERT INTO `relayhosts` (`hostname`, `username`, `password`, `active`) VALUES (:h, :u, :p, :a)");
  $stmt->execute(array(':h' => RESEND_RELAY_HOST, ':u' => $username, ':p' => $key, ':a' => (int)$account['active']));
  return (int)$pdo->lastInsertId();
}

/**
 * 把域名绑定到号池账号
 *
 * 同时：
 *   1. 在 Resend 侧创建域名（若尚未创建）
 *   2. 把返回的 DNS 记录写入 Cloudflare（若配置了 CF Token）
 *   3. 触发验证
 *   4. 更新 mailcow domain.relayhost，使出站邮件走该账号
 */
function resend_pool_assign($domain, $account_id) {
  global $pdo, $redis;

  $domain = strtolower(trim($domain));
  if (!is_valid_domain_name($domain)) {
    return array('ok' => false, 'message' => '域名格式无效');
  }
  $account_id = (int)$account_id;
  $stmt = $pdo->prepare("SELECT * FROM `resend_accounts` WHERE `id` = :id LIMIT 1");
  $stmt->execute(array(':id' => $account_id));
  $account = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$account) {
    return array('ok' => false, 'message' => '账号不存在');
  }
  if ((int)$account['active'] !== 1) {
    return array('ok' => false, 'message' => '账号已停用');
  }

  // 容量检查
  $limit = resend_pool_domain_limit();
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM `resend_domains` WHERE `account_id` = :id AND `active` = 1 AND `domain` <> :d");
  $stmt->execute(array(':id' => $account_id, ':d' => $domain));
  $used = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
  if ($used >= $limit) {
    return array('ok' => false, 'message' => '该账号已达域名上限（' . $limit . '），请先移除其他域名或添加新账号');
  }

  $api_key = (string)$account['api_key'];

  // ① 在 Resend 侧创建域名
  $created = resend_api_call($api_key, 'POST', '/domains', array('name' => $domain));
  $resend_domain_id = '';
  $records = array();
  $status = 'not_started';
  if ($created['ok'] && isset($created['data']['id'])) {
    $resend_domain_id = (string)$created['data']['id'];
    $records = isset($created['data']['records']) ? $created['data']['records'] : array();
  } else {
    // 可能已存在 —— 列出来找
    $list = resend_api_call($api_key, 'GET', '/domains');
    if ($list['ok'] && isset($list['data']['data'])) {
      foreach ($list['data']['data'] as $d) {
        if (strtolower($d['name']) === $domain) {
          $resend_domain_id = (string)$d['id'];
          $one = resend_api_call($api_key, 'GET', '/domains/' . $d['id']);
          if ($one['ok']) {
            $records = isset($one['data']['records']) ? $one['data']['records'] : array();
            $status = (string)($one['data']['status'] ?? 'not_started');
          }
          break;
        }
      }
    }
    if ($resend_domain_id === '') {
      return array('ok' => false, 'message' => 'Resend 侧创建失败: ' . $created['error']);
    }
  }

  // ② 写入 Cloudflare DNS（若配置了 CF Token）
  $dns_result = resend_pool_write_dns($domain, $records);

  // ③ 触发验证
  $verify = resend_api_call($api_key, 'POST', '/domains/' . $resend_domain_id . '/verify');
  // 触发后立即查一次状态
  $one = resend_api_call($api_key, 'GET', '/domains/' . $resend_domain_id);
  if ($one['ok']) {
    $status = (string)($one['data']['status'] ?? $status);
    if (isset($one['data']['records'])) {
      $records = $one['data']['records'];
    }
  }

  // ④ 落库（upsert）
  $stmt = $pdo->prepare("SELECT `id` FROM `resend_domains` WHERE `domain` = :d LIMIT 1");
  $stmt->execute(array(':d' => $domain));
  $exist = $stmt->fetch(PDO::FETCH_ASSOC);
  $payload = array(
    ':account_id' => $account_id,
    ':rdi' => $resend_domain_id,
    ':status' => $status,
    ':records' => json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  );
  if ($exist) {
    $payload[':id'] = (int)$exist['id'];
    $stmt = $pdo->prepare("UPDATE `resend_domains` SET `account_id`=:account_id, `resend_domain_id`=:rdi,
      `status`=:status, `records_json`=:records, `active`=1 WHERE `id`=:id");
    $stmt->execute($payload);
    $row_id = (int)$exist['id'];
  } else {
    $payload[':d'] = $domain;
    $stmt = $pdo->prepare("INSERT INTO `resend_domains` (`domain`,`account_id`,`resend_domain_id`,`status`,`records_json`,`active`)
      VALUES (:d,:account_id,:rdi,:status,:records,1)");
    $stmt->execute($payload);
    $row_id = (int)$pdo->lastInsertId();
  }

  // ⑤ 绑定 mailcow 出站中继
  $relay_id = resend_pool_ensure_relayhost($account);
  $stmt = $pdo->prepare("UPDATE `domain` SET `relayhost` = :r WHERE `domain` = :d");
  $stmt->execute(array(':r' => $relay_id, ':d' => $domain));

  return array(
    'ok' => true,
    'message' => '已绑定到账号「' . $account['label'] . '」，状态: ' . $status,
    'domain_id' => $row_id,
    'status' => $status,
    'dns' => $dns_result,
  );
}

/**
 * 把 Resend 返回的 DNS 记录写入 Cloudflare
 * 需要 redis 中存在 CF_TOKEN2 与 zone 映射；未配置时跳过（不报错）
 */
function resend_pool_write_dns($domain, $records) {
  global $redis;
  $cf_token = $redis->get('RESEND_POOL_CF_TOKEN');
  if (!$cf_token || empty($records)) {
    return array('written' => 0, 'skipped' => true, 'reason' => '未配置 Cloudflare Token 或无记录');
  }
  // 查 zone id
  $zone_id = resend_pool_cf_zone_id($cf_token, $domain);
  if (!$zone_id) {
    return array('written' => 0, 'skipped' => true, 'reason' => 'Cloudflare 中找不到该域名');
  }
  $written = 0; $failed = array();
  foreach ($records as $rec) {
    $name = (string)($rec['name'] ?? '');
    if ($name === '' || $name === '@') {
      $full = $domain;
    } else {
      $full = $name . '.' . $domain;
    }
    $type = strtoupper((string)($rec['type'] ?? 'TXT'));
    $body = array(
      'type' => $type,
      'name' => $full,
      'content' => (string)($rec['value'] ?? ''),
      'ttl' => 300,
    );
    if (!empty($rec['priority'])) {
      $body['priority'] = (int)$rec['priority'];
    }
    if ($type !== 'CNAME') {
      $body['proxied'] = false;
    }
    // 查是否已存在
    $exist = resend_pool_cf_request($cf_token, 'GET',
      '/zones/' . $zone_id . '/dns_records?type=' . urlencode($type) . '&name=' . urlencode($full));
    $exist_id = null;
    if ($exist['ok'] && isset($exist['data']['result'][0]['id'])) {
      $exist_id = $exist['data']['result'][0]['id'];
    }
    $r = $exist_id
      ? resend_pool_cf_request($cf_token, 'PATCH', '/zones/' . $zone_id . '/dns_records/' . $exist_id, $body)
      : resend_pool_cf_request($cf_token, 'POST', '/zones/' . $zone_id . '/dns_records', $body);
    if ($r['ok']) {
      $written++;
    } else {
      $failed[] = $type . ' ' . $full . ': ' . $r['error'];
    }
  }
  return array('written' => $written, 'total' => count($records), 'failed' => $failed, 'skipped' => false);
}

function resend_pool_cf_zone_id($cf_token, $domain) {
  // 逐级尝试根域
  $parts = explode('.', $domain);
  for ($i = 0; $i < count($parts) - 1; $i++) {
    $candidate = implode('.', array_slice($parts, $i));
    $r = resend_pool_cf_request($cf_token, 'GET', '/zones?name=' . urlencode($candidate) . '&per_page=1');
    if ($r['ok'] && !empty($r['data']['result'][0]['id'])) {
      return $r['data']['result'][0]['id'];
    }
  }
  return null;
}

function resend_pool_cf_request($cf_token, $method, $path, $body = null) {
  $ch = curl_init('https://api.cloudflare.com/client/v4' . $path);
  $opts = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    CURLOPT_HTTPHEADER     => array(
      'Authorization: Bearer ' . $cf_token,
      'Content-Type: application/json',
    ),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 25,
  );
  if ($body !== null) {
    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($resp === false) {
    return array('ok' => false, 'http' => 0, 'data' => null, 'error' => 'curl failed');
  }
  $data = json_decode($resp, true);
  $ok = $http >= 200 && $http < 300 && is_array($data) && !empty($data['success']);
  $err = '';
  if (!$ok && is_array($data)) {
    $err = $data['errors'][0]['message'] ?? ('HTTP ' . $http);
  }
  return array('ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $err);
}

/**
 * 从号池移除域名（Resend 侧删除 + 解除 mailcow 中继绑定）
 */
function resend_pool_remove($domain) {
  global $pdo;
  $domain = strtolower(trim($domain));
  $stmt = $pdo->prepare("SELECT d.*, a.`api_key` FROM `resend_domains` d
    LEFT JOIN `resend_accounts` a ON a.`id` = d.`account_id` WHERE d.`domain` = :d LIMIT 1");
  $stmt->execute(array(':d' => $domain));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return array('ok' => false, 'message' => '域名不在号池中');
  }
  $msg = array();
  if (!empty($row['resend_domain_id']) && !empty($row['api_key'])) {
    $r = resend_api_call($row['api_key'], 'DELETE', '/domains/' . $row['resend_domain_id']);
    $msg[] = $r['ok'] ? 'Resend 侧已删除' : ('Resend 侧删除失败: ' . $r['error']);
  }
  // 解除 mailcow 中继绑定（还原为直发）
  $stmt = $pdo->prepare("UPDATE `domain` SET `relayhost` = 0 WHERE `domain` = :d");
  $stmt->execute(array(':d' => $domain));
  $msg[] = '已解除中继绑定';

  $stmt = $pdo->prepare("DELETE FROM `resend_domains` WHERE `domain` = :d");
  $stmt->execute(array(':d' => $domain));

  return array('ok' => true, 'message' => implode('；', $msg));
}

/**
 * 同步所有域名的验证状态（从 Resend 拉取最新）
 */
function resend_pool_sync() {
  global $pdo;
  $rows = $pdo->query("SELECT d.*, a.`api_key` FROM `resend_domains` d
    LEFT JOIN `resend_accounts` a ON a.`id` = d.`account_id`")->fetchAll(PDO::FETCH_ASSOC);
  $updated = 0; $errors = array();
  foreach ($rows as $row) {
    if (empty($row['resend_domain_id']) || empty($row['api_key'])) {
      continue;
    }
    $r = resend_api_call($row['api_key'], 'GET', '/domains/' . $row['resend_domain_id']);
    if (!$r['ok']) {
      $errors[] = $row['domain'] . ': ' . $r['error'];
      continue;
    }
    $status = (string)($r['data']['status'] ?? 'unknown');
    $records = isset($r['data']['records']) ? $r['data']['records'] : array();
    $stmt = $pdo->prepare("UPDATE `resend_domains` SET `status`=:s, `records_json`=:rec,
      `verified_at` = IF(:s2 = 'verified', NOW(), `verified_at`) WHERE `id`=:id");
    $stmt->execute(array(
      ':s' => $status,
      ':s2' => $status,
      ':rec' => json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ':id' => (int)$row['id'],
    ));
    $updated++;
  }
  // 同步账号有效性
  foreach (resend_pool_accounts(true) as $acc) {
    $chk = resend_check_key($acc['api_key']);
    $stmt = $pdo->prepare("UPDATE `resend_accounts` SET `last_check`=NOW(), `check_status`=:s WHERE `id`=:id");
    $stmt->execute(array(':s' => $chk['message'], ':id' => (int)$acc['id']));
  }
  return array('ok' => true, 'updated' => $updated, 'errors' => $errors);
}

/**
 * 自动分配：把指定域名（或所有 mailcow 域名）分配到容量未满的账号
 */
function resend_pool_auto_assign(array $domains) {
  global $pdo;
  $limit = resend_pool_domain_limit();
  $accounts = resend_pool_accounts(true);
  $active = array_values(array_filter($accounts, function ($a) { return (int)$a['active'] === 1; }));
  if (empty($active)) {
    return array('ok' => false, 'message' => '号池中没有启用的账号');
  }

  // 计算各账号剩余容量
  $used = array();
  foreach ($pdo->query("SELECT `account_id`, COUNT(*) c FROM `resend_domains` WHERE `active`=1 GROUP BY `account_id`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $used[(int)$r['account_id']] = (int)$r['c'];
  }
  $cap = array();
  foreach ($active as $a) {
    $id = (int)$a['id'];
    $cap[$id] = $limit - (isset($used[$id]) ? $used[$id] : 0);
  }

  $assignments = array(); $failed = array();
  foreach ($domains as $d) {
    $d = strtolower(trim($d));
    // 选剩余容量最大的账号
    $best = null; $bestCap = 0;
    foreach ($cap as $id => $c) {
      if ($c > $bestCap) { $bestCap = $c; $best = $id; }
    }
    if ($best === null || $bestCap <= 0) {
      $failed[] = $d . '（所有账号已满）';
      continue;
    }
    $res = resend_pool_assign($d, $best);
    if ($res['ok']) {
      $cap[$best]--;
      $assignments[] = $d . ' → 账号#' . $best . '（' . $res['status'] . '）';
    } else {
      $failed[] = $d . '（' . $res['message'] . '）';
    }
  }
  return array(
    'ok' => true,
    'assigned' => $assignments,
    'failed' => $failed,
    'message' => '分配完成：成功 ' . count($assignments) . '，失败 ' . count($failed),
  );
}
