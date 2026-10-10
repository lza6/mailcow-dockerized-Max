/**
 * mailcow-dockerized-Max — 邮件投递链路追踪后台脚本
 *
 * 安全要点（Red Team 明确点名的风险点）：
 *   postfix 日志里含用户可控内容（邮箱地址、Subject、Message-ID 等）。
 *   本文件**绝不**把日志内容直接拼进 innerHTML —— 所有动态字符串一律先经
 *   esc()（$('<div>').text(x).html()）转义，再拼进模板。数值字段统一用 num()。
 *
 * 注意：本文件由 js_minifier 打包到 /cache/<hash>.js，在 jQuery 之后加载，
 *       因此这里可以在 DOM ready 时直接使用 $。
 */
$(document).ready(function () {
  'use strict';

  var DT_URL = '/inc/ajax/delivery_trace.php';

  // 与 warmup.js 相同的 CSRF 约定：服务端每次响应都会带回当前 token。
  // 本模块只做 GET 查询（GET 不轮换 token），保留这套逻辑是为了与其他后台模块一致。
  function csrf() {
    if (typeof window.__dt_csrf === 'string' && window.__dt_csrf) {
      return window.__dt_csrf;
    }
    return (typeof window.csrf_token !== 'undefined') ? window.csrf_token : '';
  }

  function updateCsrf(d) {
    if (d && typeof d.csrf === 'string' && d.csrf) {
      window.__dt_csrf = d.csrf;
    }
  }

  /** 把任意值转义为可安全插入 HTML 的文本 */
  function esc(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
  }

  /** 数值字段：确保不会带出非数字内容 */
  function num(n) {
    var v = parseInt(n, 10);
    return isNaN(v) ? 0 : v;
  }

  var STATE_LABEL = {
    info: '信息',
    sent: '已投递',
    deferred: '延迟重试',
    bounced: '退信',
    expired: '过期退回',
    rejected: '被拒',
    hold: '挂起',
    discarded: '已丢弃',
    unknown: '未知'
  };

  var STATE_CLASS = {
    sent: 'bg-success',
    deferred: 'bg-warning text-dark',
    bounced: 'bg-danger',
    expired: 'bg-danger',
    rejected: 'bg-danger',
    discarded: 'bg-danger',
    hold: 'bg-secondary',
    info: 'bg-light text-dark',
    unknown: 'bg-secondary'
  };

  var ROW_CLASS = {
    sent: 'table-success',
    deferred: 'table-warning',
    bounced: 'table-danger',
    expired: 'table-danger',
    rejected: 'table-danger',
    discarded: 'table-danger'
  };

  var STAGE_LABEL = {
    submit: '发送',
    relay: '中继',
    result: '结果',
    other: '其他'
  };

  function stateBadge(state) {
    var st = STATE_LABEL[state] ? state : 'info';
    return '<span class="badge ' + STATE_CLASS[st] + '">' + esc(STATE_LABEL[st]) + '</span>';
  }

  function notice(msg, isErr) {
    var cls = isErr ? 'alert-danger' : 'alert-success';
    $('#dt_notice').remove();
    $('<div class="alert ' + cls + ' alert-dismissible fade show" role="alert"></div>')
      .attr('id', 'dt_notice')
      .append(document.createTextNode(String(msg)))          // 文本节点，天然不发生 HTML 解析
      .append('<button type="button" class="btn-close" data-bs-dismiss="alert"></button>')
      .prependTo('#dt_results');
    setTimeout(function () { $('#dt_notice').alert('close'); }, 4000);
  }

  function renderSteps(d) {
    var order = ['submit', 'relay', 'result'];
    var html = '';
    order.forEach(function (key, idx) {
      var s = (d.stages && d.stages[key]) ? d.stages[key] : { count: 0, state: 'info' };
      html += '<div class="col">' +
        '<div class="border rounded p-2 h-100">' +
        '<div class="text-muted small">步骤 ' + (idx + 1) + '</div>' +
        '<div class="fs-6">' + esc(STAGE_LABEL[key]) + '</div>' +
        stateBadge(s.state) +
        ' <span class="text-muted small">' + num(s.count) + ' 行</span>' +
        '</div></div>';
    });
    $('#dt_steps').html(html);
  }

  function renderTimeline(d) {
    var rows = '';
    if (!d.timeline || !d.timeline.length) {
      rows = '<tr><td colspan="6" class="text-muted">未在最近的 postfix 日志中找到匹配记录' +
             '（可能邮件更早、已超出日志保留行数，或 Message-ID/收件人填写有误）</td></tr>';
    }
    else {
      d.timeline.forEach(function (l) {
        var rowCls = ROW_CLASS[l.state] || '';
        var flag = '';
        if (l.relay_failed) {
          flag = ' <span class="badge bg-danger">relay failed</span>';
        }
        if (l.is_match) {
          flag += ' <span class="badge bg-info text-dark">命中条件</span>';
        }
        rows += '<tr class="' + rowCls + '">' +
          '<td class="text-nowrap small">' + esc(l.time_str || '') + '</td>' +
          '<td class="text-nowrap small">' + esc(STAGE_LABEL[l.stage] || l.stage || '') + '</td>' +
          '<td class="text-nowrap small">' + stateBadge(l.state) + flag + '</td>' +
          '<td class="text-nowrap small">' + esc(l.program || '') + '</td>' +
          '<td class="text-nowrap small">' + esc(l.relay || '-') + '</td>' +
          '<td class="small" style="word-break:break-all;">' + esc(l.message || '') + '</td>' +
          '</tr>';
      });
    }
    $('#dt_body').html(rows);
  }

  function renderRecent(d) {
    var rows = '';
    var list = d.recent || [];
    if (!list.length) {
      rows = '<tr><td colspan="3" class="text-muted">无日志可显示</td></tr>';
    }
    list.forEach(function (e) {
      rows += '<tr>' +
        '<td class="text-nowrap small">' + esc(e.time_str || '') + '</td>' +
        '<td class="text-nowrap small">' + esc(e.program || '') + '</td>' +
        '<td class="small" style="word-break:break-all;">' + esc(e.message || '') + '</td>' +
        '</tr>';
    });
    $('#dt_recent_body').html(rows);
    $('#dt_recent_count').text(num(list.length));
  }

  function renderResult(d) {
    var verdict = STATE_LABEL[d.verdict] ? d.verdict : 'unknown';
    $('#dt_verdict').html(stateBadge(verdict));
    $('#dt_scanned').text(num(d.scanned));
    $('#dt_matches').text(num(d.match_count));
    $('#dt_truncated').toggle(!!d.truncated);

    var targets = (d.relay_targets && d.relay_targets.length) ? d.relay_targets.join(' , ') : '（无）';
    $('#dt_targets').text(targets);

    if (d.error) {
      $('#dt_note_error').text(String(d.error)).show();
    }
    else {
      $('#dt_note_error').hide().text('');
    }

    if (d.cf_bridge && d.cf_bridge.available === false) {
      $('#dt_bridge_note').text(String(d.cf_bridge.message || '')).show();
    }
    else {
      $('#dt_bridge_note').hide().text('');
    }

    renderSteps(d);
    renderTimeline(d);
    renderRecent(d);
  }

  // ---- 查询 ----
  function runQuery() {
    var mid = $.trim($('#dt_mid').val());
    var rcpt = $.trim($('#dt_rcpt').val());
    if (!mid && !rcpt) {
      notice('请至少填写 Message-ID 或收件人邮箱', true);
      return;
    }
    $.getJSON(DT_URL, {
      action: 'query',
      message_id: mid,
      recipient: rcpt,
      lines: $('#dt_lines').val(),
      csrf_token: csrf()
    })
      .done(function (d) {
        updateCsrf(d);
        if (d && d.ok) { renderResult(d); }
        else { notice((d && d.message) || '查询失败', true); }
      })
      .fail(function (x) {
        var msg = (x.responseJSON && x.responseJSON.message) ? x.responseJSON.message : ('查询失败（HTTP ' + x.status + '）');
        notice(msg, true);
      });
  }

  // ---- 最近日志浏览 ----
  function loadRecent() {
    $.getJSON(DT_URL, {
      action: 'recent',
      lines: $('#dt_lines').val(),
      csrf_token: csrf()
    })
      .done(function (d) {
        updateCsrf(d);
        if (d && d.ok) {
          renderRecent(d);
          if (d.available === false && d.error) {
            $('#dt_recent_body').html('<tr><td colspan="3" class="text-danger small">' + esc(d.error) + '</td></tr>');
          }
        }
        else { notice((d && d.message) || '日志读取失败', true); }
      })
      .fail(function (x) {
        notice('日志读取失败（HTTP ' + x.status + '）', true);
      });
  }

  // ---- 事件绑定 ----
  $('#dt_run').on('click', runQuery);
  // 该按钮在 <summary> 内，必须阻止冒泡，否则点一下会把 details 一起收起
  $('#dt_reload_recent').on('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    loadRecent();
  });
  $('#dt_mid, #dt_rcpt').on('keydown', function (e) {
    if (e.which === 13) { e.preventDefault(); runQuery(); }
  });

  // 切到本 tab 时自动拉一次最近日志
  $('button[data-bs-target="#tab-config-delivery-trace"]').on('shown.bs.tab', loadRecent);
  if ($('#tab-config-delivery-trace').hasClass('active')) { loadRecent(); }
});
