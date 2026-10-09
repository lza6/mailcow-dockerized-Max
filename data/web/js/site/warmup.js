/**
 * mailcow-dockerized-Max — 养号（Warmup）后台脚本
 *
 * 注意：本文件由 js_minifier 打包到 /cache/<hash>.js，在 jQuery 之后加载。
 * 因此这里可以在 DOM ready 时直接使用 $。
 */
$(document).ready(function () {
  'use strict';

  var WU_URL = '/inc/ajax/warmup.php';

  function csrf() {
    // 服务端每次 POST 后会轮换 token，响应里带着新值，
    // 这里优先使用内存中最新的（见 updateCsrf）。
    if (typeof window.__wu_csrf === 'string' && window.__wu_csrf) {
      return window.__wu_csrf;
    }
    // 兜底：mailcow 在页面内注入 window.csrf_token
    return (typeof window.csrf_token !== 'undefined') ? window.csrf_token : '';
  }

  function updateCsrf(d) {
    if (d && typeof d.csrf === 'string' && d.csrf) {
      window.__wu_csrf = d.csrf;
    }
  }

  function esc(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
  }

  function post(data) {
    data.csrf_token = csrf();
    return $.ajax({ type: 'POST', url: WU_URL, data: data, dataType: 'json' })
      .always(function (d) { updateCsrf(d); });
  }

  function notice(msg, isErr) {
    var cls = isErr ? 'alert-danger' : 'alert-success';
    var html = '<div class="alert ' + cls + ' alert-dismissible fade show" role="alert">' +
               esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    $('#wu_notice').remove();
    $(html).insertBefore('#wu_plans_table').attr('id', 'wu_notice');
    setTimeout(function () { $('#wu_notice').alert('close'); }, 4000);
  }

  function render(d) {
    $('#wu_cap').text(d.global_cap);
    $('#wu_perrun').text(d.max_per_run);
    $('#wu_window').text(d.window);
    $('#wu_srvtime').text(d.server_time);
    $('#wu_sent_today').text(d.sent_today + ' / ' + d.global_cap);

    // ---- 计划 ----
    var rows = '';
    if (!d.plans.length) {
      rows = '<tr><td colspan="8" class="text-muted">暂无计划</td></tr>';
    }
    d.plans.forEach(function (p) {
      var badge = p.status === 'active'
        ? '<span class="badge bg-success">进行中</span>'
        : (p.status === 'paused'
            ? '<span class="badge bg-warning text-dark" title="' + esc(p.pause_reason) + '">已暂停</span>'
            : '<span class="badge bg-secondary">已结束</span>');
      var toggleBtn = p.status === 'active'
        ? '<button class="btn btn-sm btn-outline-warning wu_status" data-id="' + p.id + '" data-status="paused">暂停</button>'
        : '<button class="btn btn-sm btn-outline-success wu_status" data-id="' + p.id + '" data-status="active">启用</button>';
      rows += '<tr>' +
        '<td>' + esc(p.domain) + '</td>' +
        '<td>' + esc(p.sender) + '</td>' +
        '<td>' + p.day_index + ' / ' + p.total_days + '</td>' +
        '<td>' + p.day_quota + '</td>' +
        '<td>' + p.sent_today + '</td>' +
        '<td>' + p.sent_all + ' / ' + p.fail_all + '</td>' +
        '<td>' + badge + '</td>' +
        '<td class="text-nowrap">' + toggleBtn +
        ' <button class="btn btn-sm btn-outline-danger wu_del" data-id="' + p.id + '">删除</button></td>' +
        '</tr>';
    });
    $('#wu_plans_body').html(rows);

    // ---- 收件人 ----
    var rrows = '';
    if (!d.recipients.length) {
      rrows = '<tr><td colspan="4" class="text-muted">暂无收件人（未添加收件人时不会发送任何邮件）</td></tr>';
    }
    d.recipients.forEach(function (r) {
      rrows += '<tr>' +
        '<td>' + esc(r.email) + '</td>' +
        '<td>' + esc(r.label) + '</td>' +
        '<td>' + (r.active ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">停用</span>') + '</td>' +
        '<td class="text-nowrap">' +
        '<button class="btn btn-sm btn-outline-secondary wu_rcpt_toggle" data-id="' + r.id + '">切换</button> ' +
        '<button class="btn btn-sm btn-outline-danger wu_rcpt_del" data-id="' + r.id + '">删除</button>' +
        '</td></tr>';
    });
    $('#wu_rcpts_body').html(rrows);

    // ---- 日志 ----
    var lrows = '';
    if (!d.logs.length) {
      lrows = '<tr><td colspan="6" class="text-muted">暂无运行记录</td></tr>';
    }
    d.logs.forEach(function (l) {
      lrows += '<tr>' +
        '<td>' + esc(l.run_at) + '</td>' +
        '<td>' + esc(l.domain || '') + '</td>' +
        '<td>#' + l.plan_id + '</td>' +
        '<td>' + l.sent + '</td>' +
        '<td>' + (l.failed > 0 ? '<span class="text-danger">' + l.failed + '</span>' : '0') + '</td>' +
        '<td class="small text-muted">' + esc((l.detail || '').slice(0, 220)) + '</td>' +
        '</tr>';
    });
    $('#wu_logs_body').html(lrows);
  }

  function load() {
    $.getJSON(WU_URL, { action: 'overview' })
      .done(function (d) {
        if (d && d.ok) { updateCsrf(d); render(d); }
        else { notice((d && d.message) || '加载失败', true); }
      })
      .fail(function (x) {
        notice('加载失败（HTTP ' + x.status + '）', true);
      });
  }

  // ---- 事件绑定（委托，避免重渲染后失效） ----
  $('#wu_refresh').on('click', load);

  $('#wu_add_plan').on('click', function () {
    post({
      action: 'add_plan',
      domain: $('#wu_new_domain').val(),
      sender: $('#wu_new_sender').val(),
      start_count: $('#wu_new_start').val(),
      step: $('#wu_new_step').val(),
      max_count: $('#wu_new_max').val(),
      total_days: $('#wu_new_days').val()
    }).done(function (d) {
      if (d.ok) { notice('计划已创建'); $('#wu_new_domain,#wu_new_sender').val(''); load(); }
      else { notice(d.message || '创建失败', true); }
    }).fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $(document).on('click', '.wu_status', function () {
    post({ action: 'set_status', id: $(this).data('id'), status: $(this).data('status') })
      .done(function (d) { d.ok ? load() : notice(d.message, true); })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $(document).on('click', '.wu_del', function () {
    if (!confirm('确定删除该计划？历史日志会保留。')) { return; }
    post({ action: 'delete_plan', id: $(this).data('id') })
      .done(function (d) { d.ok ? load() : notice(d.message, true); })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $('#wu_add_rcpt').on('click', function () {
    post({
      action: 'add_recipient',
      email: $('#wu_new_rcpt').val(),
      label: $('#wu_new_rcpt_label').val()
    }).done(function (d) {
      if (d.ok) { notice('收件人已添加'); $('#wu_new_rcpt,#wu_new_rcpt_label').val(''); load(); }
      else { notice(d.message || '添加失败', true); }
    }).fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $(document).on('click', '.wu_rcpt_toggle', function () {
    post({ action: 'toggle_recipient', id: $(this).data('id') })
      .done(function (d) { d.ok ? load() : notice(d.message, true); })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  $(document).on('click', '.wu_rcpt_del', function () {
    if (!confirm('确定删除该收件人？')) { return; }
    post({ action: 'delete_recipient', id: $(this).data('id') })
      .done(function (d) { d.ok ? load() : notice(d.message, true); })
      .fail(function (x) { notice('请求失败（HTTP ' + x.status + '）', true); });
  });

  // 切到本 tab 时自动加载
  $('button[data-bs-target="#tab-config-warmup"]').on('shown.bs.tab', load);
  if ($('#tab-config-warmup').hasClass('active')) { load(); }
});
