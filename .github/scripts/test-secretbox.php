<?php
/**
 * 凭据保管箱（secretbox）—— 离线的加解密与安全属性测试
 *
 * 守的是什么：
 *   1. **落库的是密文**，明文不能出现在库里/日志里/任何可回显的地方。
 *      这是「Client Secret / IMAP 密码可以显示」与「邮箱登录密码只能重置」
 *      两类凭据的分界线 —— 混了就是把可逆凭据当不可逆存，或反之。
 *   2. **同明文两次加密结果不同**（每次新随机 IV）。若实现退化成固定 IV
 *      或 ECB，密文相同就泄露"两条记录用了同一个密码"这一事实。
 *   3. **篡改必被发现**：AES-256-GCM 带认证标签，改一个字节必须解不出来，
 *      而不是返回一堆垃圾字节（那会让调用方把乱码当密码去登录）。
 *   4. **密钥只在 0600 的目录里**，且不落在 Web 根下。
 *
 * 全程离线：用 WARMUP_SECRET_DIR 指向临时目录（sb_dir() 支持该环境变量覆盖），
 * 不碰数据库、不碰网络。
 *
 * 用法：php .github/scripts/test-secretbox.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$F = $ROOT . '/data/web/inc/functions.secretbox.inc.php';
if (!file_exists($F)) { fwrite(STDERR, "缺 $F\n"); exit(1); }

// 必须**在 include 之前**设好：sb_key() 有 static 缓存，
// 一旦用了默认目录再改环境变量就不生效了。
$tmp = sys_get_temp_dir() . '/sb-test-' . bin2hex(random_bytes(4));
putenv('WARMUP_SECRET_DIR=' . $tmp);

require_once $F;

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = [];

function assert_true($c, string $msg): void {
  if ($c) { $GLOBALS['__pass']++; }
  else {
    $GLOBALS['__fail']++;
    $GLOBALS['__failures'][] = $msg;
    echo "  ✗ $msg\n";
  }
}

echo "\n== 1) 密钥与目录 ==";
assert_true(sb_dir() === $tmp, 'sb_dir() 跟随 WARMUP_SECRET_DIR（可指向临时目录，便于离线测试）');
$key = sb_key();
assert_true(strlen($key) === 32, '密钥是 32 字节（AES-256）');
assert_true(file_exists($tmp . '/secret.key'), '密钥文件已自动生成');
$perm = substr(sprintf('%o', fileperms($tmp . '/secret.key')), -3);
assert_true($perm === '600', '密钥文件权限是 0600（只有属主可读写），实际 ' . $perm);
assert_true(strpos(realpath($tmp . '/secret.key'), '/web/') === false,
            '密钥不在 Web 根目录下（不能通过 HTTP 取到）');

echo "\n== 2) 加密产出的是密文 ==";
$plain = 'SuperSecret!2026-' . bin2hex(random_bytes(4));
$enc = sb_encrypt($plain);
assert_true(is_string($enc) && $enc !== '', 'sb_encrypt 返回非空字符串');
assert_true(strpos((string)$enc, $plain) === false, '密文里不含明文原样片段');
$raw = base64_decode((string)$enc, true);
assert_true($raw !== false, '密文是合法 base64');
assert_true(strlen((string)$raw) > 28, '密文比 iv(12)+tag(16) 头部更长（确实带了内容）');

echo "\n== 3) 随机 IV：同明文两次结果必须不同 ==";
$enc2 = sb_encrypt($plain);
assert_true($enc !== $enc2, '两次加密结果不同（IV 随机；相同就意味着 IV 固定/ECB）');

echo "\n== 4) 往返一致 ==";
assert_true(sb_decrypt((string)$enc) === $plain, 'sb_decrypt 能取回原明文');
assert_true(sb_decrypt((string)$enc2) === $plain, '另一段密文同样能取回（不是靠撞运气）');

echo "\n== 5) GCM 认证标签：篡改必须被发现 ==";
$t = base64_decode((string)$enc);
$t[strlen($t) - 1] = chr(ord($t[strlen($t) - 1]) ^ 0xFF);
$bad = sb_decrypt(base64_encode($t));
assert_true($bad !== $plain, '改动密文最后一个字节 → 解不出原明文（不会被静默接受）');
// 改 IV 头部同样必须失败
$t2 = base64_decode((string)$enc);
$t2[0] = chr(ord($t2[0]) ^ 0xFF);
assert_true(sb_decrypt(base64_encode($t2)) !== $plain, '改动 IV → 同样解不出原明文');

echo "\n== 6) 非 base64 / 空输入不得崩 ==";
assert_true(sb_decrypt('!!!not-base64!!!') === '', '非 base64 输入返回空串（不抛异常）');
assert_true(sb_decrypt('') === '', '空输入返回空串');
assert_true(sb_decrypt(base64_encode(str_repeat("\0", 8))) === '', '长度不足的输入返回空串');

echo "\n== 7) 长文本（IMAP 密码/凭据可能很长）==";
$long = str_repeat('Aa1!', 300);   // 1200 字节
assert_true(sb_decrypt(sb_encrypt($long)) === $long, '1200 字节的明文能原样往返');

// 收尾：删掉临时密钥目录，别在 /tmp 里留密钥
foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp);

echo "\n" . str_repeat('-', 50) . "\n";
echo "通过: {$GLOBALS['__pass']}    失败: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
  foreach ($GLOBALS['__failures'] as $m) { echo "  - $m\n"; echo "::error::$m\n"; }
  exit(1);
}
echo "全部通过\n";
exit(0);
