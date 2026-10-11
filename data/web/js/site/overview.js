/**
 * mailcow-dockerized-Max — 域名总览前端
 *
 * 性能：首屏数据由服务端内联在 window.__ov_initial，本脚本**在加载时立即渲染**，
 *      不发起 AJAX、不闪「加载中」。AJAX 仅用于刷新与各项操作。
 *
 * 安全：内容含外部可控字符串，一律用 .text() 转义后再插入 DOM。
 */
$(document).ready(function () {
  'use strict';

  var API = '/inc/ajax/overview.php';
  var RP  = '/inc/ajax/resend_pool.php';   // 号池（账号/域名绑定/额度）—— 复用既有端点
  var WU  = '/inc/ajax/warmup.php';        // 养号（计划/收件人/日志）—— 复用既有端点
  var WEBMAIL = '/SOGo/';

  function csrf() {
    if (typeof window.__ov_csrf === 'string' && window.__ov_csrf) { return window.__ov_csrf; }
    return (typeof window.csrf_token !== 'undefined') ? window.csrf_token : '';
  }
  function updateCsrf(d) {
    if (d && typeof d.csrf === 'string' && d.csrf) { window.__ov_csrf = d.csrf; }
  }
  // esc() 既要用于文本位，也要用于属性位（如 data-domain="…"）。
  // jQuery 的 .html() 只转义 & < >，**不转义引号**——属性位下值里出现 "
  // 就能闭合属性并注入（带引号的 local part，如 "a"@x.com，能通过
  // FILTER_VALIDATE_EMAIL）。所以这里额外转义两种引号：
  // 文本位渲染 &quot; / &#39; 浏览器会还原成原字符，无副作用。
  function esc(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html()
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  // url 省略时打本页自己的端点；号池/养号走各自既有端点（它们同样回传轮换后的 csrf）
  function post(data, url) {
    data.csrf_token = csrf();
    return $.ajax({ type: 'POST', url: url || API, data: data, dataType: 'json' })
      .always(function (d) { updateCsrf(d); });
  }
  function notice(msg, isErr) {
    var cls = isErr ? 'alert-danger' : 'alert-success';
    $('#ov_notice').html('<div class="alert ' + cls + ' alert-dismissible fade show py-2 mb-2">' +
      esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>');
    setTimeout(function () { $('#ov_notice').empty(); }, 6000);
  }
  function badge(ok, label) {
    if (ok === null || ok === undefined) { return '<span class="badge bg-secondary">未检测</span>'; }
    return ok ? '<span class="badge bg-success">' + esc(label) + '</span>'
              : '<span class="badge bg-danger">' + esc(label) + '</span>';
  }
  /* ------------------------------------------------------------------ *
   * ⚠️ 为什么需要一个 body() 包装，而不是直接 $('#ov_xxx_body')
   *
   * mailcow 的 footer.js（随主题一起打包）里有一条**全局**规则：
   *     $('tbody').filter(function(){ return $(this).children().length < 1; }).remove();
   * 它在页面解析阶段就执行，会把所有**空的 tbody 直接删掉**。
   *
   * 本页是「服务端内联数据 + 前端填表」，tbody 天然是空的 —— 于是整张表的
   * 容器被删掉，JS 再 .html() 也写不进任何东西。症状很有迷惑性：
   * 顶部统计卡（div）正常显示「16 个域名」，但下面所有列表全空。
   *
   * 两层防护：
   *   1) 模板里每个 tbody 都放了占位行（非空 → 不会被那条规则删）；
   *   2) 这里再兜一层：万一容器真的没了，就用同表的 <table> 重建一个 tbody。
   * 只做第 1 层也能工作，但第 2 层能保证以后有人改模板时不会再踩同一个坑。
   * ------------------------------------------------------------------ */
  function body(sel, tableSel) {
    var $b = $(sel);
    if ($b.length) { return $b; }
    var $t = $(tableSel);
    if (!$t.length) { return $b; }              // 连表都没有，交给调用方静默失败
    $b = $('<tbody>').attr('id', String(sel).replace(/^#/, ''));
    $t.append($b);
    return $b;
  }

  // 出站中继展示：优先显示**号池账号名**，主机名放小字；
  // 只配了中继但没进号池时退化为显示主机名；两者都没有就是直发。
  /* ------------------------------------------------------------------ *
   * 以「某个邮箱」的身份打开 Webmail。
   *
   * ⚠️ 不能直接用 /SOGo/：SOGo 的登录态由 mailcow 会话驱动
   * （nginx 的 auth_request -> /sogo-auth-verify -> sogo-auth.php，
   *  通过 x-webobjects-remote-user 传当前登录用户），
   * 所以点 /SOGo/ 进去的永远是**你当前登录的那个人**（管理员就是管理员自己）。
   * mailcow 官方的做法是走 /sogo-auth.php?login=<邮箱>（见官方 mailbox.js），
   * 它会把会话切换到该邮箱（dual-login）—— 想回管理员身份就退出登录。
   * 该入口需要 mailcow.conf 里 ALLOW_ADMIN_EMAIL_LOGIN=y。
   * ------------------------------------------------------------------ */
  function sogoLogin(user) {
    return '/sogo-auth.php?login=' + encodeURIComponent(user);
  }
  function relayCell(acct, host) {
    if (acct && acct.label) {
      return '<span class="badge bg-primary">' + esc(acct.label) + '</span>' +
             (host ? '<div><small class="text-muted">' + esc(host) + '</small></div>' : '');
    }
    if (host) { return '<code class="small">' + esc(host) + '</code>'; }
    return '<span class="text-muted">直发</span>';
  }

  function fmtAge(d) {
    if (d === null || d === undefined) { return '<span class="text-muted">未知</span>'; }
    if (d >= 365) { return '<span class="text-success">' + (d / 365).toFixed(1) + ' 年</span>'; }
    if (d >= 90)  { return '<span class="text-success">' + d + ' 天</span>'; }
    if (d >= 30)  { return '<span class="text-warning">' + d + ' 天</span>'; }
    return '<span class="text-danger fw-bold">' + d + ' 天</span>';
  }

  function renderStats(d) {
    var s = d.stats || {};
    $('#ov_stat_domains').text(s.domains);
    $('#ov_stat_mailboxes').text(s.mailboxes);
    $('#ov_stat_msg').text(s.messages);
    $('#ov_stat_new').text(s.today_new);
    var ab = $('#ov_stat_abnormal').text(s.abnormal);
    ab.toggleClass('text-danger fw-bold', s.abnormal > 0);
    $('#ov_stat_today').text(s.today_sent);

    // 养号进箱率 / 垃圾箱率（来自收件人邮箱的真实核对，详见养号卡片）
    var ws = (d.warmup && d.warmup.stats) || {};
    function rateHtml(rate, good) {
      if (rate === null || rate === undefined) { return '<span class="text-muted fs-6">未判定</span>'; }
      var cls = good ? (rate >= 90 ? 'text-success' : (rate >= 70 ? 'text-warning' : 'text-danger'))
                     : (rate <= 5 ? 'text-success' : (rate <= 20 ? 'text-warning' : 'text-danger'));
      return '<span class="' + cls + '">' + rate + '%</span>';
    }
    $('#ov_stat_ibox').html(rateHtml(ws.inbox_rate, true));
    $('#ov_stat_ibox_sub').text('进箱 ' + (ws.inbox || 0) + ' / 已判定 ' +
      ((ws.inbox || 0) + (ws.spam || 0)) + (ws.unknown ? ('，待判定 ' + ws.unknown) : ''));
    $('#ov_stat_spam').html(rateHtml(ws.spam_rate, false));
    $('#ov_stat_spam_sub').text('垃圾箱 ' + (ws.spam || 0) + ' 封');
    if ((ws.inbox || 0) + (ws.spam || 0) === 0) {
      $('#ov_stat_ibox_sub').html('<span class="text-danger">需先给收件账号配置应用专用密码</span>');
      $('#ov_stat_spam_sub').text('');
    }

    // 号池今日可发总量 = 各启用账号 daily_quota 之和
    var r = d.relay || {};
    $('#ov_stat_pool').text(r.quota_total === undefined ? '—' : r.quota_total);
    $('#ov_stat_pool_sub').text((r.active_accounts || 0) + ' 个启用账号的日额度合计');
    var left = $('#ov_stat_pool_left').text(r.left === undefined ? '—' : r.left);
    left.toggleClass('text-danger fw-bold', (r.left || 0) <= 0);
    $('#ov_stat_pool_left_sub').text('已绑 ' + (r.used || 0) + ' / 容量 ' + (r.capacity || 0));

    // 数据来源说明 —— 每个数字都要能说清"从哪来"，否则无法判断它可不可信
    $('#ov_data_sources').html(
      '<b>数据来源</b>：' +
      '域名/邮箱/邮件数 — 本地数据库（<code>domain</code> / <code>mailbox</code> / <code>quota2</code>，不调 doveadm 所以快）；' +
      '注册时长 — RDAP（实测最快的根域注册局接口）；' +
      'MX / SPF / DKIM / DMARC — 由「体检」动作实时 <code>dns_get_record()</code> 查询后写缓存 1 小时，页面只读缓存（所以首屏不发起 DNS）；' +
      '养号进箱率/垃圾箱率 — 用收件账号的 IMAP 到它自己的收件箱与垃圾箱里按 <code>Message-ID</code> 反查得出；' +
      '号池额度 — 本地号池配置（<code>resend_accounts.daily_quota</code>），非 Resend 官方实时余额。');
  }

  function renderDomains(rows) {
    if (!rows.length) {
      body('#ov_domains_body', '#ov_tbl_domains').html('<tr><td colspan="8" class="text-center text-muted py-4">暂无域名</td></tr>');
      return;
    }
    var h = '';
    rows.forEach(function (d) {
      var mxCell;
      if (!d.checked) {
        mxCell = '<span class="badge bg-secondary">未检测</span>';
      } else if (d.mx_ok) {
        mxCell = '<span class="badge bg-success">指向本机</span>';
      } else {
        mxCell = '<span class="badge bg-danger">指向别处</span>' +
                 '<div><small class="text-danger">' + esc((d.mx || []).join(', ') || '(无 MX)') + '</small></div>';
      }

      var auth = !d.checked ? '<span class="badge bg-secondary">未检测</span>'
        : badge(d.spf_ok, 'SPF') + ' ' + badge(d.dkim_ok, 'DKIM') + ' ' + badge(d.dmarc_ok, 'DMARC');

      var wu = '<span class="text-muted">—</span>';
      if (d.warmup) {
        var w = d.warmup;
        var st = (w.status === 'active') ? '进行中' : (w.status === 'paused' ? '已暂停' : w.status);
        var stCls = (w.status === 'active') ? 'bg-success' : (w.status === 'paused' ? 'bg-warning text-dark' : 'bg-secondary');
        wu = '<span class="badge ' + stCls + '">' + esc(st) + '</span>' +
             '<div><small class="text-muted">第' + w.day + '/' + w.total_days + '天 · 今日' + w.sent_today + '/' + w.quota + '</small></div>' +
             '<div><small class="text-muted">累计' + w.sent_total + '成功/' + w.fail_total + '失败</small></div>';
      }

      var name = esc(d.domain);
      if (parseInt(d.active, 10) !== 1) { name += ' <span class="badge bg-secondary">停用</span>'; }

      h += '<tr>' +
        '<td>' + name + (d.checked ? '<div><small class="text-muted">检测 ' + esc(d.checked_at) + '</small></div>' : '') + '</td>' +
        '<td>' + fmtAge(d.age_days) + '</td>' +
        '<td>' + mxCell + '</td>' +
        '<td>' + auth + '</td>' +
        '<td>' + d.messages + ' <small class="text-muted">封</small></td>' +
        '<td>' + relayCell(d.relay_acct, d.relay) + '</td>' +
        '<td>' + wu + '</td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-primary ov_check" data-domain="' + esc(d.domain) + '">体检</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_goto_mbox" data-domain="' + esc(d.domain) + '">邮箱</button>' +
        '</td>' +
      '</tr>';
    });
    body('#ov_domains_body', '#ov_tbl_domains').html(h);
  }

  function renderMailboxes(rows) {
    if (!rows.length) {
      body('#ov_mbox_body', '#ov_tbl_mbox').html('<tr><td colspan="9" class="text-center text-muted py-4">暂无邮箱</td></tr>');
      return;
    }
    var h = '';
    rows.forEach(function (m) {
      var q = (parseInt(m.quota, 10) === 0) ? '不限' : (Math.round(m.quota / 1048576) + ' MB');
      var used = m.bytes ? (m.bytes / 1048576).toFixed(1) + ' MB' : '0 MB';
      // 出站账号由所属域的绑定决定（mailcow 的中继是域级的），同一域下相同
      var acct = (m.relay_acct && m.relay_acct.label) ? m.relay_acct.label : '';
      h += '<tr class="ov_mbox_row" data-search="' +
             esc((m.username + ' ' + m.domain + ' ' + acct).toLowerCase()) + '">' +
        '<td><code>' + esc(m.username) + '</code></td>' +
        '<td>' + esc(m.domain) + '</td>' +
        '<td>' + (parseInt(m.active, 10) === 1
                    ? '<span class="badge bg-success">启用</span>'
                    : '<span class="badge bg-secondary">停用</span>') + '</td>' +
        '<td>' + (function () {
          // 邮箱级中继：0 = 继承所属域；非 0 时优先于域级设置（mailcow 原生能力）
          var cur = parseInt(m.relay_override, 10) || 0;
          var domLabel = DOMAIN_RELAY[m.domain] || '直发';
          var o = '<option value="0"' + (cur === 0 ? ' selected' : '') + '>继承域名（' + esc(domLabel) + '）</option>';
          RELAYHOSTS.forEach(function (rh) {
            o += '<option value="' + rh.id + '"' + (cur === rh.id ? ' selected' : '') + '>' +
                 esc(rh.label) + (rh.active === 1 ? '' : '（已停用）') + '</option>';
          });
          var cls = cur > 0 ? 'text-warning fw-bold' : '';
          return '<select class="form-select form-select-sm ov_mbox_relay" data-user="' + esc(m.username) +
                 '" style="min-width:11rem">' + o + '</select>' +
                 (cur > 0 ? '<div><small class="' + cls + '">已覆盖域级设置</small></div>' : '');
        })() + '</td>' +
        '<td>' + m.messages + ' <small class="text-muted">(' + esc(used) + ')</small></td>' +
        '<td>' + (m.last_received
                    ? ('<small>' + esc(m.last_received) + '</small>' +
                       '<div><small class="' + (m.today_received > 0 ? 'text-success fw-bold' : 'text-muted') +
                       '">今日 ' + (m.today_received || 0) + ' 封</small></div>')
                    : '<span class="text-muted" title="由 dovecot 侧的采集任务提供，每 10 分钟刷新一次">—</span>') + '</td>' +
        '<td>' + (m.last_warmup && m.last_warmup.last_at
                    ? ('<small>' + esc(m.last_warmup.last_at) + '</small>' +
                       '<div><small class="text-muted">累计 ' + m.last_warmup.n + ' 封</small></div>')
                    : '<span class="text-muted">—</span>') + '</td>' +
        '<td>' + esc(q) + '</td>' +
        '<td class="text-nowrap">' +
          '<a class="btn btn-sm btn-outline-primary" href="' + sogoLogin(m.username) + '">以该邮箱进 Webmail</a> ' +
          '<button class="btn btn-sm btn-outline-warning ov_reset_pw" data-user="' + esc(m.username) + '">重置密码</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_copy" data-user="' + esc(m.username) + '">复制</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_toggle" data-user="' + esc(m.username) + '">启/停</button>' +
        '</td>' +
      '</tr>';
    });
    body('#ov_mbox_body', '#ov_tbl_mbox').html(h);
  }

  // 明文密钥只在前端缓存里，默认不请求；点了「显示」才向后端要一次（后端会记审计）
  var revealed = {};

  // 可选的中继主机（邮箱级覆盖用）与「域名 → 域级中继」的显示名
  // mailcow 原生支持 mailbox.attributes.relayhost，这是正规能力不是自造
  // 养号状态快照（供「立即执行」估算发送量用）
  var WU_STATE = null;
  var RELAYHOSTS = [];
  var DOMAIN_RELAY = {};

  function keyCell(id, masked, len) {
    if (revealed['resend:' + id]) {
      return '<code class="small">' + esc(revealed['resend:' + id]) + '</code>' +
             ' <button class="btn btn-sm btn-link p-0 ov_hide_key" data-id="' + id + '">隐藏</button>';
    }
    if (!masked) { return '<span class="text-muted">未设置</span>'; }
    return '<code class="small">' + esc(masked) + '</code> <small class="text-muted">(' + len + ')</small> ' +
           '<button class="btn btn-sm btn-link p-0 ov_show_key" data-id="' + id + '">显示</button>';
  }

  function renderRelay(r) {
    if (!r) {
      $('#ov_pool_card').hide();
      return;
    }
    $('#ov_pool_card').show();

    $('#ov_pool_accounts').text(r.active_accounts);
    $('#ov_pool_used').text(r.used);
    $('#ov_pool_capacity').text(r.capacity);
    var left = $('#ov_pool_left').text(r.left);
    left.toggleClass('text-danger fw-bold', r.left <= 0);
    $('#ov_pool_quota').text(r.quota_total);
    $('#ov_pool_limit').text(r.limit);
    $('#ov_pool_limit_in').val(r.limit);
    $('#ov_cf_state').html(r.cf_configured
      ? '<span class="badge bg-success">已配置</span> <code class="small">' + esc(r.cf_masked) + '</code>' +
        ' <small class="text-muted">(' + r.cf_len + ')</small>'
      : '<span class="badge bg-danger">未配置</span>');

    var h = '';
    if (!r.accounts.length) {
      h = '<tr><td colspan="8" class="text-center text-muted py-3">暂无中继账号，请在下方添加</td></tr>';
    }
    r.accounts.forEach(function (a) {
      var pct = a.limit > 0 ? Math.round(a.domains / a.limit * 100) : 0;
      var barCls = pct >= 100 ? 'bg-danger' : (pct >= 67 ? 'bg-warning' : 'bg-success');
      h += '<tr>' +
        '<td>' + a.id + '</td>' +
        '<td>' + esc(a.label) + '</td>' +
        '<td>' + keyCell(a.id, a.key_masked, a.key_len) + '</td>' +
        '<td>' + (a.active === 1 ? '<span class="badge bg-success">启用</span>'
                                 : '<span class="badge bg-secondary">停用</span>') + '</td>' +
        '<td>' + a.domains + ' / ' + a.limit +
          '<div class="progress" style="height:4px"><div class="progress-bar ' + barCls +
          '" style="width:' + Math.min(100, pct) + '%"></div></div></td>' +
        '<td>' + a.daily_quota + '</td>' +
        '<td><small>' + esc(a.last_check || '未校验') + '</small>' +
          (a.check_status ? '<div><small class="text-muted">' + esc(a.check_status) + '</small></div>' : '') + '</td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-secondary ov_acc_toggle" data-id="' + a.id +
            '" data-active="' + a.active + '">' + (a.active === 1 ? '停用' : '启用') + '</button> ' +
          '<button class="btn btn-sm btn-outline-danger ov_acc_del" data-id="' + a.id +
            '" data-label="' + esc(a.label) + '">删除</button>' +
        '</td></tr>';
    });
    body('#ov_pool_body', '#ov_tbl_pool').html(h);

    // 绑定表
    var b = '';
    if (!r.bindings.length) {
      b = '<tr><td colspan="5" class="text-center text-muted py-3">还没有域名绑定到号池</td></tr>';
    }
    r.bindings.forEach(function (x) {
      var st = String(x.status || '');
      var cls = st === 'verified' ? 'bg-success'
              : (st === 'pending' || st === 'not_started' ? 'bg-warning text-dark' : 'bg-danger');
      b += '<tr>' +
        '<td><code>' + esc(x.domain) + '</code></td>' +
        '<td>' + (x.account_label ? esc(x.account_label) : '<span class="text-muted">—</span>') + '</td>' +
        '<td><span class="badge ' + cls + '">' + esc(st || '未知') + '</span></td>' +
        '<td><small>' + esc(x.verified_at || '—') + '</small></td>' +
        '<td><button class="btn btn-sm btn-outline-danger ov_unbind" data-domain="' + esc(x.domain) + '">解绑</button></td>' +
      '</tr>';
    });
    body('#ov_bind_body', '#ov_tbl_bind').html(b);

    // 绑定表单的两个下拉
    var accSel = $('#ov_bind_account').empty();
    r.accounts.filter(function (a) { return a.active === 1; }).forEach(function (a) {
      accSel.append($('<option>').val(a.id).text(a.label + '（' + a.domains + '/' + a.limit + '）'));
    });
  }

  function renderWarmup(w) {
    if (!w) { $('#ov_warmup_card').hide(); return; }
    $('#ov_warmup_card').show();

    var st = w.stats || {};
    $('#ov_wu_rule').text('发送窗口 ' + w.window + ' · 单次最多 ' + w.max_per_run +
                          ' 封 · 全局每日上限 ' + w.global_cap + ' 封');
    $('#ov_wu_today').text(w.sent_today);
    $('#ov_wu_cap').text(w.global_cap);
    $('#ov_wu_sent').text(w.sent_total);
    $('#ov_wu_fail').text(w.fail_total);

    // 进箱率 / 垃圾箱率 —— 来自收件人邮箱里的**真实核对**，不是估算
    function rateCell(rate, okGood) {
      if (rate === null || rate === undefined) { return '<span class="text-muted">未判定</span>'; }
      var cls = okGood ? (rate >= 90 ? 'text-success' : (rate >= 70 ? 'text-warning' : 'text-danger'))
                       : (rate <= 5 ? 'text-success' : (rate <= 20 ? 'text-warning' : 'text-danger'));
      return '<span class="' + cls + ' fw-bold">' + rate + '%</span>';
    }
    $('#ov_wu_ibox_rate').html(rateCell(st.inbox_rate, true));
    $('#ov_wu_ibox_sub').text('进箱 ' + (st.inbox || 0) + ' / 已判定 ' +
      ((st.inbox || 0) + (st.spam || 0)));
    $('#ov_wu_spam_rate').html(rateCell(st.spam_rate, false));
    $('#ov_wu_spam_sub').text('垃圾箱 ' + (st.spam || 0) + ' / 待判定 ' + (st.unknown || 0));
    $('#ov_wu_today_split').text((st.today_inbox || 0) + ' / ' + (st.today_spam || 0));
    $('#ov_wu_today_rate').text(
      (st.today_inbox_rate === null || st.today_inbox_rate === undefined)
        ? '今日暂无判定' : ('今日进箱率 ' + st.today_inbox_rate + '%'));
    $('#ov_wu_replied').text(st.replied || 0);
    $('#ov_wu_rescued').text(st.inbox || 0);
    $('#ov_wu_box_state').html(w.secretbox
      ? '<span class="text-success">凭据保管箱可用</span>'
      : '<span class="text-danger">凭据保管箱不可用（无法保存应用专用密码）</span>');

    $('#ov_wu_howto').html(
      '判定来源：系统把每封养号邮件的 <code>Message-ID</code> 记下来，再用收件人账号的 IMAP ' +
      '去它自己的收件箱/垃圾箱里反查落点。' +
      '<b>只统计已判定的邮件</b>（待判定/找不到不算进分母，否则垃圾箱率会被稀释）。' +
      '未配置应用专用密码的收件账号无法判定。');

    // ---- 计划表 ----
    var h = '';
    if (!w.plans.length) {
      h = '<tr><td colspan="8" class="text-center text-muted py-3">还没有养号计划</td></tr>';
    }
    w.plans.forEach(function (p) {
      var stCls = p.status === 'active' ? 'bg-success'
                : (p.status === 'paused' ? 'bg-warning text-dark' : 'bg-secondary');
      var stTxt = p.status === 'active' ? '进行中' : (p.status === 'paused' ? '已暂停' : p.status);
      var today = '<span class="text-success fw-bold">' + p.sent_today + '</span>';
      if (!p.ran_today) {
        today = '<span class="text-danger fw-bold">' + p.sent_today + '</span>' +
                '<div><small class="text-danger">今日未运行</small></div>';
      }
      // 下一封：直接显示调度器算出来的时刻，并标出状态
      var next = '<span class="text-muted">—</span>';
      if (p.next_at) {
        var nc = p.next_state === 'due' ? 'text-danger fw-bold'
               : (p.next_state === 'pending' ? 'text-success' : 'text-muted');
        var nt = { due: '已到点，等待执行', pending: '待发送',
                   today_done: '今日配额已用完', expired: '已到期', paused: '已暂停' }[p.next_state] || p.next_state;
        next = '<span class="' + nc + '">' + esc(p.next_at) + '</span>' +
               '<div><small class="text-muted">' + esc(nt) + '</small></div>';
      }
      h += '<tr>' +
        '<td><code class="small">' + esc(p.sender) + '</code>' +
          '<div><small class="text-muted">' + esc(p.domain) + '</small></div></td>' +
        '<td>第 ' + p.day + ' / ' + p.total_days + ' 天' +
          (p.days_left > 0 ? '<div><small class="text-muted">剩 ' + p.days_left + ' 天</small></div>'
                           : '<div><small class="text-danger">已到期</small></div>') + '</td>' +
        '<td class="text-nowrap">' +
          '<div class="input-group input-group-sm" style="width:8.5rem">' +
            '<input type="number" class="form-control ov_wu_q" data-id="' + p.id +
              '" value="' + p.start_count + '" min="1" max="50" title="起量">' +
            '<input type="number" class="form-control ov_wu_s" data-id="' + p.id +
              '" value="' + p.step + '" min="0" max="20" title="日增">' +
            '<input type="number" class="form-control ov_wu_m" data-id="' + p.id +
              '" value="' + p.max_count + '" min="1" max="200" title="上限">' +
          '</div>' +
          '<div><small class="text-muted">当前今日配额 <b>' + p.day_quota + '</b> 封（起量/日增/上限）</small></div>' +
          '<button class="btn btn-sm btn-link p-0 ov_wu_quota_set" data-id="' + p.id + '">保存配额</button>' +
        '</td>' +
        '<td>' + today + ' / ' + p.day_quota + '</td>' +
        '<td>' + next + '</td>' +
        '<td>' + p.sent_all + ' <span class="text-muted">成</span> / ' + p.fail_all + ' <span class="text-muted">败</span></td>' +
        '<td><span class="badge ' + stCls + '">' + esc(stTxt) + '</span>' +
          (p.pause_reason ? '<div><small class="text-danger">' + esc(p.pause_reason) + '</small></div>' : '') +
          // 上一次为什么没发出去 —— 没有它只能显示「今日未运行」，
          // 分不清是「没到随机时刻」「配额为 0」还是「被别的计划抢光了全局额度」
          (p.skip_reason ? '<div><small class="text-muted">上次跳过：' + esc(p.skip_reason) + '</small></div>' : '') +
        '</td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-secondary ov_wu_status" data-id="' + p.id +
            '" data-status="' + (p.status === 'active' ? 'paused' : 'active') + '">' +
            (p.status === 'active' ? '暂停' : '恢复') + '</button> ' +
          '<button class="btn btn-sm btn-outline-danger ov_wu_del_plan" data-id="' + p.id + '">删除</button>' +
        '</td></tr>';
    });
    body('#ov_wu_plan_body', '#ov_tbl_wuplan').html(h);

    // ---- 收件人账号 ----
    var r2 = '';
    if (!w.recipients.length) {
      r2 = '<tr><td colspan="6" class="text-center text-muted py-3">' +
           '还没有收件人，养号不会发出任何邮件</td></tr>';
    }
    w.recipients.forEach(function (x) {
      var vs = String(x.verify_status || '');
      var vCls = vs.indexOf('通过') >= 0 ? 'bg-success' : (vs === '' ? 'bg-secondary' : 'bg-danger');
      r2 += '<tr>' +
        '<td><code class="small">' + esc(x.email) + '</code>' +
          (x.label ? '<div><small class="text-muted">' + esc(x.label) + '</small></div>' : '') + '</td>' +
        '<td>' + (x.has_secret
          ? '<span class="badge bg-success">已配置</span>'
          : '<span class="badge bg-secondary">未配置</span>') + '</td>' +
        '<td><span class="badge ' + vCls + '">' + esc(vs || '未验证') + '</span>' +
          (x.last_verify ? '<div><small class="text-muted">' + esc(x.last_verify) + '</small></div>' : '') + '</td>' +
        '<td>' + x.inbox_count + ' / ' + x.spam_count + ' / ' + x.reply_count +
          (x.last_check ? '<div><small class="text-muted">核对于 ' + esc(x.last_check) + '</small></div>' : '') + '</td>' +
        '<td>' + (x.auto_reply === 1 ? '<span class="badge bg-primary">自动回复</span>' : '') +
          (x.rescue_spam === 1 ? ' <span class="badge bg-info text-dark">垃圾箱救援</span>' : '') +
          (x.auto_reply === 0 && x.rescue_spam === 0 ? '<span class="text-muted">—</span>' : '') + '</td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-primary ov_wu_rc_verify" data-id="' + x.id + '">验证 IMAP</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_wu_rc_edit" data-id="' + x.id +
            '" data-email="' + esc(x.email) + '">改密码</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_wu_rc_toggle" data-id="' + x.id + '">启/停</button> ' +
          '<button class="btn btn-sm btn-outline-danger ov_wu_rc_del" data-id="' + x.id + '">删除</button>' +
          '<div class="mt-1">' +
            '<button class="btn btn-sm btn-link p-0 ov_wu_rc_ar" data-id="' + x.id +
              '" data-v="' + (x.auto_reply === 1 ? 0 : 1) + '">' +
              (x.auto_reply === 1 ? '关闭自动回复' : '开启自动回复') + '</button> · ' +
            '<button class="btn btn-sm btn-link p-0 ov_wu_rc_rs" data-id="' + x.id +
              '" data-v="' + (x.rescue_spam === 1 ? 0 : 1) + '">' +
              (x.rescue_spam === 1 ? '关闭垃圾箱救援' : '开启垃圾箱救援') + '</button>' +
          '</div>' +
        '</td></tr>';
    });
    body('#ov_wu_rc_body', '#ov_tbl_wurc').html(r2);

    // ---- 逐封明细 ----
    var mm = '';
    var msgs = w.messages || [];
    if (!msgs.length) {
      mm = '<tr><td colspan="6" class="text-center text-muted py-3">' +
           '还没有逐封记录（下一次养号发送后就会出现）</td></tr>';
    }
    msgs.forEach(function (x) {
      var res = String(x.result || 'unknown');
      var rCls = res === 'inbox' ? 'bg-success'
               : (res === 'spam' ? 'bg-danger'
               : (res === 'missing' ? 'bg-warning text-dark' : 'bg-secondary'));
      var rTxt = { inbox: '进收件箱', spam: '进垃圾箱', missing: '找不到', unknown: '待判定' }[res] || res;
      if (parseInt(x.send_ok, 10) !== 1) { rCls = 'bg-danger'; rTxt = '发送失败'; }
      mm += '<tr>' +
        '<td><small>' + esc(x.sent_at) + '</small></td>' +
        '<td><small>' + esc(x.sender || ('计划#' + x.plan_id)) + '</small></td>' +
        '<td><small>' + esc(x.recipient) + '</small></td>' +
        '<td><span class="badge ' + rCls + '">' + rTxt + '</span>' +
          (x.checked_at ? '<div><small class="text-muted">' + esc(x.checked_at) + '</small></div>' : '') +
          (x.fail_reason ? '<div><small class="text-danger">' + esc(x.fail_reason) + '</small></div>' : '') + '</td>' +
        '<td>' + (x.replied_at ? '<span class="badge bg-success">已回复</span>' +
            '<div><small class="text-muted">' + esc(x.replied_at) + '</small></div>'
          : '<span class="text-muted">—</span>') + '</td>' +
        '<td><small class="text-muted">' + esc(x.message_id || '—') + '</small></td>' +
      '</tr>';
    });
    body('#ov_wu_msg_body', '#ov_tbl_wumsg').html(mm);

    // ---- 按天汇总 ----
    var l = '';
    if (!w.logs.length) {
      l = '<tr><td colspan="6" class="text-center text-muted py-3">暂无发送记录</td></tr>';
    }
    w.logs.forEach(function (x) {
      l += '<tr>' +
        '<td><small>' + esc(x.run_at) + '</small></td>' +
        '<td><small>' + esc(x.sender || ('计划#' + x.plan_id)) + '</small></td>' +
        '<td>' + x.planned + '</td>' +
        '<td>' + (x.sent > 0 ? '<span class="text-success fw-bold">' + x.sent + '</span>' : '0') + '</td>' +
        '<td>' + (x.failed > 0 ? '<span class="text-danger fw-bold">' + x.failed + '</span>' : '0') + '</td>' +
        '<td><small class="text-muted">' + esc(String(x.detail || '').slice(0, 300)) + '</small></td>' +
      '</tr>';
    });
    body('#ov_wu_log_body', '#ov_tbl_wulog').html(l);
  }
  function renderAudit(rows) {
    if (!rows || !rows.length) {
      body('#ov_audit_body', '#ov_tbl_audit').html('<tr><td colspan="6" class="text-center text-muted py-4">暂无记录</td></tr>');
      return;
    }
    var h = '';
    rows.forEach(function (a) {
      h += '<tr>' +
        '<td><small>' + esc(a.created) + '</small></td>' +
        '<td><small>' + esc(a.actor) + (a.actor_ip ? '<div class="text-muted">' + esc(a.actor_ip) + '</div>' : '') + '</small></td>' +
        '<td><code class="small">' + esc(a.action) + '</code></td>' +
        '<td><small>' + esc(a.target_type) + (a.target ? ' / ' + esc(a.target) : '') + '</small></td>' +
        '<td>' + ((a.result === 'ok') ? '<span class="badge bg-success">成功</span>'
                                      : '<span class="badge bg-danger">失败</span>') + '</td>' +
        '<td><small class="text-muted">' + esc((a.detail || '').slice(0, 120)) + '</small></td>' +
      '</tr>';
    });
    body('#ov_audit_body', '#ov_tbl_audit').html(h);
  }

  // 下拉框：只重建一次选项，避免每次刷新把用户已选的项冲掉
  function fillSelect(sel, items, cur) {
    var $s = $(sel);
    var want = items.map(function (x) { return String(x.v); }).join('|');
    if ($s.data('sig') === want) { return; }
    var keep = $s.val() || cur || '';
    $s.empty();
    items.forEach(function (x) { $s.append($('<option>').val(x.v).text(x.t)); });
    $s.data('sig', want);
    if (keep && $s.find('option[value="' + keep.replace(/"/g, '\\"') + '"]').length) { $s.val(keep); }
  }

  function apply(d) {
    if (!d || !d.ok) { notice((d && d.message) || '加载失败', true); return; }
    updateCsrf(d);
    renderStats(d);
    RELAYHOSTS = d.relayhosts || [];
    DOMAIN_RELAY = {};
    (d.domains || []).forEach(function (x) {
      DOMAIN_RELAY[x.domain] = (x.relay_acct && x.relay_acct.label)
        ? x.relay_acct.label
        : (x.relay ? x.relay : '直发');
    });
    renderDomains(d.domains);
    renderMailboxes(d.mailboxes);
    renderRelay(d.relay);
    WU_STATE = d.warmup;
    renderWarmup(d.warmup);
    if (d.audit) { renderAudit(d.audit); }

    // 绑定域名下拉 = mailcow 里的域名；发件邮箱下拉 = 邮箱账号列表
    fillSelect('#ov_bind_domain', (d.domains || []).map(function (x) {
      return { v: x.domain, t: x.domain };
    }));
    fillSelect('#ov_wu_sender', (d.mailboxes || []).filter(function (m) {
      return parseInt(m.active, 10) === 1;
    }).map(function (m) {
      return { v: m.username, t: m.username };
    }));
  }

  function load() {
    $.getJSON(API, { action: 'overview' })
      .done(apply)
      .fail(function (x) { notice('加载失败（HTTP ' + x.status + '）', true); });
  }

  // ---- 首屏：立即用内联数据渲染，不等 AJAX ----
  if (window.__ov_initial) { apply(window.__ov_initial); }

  // ---- 事件 ----
  $('#ov_refresh, #ov_refresh_audit').on('click', load);

  $('#ov_check_all').on('click', function () {
    var btn = $(this).prop('disabled', true).text('体检中…');
    post({ action: 'check_all' })
      .done(function (d) {
        if (d.ok) { notice('体检完成：' + d.checked + ' 个域，异常 ' + d.abnormal + ' 个'); load(); }
        else { notice(d.message || '体检失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { btn.prop('disabled', false).text('一键体检全部'); });
  });

  $(document).on('click', '.ov_check', function () {
    var dom = $(this).data('domain');
    var btn = $(this).prop('disabled', true).text('…');
    post({ action: 'check_domain', domain: dom })
      .done(function (d) { d.ok ? (notice(dom + ' 体检完成'), load()) : notice(d.message || '体检失败', true); })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { btn.prop('disabled', false).text('体检'); });
  });

  $(document).on('click', '.ov_goto_mbox', function () {
    var dom = $(this).data('domain');
    $('#ov_mbox_filter').val(dom).trigger('input');
    $('html,body').animate({ scrollTop: body('#ov_mbox_body', '#ov_tbl_mbox').offset().top - 120 }, 250);
  });

  $(document).on('click', '.ov_reset_pw', function () {
    var u = $(this).data('user');
    if (!confirm('确定重置 ' + u + ' 的密码？将生成新的随机密码。')) { return; }
    post({ action: 'reset_mailbox_password', username: u })
      .done(function (d) {
        if (d.ok) {
          alert('已重置：' + u + '\n\n新密码（只显示这一次，请立即保存）：\n' + d.password);
          load();
        } else { notice(d.message || '重置失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $(document).on('click', '.ov_copy', function () {
    var u = $(this).data('user');
    var txt = u + '\t' + location.origin + WEBMAIL;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(txt).then(function () { notice('已复制：' + u); });
    } else { window.prompt('复制以下内容：', txt); }
  });

  $(document).on('click', '.ov_toggle', function () {
    var u = $(this).data('user');
    post({ action: 'toggle_mailbox', username: u })
      .done(function (d) { d.ok ? load() : notice(d.message, true); })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $('#ov_gen_pw').on('click', function () {
    var a = new Uint8Array(8);
    (window.crypto || window.msCrypto).getRandomValues(a);
    $('#ov_new_mbox_pw').val(Array.prototype.map.call(a, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join(''));
  });

  $('#ov_add_domain').on('click', function () {
    var dom = $('#ov_new_domain').val();
    var mboxes = $('#ov_new_domain_mboxes').val();
    var defq   = $('#ov_new_domain_defquota').val();
    var dq     = $('#ov_new_domain_quota').val();
    // 前端先做一次同样的约束检查，避免无意义往返
    if (parseInt(defq, 10) > parseInt(dq, 10)) {
      notice('单邮箱配额不能大于域总配额', true);
      return;
    }
    post({
      action: 'add_domain',
      domain: dom,
      description: $('#ov_new_domain_desc').val(),
      mailboxes: mboxes,
      defquota: defq,
      maxquota: defq,
      quota: dq
    }).done(function (d) {
      if (d.ok) { notice('域名已添加'); $('#ov_new_domain,#ov_new_domain_desc').val(''); load(); }
      else { notice(d.message || '添加失败', true); }
    }).fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  // 邮箱级中继切换（mailcow 原生能力：mailbox.attributes.relayhost）
  $(document).on('change', '.ov_mbox_relay', function () {
    var u = $(this).data('user');
    var rid = $(this).val();
    var sel = $(this).prop('disabled', true);
    post({ action: 'set_mailbox_relay', username: u, relayhost_id: rid })
      .done(function (d) {
        if (d && d.ok) { notice(u + ' 的出站中继已设为：' + (d.label || '')); load(); }
        else { notice((d && d.message) || '设置失败', true); load(); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); load(); })
      .always(function () { sel.prop('disabled', false); });
  });

  $('#ov_add_mbox').on('click', function () {
    post({
      action: 'add_mailbox',
      username: $('#ov_new_mbox').val(),
      password: $('#ov_new_mbox_pw').val(),
      quota: $('#ov_new_mbox_quota').val()
    }).done(function (d) {
      if (d.ok) { notice('邮箱已添加'); $('#ov_new_mbox,#ov_new_mbox_pw').val(''); load(); }
      else { notice(d.message || '添加失败', true); }
    }).fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $('#ov_mbox_filter').on('input', function () {
    var q = ($(this).val() || '').toLowerCase();
    $('.ov_mbox_row').each(function () {
      var s = $(this).data('search') || '';
      $(this).toggle(!q || s.indexOf(q) !== -1);
    });
  });

  /* ==================== 号池 / CF ==================== */
  // 统一动作封装：成功提示 + 刷新首屏数据
  function act(url, data, okMsg) {
    return post(data, url)
      .done(function (d) {
        if (d && d.ok) { notice(okMsg || d.message || '已执行'); load(); }
        else { notice((d && d.message) || '操作失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  }

  $('#ov_pool_sync').on('click', function () {
    var b = $(this).prop('disabled', true).text('同步中…');
    post({ action: 'sync' }, RP)
      .done(function (d) {
        if (d && d.ok) { notice('同步完成，更新 ' + d.updated + ' 个域名'); load(); }
        else { notice((d && d.message) || '同步失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('同步状态'); });
  });

  // 显示/隐藏明文 Key —— 明文只在前端点一次要一次，后端每次都会记审计
  $(document).on('click', '.ov_show_key', function () {
    var id = $(this).data('id');
    post({ action: 'reveal_secret', kind: 'resend', id: id })
      .done(function (d) {
        if (d && d.ok) { revealed['resend:' + id] = d.value; load(); }
        else { notice((d && d.message) || '无法读取', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });
  $(document).on('click', '.ov_hide_key', function () {
    delete revealed['resend:' + $(this).data('id')];
    load();
  });

  // 批量显示/隐藏全部账号 Key。
  // 每把 Key 都是单独向服务端要一次（reveal_secret），因此**每把都会单独落审计** ——
  // 不存在"点一下就悄悄拿到全部密钥"的路径。
  $('#ov_pool_reveal').on('click', function () {
    var hasResend = Object.keys(revealed).some(function (k) { return k.indexOf('resend:') === 0; });
    if (hasResend) {
      Object.keys(revealed).forEach(function (k) {
        if (k.indexOf('resend:') === 0) { delete revealed[k]; }
      });
      load();
      return;
    }
    var ids = [];
    $('#ov_pool_body tr').each(function () {
      var id = $(this).find('.ov_show_key').data('id');
      if (id) { ids.push(id); }
    });
    if (!ids.length) { notice('没有可显示的账号 Key', true); return; }
    var btn = $(this).prop('disabled', true).text('读取中…');
    var i = 0, fail = 0;
    (function next() {
      if (i >= ids.length) {
        btn.prop('disabled', false).text('显示/隐藏 Key');
        notice('已显示 ' + (ids.length - fail) + ' 把 Key（每把均已记入审计）', fail > 0);
        load();
        return;
      }
      var id = ids[i++];
      post({ action: 'reveal_secret', kind: 'resend', id: id })
        .done(function (d) {
          if (d && d.ok) { revealed['resend:' + id] = d.value; } else { fail++; }
        })
        .fail(function () { fail++; })
        .always(next);
    })();
  });

  $('#ov_pool_limit_set').on('click', function () {
    act(RP, { action: 'set_limit', limit: $('#ov_pool_limit_in').val() }, '域名上限已更新');
  });
  $('#ov_cf_save').on('click', function () {
    var t = $('#ov_cf_token').val();
    if (!t && !confirm('留空将清除已保存的 Cloudflare Token，确定？')) { return; }
    act(RP, { action: 'set_cf_token', cf_token: t }, 'Cloudflare Token 已保存');
    $('#ov_cf_token').val('');
  });
  $('#ov_acc_check').on('click', function () {
    var k = $('#ov_acc_key').val();
    if (!k) { notice('请先填入 API Key', true); return; }
    var b = $(this).prop('disabled', true).text('测试中…');
    post({ action: 'check_key', api_key: k }, RP)
      .done(function (d) { notice((d && d.message) || (d && d.ok ? 'Key 有效' : 'Key 无效'), !(d && d.ok)); })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('先测 Key'); });
  });
  $('#ov_acc_add').on('click', function () {
    var k = $('#ov_acc_key').val();
    if (!k) { notice('请填入 API Key', true); return; }
    act(RP, { action: 'add_account', api_key: k, label: $('#ov_acc_label').val() }, '账号已添加');
    $('#ov_acc_key').val('');
  });
  $(document).on('click', '.ov_acc_toggle', function () {
    var id = $(this).data('id');
    var next = parseInt($(this).data('active'), 10) === 1 ? 0 : 1;
    act(RP, { action: 'edit_account', id: id, active: next }, next === 1 ? '账号已启用' : '账号已停用');
  });
  $(document).on('click', '.ov_acc_del', function () {
    var id = $(this).data('id'), label = $(this).data('label');
    if (!confirm('确定删除账号「' + label + '」？仅在该账号未绑定任何域名时可删除。')) { return; }
    act(RP, { action: 'delete_account', id: id }, '账号已删除');
  });
  $('#ov_bind').on('click', function () {
    var d = $('#ov_bind_domain').val(), a = $('#ov_bind_account').val();
    if (!d || !a) { notice('请选择域名与账号', true); return; }
    var b = $(this).prop('disabled', true).text('绑定中…');
    post({ action: 'assign', domain: d, account_id: a }, RP)
      .done(function (x) {
        if (x && x.ok) {
          var w = x.dns && x.dns.written ? ('，已写 DNS ' + x.dns.written + ' 条') : '';
          notice(d + ' → ' + (x.message || '已绑定') + w); load();
        } else { notice((x && x.message) || '绑定失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('绑定'); });
  });
  $('#ov_auto_assign').on('click', function () {
    if (!confirm('将把 mailcow 中所有启用域名自动分配到容量未满的账号，继续？')) { return; }
    var b = $(this).prop('disabled', true).text('分配中…');
    post({ action: 'auto_assign' }, RP)
      .done(function (d) {
        if (d && d.ok) {
          notice((d.message || '') + (d.failed && d.failed.length ? ('；失败：' + d.failed.join('、')) : ''));
          load();
        } else { notice((d && d.message) || '分配失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('一键自动分配'); });
  });
  $(document).on('click', '.ov_unbind', function () {
    var d = $(this).data('domain');
    if (!confirm('解除 ' + d + ' 的号池绑定？该域将还原为直发，同时删除 Resend 侧域名。')) { return; }
    act(RP, { action: 'remove_domain', domain: d }, d + ' 已解绑');
  });

  /* ==================== 养号 ==================== */
  $('#ov_wu_refresh').on('click', load);
  $('#ov_wu_run').on('click', function () {
    // 「立即执行一次」会**跳过随机时刻**（force），按各计划剩余配额 × 单次上限发出。
    // 在计划多、配额足的时候，一次点击能把全局日上限（默认 30 封）当场发完 ——
    // 而这恰好是本模块自己认定的「短时间批量突发 → 进垃圾箱」主因。
    // 所以：把预计发送量摆出来让管理员自己确认，不静默猛发。
    var w = WU_STATE || {};
    var plans = w.plans || [];
    var cap = w.global_cap || 30;
    var per = w.max_per_run || 3;
    var remainGlobal = Math.max(0, cap - (w.sent_today || 0));
    var willSend = 0;
    plans.forEach(function (p) {
      if (p.status !== 'active') { return; }
      var left = Math.max(0, (p.day_quota || 0) - (p.sent_today || 0));
      willSend += Math.min(left, per);
    });
    willSend = Math.min(willSend, remainGlobal);
    var tip = '「立即执行一次」会**跳过随机时刻**立刻发送。\n\n' +
              '本次预计发送 ' + willSend + ' 封（全局今日还能发 ' + remainGlobal + ' / ' + cap + ' 封）。\n\n' +
              '注意：短时间批量突发是进垃圾箱的主要原因，这一步只建议在测试或补发时用；\n' +
              '日常养号请让定时任务按随机时刻自己跑。\n\n确定继续？';
    if (!confirm(tip)) { return; }
    if (willSend === 0) { notice('当前没有可发送的配额', true); return; }
    var b = $(this).prop('disabled', true).text('执行中…');
    post({ action: 'run_now' }, WU)
      .done(function (d) {
        if (d && d.ok) {
          var r = d.result || {};
          notice('已执行一轮：发送 ' + (r.sent !== undefined ? r.sent : '?') +
                 ' 封，失败 ' + (r.failed !== undefined ? r.failed : '?') + ' 封');
          load();
        } else { notice((d && d.message) || '执行失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('立即执行一次'); });
  });
  $('#ov_wu_add_plan').on('click', function () {
    var sender = $('#ov_wu_sender').val();
    if (!sender) { notice('请选择发件邮箱', true); return; }
    var dom = sender.split('@')[1];
    act(WU, {
      action: 'add_plan', domain: dom, sender: sender,
      start_count: $('#ov_wu_start').val(), step: $('#ov_wu_step').val(),
      max_count: $('#ov_wu_max').val(), total_days: $('#ov_wu_days').val()
    }, '养号计划已添加');
  });
  $(document).on('click', '.ov_wu_status', function () {
    act(WU, { action: 'set_status', id: $(this).data('id'), status: $(this).data('status') });
  });
  $(document).on('click', '.ov_wu_del_plan', function () {
    if (!confirm('删除该养号计划？（历史日志保留）')) { return; }
    act(WU, { action: 'delete_plan', id: $(this).data('id') }, '计划已删除');
  });
  $('#ov_wu_rc_add').on('click', function () {
    var e = $('#ov_wu_rc_email').val();
    if (!e) { notice('请填入收件地址', true); return; }
    var b = $(this).prop('disabled', true).text('添加并验证中…');
    post({
      action: 'add_recipient',
      email: e,
      label: $('#ov_wu_rc_label').val(),
      secret: $('#ov_wu_rc_secret').val(),
      auto_reply: $('#ov_wu_rc_autoreply').is(':checked') ? 1 : 0,
      rescue_spam: $('#ov_wu_rc_rescue').is(':checked') ? 1 : 0
    }, WU)
      .done(function (d) {
        if (d && d.ok) {
          var v = d.verify;
          if (v) { notice(v.ok ? ('已添加，' + v.message) : ('已添加，但 ' + v.message), !v.ok); }
          else { notice('收件人已添加（未填应用专用密码，无法核对进箱/垃圾箱）'); }
          $('#ov_wu_rc_email,#ov_wu_rc_label,#ov_wu_rc_secret').val('');
          $('#ov_wu_rc_autoreply,#ov_wu_rc_rescue').prop('checked', false);
          load();
        } else { notice((d && d.message) || '添加失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('添加并验证'); });
  });
  $(document).on('click', '.ov_wu_rc_toggle', function () {
    act(WU, { action: 'toggle_recipient', id: $(this).data('id') });
  });
  $(document).on('click', '.ov_wu_rc_del', function () {
    if (!confirm('删除该收件人？')) { return; }
    act(WU, { action: 'delete_recipient', id: $(this).data('id') }, '收件人已删除');
  });

  /* ============ 养号扩展：投递核对 / 互动 / 配额 / 收件人账号 ============ */

  // 手动触发「去收件人邮箱核对进箱还是垃圾箱」
  $('#ov_wu_check_deliv').on('click', function () {
    var b = $(this).prop('disabled', true).text('核对中…');
    post({ action: 'check_deliveries' }, WU)
      .done(function (d) {
        if (d && d.ok) {
          var r = d.result || {};
          var msg = '核对完成：检查 ' + (r.checked || 0) + ' 封，进箱 ' + (r.inbox || 0) +
                    '，垃圾箱 ' + (r.spam || 0) + '，找不到 ' + (r.missing || 0);
          if (r.errors && r.errors.length) { msg += '；' + r.errors.length + ' 条错误：' + r.errors[0]; }
          notice(msg, !!(r.errors && r.errors.length));
          load();
        } else { notice((d && d.message) || '核对失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('核对投递（进箱/垃圾箱）'); });
  });

  // 手动触发互动：垃圾箱救援 + 代回信
  $('#ov_wu_interact').on('click', function () {
    var b = $(this).prop('disabled', true).text('执行中…');
    post({ action: 'run_interact' }, WU)
      .done(function (d) {
        if (d && d.ok) {
          var r = d.result || {};
          var msg = '互动完成：救援 ' + (r.rescued || 0) + ' 封，回复 ' + (r.replied || 0) + ' 封';
          if (r.errors && r.errors.length) { msg += '；' + r.errors.length + ' 条错误：' + r.errors[0]; }
          notice(msg, !!(r.errors && r.errors.length));
          load();
        } else { notice((d && d.message) || '执行失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('执行互动'); });
  });

  // 实时改「今日配额」：起量 / 日增 / 上限 三个数直接决定当天的配额
  $(document).on('click', '.ov_wu_quota_set', function () {
    var id = $(this).data('id');
    var $row = $(this).closest('tr');
    var start = $row.find('.ov_wu_q[data-id="' + id + '"]').val();
    var step  = $row.find('.ov_wu_s[data-id="' + id + '"]').val();
    var max   = $row.find('.ov_wu_m[data-id="' + id + '"]').val();
    if (parseInt(start, 10) > parseInt(max, 10)) {
      notice('起量不能大于上限', true); return;
    }
    act(WU, { action: 'edit_plan', id: id, start_count: start, step: step, max_count: max },
        '配额已更新（起量 ' + start + ' / 日增 ' + step + ' / 上限 ' + max + '）');
  });

  // 一键为「还没有养号计划的活跃邮箱」批量建计划
  $('#ov_wu_add_all').on('click', function () {
    if (!confirm('将为所有「还没有养号计划」的启用邮箱各建一个计划（起量 2 / 日增 1 / 上限 20 / 30 天）。继续？')) { return; }
    var b = $(this).prop('disabled', true).text('创建中…');
    post({ action: 'add_plans_for_all' }, WU)
      .done(function (d) {
        if (d && d.ok) {
          var msg = '已创建 ' + (d.created || 0) + ' 个计划';
          if (d.skipped && d.skipped.length) { msg += '；跳过 ' + d.skipped.length + ' 个：' + d.skipped[0]; }
          notice(msg);
          load();
        } else { notice((d && d.message) || '创建失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('一键为全部未养号的域创建计划'); });
  });

  // 「怎么获取 Google 应用专用密码」—— 直接把步骤摆出来，不用去翻文档
  $('#ov_wu_gp_help').on('click', function () {
    alert(
      '如何获取 Google 应用专用密码（应用专用密码 / App Password）\n\n' +
      '前提：该 Google 账号必须已开启「两步验证」，否则 Google 不会提供应用专用密码。\n\n' +
      '1. 用该账号登录 https://myaccount.google.com/\n' +
      '2. 左侧「安全性」→ 两步验证（如未开启先开启）\n' +
      '3. 页面底部「应用专用密码」（也可直接打开 https://myaccount.google.com/apppasswords）\n' +
      '4. 输入一个名称，例如 mailcow-warmup，点「创建」\n' +
      '5. Google 会显示 16 位密码（形如 abcd efgh ijkl mnop）。**空格可以去掉**\n' +
      '6. 把这 16 位填到本页「应用专用密码」框里\n\n' +
      '另外需要确认（一次性）：\n' +
      '· Gmail 设置 → 转发和 POP/IMAP → 启用 IMAP\n' +
      '· 若账号开启了「高级保护」，应用专用密码不可用，需要换一个普通账号\n\n' +
      '安全说明：该密码会用 AES-256-GCM 加密后存到服务器（密钥在 data/conf/warmup/secret.key，不进仓库），\n' +
      '仅用于读取该账号的收件箱以核对进箱/垃圾箱、以及代替它回信。'
    );
  });

  // 重新验证收件人 IMAP 凭据
  $(document).on('click', '.ov_wu_rc_verify', function () {
    var b = $(this).prop('disabled', true).text('验证中…');
    post({ action: 'verify_recipient', id: $(this).data('id') }, WU)
      .done(function (d) {
        notice((d && d.message) || (d && d.ok ? '验证通过' : '验证失败'), !(d && d.ok));
        if (d && d.ok) { load(); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { b.prop('disabled', false).text('验证 IMAP'); });
  });

  // 更新收件人密码
  $(document).on('click', '.ov_wu_rc_edit', function () {
    var id = $(this).data('id'), email = $(this).data('email');
    var pw = window.prompt('为 ' + email + ' 设置新的应用专用密码（留空取消）：', '');
    if (!pw) { return; }
    post({ action: 'edit_recipient', id: id, secret: pw }, WU)
      .done(function (d) {
        if (d && d.ok) {
          notice('密码已更新，正在重新验证…');
          post({ action: 'verify_recipient', id: id }, WU).always(function () { load(); });
        } else { notice((d && d.message) || '更新失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  // 互动开关
  $(document).on('click', '.ov_wu_rc_ar', function () {
    var v = parseInt($(this).data('v'), 10);
    act(WU, { action: 'edit_recipient', id: $(this).data('id'), auto_reply: v },
        v ? '已开启自动回复' : '已关闭自动回复');
  });
  $(document).on('click', '.ov_wu_rc_rs', function () {
    var v = parseInt($(this).data('v'), 10);
    act(WU, { action: 'edit_recipient', id: $(this).data('id'), rescue_spam: v },
        v ? '已开启垃圾箱救援' : '已关闭垃圾箱救援');
  });
});
