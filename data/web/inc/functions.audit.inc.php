<?php
/**
 * mailcow-dockerized-Max — 管理员操作审计
 *
 * 目的：把"谁、何时、从哪、对什么、做了什么、结果如何"全部留痕，
 * 便于事后排查与责任落实。
 *
 * 设计原则：
 *   - 只记事实，不记私有推理；密码类字段一律不入库
 *   - 记录失败也要落库（排查时"失败记录"往往比成功记录更有用）
 *   - 任何异常都不影响主流程（审计是旁路，不能因审计失败而让业务失败）
 */

/**
 * 写一条审计记录。
 *
 * @param PDO    $pdo
 * @param string $action      动作标识，如 domain.add / mailbox.add / mailbox.reset_pw
 * @param string $targetType  对象类型，如 domain / mailbox / relay / warmup
 * @param string $target      对象标识，如 example.com 或 user@example.com
 * @param string $result      ok | fail
 * @param mixed  $detail      附加信息（数组会被 json 编码），
 *                            **调用方必须确保其中不含密码等敏感值**
 * @return void
 */
function audit_log($pdo, $action, $targetType = '', $target = '', $result = 'ok', $detail = null) {
  try {
    $actor = '';
    if (isset($_SESSION['mailcow_cc_username'])) {
      $actor = (string)$_SESSION['mailcow_cc_username'];
    } elseif (isset($_SESSION['mailcow_cc_role'])) {
      $actor = 'role:' . (string)$_SESSION['mailcow_cc_role'];
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

    if (is_array($detail) || is_object($detail)) {
      $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $detail = $detail === null ? null : mb_substr((string)$detail, 0, 60000);

    $stmt = $pdo->prepare(
      "INSERT INTO `admin_audit_log`
         (`actor`, `actor_ip`, `action`, `target_type`, `target`, `result`, `detail`)
       VALUES (:a, :ip, :act, :tt, :tg, :r, :d)"
    );
    $stmt->execute(array(
      ':a'  => mb_substr($actor, 0, 255),
      ':ip' => mb_substr($ip, 0, 64),
      ':act'=> mb_substr((string)$action, 0, 64),
      ':tt' => mb_substr((string)$targetType, 0, 32),
      ':tg' => mb_substr((string)$target, 0, 255),
      ':r'  => ($result === 'fail' ? 'fail' : 'ok'),
      ':d'  => $detail,
    ));
  } catch (Exception $e) {
    // 审计失败不得影响业务；仅写 error_log 供排查
    error_log('audit_log failed: ' . $e->getMessage());
  } catch (Error $e) {
    error_log('audit_log error: ' . $e->getMessage());
  }
}

/**
 * 读取审计日志（最新在前）。
 *
 * @param PDO   $pdo
 * @param int   $limit  条数上限
 * @param array $filter 可选过滤：action / target / actor
 */
function audit_recent($pdo, $limit = 100, $filter = array()) {
  $limit = max(1, min(500, (int)$limit));
  $where = array();
  $args  = array();
  foreach (array('action', 'actor') as $k) {
    if (!empty($filter[$k])) {
      $where[] = "`$k` = :$k";
      $args[":$k"] = (string)$filter[$k];
    }
  }
  if (!empty($filter['target'])) {
    $where[] = "`target` LIKE :target";
    $args[':target'] = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), (string)$filter['target']) . '%';
  }
  $sql = "SELECT * FROM `admin_audit_log`";
  if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
  }
  $sql .= " ORDER BY `id` DESC LIMIT " . $limit;
  $stmt = $pdo->prepare($sql);
  $stmt->execute($args);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 审计日志条数（用于概览）。
 */
function audit_count($pdo) {
  try {
    return (int)$pdo->query("SELECT COUNT(*) FROM `admin_audit_log`")->fetchColumn();
  } catch (Exception $e) {
    return 0;
  }
}
