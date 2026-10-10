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
    $mail->setFrom($sender, $domain);
    $mail->addAddress((string)$recipient['email']);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->send();
    return array('ok' => true, 'error' => '');
  } catch (Exception $e) {
    // PHPMailer 异常信息可能含 SMTP 细节，截断后记录
    return array('ok' => false, 'error' => mb_substr($mail->ErrorInfo ?: $e->getMessage(), 0, 200));
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
      // 用"计划id+日期"派生一个稳定的当日目标时刻，避免每次调用都变
      $seed = (int)$plan['id'] * 100000 + (int)date('Ymd');
      mt_srand($seed);
      $targetTs = mt_rand(
        strtotime(date('Y-m-d') . ' ' . WARMUP_WINDOW_START_HOUR . ':00:00'),
        strtotime(date('Y-m-d') . ' ' . WARMUP_WINDOW_END_HOUR . ':00:00')
      );
      mt_srand();
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
