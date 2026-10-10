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
  var WEBMAIL = '/SOGo/';

  function csrf() {
    if (typeof window.__ov_csrf === 'string' && window.__ov_csrf) { return window.__ov_csrf; }
    return (typeof window.csrf_token !== 'undefined') ? window.csrf_token : '';
  }
  function updateCsrf(d) {
    if (d && typeof d.csrf === 'string' && d.csrf) { window.__ov_csrf = d.csrf; }
  }
  function esc(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
  }
  function post(data) {
    data.csrf_token = csrf();
    return $.ajax({ type: 'POST', url: API, data: data, dataType: 'json' })
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

  function renderPool(pool) {
    if (!pool || !pool.length) { $('#ov_pool_card').hide(); return; }
    $('#ov_pool_card').show();
    var h = '';
    pool.forEach(function (p) {
      h += '<tr>' +
        '<td>' + p.id + '</td>' +
        '<td>' + esc(p.label) + '</td>' +
        '<td>' + (parseInt(p.active, 10) === 1
                    ? '<span class="badge bg-success">启用</span>'
                    : '<span class="badge bg-secondary">停用</span>') + '</td>' +
        '<td>' + p.domains + '</td>' +
        '<td>' + p.quota + '</td>' +
        '<td><small class="text-muted">' + esc(p.status || '(未校验)') + '</small></td>' +
      '</tr>';
    });
    $('#ov_pool_body').html(h);
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

  function apply(d) {
    if (!d || !d.ok) { notice((d && d.message) || '加载失败', true); return; }
    updateCsrf(d);
    renderStats(d.stats);
    renderDomains(d.domains);
    renderMailboxes(d.mailboxes);
    renderPool(d.pool);
    if (d.audit) { renderAudit(d.audit); }
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
    post({ action: 'add_domain', domain: $('#ov_new_domain').val(), description: $('#ov_new_domain_desc').val() })
      .done(function (d) {
        if (d.ok) { notice('域名已添加'); $('#ov_new_domain,#ov_new_domain_desc').val(''); load(); }
        else { notice(d.message || '添加失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
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
});
