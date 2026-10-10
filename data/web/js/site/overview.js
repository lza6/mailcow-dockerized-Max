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
  function fmtAge(d) {
    if (d === null || d === undefined) { return '<span class="text-muted">未知</span>'; }
    if (d >= 365) { return '<span class="text-success">' + (d / 365).toFixed(1) + ' 年</span>'; }
    if (d >= 90)  { return '<span class="text-success">' + d + ' 天</span>'; }
    if (d >= 30)  { return '<span class="text-warning">' + d + ' 天</span>'; }
    return '<span class="text-danger fw-bold">' + d + ' 天</span>';
  }

  function renderStats(s) {
    $('#ov_stat_domains').text(s.domains);
    $('#ov_stat_mailboxes').text(s.mailboxes);
    $('#ov_stat_msg').text(s.messages);
    $('#ov_stat_new').text(s.today_new);
    var ab = $('#ov_stat_abnormal').text(s.abnormal);
    ab.toggleClass('text-danger fw-bold', s.abnormal > 0);
    $('#ov_stat_today').text(s.today_sent);
  }

  function renderDomains(rows) {
    if (!rows.length) {
      $('#ov_domains_body').html('<tr><td colspan="8" class="text-center text-muted py-4">暂无域名</td></tr>');
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
        '<td>' + (d.relay ? '<code>' + esc(d.relay) + '</code>' : '<span class="text-muted">直发</span>') + '</td>' +
        '<td>' + wu + '</td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-primary ov_check" data-domain="' + esc(d.domain) + '">体检</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_goto_mbox" data-domain="' + esc(d.domain) + '">邮箱</button>' +
        '</td>' +
      '</tr>';
    });
    $('#ov_domains_body').html(h);
  }

  function renderMailboxes(rows) {
    if (!rows.length) {
      $('#ov_mbox_body').html('<tr><td colspan="7" class="text-center text-muted py-4">暂无邮箱</td></tr>');
      return;
    }
    var h = '';
    rows.forEach(function (m) {
      var q = (parseInt(m.quota, 10) === 0) ? '不限' : (Math.round(m.quota / 1048576) + ' MB');
      var used = m.bytes ? (m.bytes / 1048576).toFixed(1) + ' MB' : '0 MB';
      h += '<tr class="ov_mbox_row" data-search="' + esc((m.username + ' ' + m.domain).toLowerCase()) + '">' +
        '<td><code>' + esc(m.username) + '</code></td>' +
        '<td>' + esc(m.domain) + '</td>' +
        '<td>' + (parseInt(m.active, 10) === 1
                    ? '<span class="badge bg-success">启用</span>'
                    : '<span class="badge bg-secondary">停用</span>') + '</td>' +
        '<td>' + m.messages + ' <small class="text-muted">(' + esc(used) + ')</small></td>' +
        '<td>' + (m.today_new > 0
                    ? '<span class="badge bg-success">+' + m.today_new + '</span>'
                    : '<span class="text-muted">0</span>') + '</td>' +
        '<td>' + esc(q) + '</td>' +
        '<td class="text-nowrap">' +
          '<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="' + WEBMAIL + '">进 Webmail</a> ' +
          '<button class="btn btn-sm btn-outline-warning ov_reset_pw" data-user="' + esc(m.username) + '">重置密码</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_copy" data-user="' + esc(m.username) + '">复制</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_toggle" data-user="' + esc(m.username) + '">启/停</button>' +
        '</td>' +
      '</tr>';
    });
    $('#ov_mbox_body').html(h);
  }

  // 明文密钥只在前端缓存里，默认不请求；点了「显示」才向后端要一次（后端会记审计）
  var revealed = {};

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
    $('#ov_pool_body').html(h);

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
    $('#ov_bind_body').html(b);

    // 绑定表单的两个下拉
    var accSel = $('#ov_bind_account').empty();
    r.accounts.filter(function (a) { return a.active === 1; }).forEach(function (a) {
      accSel.append($('<option>').val(a.id).text(a.label + '（' + a.domains + '/' + a.limit + '）'));
    });
  }

  function renderWarmup(w) {
    if (!w) { $('#ov_warmup_card').hide(); return; }
    $('#ov_warmup_card').show();

    $('#ov_wu_rule').text('发送窗口 ' + w.window + ' · 单次最多 ' + w.max_per_run +
                          ' 封 · 全局每日上限 ' + w.global_cap + ' 封');
    $('#ov_wu_today').text(w.sent_today);
    $('#ov_wu_cap').text(w.global_cap);
    $('#ov_wu_sent').text(w.sent_total);
    $('#ov_wu_fail').text(w.fail_total);
    $('#ov_wu_plans').text(w.plans.length);
    var rcOn = w.recipients.filter(function (x) { return x.active === 1; }).length;
    $('#ov_wu_rc').text(rcOn + ' / ' + w.recipients.length);

    // 计划表
    var h = '';
    if (!w.plans.length) {
      h = '<tr><td colspan="7" class="text-center text-muted py-3">还没有养号计划</td></tr>';
    }
    w.plans.forEach(function (p) {
      var stCls = p.status === 'active' ? 'bg-success'
                : (p.status === 'paused' ? 'bg-warning text-dark' : 'bg-secondary');
      var stTxt = p.status === 'active' ? '进行中' : (p.status === 'paused' ? '已暂停' : p.status);
      // 今日没跑过要显式提示：分不清「配额为 0」与「调度器没跑」会掩盖故障
      var today = '<span class="text-success fw-bold">' + p.sent_today + '</span>';
      if (!p.ran_today) {
        today = '<span class="text-danger fw-bold">' + p.sent_today + '</span>' +
                '<div><small class="text-danger">今日未运行</small></div>';
      }
      h += '<tr>' +
        '<td><code class="small">' + esc(p.sender) + '</code>' +
          '<div><small class="text-muted">' + esc(p.domain) + '</small></div></td>' +
        '<td>第 ' + p.day + ' / ' + p.total_days + ' 天' +
          (p.days_left > 0 ? '<div><small class="text-muted">剩 ' + p.days_left + ' 天</small></div>'
                           : '<div><small class="text-danger">已到期</small></div>') + '</td>' +
        '<td>' + today + ' / ' + p.day_quota + '</td>' +
        '<td>' + p.sent_all + ' <span class="text-muted">成</span> / ' + p.fail_all + ' <span class="text-muted">败</span></td>' +
        '<td><span class="badge ' + stCls + '">' + esc(stTxt) + '</span>' +
          (p.pause_reason ? '<div><small class="text-danger">' + esc(p.pause_reason) + '</small></div>' : '') + '</td>' +
        '<td><small>' + esc(p.last_run || '—') + '</small></td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-secondary ov_wu_status" data-id="' + p.id +
            '" data-status="' + (p.status === 'active' ? 'paused' : 'active') + '">' +
            (p.status === 'active' ? '暂停' : '恢复') + '</button> ' +
          '<button class="btn btn-sm btn-outline-danger ov_wu_del_plan" data-id="' + p.id + '">删除</button>' +
        '</td></tr>';
    });
    $('#ov_wu_plan_body').html(h);

    // 收件人
    var r2 = '';
    if (!w.recipients.length) {
      r2 = '<tr><td colspan="4" class="text-center text-muted py-3">还没有收件人，养号不会发出任何邮件</td></tr>';
    }
    w.recipients.forEach(function (x) {
      r2 += '<tr>' +
        '<td><code class="small">' + esc(x.email) + '</code></td>' +
        '<td><small>' + esc(x.label || '—') + '</small></td>' +
        '<td>' + (x.active === 1 ? '<span class="badge bg-success">启用</span>'
                                 : '<span class="badge bg-secondary">停用</span>') + '</td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-secondary ov_wu_rc_toggle" data-id="' + x.id + '">启/停</button> ' +
          '<button class="btn btn-sm btn-outline-danger ov_wu_rc_del" data-id="' + x.id + '">删除</button>' +
        '</td></tr>';
    });
    $('#ov_wu_rc_body').html(r2);

    // 日志
    var l = '';
    if (!w.logs.length) {
      l = '<tr><td colspan="6" class="text-center text-muted py-3">暂无发送记录</td></tr>';
    }
    w.logs.forEach(function (x) {
      var det = String(x.detail || '');
      l += '<tr>' +
        '<td><small>' + esc(x.run_at) + '</small></td>' +
        '<td><small>' + esc(x.sender || ('计划#' + x.plan_id)) + '</small></td>' +
        '<td>' + x.planned + '</td>' +
        '<td>' + (x.sent > 0 ? '<span class="text-success fw-bold">' + x.sent + '</span>' : '0') + '</td>' +
        '<td>' + (x.failed > 0 ? '<span class="text-danger fw-bold">' + x.failed + '</span>' : '0') + '</td>' +
        '<td><small class="text-muted">' + esc(det.slice(0, 300)) + '</small></td>' +
      '</tr>';
    });
    $('#ov_wu_log_body').html(l);
  }

  function renderAudit(rows) {
    if (!rows || !rows.length) {
      $('#ov_audit_body').html('<tr><td colspan="6" class="text-center text-muted py-4">暂无记录</td></tr>');
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
    $('#ov_audit_body').html(h);
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
    renderStats(d.stats);
    renderDomains(d.domains);
    renderMailboxes(d.mailboxes);
    renderRelay(d.relay);
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
    $('html,body').animate({ scrollTop: $('#ov_mbox_body').offset().top - 120 }, 250);
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
    act(WU, { action: 'add_recipient', email: e, label: $('#ov_wu_rc_label').val() }, '收件人已添加');
    $('#ov_wu_rc_email').val('');
    $('#ov_wu_rc_label').val('');
  });
  $(document).on('click', '.ov_wu_rc_toggle', function () {
    act(WU, { action: 'toggle_recipient', id: $(this).data('id') });
  });
  $(document).on('click', '.ov_wu_rc_del', function () {
    if (!confirm('删除该收件人？')) { return; }
    act(WU, { action: 'delete_recipient', id: $(this).data('id') }, '收件人已删除');
  });
});
