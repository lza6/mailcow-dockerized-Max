/**
 * mailcow-dockerized-Max — Google Postmaster Tools 后台脚本
 *
 * 注意：本文件由 js_minifier 打包到 /cache/<hash>.js，在 jQuery 之后加载。
 * 因此这里可以在 DOM ready 时直接使用 $。
 *
 * 两条路径都在这一个文件里：
 *   · 连接 Google 账号后：自动取 token / 自动写 DNS / 自动提交验证 / 自动拉数据
 *   · 没连接时：用户手工粘 Google 给的记录，我们照样把 DNS 写对
 * 界面上必须让两条路都**看得出当前处于哪一条**，否则用户会以为功能坏了。
 */
$(document).ready(function () {
  'use strict';

  var PM_URL = '/inc/ajax/postmaster.php';
  var STATE = null;

  function csrf() {
    if (typeof window.__pm_csrf === 'string' && window.__pm_csrf) { return window.__pm_csrf; }
    return (typeof window.csrf_token !== 'undefined') ? window.csrf_token : '';
  }
  function updateCsrf(d) {
    if (d && typeof d.csrf === 'string' && d.csrf) { window.__pm_csrf = d.csrf; }
  }
  function esc(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
  }
  function post(data) {
    data.csrf_token = csrf();
    return $.ajax({ type: 'POST', url: PM_URL, data: data, dataType: 'json' })
      .always(function (d) { updateCsrf(d); });
  }
  function notice(msg, isErr) {
    var cls = isErr ? 'alert-danger' : 'alert-success';
    var html = '<div class="alert ' + cls + ' alert-dismissible fade show" role="alert">' +
               esc(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    var $box = $('#pm_notice').html(html);
    setTimeout(function () {
      // `.alert()` 是 Bootstrap 插件。它会缺席（精简构建、插件加载失败、
      // 或被其它脚本覆盖），此时硬调会抛 "alert is not a function"，
      // 且在定时器里抛出 → 每次提示都往控制台丢一条未捕获异常。
      // 提示本身是次要的，降级成直接移除，绝不因为收起动画失败而报错。
      var $a = $box.find('.alert');
      if ($.fn && typeof $.fn.alert === 'function') { $a.alert('close'); }
      else { $a.remove(); }
    }, 6000);
  }

  // 一致性：这些颜色要和 postmaster_lib.inc.php 里 pm_*_level() 的 level 串对应
  var BADGE = { good: 'success', ok: 'info', warn: 'warning text-dark', bad: 'danger', unknown: 'secondary' };
  function badge(level, text) {
    return '<span class="badge bg-' + (BADGE[level] || 'secondary') + '">' + esc(text) + '</span>';
  }

  /*
   * ⚠️ 不能直接把 $('#pm_domains_body') 当成一定存在。
   *
   * mailcow 的 footer.js 有一条**全局**规则：
   *     $('tbody').filter(function(){ return $(this).children().length < 1; }).remove();
   * 它在页面解析阶段执行，会把所有**空的 tbody 删掉**。模板里虽然放了占位行
   * （所以正常情况下会被保留），但这条兜底能保证：万一哪天占位行被删了、
   * 或页面结构被改动，表格也不会永久空白 —— 那种故障**控制台一声不响**，
   * 只在界面上表现为"一直在加载"。
   * 行内按钮的委托也挂在 table 上（table 不会被删），避免 tbody 被重建后事件丢失。
   */
  function body(sel, tableSel) {
    var $b = $(sel);
    if ($b.length) { return $b; }
    var $t = $(tableSel);
    if (!$t.length) { return $b; }
    $b = $('<tbody>').attr('id', String(sel).replace(/^#/, ''));
    $t.append($b);
    return $b;
  }

  function copyText(txt, $btn) {
    var done = function () {
      var old = $btn.html();
      $btn.html('<i class="bi bi-check2"></i> 已复制');
      setTimeout(function () { $btn.html(old); }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(done, function () { notice('复制失败，请手工选中复制', true); });
    } else {
      var $t = $('<textarea>').val(txt).appendTo('body').select();
      try { document.execCommand('copy'); done(); } catch (e) { notice('复制失败，请手工选中复制', true); }
      $t.remove();
    }
  }

  /* ---------------- 渲染 ---------------- */

  function renderOauth(d) {
    $('#pm_redirect_uri').val(d.redirect_uri || '');
    if (d.oauth_connected) {
      $('#pm_conn_badge').attr('class', 'badge bg-success').text('已连接');
      $('#pm_conn_email').text(d.oauth_email ? ('账号：' + d.oauth_email) : '');
      $('#pm_oauth_off').hide();
      $('#pm_oauth_on').show();
      $('#pm_connect').hide();
      $('#pm_reconfig').show();
      $('#pm_disconnect').show();
    } else if (d.oauth_configured) {
      $('#pm_conn_badge').attr('class', 'badge bg-warning text-dark').text('已填凭据，未连接');
      $('#pm_conn_email').text('');
      $('#pm_oauth_off').show();
      $('#pm_oauth_on').show();
      $('#pm_connect').show();
      $('#pm_reconfig').hide();
      $('#pm_disconnect').hide();
    } else {
      $('#pm_conn_badge').attr('class', 'badge bg-secondary').text('未连接（可用手工方式）');
      $('#pm_conn_email').text('');
      $('#pm_oauth_off').show();
      $('#pm_oauth_on').hide();
    }
    // Cloudflare 不可用时，"自动写 DNS"这条路是不通的，必须提前说清楚，
    // 否则用户点完按钮只会看到一句语焉不详的失败。
    if (!d.cf_ready) {
      $('#pm_cf_hint').show();
    } else {
      $('#pm_cf_hint').hide();
    }
  }

  function healthCell(h) {
    if (!h) { return '<span class="text-muted small">暂无数据</span>'; }
    var L = [];
    if (h.spam) {
      var t = (h.spam.pct === null || h.spam.pct === undefined)
        ? h.spam.label : (h.spam.pct.toFixed(3) + '% ' + h.spam.label);
      L.push('投诉 ' + badge(h.spam.level, t));
    }
    if (h.reputation) {
      L.push('域信誉 ' + badge(h.reputation.level, h.reputation.label));
    }
    var auth = [];
    if (h.spf && h.spf.pct !== null) { auth.push('SPF ' + h.spf.pct.toFixed(1) + '%'); }
    if (h.dkim && h.dkim.pct !== null) { auth.push('DKIM ' + h.dkim.pct.toFixed(1) + '%'); }
    if (h.dmarc && h.dmarc.pct !== null) { auth.push('DMARC ' + h.dmarc.pct.toFixed(1) + '%'); }
    if (auth.length) { L.push('<span class="text-muted small">' + esc(auth.join(' · ')) + '</span>'); }
    if (h.errors && h.errors.length) {
      // ratio 是**单日平均**比例（后端按"有错误的天"求平均，与 SPF/DKIM 同口径），
      // 所以这里乘 100 最多到 100%。单看平均会漏掉"偶发尖峰"，
      // 因此把出现天数和单日最高一并显示：平均低 + max 高 = 某一天集中爆过，
      // 平均和 max 都高 = 持续性问题，两种情况的处理方式完全不同。
      var e0 = h.errors[0];
      var avg = Number(e0.ratio) * 100;
      var mx = Number(e0.max) * 100;
      var txt = '错误 ' + esc(e0.type) + ' 日均 ' + avg.toFixed(2) + '%';
      if (e0.days) { txt += '（' + e0.days + ' 天）'; }
      if (isFinite(mx) && mx > avg + 0.005) { txt += ' 单日最高 ' + mx.toFixed(2) + '%'; }
      L.push('<span class="text-danger small">' + txt + '</span>');
    }
    L.push('<span class="text-muted small">' + (h.days || 0) + ' 天数据' +
           (h.latest_date ? '（截至 ' + esc(h.latest_date) + '）' : '') + '</span>');
    return L.join('<br>');
  }

  function recordCell(d) {
    if (!d.dns_type) {
      return '<span class="text-muted small">还没有记录 —— 点右侧「取记录」或到下面手工粘贴</span>';
    }
    var fqdn = d.dns_fqdn || '';
    return '<div class="small">' +
      '<span class="badge bg-dark">' + esc(d.dns_type) + '</span> ' +
      '<code class="user-select-all">' + esc(fqdn) + '</code>' +
      ' <button class="btn btn-sm btn-link p-0 pm-copy-fqdn" data-v="' + esc(fqdn) + '" title="复制记录名">' +
      '<i class="bi bi-clipboard"></i></button>' +
      '<div class="text-muted mt-1">值：<code class="user-select-all">' + esc(d.dns_content) + '</code>' +
      ' <button class="btn btn-sm btn-link p-0 pm-copy-val" data-v="' + esc(d.dns_content) + '" title="复制记录值">' +
      '<i class="bi bi-clipboard"></i></button></div>' +
      (d.dns_written_at ? '<div class="text-muted">已写入 Cloudflare：' + esc(d.dns_written_at) + '</div>' : '') +
      '</div>';
  }

  function actionsCell(d) {
    var h = [];
    if (d.verify_status !== 'verified') {
      h.push('<button class="btn btn-sm btn-outline-primary pm-fetch" data-d="' + esc(d.domain) + '">取记录</button>');
      h.push('<button class="btn btn-sm btn-outline-success pm-verify" data-d="' + esc(d.domain) + '">提交验证</button>');
    } else {
      h.push('<span class="text-success small"><i class="bi bi-check-circle"></i> 已完成</span>');
    }
    h.push('<button class="btn btn-sm btn-outline-secondary pm-manual" data-d="' + esc(d.domain) + '">手工</button>');
    if (d.verify_status !== 'unknown') {
      h.push('<button class="btn btn-sm btn-outline-danger pm-remove" data-d="' + esc(d.domain) + '">移除</button>');
    }
    return '<div class="d-flex flex-wrap gap-1">' + h.join('') + '</div>';
  }

  function render(d) {
    STATE = d;
    renderOauth(d);
    var s = d.stats || {};
    $('#pm_stat_total').text('共 ' + (s.total || 0) + ' 个域');
    $('#pm_stat_verified').text('已验证 ' + (s.verified || 0));
    $('#pm_stat_pending').text('进行中 ' + (s.pending || 0));

    var rows = '';
    (d.domains || []).forEach(function (x) {
      rows += '<tr>' +
        '<td><b>' + esc(x.domain) + '</b>' +
          (x.last_error ? '<div class="text-danger small">' + esc(x.last_error) + '</div>' : '') + '</td>' +
        '<td>' + badge(x.badge.level, x.badge.label) +
          (x.registered ? '<div class="text-muted small">Google 已收录</div>' : '') + '</td>' +
        '<td>' + recordCell(x) + '</td>' +
        '<td>' + healthCell(x.health) + '</td>' +
        '<td>' + actionsCell(x) + '</td>' +
        '</tr>';
    });
    if (!rows) {
      rows = '<tr><td colspan="5" class="text-center text-muted py-3">mailcow 里还没有启用的域名</td></tr>';
    }
    // 模板里的占位行在，容器就不会被 footer.js 删掉；这里整体替换
    body('#pm_domains_body', '#pm_domains_table').html(rows);
  }

  function load() {
    return $.getJSON(PM_URL, { action: 'overview' })
      .done(function (d) {
        updateCsrf(d);
        if (!d || !d.ok) { notice((d && d.message) || '加载失败', true); return; }
        render(d.data);
      })
      .fail(function () { notice('加载失败', true); });
  }

  function afterWrite(d) {
    updateCsrf(d);
    if (d && d.data) { render(d.data); }
  }

  /* ---------------- 事件 ---------------- */

  $('#pm_refresh').on('click', load);
  $('#pm_copy_redirect').on('click', function () {
    copyText($('#pm_redirect_uri').val(), $(this));
  });

  $('#pm_save_oauth').on('click', function () {
    post({
      action: 'set_oauth',
      client_id: $('#pm_client_id').val(),
      client_secret: $('#pm_client_secret').val()
    }).done(function (d) {
      if (!d || !d.ok) { notice((d && d.message) || '保存失败', true); return; }
      $('#pm_client_secret').val('');   // 明文不留在页面上
      notice('已保存。现在可以点「连接 Google 账号」。');
      load();
    }).fail(function () { notice('保存失败', true); });
  });

  $('#pm_connect').on('click', function () {
    post({ action: 'auth_url' }).done(function (d) {
      if (!d || !d.ok) { notice((d && d.message) || '无法发起授权', true); return; }
      window.location.href = d.url;
    }).fail(function () { notice('无法发起授权', true); });
  });

  $('#pm_reconfig').on('click', function () { $('#pm_oauth_off').toggle(); });

  $('#pm_disconnect').on('click', function () {
    if (!window.confirm('断开后会清除已保存的 Google 授权，信誉数据将无法再自动同步。已写入的 DNS 记录与 Google 侧的所有权不受影响。确定断开？')) { return; }
    post({ action: 'disconnect' }).done(function (d) {
      if (!d || !d.ok) { notice((d && d.message) || '断开失败', true); return; }
      notice(d.message);
      load();
    }).fail(function () { notice('断开失败', true); });
  });

  $('#pm_sync').on('click', function () {
    var $b = $(this).prop('disabled', true);
    post({ action: 'sync' }).done(function (d) {
      if (!d || !d.ok) { notice((d && d.message) || '同步失败', true); return; }
      var r = d.result || {};
      var msg = '同步完成：合法域 ' + (r.registered || 0) + ' 个，拉到数据 ' + (r.synced || 0) +
                ' 个，复核通过 ' + (r.verified || 0) + ' 个';
      if (r.errors && r.errors.length) { msg += '；' + r.errors.length + ' 个域有问题，详见各行提示'; }
      notice(msg, r.errors && r.errors.length > 0);
      afterWrite(d);
    }).fail(function () { notice('同步失败', true); })
      .always(function () { $b.prop('disabled', false); });
  });

  // 行内按钮（事件委托：表格是整体重绘的）
  $('#pm_domains_table').on('click', '.pm-copy-fqdn', function () { copyText($(this).data('v'), $(this)); });
  $('#pm_domains_table').on('click', '.pm-copy-val', function () { copyText($(this).data('v'), $(this)); });

  $('#pm_domains_table').on('click', '.pm-fetch', function () {
    var dm = $(this).data('d');
    post({ action: 'fetch_token', domain: dm, method: 'DNS_TXT' }).done(function (d) {
      if (!d || !d.ok) { notice((d && d.message) || '取记录失败', true); return; }
      notice(dm + '：' + d.message);
      afterWrite(d);
    }).fail(function () { notice('取记录失败', true); });
  });

  $('#pm_domains_table').on('click', '.pm-verify', function () {
    var dm = $(this).data('d');
    post({ action: 'verify', domain: dm }).done(function (d) {
      if (!d || !d.ok) { notice((d && d.message) || '验证未通过', true); return; }
      notice(dm + '：' + d.message);
      afterWrite(d);
    }).fail(function () { notice('验证请求失败', true); });
  });

  $('#pm_domains_table').on('click', '.pm-manual', function () {
    $('#pm_manual_domain').val($(this).data('d'));
    $('#pm_manual_token').focus();
    notice('已填入域名，请粘贴 Google 给的记录值后点「写入并保存」');
  });

  $('#pm_domains_table').on('click', '.pm-remove', function () {
    var dm = $(this).data('d');
    if (!window.confirm('仅移除本站里 ' + dm + ' 的状态记录。DNS 记录与 Google 侧的所有权不会被改动。确定？')) { return; }
    post({ action: 'remove', domain: dm }).done(function (d) {
      if (!d || !d.ok) { notice((d && d.message) || '移除失败', true); return; }
      notice(d.message);
      afterWrite(d);
    }).fail(function () { notice('移除失败', true); });
  });

  $('#pm_manual_save').on('click', function () {
    var dm = $('#pm_manual_domain').val();
    var tk = $('#pm_manual_token').val();
    if (!dm || !tk) { notice('域名和记录值都要填', true); return; }
    post({ action: 'save_manual', domain: dm, method: $('#pm_manual_method').val(), token: tk })
      .done(function (d) {
        if (!d || !d.ok) { notice((d && d.message) || '保存失败', true); return; }
        $('#pm_manual_token').val('');
        notice(dm + '：' + d.message);
        afterWrite(d);
      }).fail(function () { notice('保存失败', true); });
  });

  /* ---------------- 启动 ---------------- */
  // OAuth 回调跳回来时会带 ?pm=ok|err&pm_msg=…，读出来给用户看，然后把参数从地址栏清掉
  // （不清的话用户刷新页面会重复看到同一条旧消息，分不清是新结果还是上次的）。
  (function () {
    var m = window.location.search.match(/[?&]pm=(ok|err)(?:&pm_msg=([^&]*))?/);
    if (m) {
      notice(decodeURIComponent(m[2] || (m[1] === 'ok' ? '已连接' : '操作失败')), m[1] === 'err');
      if (window.history && window.history.replaceState) {
        window.history.replaceState({}, '', window.location.pathname + window.location.hash);
      }
    }
  })();

  load();
});
