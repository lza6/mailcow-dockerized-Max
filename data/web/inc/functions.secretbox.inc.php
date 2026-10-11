<?php
/**
 * mailcow-dockerized-Max — 凭据保管箱（可再取出的敏感凭据）
 *
 * 为什么需要它，以及它和「邮箱登录密码」的区别（这两件事经常被混为一谈）：
 *
 *   · 邮箱登录密码在 mailcow 里是 bcrypt **单向哈希**，数学上不可逆 ——
 *     所以任何界面都**不可能**显示明文，只能重置。这是正确的安全设计。
 *   · 但养号需要用**收件人自己的** IMAP/SMTP 账号去做两件必须拿到明文的事：
 *       ① 判断我们发出去的邮件进了收件箱还是垃圾箱；
 *       ② 代替收件人回复，制造真实的互动信号。
 *     这类凭据因此必须可逆保存 —— 统一放进这个保管箱。
 *
 * 密钥管理：
 *   · 密钥文件：data/conf/warmup/secret.key（容器内 /warmup-secrets/secret.key）
 *   · 首次使用自动生成 32 字节随机密钥，权限 0600
 *   · **绝不进仓库**（已写入 .gitignore），也不放在 Web 根目录下
 *   · 密钥丢失 = 已保存的凭据不可恢复，需要重新填写；不会让系统不可用
 *
 * 算法：AES-256-GCM
 *   · 带认证标签：密文被篡改会**解密失败**，而不是返回一堆垃圾字节
 *   · 每次加密使用新的随机 IV，同样的明文两次加密结果不同
 * 编码：base64( iv[12] || tag[16] || ciphertext )
 */

/** 密钥目录（容器内），可用环境变量覆盖以便本地测试 */
function sb_dir() {
  $d = getenv('WARMUP_SECRET_DIR');
  if (!$d) { $d = '/warmup-secrets'; }
  return rtrim($d, '/');
}

function sb_key_path() {
  return sb_dir() . '/secret.key';
}

/**
 * 读取密钥；不存在则生成。失败返回空串（调用方据此报"保管箱不可用"，而不是崩溃）。
 */
function sb_key() {
  static $cached = null;
  if ($cached !== null) { return $cached; }
  $path = sb_key_path();

  if (is_readable($path)) {
    $k = @file_get_contents($path);
    if (is_string($k) && strlen($k) >= 32) {
      $cached = substr($k, 0, 32);
      return $cached;
    }
    error_log('secretbox: 密钥文件存在但内容不合法（长度 ' . (is_string($k) ? strlen($k) : -1) . '）');
    $cached = '';
    return $cached;
  }

  $dir = sb_dir();
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
      error_log('secretbox: 无法创建密钥目录 ' . $dir);
      $cached = '';
      return $cached;
    }
  }
  try {
    $k = random_bytes(32);
  } catch (Exception $e) {
    error_log('secretbox: 无法生成随机密钥: ' . $e->getMessage());
    $cached = '';
    return $cached;
  }
  if (@file_put_contents($path, $k) === false) {
    error_log('secretbox: 无法写入密钥文件 ' . $path);
    $cached = '';
    return $cached;
  }
  @chmod($path, 0600);
  $cached = $k;
  return $cached;
}

/** 保管箱是否可用（openssl 扩展存在 + 密钥可读可写） */
function sb_available() {
  if (!function_exists('openssl_encrypt')) { return false; }
  return sb_key() !== '';
}

/**
 * 加密。失败返回空串（**不要**把明文当作"降级"返回 —— 那等于静默泄露）。
 */
function sb_encrypt($plain) {
  $key = sb_key();
  if ($key === '') { return ''; }
  $iv  = random_bytes(12);
  $tag = '';
  $ct  = @openssl_encrypt((string)$plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
  if ($ct === false || $tag === '') { return ''; }
  return base64_encode($iv . $tag . $ct);
}

/**
 * 解密。失败（密钥不对 / 密文被改 / 格式不对）返回空串，并记一条日志 ——
 * 不要用 false 之外的"猜测值"兜底。
 */
function sb_decrypt($blob) {
  $key = sb_key();
  if ($key === '' || $blob === null || $blob === '') { return ''; }
  $raw = base64_decode((string)$blob, true);
  if ($raw === false || strlen($raw) < 29) { return ''; }
  $iv  = substr($raw, 0, 12);
  $tag = substr($raw, 12, 16);
  $ct  = substr($raw, 28);
  $pt  = @openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($pt === false) {
    error_log('secretbox: 解密失败（密钥不匹配或密文被篡改）');
    return '';
  }
  return $pt;
}

/** 只展示"是否已设置"，永远不把值吐出去（用于列表页） */
function sb_is_set($blob) {
  return $blob !== null && $blob !== '';
}
