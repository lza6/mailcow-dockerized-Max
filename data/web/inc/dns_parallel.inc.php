<?php
/**
 * 并发 DNS 查询：把 N 个名字的 A 记录查询同时发出，而不是一个一个等。
 *
 * 为什么需要它：
 *   域名体检的冷路径要查 13 个 RBL 名单。每个名字在**没被解析器缓存**时
 *   要约 1–3.6 秒（实测），串行就是 21.6 秒 —— 足以打满 php-fpm worker，
 *   而这一页恰恰是"出问题时才会打开"的页面。
 *
 * 实现取舍：
 *   - 用原始 UDP socket 自己拼 DNS 报文，而不是 `dns_get_record()` 串行调用，
 *     因为 PHP 没有并发的 DNS API（`ext-sockets` 在 mailcow 镜像里可用）。
 *   - **不引入依赖**：项目无 composer/npm，全部用标准库函数实现。
 *   - 参数（如 autoAppendSearchDomains/缓存）不在这里处理 —— 只做"给我这几个名字的 A 记录"。
 *   - 任何异常都降级为"该名字无结果"，**绝不**让体检页因此失败。
 *
 * ⚠️ 这个文件**只做网络 I/O**，判定逻辑在 domain_health_lib.inc.php 里（可离线测试）。
 */

if (!defined('DHC_DNS_TIMEOUT'))    { define('DHC_DNS_TIMEOUT', 5); }
if (!defined('DHC_DNS_MAX_INFLIGHT')) { define('DHC_DNS_MAX_INFLIGHT', 16); }
/**
 * 用哪个解析器 —— 这是本文件最反直觉的一处，实测数据如下：
 *
 *   方式                                 应答       耗时
 *   并发 5 个 → unbound(172.22.1.254)    1/5       6100 ms   ← unbound 不并发应答
 *   并发 5 个 → 内嵌(127.0.0.11)  冷     1–4/5     4000 ms   ← 冷路径要转发，慢且丢
 *   并发 5 个 → 内嵌              热     5/5        200 ms
 *   直连公共解析器原始 RTT               5/5     50–160 ms   ← 又快又稳
 *
 * 结论：**绕开容器内的解析器链路，直接问公共解析器**。
 * mailcow 的 unbound 是给邮件事务用的（单线程递归、串行服务），
 * 拿它跑"一次十几个并发查询"的体检页只会互相拖累；Docker 内嵌解析器
 * 在缓存未命中时也要转发给 unbound，同样受制于它。
 *
 * 隐私/一致性说明：这些查询全是**公开的 DNS 记录**（域名的 SPF/DKIM/DMARC/MX/A
 * 与 RBL 名单），不含任何本地或用户隐私信息，因此发给公共解析器没有额外暴露面。
 * 本文件只做"记录存在性与取值"，判断逻辑在 domain_health_lib.inc.php（离线可测）。
 *
 * 可用 `define('DHC_DNS_SERVERS', '...')` 覆盖（例如自建解析器或内网 DNS）。
 */
if (!defined('DHC_DNS_SERVERS'))    { define('DHC_DNS_SERVERS', '1.1.1.1,8.8.8.8,9.9.9.9'); }

/** 把一组 resolver 地址解析成 [ip, ...]（跳过不可用的） */
function dhc_dns_resolvers() {
  static $cache = null;
  if ($cache !== null) { return $cache; }
  $cache = array();
  // 优先用环境变量覆盖（运维可在不改代码的前提下换解析器），其次用常量
  $spec = getenv('DHC_DNS_SERVERS');
  if ($spec === false || trim((string)$spec) === '') { $spec = DHC_DNS_SERVERS; }
  foreach (explode(',', (string)$spec) as $s) {
    $s = trim($s);
    if ($s !== '' && filter_var($s, FILTER_VALIDATE_IP)) {
      $cache[] = $s;
    }
  }
  if (empty($cache)) { $cache[] = '127.0.0.11'; }
  return $cache;
}

/**
 * 构造一个最小 DNS 查询报文（QTYPE 可指定 / QCLASS=IN，RD=1）
 *
 * @param int $qtype 1=A, 15=MX, 16=TXT, 12=PTR
 */
function dhc_dns_build_query($name, $id, $qtype = 1) {
  $q = pack('n6', $id, 0x0100, 1, 0, 0, 0);
  foreach (explode('.', rtrim((string)$name, '.')) as $label) {
    $label = (string)$label;
    if ($label === '' || strlen($label) > 63) { return ''; }
    $q .= chr(strlen($label)) . $label;
  }
  $q .= "\x00" . pack('nn', (int)$qtype, 1);   // QTYPE, QCLASS=IN
  return $q;
}

/**
 * 解析 DNS 应答，按类型提取记录。
 *
 * 支持 A(1) / MX(15) / TXT(16) / PTR(12) —— 域名体检要的全部类型。
 * 报文损坏一律当"无结果"，不抛异常 —— 体检页不该因为一个畸形包而挂掉。
 *
 * @return array 每种类型都是字符串列表：
 *   A   → ['1.2.3.4', ...]
 *   MX  → ['10 mx.example.com', ...]        （含优先级，与原 dns_get_record 的语义对齐）
 *   TXT → ['v=spf1 ...', ...]               （多段 TXT 已拼接）
 *   PTR → ['mail.example.com', ...]
 *   另外 'raw' 键保留未归类的记录，便于排障。
 */
function dhc_dns_parse($resp) {
  $out = array('A' => array(), 'MX' => array(), 'TXT' => array(), 'PTR' => array());
  $len = strlen($resp);
  if ($len < 12) { return $out; }

  $h = @unpack('nid/nflags/nqd/nan/nns/nar', substr($resp, 0, 12));
  if (!is_array($h)) { return $out; }
  if (($h['flags'] & 0x8000) === 0) { return $out; }   // 不是应答
  if (($h['flags'] & 0x000F) !== 0) { return $out; }   // RCODE != NOERROR（NXDOMAIN 等）

  // 跳过问题段
  $off = 12;
  for ($i = 0; $i < (int)$h['qd']; $i++) {
    $off = dhc_dns_skip_name($resp, $off);
    if ($off < 0 || $off + 4 > $len) { return $out; }
    $off += 4;
  }

  // 读答案段
  for ($i = 0; $i < (int)$h['an']; $i++) {
    $off = dhc_dns_skip_name($resp, $off);
    if ($off < 0 || $off + 10 > $len) { return $out; }
    $rr = @unpack('ntype/nclass/Nttl/nrdlen', substr($resp, $off, 10));
    if (!is_array($rr)) { return $out; }
    $off += 10;
    $rdlen = (int)$rr['rdlen'];
    if ($off + $rdlen > $len) { return $out; }
    $raw = substr($resp, $off, $rdlen);

    if ((int)$rr['class'] === 1) {
      switch ((int)$rr['type']) {
        case 1:   // A
          if ($rdlen === 4) { $out['A'][] = long2ip(unpack('N', $raw)[1]); }
          break;
        case 15:  // MX：2 字节优先级 + 名字（可能用压缩指针，要按整包解）
          if ($rdlen >= 3) {
            $prio = unpack('n', substr($raw, 0, 2))[1];
            $target = dhc_dns_read_name($resp, $off + 2);
            if ($target !== '') { $out['MX'][] = (int)$prio . ' ' . $target; }
          }
          break;
        case 16:  // TXT：一个或多个 length-prefixed 字符串
          $txt = ''; $p = 0;
          while ($p < $rdlen) {
            $sl = ord($raw[$p]);
            $p++;
            if ($p + $sl > $rdlen) { break; }
            $txt .= substr($raw, $p, $sl);
            $p += $sl;
          }
          if ($txt !== '') { $out['TXT'][] = $txt; }
          break;
        case 12:  // PTR
          $t = dhc_dns_read_name($resp, $off);
          if ($t !== '') { $out['PTR'][] = strtolower(rtrim($t, '.')); }
          break;
      }
    }
    $off += $rdlen;
  }

  foreach ($out as $k => $v) { $out[$k] = array_values(array_unique($v)); }
  return $out;
}

/**
 * 从报文里读一个（可能被压缩的）域名，返回小写、无结尾点的形式。
 * 失败返回空串。带循环上限，畸形包不会死循环。
 */
function dhc_dns_read_name($buf, $off) {
  $len = strlen($buf);
  $labels = array();
  $guard = 0;
  while ($off < $len) {
    if (++$guard > 128) { return ''; }
    $l = ord($buf[$off]);
    if ($l === 0) { break; }
    if (($l & 0xC0) === 0xC0) {
      if ($off + 1 >= $len) { return ''; }
      $ptr = (($l & 0x3F) << 8) | ord($buf[$off + 1]);
      // **只允许向前跳**（DNS 压缩指针本就只能指向前面已出现的位置）。
      // 这一条把「自指」「任意环」「越界」一次性都排除了：
      //   越界 —— 若 $ptr < $off 而 $off < $len，则必然 $ptr < $len；
      //   成环 —— 偏移严格递减必然收敛，所以不需要额外的跳转计数器。
      // （这两句以前写成独立判断，实测是**不可达的冗余代码**，删掉。）
      if ($ptr >= $off) { return ''; }
      $off = $ptr;
      continue;
    }
    // 保留位（0x40/0x80 开头）不是合法的长度或指针。
    // 注意这条**不能**靠「标签长度超出报文」兜底：只要缓冲区够大，
    // 一个 0x41 开头的畸形标签就会被当成 65 字节的正常标签读出乱码。
    if (($l & 0xC0) !== 0) { return ''; }
    if ($off + 1 + $l > $len) { return ''; }
    $labels[] = substr($buf, $off + 1, $l);
    $off += 1 + $l;
  }
  return strtolower(rtrim(implode('.', $labels), '.'));
}

/**
 * 跳过 DNS 名字（支持压缩指针）。返回下一个字节的偏移；-1 表示报文畸形。
 * 用循环上限防止畸形包造成死循环。
 */
function dhc_dns_skip_name($buf, $off) {
  $len = strlen($buf);
  $guard = 0;
  while ($off < $len) {
    if (++$guard > 128) { return -1; }
    $l = ord($buf[$off]);
    if ($l === 0) { return $off + 1; }
    if (($l & 0xC0) === 0xC0) { return $off + 2; }   // 压缩指针，名字到此结束
    if (($l & 0xC0) !== 0) { return -1; }            // 保留位，畸形
    $off += 1 + $l;
  }
  return -1;
}

/**
 * 并发查询一批「名字 + 类型」。
 *
 * @param array $queries 每项 ['name' => 'a.example', 'type' => 1|15|16|12]
 * @param int   $timeout 秒；到期仍未应答的按"无结果"处理
 * @return array  "name|qtype" => 解析结果数组（见 dhc_dns_parse）
 *
 * 每个查询用独立的 key，所以同一个名字的 TXT 与 A 可以同时在途、互不覆盖。
 * 无结果（NXDOMAIN / 超时 / 解析器拒答）返回各类型皆空的数组 —— 与串行版
 * `dns_get_record()` 返回 false/空数组的语义一致，调用方不必区分。
 */
function dhc_dns_query_batch_typed(array $queries, $timeout = null) {
  $timeout = $timeout === null ? (int)DHC_DNS_TIMEOUT : (int)$timeout;

  $empty = array('A' => array(), 'MX' => array(), 'TXT' => array(), 'PTR' => array());
  $pending = array();
  $result  = array();
  $seen    = array();
  foreach ($queries as $spec) {
    $name = isset($spec['name']) ? (string)$spec['name'] : '';
    $type = isset($spec['type']) ? (int)$spec['type'] : 1;
    if ($name === '') { continue; }
    $key = $name . '|' . $type;
    if (isset($seen[$key])) { continue; }
    $seen[$key] = true;
    $pending[$key] = array('name' => $name, 'type' => $type);
    $result[$key]  = $empty;
  }
  if (empty($pending) || !function_exists('socket_create')) { return $result; }

  $socks = array();
  foreach (dhc_dns_resolvers() as $r) {
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($s === false) { continue; }
    @socket_set_nonblock($s);
    $socks[$r] = $s;
  }
  if (empty($socks)) { return $result; }

  $resolverList = array_keys($socks);
  // ⚠️ 只用一个 resolver，**不要**轮询多个。
  // 实测教训：把查询轮流发到 unbound(172.22.1.254) 与 Docker 内嵌(127.0.0.11) 时，
  // 发到内嵌那半边的查询大量拿不到应答（它是要再转发一跳的），
  // 结果不管发多少个查询，永远只有约一半回来、剩下的全部耗满超时。
  // 单 resolver 既避免这种"半死"路径，也让 socket 就绪集合始终一致。
  $resolver = $resolverList[0];
  $ri = 0;
  $idSeq = 0;
  $byId  = array();
  $deadlineAll = microtime(true) + $timeout;
  $buf = '';

  $sendNext = function () use (&$pending, &$byId, &$idSeq, $resolver, $socks) {
    if (empty($pending)) { return; }
    if (count($byId) >= (int)DHC_DNS_MAX_INFLIGHT) { return; }
    $key  = array_key_first($pending);
    $spec = $pending[$key];
    unset($pending[$key]);
    $id = (++$idSeq) & 0xFFFF;
    // id 是 16 位，理论可回绕；把已占用的让开，避免应答串台
    $tries = 0;
    while (isset($byId[$id]) && $tries++ < 65536) { $id = (++$idSeq) & 0xFFFF; }
    $q = dhc_dns_build_query($spec['name'], $id, $spec['type']);
    if ($q === '') { return; }
    if (@socket_sendto($socks[$resolver], $q, strlen($q), 0, $resolver, 53) === false) {
      return;   // 发不出去 → 该查询保持空结果
    }
    $byId[$id] = array('key' => $key, 'resolver' => $resolver);
  };
  $fill = function () use (&$byId, &$pending, $sendNext) {
    while (count($byId) < (int)DHC_DNS_MAX_INFLIGHT && !empty($pending)) { $sendNext(); }
  };
  $fill();

  while (!empty($byId)) {
    if (microtime(true) >= $deadlineAll) { break; }

    $readSocks = array();
    foreach (array_unique(array_map(function ($e) { return $e['resolver']; }, $byId)) as $r) {
      $readSocks[] = $socks[$r];
    }
    $w = null; $e = null;
    // ⚠️ 等待窗口上限 200ms：这样"什么时候发下一个查询"不受 select 阻塞时长影响。
    // 之前拿剩余总时长当超时，一旦 select 长时间阻塞，排队的查询就一直发不出去，
    // 最后整批一起超时 —— 表现就是间歇性"一个结果都拿不到"。
    $wait = min($deadlineAll - microtime(true), 0.2);
    $sec  = (int)floor($wait);
    $usec = (int)(max(0, $wait - $sec) * 1000000);

    $n = @socket_select($readSocks, $w, $e, $sec, $usec);
    if ($n === false || $n === 0) {
      $fill();
      continue;
    }

    foreach ($readSocks as $s) {
      // ⚠️ 必须排空：select 只说"这个 fd 可读"，其接收缓冲里可能排着多个应答。
      // 只 recvfrom 一次的话，剩下的包会一直躺着，而下一轮 select 未必再报这个 fd
      // （其它 fd 也可能同时就绪），结果就是**间歇性丢结果**。
      while (true) {
        if (empty($byId)) { break 2; }
        $from = ''; $port = 0;
        $data = @socket_recvfrom($s, $buf, 4096, 0, $from, $port);
        if ($data === false || $data === 0) { break; }

        $h  = @unpack('nid', substr($buf, 0, 2));
        $id = (is_array($h) && isset($h['id'])) ? (int)$h['id'] : -1;
        if ($id >= 0 && isset($byId[$id])) {
          $key = $byId[$id]['key'];
          $got = dhc_dns_parse($buf);
          // 只在有内容时写回，避免把已拿到的结果被畸形包覆盖成空
          foreach ($got as $t => $vals) {
            if (!empty($vals)) { $result[$key][$t] = $vals; }
          }
          unset($byId[$id]);
          $fill();
        }
      }
    }
  }

  foreach ($socks as $s) { @socket_close($s); }
  return $result;
}

/**
 * 并发查询多个名字的 A 记录（窄接口，RBL 批量判定用）。
 *
 * @return array name => [ip, ...]（无结果的名字也会出现，值为空数组）
 */
function dhc_dns_query_a_batch(array $names, $timeout = null) {
  $names = array_values(array_unique(array_filter(array_map('strval', $names), 'strlen')));
  $specs = array();
  foreach ($names as $n) { $specs[] = array('name' => $n, 'type' => 1); }
  $typed = dhc_dns_query_batch_typed($specs, $timeout);
  $out = array();
  foreach ($names as $n) {
    $out[$n] = isset($typed[$n . '|1']['A']) ? $typed[$n . '|1']['A'] : array();
  }
  return $out;
}
