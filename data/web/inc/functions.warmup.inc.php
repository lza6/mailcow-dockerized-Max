<?php
/**
 * mailcow-dockerized-Max — 养号（Warmup）核心逻辑
 *
 * 作用：按计划、低速、分散地向"真实收件人池"内的地址发信，用于逐步建立
 * 发件域在 Gmail 等收件方处的信誉。
 *
 * 设计依据（见 docs/01-投递与信誉/15个根域逐域验证报告.md）：
 *   - 同一域：单独发 1 封 → 进收件箱；混在 15 封批量里 → 进垃圾箱
 *   - 结论：落点主因是"短时间批量突发"，因此本模块的三个核心约束是
 *     ① 每天总量小  ② 发送时刻随机分散  ③ 只发真实收件人
 *
 * 安全边界：
 *   - 收件人只能来自 warmup_recipients 表（管理员维护），不接受任意外部输入
 *   - 主题/正文为固定模板，用户可控字段一律不入邮件头，避免头注入
 *   - 三重上限：单计划当日配额、单次运行批量、全局每日总量
 *   - 失败率超阈值自动暂停，避免在域已被降权时继续硬发
 */

// 单次运行最多发几封（防止一次 cron 触发猛发）
define('WARMUP_MAX_PER_RUN', 3);
// 全局每日发送总量上限（跨所有计划，兜底防配置错误）
define('WARMUP_GLOBAL_DAILY_CAP', 30);
// 失败率阈值与最小样本（达到后才判定）
define('WARMUP_PAUSE_FAIL_RATIO', 0.5);
define('WARMUP_PAUSE_FAIL_MIN', 4);
// 发送窗口：在每天的这个时间区间内随机
define('WARMUP_WINDOW_START_HOUR', 8);
define('WARMUP_WINDOW_END_HOUR', 20);
// 交给 postfix 的地址（容器网络别名）
define('WARMUP_SMTP_HOST', 'postfix');
define('WARMUP_SMTP_PORT', 25);

/**
 * 对齐时区 —— 必须做，否则"当日配额"会算错。
 *
 * 实测：php-fpm 容器的 TZ 环境变量是 America/New_York，但 PHP 的
 * date_default_timezone 是 UTC（php.ini 未跟随 TZ），而 MySQL 用 SYSTEM
 * 时区（= America/New_York）。两者相差若干小时，导致：
 *   - warmup_day_index() 用 PHP 的 UTC 日期去减 started_on（MySQL 的本地日期），
 *     在每日本地 20:00-24:00 这段会把天数多算一天，配额随之算错；
 *   - 发送窗口 8:00-20:00 实际按 UTC 生效，偏离本地白天。
 * 这里显式对齐到容器的 TZ，使 PHP 与 MySQL 使用同一时区。
 */
function warmup_sync_timezone() {
  $tz = getenv('TZ');
  if (!$tz && file_exists('/etc/timezone')) {
    $tz = trim((string)@file_get_contents('/etc/timezone'));
  }
  if ($tz) {
    @date_default_timezone_set($tz);
  }
}
warmup_sync_timezone();

/**
 * 取所有养号计划
 */
function warmup_plans($pdo, $only_active = false) {
  $sql = "SELECT `p`.*,
                 (SELECT COALESCE(SUM(`sent`),0) FROM `warmup_log` WHERE `plan_id` = `p`.`id` AND `run_date` = CURDATE()) AS `sent_today`,
                 (SELECT COALESCE(SUM(`sent`),0) FROM `warmup_log` WHERE `plan_id` = `p`.`id`) AS `sent_all`,
                 (SELECT COALESCE(SUM(`failed`),0) FROM `warmup_log` WHERE `plan_id` = `p`.`id`) AS `fail_all`
            FROM `warmup_plans` `p`";
  if ($only_active) {
    $sql .= " WHERE `p`.`status` = 'active'";
  }
  $sql .= " ORDER BY `p`.`id` ASC";
  $stmt = $pdo->query($sql);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 取单个计划
 */
function warmup_plan($pdo, $id) {
  $stmt = $pdo->prepare("SELECT * FROM `warmup_plans` WHERE `id` = :id LIMIT 1");
  $stmt->execute(array(':id' => (int)$id));
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 取收件人池
 */
function warmup_recipients($pdo, $only_active = false) {
  $sql = "SELECT * FROM `warmup_recipients`";
  if ($only_active) {
    $sql .= " WHERE `active` = 1";
  }
  $sql .= " ORDER BY `id` ASC";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 取最近日志
 */
function warmup_logs($pdo, $limit = 100) {
  $limit = max(1, min(500, (int)$limit));
  $sql = "SELECT `l`.*, `p`.`domain` AS `domain`, `p`.`sender` AS `sender`
            FROM `warmup_log` `l`
            LEFT JOIN `warmup_plans` `p` ON `p`.`id` = `l`.`plan_id`
           ORDER BY `l`.`id` DESC
           LIMIT " . $limit;
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 计划已进行到第几天（从 1 开始）
 */
function warmup_day_index($plan) {
  $start = strtotime($plan['started_on'] . ' 00:00:00');
  $today = strtotime(date('Y-m-d') . ' 00:00:00');
  if ($start === false) {
    return 1;
  }
  $day = (int)floor(($today - $start) / 86400) + 1;
  return $day < 1 ? 1 : $day;
}

/**
 * 计划当日应发配额（线性递增，不超过 max_count）
 */
function warmup_daily_quota($plan) {
  $day = warmup_day_index($plan);
  $quota = (int)$plan['start_count'] + ($day - 1) * (int)$plan['step'];
  $max = (int)$plan['max_count'];
  if ($quota > $max) {
    $quota = $max;
  }
  return $quota < 0 ? 0 : $quota;
}

/**
 * 计划是否已到期
 */
function warmup_is_expired($plan) {
  return warmup_day_index($plan) > (int)$plan['total_days'];
}

/**
 * 今天全局已发多少封
 */
function warmup_sent_today_total($pdo) {
  $stmt = $pdo->query("SELECT COALESCE(SUM(`sent`),0) FROM `warmup_log` WHERE `run_date` = CURDATE()");
  return (int)$stmt->fetchColumn();
}

/**
 * 暂停计划并记录原因
 */
function warmup_pause($pdo, $planId, $reason) {
  $stmt = $pdo->prepare("UPDATE `warmup_plans`
                            SET `status` = 'paused', `pause_reason` = :r
                          WHERE `id` = :id");
  $stmt->execute(array(':r' => mb_substr((string)$reason, 0, 255), ':id' => (int)$planId));
}

/**
 * 用 PHPMailer 经 postfix 发出一封养号邮件
 *
 * @return array array('ok' => bool, 'error' => string)
 */
function warmup_send_mail($plan, $recipient) {
  if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    $autoload = __DIR__ . '/lib/vendor/autoload.php';
    if (file_exists($autoload)) {
      require_once $autoload;
    }
  }
  if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    return array('ok' => false, 'error' => 'PHPMailer not available');
  }

  $sender = (string)$plan['sender'];
  $domain = (string)$plan['domain'];
  $day    = warmup_day_index($plan);

  // 固定模板：不含任何用户输入，杜绝头注入
  $subject = sprintf('Quick note from %s', $domain);
  $body = "Hi,\n\n"
        . "This is a routine note from our team at {$domain}.\n"
        . "If you need anything, just reply to this email.\n\n"
        . "Thanks,\n"
        . htmlspecialchars($sender, ENT_QUOTES, 'UTF-8') . "\n";

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host       = WARMUP_SMTP_HOST;
    $mail->Port       = WARMUP_SMTP_PORT;
    $mail->SMTPAuth   = false;
    $mail->SMTPAutoTLS = false;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 20;
    $mail->XMailer    = '';          // 不暴露库指纹
    // 显式指定 Message-ID 并回传给调用方 —— 之后要靠它到收件人邮箱里反查
    // 这封邮件落在收件箱还是垃圾箱（见 functions.warmup_ext.inc.php 的 wwe_find_message）。
    $mail->MessageID  = sprintf('<%s.%d@%s>', bin2hex(random_bytes(10)), time(), $domain);
    $mail->setFrom($sender, $domain);
    $mail->addAddress((string)$recipient['email']);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->send();
    return array('ok' => true, 'error' => '',
                 'message_id' => (string)$mail->MessageID, 'subject' => $subject);
  } catch (Exception $e) {
    // PHPMailer 异常信息可能含 SMTP 细节，截断后记录
    return array('ok' => false, 'error' => mb_substr($mail->ErrorInfo ?: $e->getMessage(), 0, 200),
                 'message_id' => '', 'subject' => '');
  }
}

/**
 * 执行一轮养号调度
 *
 * @param bool $force 忽略"随机时刻"限制（仅用于人工触发/测试）
 * @return array 汇总结果
 */
function warmup_run($pdo, $force = false) {
  $result = array(
    'checked'  => 0,
    'skipped'  => array(),
    'sent'     => 0,
    'failed'   => 0,
    'paused'   => array(),
    'details'  => array(),
  );

  $recipients = warmup_recipients($pdo, true);
  if (empty($recipients)) {
    $result['skipped'][] = 'no active recipients';
    return $result;
  }

  $globalCap = WARMUP_GLOBAL_DAILY_CAP;
  $globalSent = warmup_sent_today_total($pdo);
  if ($globalSent >= $globalCap) {
    $result['skipped'][] = 'global daily cap reached (' . $globalSent . '/' . $globalCap . ')';
    return $result;
  }

  foreach (warmup_plans($pdo, true) as $plan) {
    $result['checked']++;

    if (warmup_is_expired($plan)) {
      // 到期自动收尾
      $stmt = $pdo->prepare("UPDATE `warmup_plans` SET `status` = 'finished' WHERE `id` = :id");
      $stmt->execute(array(':id' => (int)$plan['id']));
      $result['skipped'][] = $plan['domain'] . ': finished (total_days reached)';
      continue;
    }

    $quota   = warmup_daily_quota($plan);
    $already = (int)$plan['sent_today'];
    $remaining = $quota - $already;
    if ($remaining <= 0) {
      $result['skipped'][] = $plan['domain'] . ': daily quota reached (' . $already . '/' . $quota . ')';
      continue;
    }

    // 单次运行批量上限
    $batch = min($remaining, WARMUP_MAX_PER_RUN, $globalCap - $globalSent);
    if ($batch <= 0) {
      $result['skipped'][] = $plan['domain'] . ': global cap';
      continue;
    }

    // 随机时刻：未到点则跳过（force 时忽略）
    if (!$force) {
      // 用 warmup_target_ts() —— 与界面显示「下一封什么时候发」**同一套算法**。
      // 各算各的必然漂移，管理员看到的时刻就会和实际发送时刻对不上。
      $targetTs = warmup_target_ts($plan);
      if (time() < $targetTs) {
        $result['skipped'][] = $plan['domain'] . ': before random slot ' . date('H:i', $targetTs);
        continue;
      }
    }

    $sent = 0;
    $failed = 0;
    $errs = array();
    for ($i = 0; $i < $batch; $i++) {
      $rc = $recipients[array_rand($recipients)];
      $r = warmup_send_mail($plan, $rc);
      if ($r['ok']) {
        $sent++;
      } else {
        $failed++;
        $errs[] = $rc['email'] . ': ' . $r['error'];
      }
      // 逐封落库：记下「发给了谁 / Message-ID / 成功与否」。
      // 原先只有按天汇总的 warmup_log，「都发给谁了」查不到，更没法回头
      // 到收件人邮箱核对这封到底进了收件箱还是垃圾箱。
      try {
        $st = $pdo->prepare("INSERT INTO `warmup_messages`
            (`plan_id`,`recipient_id`,`recipient`,`message_id`,`subject`,`send_ok`,`fail_reason`)
            VALUES (:p,:rid,:rc,:mid,:subj,:ok,:fr)");
        $st->execute(array(
          ':p'    => (int)$plan['id'],
          ':rid'  => (int)$rc['id'],
          ':rc'   => (string)$rc['email'],
          ':mid'  => mb_substr((string)($r['message_id'] ?? ''), 0, 255),
          ':subj' => mb_substr((string)($r['subject'] ?? ''), 0, 255),
          ':ok'   => $r['ok'] ? 1 : 0,
          ':fr'   => mb_substr((string)($r['error'] ?? ''), 0, 255),
        ));
      } catch (Exception $e) {
        error_log('warmup: 写入 warmup_messages 失败: ' . $e->getMessage());
      }
      // 封与封之间也留间隔，避免同秒连发
      if ($i < $batch - 1) {
        sleep(random_int(5, 25));
      }
    }

    $stmt = $pdo->prepare("INSERT INTO `warmup_log`
        (`plan_id`, `run_date`, `planned`, `sent`, `failed`, `detail`)
        VALUES (:pid, CURDATE(), :planned, :sent, :failed, :detail)");
    $stmt->execute(array(
      ':pid'     => (int)$plan['id'],
      ':planned' => (int)$batch,
      ':sent'    => (int)$sent,
      ':failed'  => (int)$failed,
      ':detail'  => mb_substr(implode("\n", $errs), 0, 60000),
    ));

    $stmt = $pdo->prepare("UPDATE `warmup_plans`
                              SET `last_run` = NOW(0),
                                  `sent_total` = `sent_total` + :s,
                                  `fail_total` = `fail_total` + :f
                            WHERE `id` = :id");
    $stmt->execute(array(':s' => $sent, ':f' => $failed, ':id' => (int)$plan['id']));

    $result['sent']   += $sent;
    $result['failed'] += $failed;
    $globalSent       += $sent;
    $result['details'][] = $plan['domain'] . ': sent=' . $sent . ' failed=' . $failed;

    // 失败率过高 → 自动暂停
    $totAll = (int)$plan['sent_all'] + (int)$plan['fail_all'] + $sent + $failed;
    $failAll = (int)$plan['fail_all'] + $failed;
    if ($totAll >= WARMUP_PAUSE_FAIL_MIN && ($failAll / max(1, $totAll)) > WARMUP_PAUSE_FAIL_RATIO) {
      warmup_pause($pdo, $plan['id'],
        'auto-paused: failure rate ' . round($failAll / $totAll * 100) . '% ('
        . $failAll . '/' . $totAll . ')');
      $result['paused'][] = $plan['domain'];
    }
  }

  return $result;
}

/* ==================================================================== *
 * 以下为 2026-10-11 追加：把「养号」从"按天发几封"扩展成可观测、可互动的闭环
 * ==================================================================== */

/**
 * 计算某个计划**今天**的目标发送时刻（时间戳）。
 *
 * 抽出来是为了让调度器与界面用**同一套算法**：
 * 界面要显示"下一封什么时候发"，如果它自己再算一遍，两边必然会漂移，
 * 到时候管理员看到的时刻和实际发送时刻对不上，比不显示更糟。
 */
function warmup_target_ts($plan) {
  $seed = (int)$plan['id'] * 100000 + (int)date('Ymd');
  mt_srand($seed);
  $ts = mt_rand(
    strtotime(date('Y-m-d') . ' ' . WARMUP_WINDOW_START_HOUR . ':00:00'),
    strtotime(date('Y-m-d') . ' ' . WARMUP_WINDOW_END_HOUR . ':00:00')
  );
  mt_srand();
  return $ts;
}

/**
 * 「下一封什么时候发」——供界面展示。
 * @return array array('ts'=>int, 'at'=>'Y-m-d H:i', 'state'=>'pending'|'today_done'|'expired'|'paused'|'waiting_quota')
 */
function warmup_next_send($plan) {
  if ($plan['status'] !== 'active') {
    return array('ts' => 0, 'at' => '', 'state' => 'paused');
  }
  if (warmup_is_expired($plan)) {
    return array('ts' => 0, 'at' => '', 'state' => 'expired');
  }
  $quota = warmup_daily_quota($plan);
  if ((int)$plan['sent_today'] >= $quota) {
    // 今天的配额用完了，下一封在明天窗口内的随机时刻
    $seed = (int)$plan['id'] * 100000 + (int)date('Ymd', strtotime('+1 day'));
    mt_srand($seed);
    $ts = mt_rand(
      strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . WARMUP_WINDOW_START_HOUR . ':00:00'),
      strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . WARMUP_WINDOW_END_HOUR . ':00:00')
    );
    mt_srand();
    return array('ts' => $ts, 'at' => date('Y-m-d H:i', $ts), 'state' => 'today_done');
  }
  $ts = warmup_target_ts($plan);
  return array('ts' => $ts, 'at' => date('Y-m-d H:i', $ts),
               'state' => (time() >= $ts ? 'due' : 'pending'));
}

/**
 * 投递结果统计（进箱率 / 垃圾箱率 / 待判定）。
 *
 * 「垃圾箱率」以前**根本没有可信来源** —— 只能靠外部工具人工抽查。
 * 现在有了收件人账号，就可以真实去对方邮箱里看这封邮件在哪个文件夹。
 *
 * @param int $days 只统计最近 N 天
 */
function warmup_delivery_stats($pdo, $days = 30) {
  $out = array(
    'total' => 0, 'sent_ok' => 0, 'send_fail' => 0,
    'inbox' => 0, 'spam' => 0, 'missing' => 0, 'unknown' => 0,
    'inbox_rate' => null, 'spam_rate' => null,
    'today_total' => 0, 'today_inbox' => 0, 'today_spam' => 0,
    'today_inbox_rate' => null, 'today_spam_rate' => null,
    'replied' => 0, 'rescued' => 0,
  );
  $days = max(1, min(365, (int)$days));
  try {
    $row = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(`send_ok` = 1) AS sent_ok,
        SUM(`send_ok` = 0) AS send_fail,
        SUM(`result` = 'inbox')   AS inbox,
        SUM(`result` = 'spam')    AS spam,
        SUM(`result` = 'missing') AS missing,
        SUM(`result` = 'unknown') AS unknown,
        SUM(`replied_at` IS NOT NULL) AS replied
      FROM `warmup_messages`
      WHERE `sent_at` >= DATE_SUB(NOW(), INTERVAL :d DAY)");
    $row->bindValue(':d', $days, PDO::PARAM_INT);
    $row->execute();
    $r = $row->fetch(PDO::FETCH_ASSOC) ?: array();
    foreach (array('total','sent_ok','send_fail','inbox','spam','missing','unknown','replied') as $k) {
      $out[$k] = (int)($r[$k] ?? 0);
    }
    // 分母只算**已经判定出结果**的：unknown/missing 既不是进箱也不是垃圾箱，
    // 把它们算进分母会让"垃圾箱率"被稀释，看起来比实际好。
    $judged = $out['inbox'] + $out['spam'];
    if ($judged > 0) {
      $out['inbox_rate'] = round($out['inbox'] / $judged * 100, 1);
      $out['spam_rate']  = round($out['spam']  / $judged * 100, 1);
    }
  } catch (Exception $e) {
    error_log('warmup: 投递统计失败: ' . $e->getMessage());
  }

  try {
    $row = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(`result` = 'inbox') AS inbox,
        SUM(`result` = 'spam')  AS spam
      FROM `warmup_messages` WHERE DATE(`sent_at`) = CURDATE()");
    $row->execute();
    $r = $row->fetch(PDO::FETCH_ASSOC) ?: array();
    $out['today_total'] = (int)($r['total'] ?? 0);
    $out['today_inbox'] = (int)($r['inbox'] ?? 0);
    $out['today_spam']  = (int)($r['spam'] ?? 0);
    $j = $out['today_inbox'] + $out['today_spam'];
    if ($j > 0) {
      $out['today_inbox_rate'] = round($out['today_inbox'] / $j * 100, 1);
      $out['today_spam_rate']  = round($out['today_spam']  / $j * 100, 1);
    }
  } catch (Exception $e) {}

  return $out;
}

/** 取一个收件人账号的可解密凭据（供 IMAP/SMTP 使用）；无凭据返回 null */
function warmup_recipient_cred($pdo, $recipientId) {
  $st = $pdo->prepare("SELECT * FROM `warmup_recipients` WHERE `id` = :i LIMIT 1");
  $st->execute(array(':i' => (int)$recipientId));
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r || empty($r['secret_enc'])) { return null; }
  $pw = sb_decrypt($r['secret_enc']);
  if ($pw === '') { return null; }
  $r['password'] = $pw;
  return $r;
}

/**
 * 去收件人邮箱核对「已发出但还没判定」的邮件落在收件箱还是垃圾箱。
 * 由 cron 定期调用。**只处理有凭据的收件人**，其余保持 unknown。
 *
 * @return array 统计
 */
function warmup_check_deliveries($pdo, $limit = 20) {
  $res = array('checked' => 0, 'inbox' => 0, 'spam' => 0, 'missing' => 0, 'errors' => array());
  $limit = max(1, min(100, (int)$limit));

  // 只挑已经过了几分钟（给对方服务器投递 + 归档的时间）的邮件
  $st = $pdo->prepare("SELECT * FROM `warmup_messages`
                        WHERE `send_ok` = 1 AND `result` = 'unknown'
                          AND `message_id` <> ''
                          AND `sent_at` < DATE_SUB(NOW(), INTERVAL 3 MINUTE)
                        ORDER BY `id` ASC LIMIT " . $limit);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) { return $res; }

  // 按收件人分组，一个账号只连一次 IMAP（连接成本高）
  $byRcpt = array();
  foreach ($rows as $m) { $byRcpt[(int)$m['recipient_id']][] = $m; }

  foreach ($byRcpt as $rid => $msgs) {
    $cred = warmup_recipient_cred($pdo, $rid);
    if (!$cred) {
      $res['errors'][] = '收件人 #' . $rid . ' 未配置应用专用密码，跳过';
      continue;
    }
    list($conn, $err) = wwe_connect($cred, 'INBOX');
    if ($conn === false) {
      $res['errors'][] = $cred['email'] . ': ' . $err;
      continue;
    }
    try {
      $spam = wwe_spam_folder($conn, wwe_ref($cred));
      foreach ($msgs as $m) {
        $f = wwe_find_message($conn, $m['message_id'], $spam, wwe_ref($cred));
        $state = $f['state'];
        if ($state === 'missing') {
          // 找不到就先记 missing 但不落库为终态 —— 可能只是还没投递完，
          // 下一轮还会再查（只有超过 3 天仍是 missing 才当终态处理）
          $age = time() - strtotime($m['sent_at']);
          if ($age < 3 * 86400) {
            $res['checked']++;
            continue;
          }
        }
        $up = $pdo->prepare("UPDATE `warmup_messages` SET `result` = :r, `checked_at` = NOW(0)
                              WHERE `id` = :id");
        $up->execute(array(':r' => $state, ':id' => (int)$m['id']));
        if ($state === 'inbox')   { $res['inbox']++; }
        if ($state === 'spam')    { $res['spam']++; }
        if ($state === 'missing') { $res['missing']++; }
        $res['checked']++;
      }
    } finally {
      @imap_close($conn);
    }
    // 顺带更新账号的统计与最后检查时间
    try {
      $u = $pdo->prepare("UPDATE `warmup_recipients` r SET
          r.`last_check` = NOW(0),
          r.`inbox_count` = (SELECT COUNT(*) FROM `warmup_messages` m
                             WHERE m.`recipient_id` = r.`id` AND m.`result` = 'inbox'),
          r.`spam_count`  = (SELECT COUNT(*) FROM `warmup_messages` m
                             WHERE m.`recipient_id` = r.`id` AND m.`result` = 'spam')
        WHERE r.`id` = :id");
      $u->execute(array(':id' => (int)$rid));
    } catch (Exception $e) {}
  }
  return $res;
}

/**
 * 互动：把落进垃圾箱的邮件救回收件箱；按配置代收件人回复。
 * 这两件事都是 Gmail 判定里权重很高的**正向信号**，也是"养号"真正的目的。
 *
 * 安全/风控约束（防把收件人账号玩坏）：
 *   · 每个账号每轮最多 1 次回复
 *   · 只回复我们自己发出去的、且**已判定为 inbox** 的邮件
 *   · 同一封只回复一次（replied_at 非空即跳过）
 */
function warmup_interact($pdo, $limit = 10) {
  $res = array('rescued' => 0, 'replied' => 0, 'errors' => array());
  $limit = max(1, min(50, (int)$limit));

  // ---- 1) 垃圾箱救援 ----
  $st = $pdo->prepare("SELECT m.*, r.`rescue_spam`, r.`email` AS rcpt_email
                         FROM `warmup_messages` m
                         JOIN `warmup_recipients` r ON r.`id` = m.`recipient_id`
                        WHERE m.`result` = 'spam' AND r.`rescue_spam` = 1 AND r.`active` = 1
                        ORDER BY m.`id` ASC LIMIT " . $limit);
  $st->execute();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $cred = warmup_recipient_cred($pdo, (int)$m['recipient_id']);
    if (!$cred) { continue; }
    list($ok, $msg) = wwe_rescue_from_spam($cred, $m['message_id']);
    if ($ok) {
      $res['rescued']++;
      $pdo->prepare("UPDATE `warmup_messages` SET `result` = 'inbox', `checked_at` = NOW(0)
                      WHERE `id` = :id")->execute(array(':id' => (int)$m['id']));
    } else {
      $res['errors'][] = $m['rcpt_email'] . ' 救援失败: ' . $msg;
    }
  }

  // ---- 2) 自动回复（每账号每轮最多 1 封）----
  $st = $pdo->prepare("SELECT m.*, p.`sender`, p.`domain`, r.`auto_reply`
                         FROM `warmup_messages` m
                         JOIN `warmup_plans` p ON p.`id` = m.`plan_id`
                         JOIN `warmup_recipients` r ON r.`id` = m.`recipient_id`
                        WHERE m.`result` = 'inbox' AND m.`replied_at` IS NULL
                          AND r.`auto_reply` = 1 AND r.`active` = 1
                        ORDER BY m.`id` ASC LIMIT " . ($limit * 2));
  $st->execute();
  $donePerRcpt = array();
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $rid = (int)$m['recipient_id'];
    if (!empty($donePerRcpt[$rid])) { continue; }
    $cred = warmup_recipient_cred($pdo, $rid);
    if (!$cred) { continue; }
    $donePerRcpt[$rid] = true;

    $subject = (stripos((string)$m['subject'], 'Re:') === 0)
      ? (string)$m['subject'] : ('Re: ' . (string)$m['subject']);
    list($ok, $err) = wwe_send_reply(
      $cred, (string)$m['sender'], $subject,
      warmup_reply_body($m['domain']), (string)$m['message_id']
    );
    if ($ok) {
      $res['replied']++;
      $pdo->prepare("UPDATE `warmup_messages` SET `replied_at` = NOW(0) WHERE `id` = :id")
          ->execute(array(':id' => (int)$m['id']));
      $pdo->prepare("UPDATE `warmup_recipients` SET `reply_count` = `reply_count` + 1
                      WHERE `id` = :id")->execute(array(':id' => $rid));
    } else {
      $res['errors'][] = $m['rcpt_email'] . ' 回复失败: ' . $err;
    }
  }
  return $res;
}

/** 回复正文：固定模板，不含任何用户可控字段（防头注入 / 防被当成营销内容） */
function warmup_reply_body($domain) {
  return "Thanks for the note — got it.\n\n"
       . "Everything looks fine on my side. Feel free to follow up if anything changes.\n\n"
       . "Best,\n";
}
