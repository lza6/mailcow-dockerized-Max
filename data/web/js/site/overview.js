/**
 * mailcow-dockerized-Max — 域名总览前端
 *
 * 安全要点：日志/域名/邮箱等内容含外部可控字符串，
 * 一律用 .text() 转义后再插入 DOM，绝不用 innerHTML 拼未转义内容。
 */
$(document).ready(function () {
  'use strict';

  var API = '/inc/ajax/overview.php';
  var WEBMAIL = '/SOGo/';

  // ---- CSRF：服务端每次 POST 后轮换 token，响应会带回新值 ----
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
    var html = '<div class="alert ' + cls + ' alert-dismissible fade show py-2 mb-2">' +
               esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    $('#ov_notice').html(html);
    setTimeout(function () { $('#ov_notice').empty(); }, 6000);
  }

  function badge(ok, textOk, textBad) {
    if (ok === null || ok === undefined) {
      return '<span class="badge bg-secondary">未检测</span>';
    }
    return ok
      ? '<span class="badge bg-success">' + esc(textOk) + '</span>'
      : '<span class="badge bg-danger">' + esc(textBad) + '</span>';
  }

  function renderStats(s) {
    $('#ov_stat_domains').text(s.domains);
    $('#ov_stat_mailboxes').text(s.mailboxes);
    var ab = $('#ov_stat_abnormal');
    ab.text(s.abnormal);
    ab.toggleClass('text-danger fw-bold', s.abnormal > 0);
    $('#ov_stat_today').text(s.today_sent);
  }

  function renderDomains(rows) {
    if (!rows.length) {
      $('#ov_domains_body').html('<tr><td colspan="7" class="text-center text-muted py-4">暂无域名</td></tr>');
      return;
    }
    var h = '';
    rows.forEach(function (d) {
      // 收信 MX
      var mxCell;
      if (!d.checked) {
        mxCell = '<span class="badge bg-secondary">未检测</span>';
      } else if (d.mx_ok) {
        mxCell = '<span class="badge bg-success">指向本机</span>';
      } else {
        var tgt = (d.mx || []).join(', ') || '(无 MX)';
        mxCell = '<span class="badge bg-danger">指向别处</span>' +
                 '<div><small class="text-danger">' + esc(tgt) + '</small></div>';
      }

      // 认证三项
      var auth = '';
      if (!d.checked) {
        auth = '<span class="badge bg-secondary">未检测</span>';
      } else {
        auth = badge(d.spf_ok, 'SPF', 'SPF') + ' ' +
               badge(d.dkim_ok, 'DKIM', 'DKIM') + ' ' +
               badge(d.dmarc_ok, 'DMARC', 'DMARC');
      }

      // 养号
      var wu = '—';
      if (d.warmup) {
        var w = d.warmup;
        var st = (w.status === 'active') ? '进行中' : (w.status === 'paused' ? '已暂停' : w.status);
        wu = '<span class="small">' + esc(st) + '</span>' +
             '<div><small class="text-muted">第' + w.day + '/' + w.total_days + '天 · 今日' +
             w.sent_today + '/' + w.quota + '</small></div>' +
             '<div><small class="text-muted">累计 ' + w.sent_all + ' 成功 / ' + w.fail_all + ' 失败</small></div>';
      }

      // 域名状态
      var domName = esc(d.domain);
      if (parseInt(d.active, 10) !== 1) {
        domName += ' <span class="badge bg-secondary">已停用</span>';
      }

      h += '<tr>' +
        '<td>' + domName +
          (d.checked ? '<div><small class="text-muted">检测于 ' + esc(d.checked_at) + '</small></div>' : '') +
        '</td>' +
        '<td>' + mxCell + '</td>' +
        '<td>' + auth + '</td>' +
        '<td>' + (d.relay ? '<code>' + esc(d.relay) + '</code>' : '<span class="text-muted">直发</span>') + '</td>' +
        '<td>' + d.mailboxes + '</td>' +
        '<td>' + wu + '</td>' +
        '<td class="text-nowrap">' +
          '<button class="btn btn-sm btn-outline-primary ov_check" data-domain="' + esc(d.domain) + '">体检</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_goto_mbox" data-domain="' + esc(d.domain) + '">邮箱</button> ' +
          '<a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" ' +
             'href="' + WEBMAIL + '">Webmail</a>' +
        '</td>' +
      '</tr>';
    });
    $('#ov_domains_body').html(h);
  }

  function renderMailboxes(rows) {
    if (!rows.length) {
      $('#ov_mbox_body').html('<tr><td colspan="5" class="text-center text-muted py-4">暂无邮箱</td></tr>');
      return;
    }
    var h = '';
    rows.forEach(function (m) {
      var q = (parseInt(m.quota, 10) === 0) ? '不限' : (Math.round(m.quota / 1048576) + ' MB');
      h += '<tr class="ov_mbox_row" data-search="' + esc((m.username + ' ' + m.domain).toLowerCase()) + '">' +
        '<td><code>' + esc(m.username) + '</code>' +
          (m.name ? '<div><small class="text-muted">' + esc(m.name) + '</small></div>' : '') + '</td>' +
        '<td>' + esc(m.domain) + '</td>' +
        '<td>' + (parseInt(m.active, 10) === 1
                    ? '<span class="badge bg-success">启用</span>'
                    : '<span class="badge bg-secondary">停用</span>') + '</td>' +
        '<td>' + esc(q) + '</td>' +
        '<td class="text-nowrap">' +
          '<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="' + WEBMAIL + '">进 Webmail</a> ' +
          '<button class="btn btn-sm btn-outline-warning ov_reset_pw" data-user="' + esc(m.username) + '">重置密码</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_copy" data-user="' + esc(m.username) + '">复制地址</button> ' +
          '<button class="btn btn-sm btn-outline-secondary ov_toggle" data-user="' + esc(m.username) + '">启/停</button>' +
        '</td>' +
      '</tr>';
    });
    $('#ov_mbox_body').html(h);
  }

  function renderAudit(rows) {
    if (!rows || !rows.length) {
      $('#ov_audit_body').html('<tr><td colspan="6" class="text-center text-muted py-4">暂无记录</td></tr>');
      return;
    }
    var h = '';
    rows.forEach(function (a) {
      var ok = (a.result === 'ok');
      h += '<tr>' +
        '<td><small>' + esc(a.created) + '</small></td>' +
        '<td><small>' + esc(a.actor) + (a.actor_ip ? '<div class="text-muted">' + esc(a.actor_ip) + '</div>' : '') + '</small></td>' +
        '<td><code class="small">' + esc(a.action) + '</code></td>' +
        '<td><small>' + esc(a.target_type) + (a.target ? ' / ' + esc(a.target) : '') + '</small></td>' +
        '<td>' + (ok ? '<span class="badge bg-success">成功</span>'
                     : '<span class="badge bg-danger">失败</span>') + '</td>' +
        '<td><small class="text-muted">' + esc((a.detail || '').slice(0, 120)) + '</small></td>' +
      '</tr>';
    });
    $('#ov_audit_body').html(h);
  }

  function load() {
    $.getJSON(API, { action: 'overview' })
      .done(function (d) {
        if (!d || !d.ok) { notice((d && d.message) || '加载失败', true); return; }
        updateCsrf(d);
        renderStats(d.stats);
        renderDomains(d.domains);
        renderMailboxes(d.mailboxes);
        renderAudit(d.audit);
      })
      .fail(function (x) { notice('加载失败（HTTP ' + x.status + '）', true); });
  }

  // ---- 事件 ----
  $('#ov_refresh, #ov_refresh_audit').on('click', load);

  $('#ov_check_all').on('click', function () {
    var btn = $(this).prop('disabled', true).text('体检中…');
    post({ action: 'check_all' })
      .done(function (d) {
        if (d.ok) { notice('体检完成：共 ' + d.checked + ' 个域，异常 ' + d.abnormal + ' 个'); load(); }
        else { notice(d.message || '体检失败', true); }
      })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); })
      .always(function () { btn.prop('disabled', false).html('<i class="bi bi-clipboard-check"></i> 一键体检全部'); });
  });

  $(document).on('click', '.ov_check', function () {
    var dom = $(this).data('domain');
    var btn = $(this).prop('disabled', true).text('…');
    post({ action: 'check_domain', domain: dom })
      .done(function (d) {
        if (d.ok) { notice(dom + ' 体检完成'); load(); }
        else { notice((d.message || '体检失败'), true); }
      })
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
    if (!confirm('确定重置 ' + u + ' 的密码？将生成一个新的随机密码。')) { return; }
    post({ action: 'reset_mailbox_password', username: u })
      .done(function (d) {
        if (d.ok) {
          // 只显示一次；不写入任何日志
          alert('已重置：' + u + '\n\n新密码（请立即保存，只显示这一次）：\n' + d.password);
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
    } else {
      window.prompt('复制以下内容：', txt);
    }
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
    var s = Array.prototype.map.call(a, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
    $('#ov_new_mbox_pw').val(s);
  });

  $('#ov_add_domain').on('click', function () {
    post({
      action: 'add_domain',
      domain: $('#ov_new_domain').val(),
      description: $('#ov_new_domain_desc').val()
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

  load();
});
