/*
 * Resend 号池 — 前端逻辑
 *
 * 加载方式说明（重要）：
 *   本文件不在 tab 模板内用 <script src> 引入，而是在 admin.twig 里通过
 *   动态插入的方式挂到页面底部加载。
 *   原因：tab 模板渲染于 base.twig 的 {% block content %} 内（第 154 行），
 *   而 jQuery 在更晚的第 159 行才加载。若直接在 tab 内写脚本，
 *   jQuery 尚未定义会抛 ReferenceError，脚本静默失效。
 *
 *   另外 csrf_token 由 admin.twig 在 block 内用 <script> 定义，
 *   因此运行时要读 window.csrf_token 而不是闭包变量。
 */
(function () {
  var RP_URL = '/inc/ajax/resend_pool.php';

  // csrf_token 由 admin.twig 注入到 window（var 声明在全局作用域）
  // 服务端每次 POST 后会轮换 token，响应里带回新值，优先使用最新的
  function csrf() {
    if (typeof window.__rp_csrf === 'string' && window.__rp_csrf) { return window.__rp_csrf; }
    return (typeof window.csrf_token !== 'undefined' && window.csrf_token) ? window.csrf_token : '';
  }
  function updateCsrf(d) {
    if (d && typeof d.csrf === 'string' && d.csrf) { window.__rp_csrf = d.csrf; }
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function post(data) {
    return jQuery.ajax({
      url: RP_URL, type: 'POST',
      data: Object.assign({ csrf_token: csrf() }, data),
      dataType: 'json'
    }).always(function (d) { updateCsrf(d); });
  }
  function get(data) {
    return jQuery.ajax({ url: RP_URL, type: 'GET', data: data, dataType: 'json' });
  }
  function statusBadge(s) {
    var map = {
      'verified': 'bg-success', 'partially_verified': 'bg-warning text-dark',
      'pending': 'bg-info text-dark', 'not_started': 'bg-secondary',
      'failed': 'bg-danger', 'temporary_failure': 'bg-danger'
    };
    var cls = map[s] || 'bg-secondary';
    return '<span class="badge ' + cls + '">' + esc(s || '未知') + '</span>';
  }

  var state = { accounts: [], domains: [] };

  function render(data) {
    state.accounts = data.accounts || [];
    state.domains = data.domains || [];

    jQuery('#rp_limit').val(data.limit);
    jQuery('#rp_cf_state').text(data.cf_configured ? '已配置（绑定域名时会自动写 DNS）' : '未配置（需手工把 DNS 记录加到 Cloudflare）');

    // 账号表
    if (!state.accounts.length) {
      jQuery('#rp_accounts_body').html('<tr><td colspan="6" class="text-muted">还没有账号，请在下方添加</td></tr>');
    } else {
      var h = '';
      state.accounts.forEach(function (a) {
        var pct = a.domain_limit ? Math.round(100 * a.domain_count / a.domain_limit) : 0;
        var bar = '<div class="progress" style="height:6px"><div class="progress-bar" style="width:' + pct + '%"></div></div>' +
                  '<small class="text-muted">' + a.domain_count + ' / ' + a.domain_limit + '</small>';
        h += '<tr>' +
          '<td>' + esc(a.label) + '</td>' +
          '<td><code>' + esc(a.api_key_masked) + '</code></td>' +
          '<td>' + bar + '</td>' +
          '<td>' + (a.active == 1 ? '<span class="badge bg-success">启用</span>' : '<span class="badge bg-secondary">停用</span>') + '</td>' +
          '<td><small class="text-muted">' + esc(a.check_status || '—') + '</small></td>' +
          '<td>' +
            '<button class="btn btn-sm btn-outline-secondary rp-toggle" data-id="' + a.id + '" data-active="' + (a.active == 1 ? 0 : 1) + '">' + (a.active == 1 ? '停用' : '启用') + '</button> ' +
            '<button class="btn btn-sm btn-outline-danger rp-del-acc" data-id="' + a.id + '">删除</button>' +
          '</td></tr>';
      });
      jQuery('#rp_accounts_body').html(h);
    }

    // 账号下拉
    var opts = '<option value="">选择账号…</option>';
    state.accounts.filter(function (a) { return a.active == 1; }).forEach(function (a) {
      opts += '<option value="' + a.id + '">' + esc(a.label) + '（' + a.domain_count + '/' + a.domain_limit + '）</option>';
    });
    jQuery('#rp_assign_account').html(opts);

    // 域名表
    if (!state.domains.length) {
      jQuery('#rp_domains_body').html('<tr><td colspan="5" class="text-muted">还没有绑定任何域名</td></tr>');
    } else {
      var dh = '';
      state.domains.forEach(function (d) {
        var recs = [];
        try { recs = JSON.parse(d.records_json || '[]') || []; } catch (e) { recs = []; }
        var recText = recs.length
          ? recs.map(function (r) { return '<div><code>' + esc(r.type) + '</code> ' + esc(r.name) + ' <span class="text-muted">' + esc(String(r.status || '')) + '</span></div>'; }).join('')
          : '<span class="text-muted">—</span>';
        dh += '<tr>' +
          '<td><b>' + esc(d.domain) + '</b></td>' +
          '<td>' + esc(d.account_label || ('#' + d.account_id)) + '</td>' +
          '<td>' + statusBadge(d.status) + '</td>' +
          '<td><small>' + recText + '</small></td>' +
          '<td><button class="btn btn-sm btn-outline-danger rp-del-dom" data-domain="' + esc(d.domain) + '">移除</button></td>' +
          '</tr>';
      });
      jQuery('#rp_domains_body').html(dh);
    }
  }

  function load() {
    get({ action: 'list' }).done(function (r) {
      if (!r.ok) { jQuery('#rp_accounts_body').html('<tr><td colspan="6" class="text-danger">' + esc(r.message) + '</td></tr>'); return; }
      render(r);
    }).fail(function (x) {
      jQuery('#rp_accounts_body').html('<tr><td colspan="6" class="text-danger">加载失败</td></tr>');
    });
  }

  jQuery(function () {
    load();

    jQuery('#rp_refresh').on('click', load);

    jQuery('#rp_test_key').on('click', function () {
      var k = jQuery('#rp_new_key').val().trim();
      if (!k) { jQuery('#rp_key_result').html('<span class="text-danger">请输入 API Key</span>'); return; }
      jQuery('#rp_key_result').html('<span class="text-muted">测试中…</span>');
      post({ action: 'check_key', api_key: k }).done(function (r) {
        jQuery('#rp_key_result').html(r.ok
          ? '<span class="text-success">✓ 有效（' + esc(r.message) + '）</span>'
          : '<span class="text-danger">✗ ' + esc(r.message) + '</span>');
      });
    });

    jQuery('#rp_add_account').on('click', function () {
      var k = jQuery('#rp_new_key').val().trim();
      var l = jQuery('#rp_new_label').val().trim();
      if (!k) { jQuery('#rp_key_result').html('<span class="text-danger">请输入 API Key</span>'); return; }
      jQuery('#rp_key_result').html('<span class="text-muted">添加中…</span>');
      post({ action: 'add_account', api_key: k, label: l }).done(function (r) {
        jQuery('#rp_key_result').html(r.ok
          ? '<span class="text-success">✓ ' + esc(r.message) + '</span>'
          : '<span class="text-danger">✗ ' + esc(r.message) + '</span>');
        if (r.ok) { jQuery('#rp_new_key').val(''); jQuery('#rp_new_label').val(''); load(); }
      });
    });

    jQuery('#rp_accounts_body').on('click', '.rp-toggle', function () {
      post({ action: 'edit_account', id: jQuery(this).data('id'), active: jQuery(this).data('active') })
        .done(function () { load(); });
    });
    jQuery('#rp_accounts_body').on('click', '.rp-del-acc', function () {
      if (!confirm('确定删除该账号？')) return;
      post({ action: 'delete_account', id: jQuery(this).data('id') }).done(function (r) {
        if (!r.ok) alert(r.message);
        load();
      });
    });

    jQuery('#rp_save_limit').on('click', function () {
      post({ action: 'set_limit', limit: jQuery('#rp_limit').val() }).done(function (r) {
        alert(r.message); load();
      });
    });

    jQuery('#rp_save_cf').on('click', function () {
      post({ action: 'set_cf_token', cf_token: jQuery('#rp_cf_token').val() }).done(function (r) {
        jQuery('#rp_cf_state').text(r.message);
        jQuery('#rp_cf_token').val('');
        load();
      });
    });
    jQuery('#rp_clear_cf').on('click', function () {
      post({ action: 'set_cf_token', cf_token: '' }).done(function (r) { load(); });
    });

    jQuery('#rp_assign_btn').on('click', function () {
      var d = jQuery('#rp_assign_domain').val().trim();
      var a = jQuery('#rp_assign_account').val();
      if (!d || !a) { alert('请填写域名并选择账号'); return; }
      jQuery(this).prop('disabled', true).text('绑定中…');
      var btn = jQuery(this);
      post({ action: 'assign', domain: d, account_id: a }).done(function (r) {
        alert(r.message);
        if (r.ok) jQuery('#rp_assign_domain').val('');
        load();
      }).always(function () { btn.prop('disabled', false).text('绑定'); });
    });

    jQuery('#rp_auto_assign').on('click', function () {
      if (!confirm('将把 mailcow 中所有启用的域名自动分配到号池账号，继续？')) return;
      var btn = jQuery(this);
      btn.prop('disabled', true).text('分配中…');
      post({ action: 'auto_assign' }).done(function (r) {
        var msg = r.message || '';
        if (r.assigned && r.assigned.length) msg += '\n\n成功：\n' + r.assigned.join('\n');
        if (r.failed && r.failed.length) msg += '\n\n失败：\n' + r.failed.join('\n');
        alert(msg);
        load();
      }).always(function () { btn.prop('disabled', false).html('<i class="bi bi-magic"></i> 自动分配全部域名'); });
    });

    jQuery('#rp_sync').on('click', function () {
      var btn = jQuery(this);
      btn.prop('disabled', true).text('同步中…');
      post({ action: 'sync' }).done(function (r) {
        alert('已同步 ' + (r.updated || 0) + ' 个域名' + ((r.errors && r.errors.length) ? '\n错误：' + r.errors.join('\n') : ''));
        load();
      }).always(function () { btn.prop('disabled', false).html('<i class="bi bi-cloud-download"></i> 同步验证状态'); });
    });

    jQuery('#rp_domains_body').on('click', '.rp-del-dom', function () {
      var d = jQuery(this).data('domain');
      if (!confirm('移除 ' + d + ' ？\n（Resend 侧会删除该域名，mailcow 侧解除中继绑定）')) return;
      post({ action: 'remove_domain', domain: d }).done(function (r) {
        alert(r.message); load();
      });
    });
  });
})();
