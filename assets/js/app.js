/* ============ IPMI Web —— 前端主逻辑 ============ */
(function () {
  'use strict';

  // 统一 fetch JSON 封装：处理非 2xx / 非 JSON 的情况
  async function api(action, opts = {}) {
    const base = (location.pathname.replace(/\/$/, '') || '') + '/api/' + action;
    const params = new URLSearchParams(opts.query || {}).toString();
    const url = params ? base + '?' + params : base;
    const init = { method: opts.method || 'GET', headers: {} };
    if (opts.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    const resp = await fetch(url, init);
    const ct = resp.headers.get('content-type') || '';
    let data;
    if (ct.includes('application/json')) {
      data = await resp.json();
    } else {
      data = { ok: false, error: await resp.text() || '服务器返回非 JSON 数据' };
    }
    if (resp.status === 401) {
      showLogin();
    }
    return { ok: resp.ok, status: resp.status, data };
  }

  function toast(msg, ms = 2200) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.remove('hidden');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.add('hidden'), ms);
  }

  // ============ 登录 ============
  async function init() {
    const { data: ping } = await api('ping');
    if (ping.demo) document.getElementById('badge-demo').classList.remove('hidden');

    const { data: me } = await api('whoami');
    if (me.logged) {
      document.getElementById('whoami').textContent = me.user;
      showApp();
    } else {
      showLogin();
    }
  }

  function showLogin() {
    document.getElementById('view-app').classList.add('hidden');
    document.getElementById('view-login').classList.remove('hidden');
    document.getElementById('form-login').reset();
    setTimeout(() => document.querySelector('#view-login input[name=user]').focus(), 30);
  }
  function showApp() {
    document.getElementById('view-login').classList.add('hidden');
    document.getElementById('view-app').classList.remove('hidden');
    loadDashboard();
    bindTabs();
    bindCommon();
  }

  document.addEventListener('submit', async (e) => {
    if (e.target.id !== 'form-login') return;
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await api('login', { method: 'POST', body: Object.fromEntries(fd) });
    if (res.status === 200 && res.data.ok) {
      toast('登录成功');
      document.getElementById('whoami').textContent = Object.fromEntries(fd).user;
      showApp();
    } else {
      document.getElementById('login-msg').textContent = res.data.error || '登录失败';
    }
  });

  document.getElementById('btn-logout').addEventListener('click', async () => {
    await api('logout');
    showLogin();
  });

  // ============ Tab 切换 ============
  function bindTabs() {
    if (bindTabs._done) return; bindTabs._done = true;
    document.querySelectorAll('#tabs button').forEach(btn => {
      btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });
  }
  function switchTab(tab) {
    document.querySelectorAll('#tabs button').forEach(b =>
      b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.tab-content').forEach(s =>
      s.classList.toggle('hidden', s.dataset.pane !== tab));
    if (tab === 'dashboard') loadDashboard();
    if (tab === 'bmcs') loadBmcTable();
    if (tab === 'console') loadConsoleSelect();
  }

  function bindCommon() {
    document.getElementById('btn-refresh-dash').onclick = loadDashboard;
    document.getElementById('btn-add-bmc').onclick = () => openBmcModal();

    // BMC 模态框通用关闭
    document.querySelectorAll('.modal-close, .modal-cancel').forEach(el => {
      el.addEventListener('click', () => {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('confirm').classList.add('hidden');
      });
    });
    document.querySelectorAll('.modal').forEach(m => {
      m.addEventListener('click', (e) => { if (e.target === m) m.classList.add('hidden'); });
    });

    // console 快捷命令
    document.getElementById('sel-quick').addEventListener('change', (e) => {
      const v = e.target.value;
      if (v) document.getElementById('cmd-input').value = v;
    });

    // console 执行
    document.getElementById('cmd-run').addEventListener('click', runCmdFromConsole);
    document.getElementById('cmd-input').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') runCmdFromConsole();
    });

    // 电源控制按钮
    document.getElementById('pw-on').addEventListener('click',    () => powerAction('on',    '确定要开机吗？'));
    document.getElementById('pw-off').addEventListener('click',   () => powerAction('off',   '⚠ 确定要关机吗？'));
    document.getElementById('pw-cycle').addEventListener('click',  () => powerAction('cycle','⚠ 确定要强制重启吗？'));
    document.getElementById('pw-reset').addEventListener('click', () => powerAction('reset','⚠ 确定要复位吗？'));
    document.getElementById('pw-soft').addEventListener('click',  () => powerAction('soft', '⚠ 确定要软关机吗？'));

    // BMC 表单保存
    document.getElementById('form-bmc').addEventListener('submit', saveBmc);

    // ESC 关闭模态框
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('confirm').classList.add('hidden');
      }
    });
  }

  // ============ 仪表盘 ============
  async function loadDashboard() {
    const grid = document.getElementById('dash-grid');
    grid.innerHTML = '<div style="color:#94a3b8;padding:20px;">加载中…</div>';
    const res = await api('dashboard');
    grid.innerHTML = '';
    if (!res.data.ok || !res.data.data.length) {
      grid.innerHTML = '<div style="color:#94a3b8;padding:20px;">暂无 BMC 目标，请到「BMC 目标」页添加。</div>';
      return;
    }
    for (const item of res.data.data) {
      const card = document.createElement('div');
      card.className = 'card';
      const power    = item.status.power || 'unknown';
      const cls      = !item.ok ? 'st-err' : power === 'on' ? 'st-ok' : power === 'off' ? 'st-off' : 'st-warn';
      const label    = !item.ok ? '通信失败' : power === 'on' ? '已开机' : power === 'off' ? '已关机' : power;
      const extra    = [];
      if (item.status.cooling_fault) extra.push('风扇故障');
      if (item.status.drive_fault)   extra.push('磁盘故障');
      card.innerHTML = `
        <div class="card-head">
          <div>
            <div class="card-name">${escape(item.name)}</div>
            <div class="card-host">${escape(item.host)}</div>
          </div>
          <span class="card-status ${cls}">${label}</span>
        </div>
        ${extra.length ? `<div style="font-size:12px;color:#b45309;">${extra.join(' · ')}</div>` : ''}
        <div class="card-row">
          <button class="btn" data-act="status" data-id="${item.id}">↻ 状态</button>
          <button class="btn btn-green" data-act="on" data-id="${item.id}">▶ 开机</button>
          <button class="btn btn-red"   data-act="off" data-id="${item.id}">■ 关机</button>
          <button class="btn btn-orange" data-act="cycle" data-id="${item.id}">⟳ 重启</button>
        </div>`;
      card.querySelectorAll('button[data-act]').forEach(b => {
        b.addEventListener('click', () => cardPower(item.id, b.dataset.act));
      });
      grid.appendChild(card);
    }
  }

  async function cardPower(id, act) {
    if (act !== 'status' && act !== 'on') {
      if (!await confirmDlg(`⚠ 确定执行「${act}」操作吗？`)) return;
    }
    const res = await api('chassis_power', { query: { id, op: act } });
    if (res.data.ok && res.data.data) {
      showOutput(res.data.data.stdout + (res.data.data.stderr ? '\n--- STDERR ---\n' + res.data.data.stderr : ''), !res.data.data.ok);
    }
    loadDashboard();
  }

  // ============ BMC 管理 ============
  async function loadBmcTable() {
    const tbody = document.querySelector('#bmc-table tbody');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px;">加载中…</td></tr>';
    const res = await api('bmcs');
    tbody.innerHTML = '';
    if (!res.data.data.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:20px;">暂无数据，点击右上角「+ 新增 BMC」添加。</td></tr>';
      return;
    }
    for (const b of res.data.data) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escape(b.name)}</td>
        <td class="mono">${escape(b.host)}:${b.port || 623}</td>
        <td>${escape(b.user)}</td>
        <td>${escape(b.interface)}</td>
        <td>${escape(b.privl || 'USER')}</td>
        <td>
          <button class="btn btn-ghost" data-edit="${b.id}">编辑</button>
          <button class="btn btn-ghost" data-del="${b.id}" style="color:#dc2626;">删除</button>
        </td>`;
      tbody.appendChild(tr);
    }
    tbody.querySelectorAll('[data-edit]').forEach(btn => btn.onclick = () => openBmcModal(parseInt(btn.dataset.edit)));
    tbody.querySelectorAll('[data-del]').forEach(btn => btn.onclick = () => deleteBmc(parseInt(btn.dataset.del)));
  }

  async function openBmcModal(id) {
    const modal = document.getElementById('modal');
    const form  = document.getElementById('form-bmc');
    document.getElementById('modal-title').textContent = id ? '编辑 BMC' : '新增 BMC';
    form.reset();
    if (id) {
      const { data } = await api('bmcs');
      const b = (data.data || []).find(x => x.id === id);
      if (!b) return;
      b.pass = ''; // 编辑时密码留空 = 不修改
      Object.entries(b).forEach(([k, v]) => { if (form.elements[k]) form.elements[k].value = v; });
    }
    modal.classList.remove('hidden');
    setTimeout(() => form.elements.name.focus(), 30);
  }

  async function saveBmc(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd);
    const id = parseInt(data.id || 0);
    delete data.id;
    const res = await api(id ? 'bmcs_update?id=' + id : 'bmcs', {
      method: id ? 'POST' : 'POST', body: data,
    });
    if (res.data.ok) {
      toast(id ? '已更新' : '已添加');
      document.getElementById('modal').classList.add('hidden');
      loadBmcTable();
      loadConsoleSelect();
      loadDashboard();
    } else {
      toast(res.data.error || '保存失败');
    }
  }

  async function deleteBmc(id) {
    if (!await confirmDlg('⚠ 确定删除该 BMC 目标吗？')) return;
    const res = await api('bmcs_delete', { query: { id } });
    if (res.data.ok) {
      toast('已删除');
      loadBmcTable();
      loadConsoleSelect();
      loadDashboard();
    } else {
      toast(res.data.error || '删除失败');
    }
  }

  // ============ Console ============
  async function loadConsoleSelect() {
    const sel = document.getElementById('sel-bmc');
    const cur = sel.value;
    const res = await api('bmcs');
    sel.innerHTML = '';
    if (!res.data.data.length) {
      const op = document.createElement('option');
      op.textContent = '（请先在「BMC 目标」页添加）';
      op.disabled = true;
      sel.appendChild(op);
      return;
    }
    res.data.data.forEach(b => {
      const op = document.createElement('option');
      op.value = b.id;
      op.textContent = `${b.name} — ${b.host}`;
      sel.appendChild(op);
    });
    if (cur && [...sel.options].some(o => o.value === cur)) sel.value = cur;
  }

  async function runCmdFromConsole() {
    const id = parseInt(document.getElementById('sel-bmc').value);
    const cmd = document.getElementById('cmd-input').value.trim();
    if (!id) { toast('请先选择 BMC'); return; }
    if (!cmd) { toast('请输入命令'); return; }
    document.getElementById('cmd-output').classList.remove('err');
    document.getElementById('cmd-output').textContent = '执行中…';
    const res = await api('ipmi_run', { method: 'POST', body: { id, cmd } });
    if (res.data.ok && res.data.data) {
      const d = res.data.data;
      const out = (d.stdout || '') + (d.stderr ? '\n--- STDERR ---\n' + d.stderr : '');
      showOutput(out, !d.ok);
    } else {
      showOutput(res.data.error || '请求失败', true);
    }
  }

  function showOutput(text, isErr) {
    const box = document.getElementById('cmd-output');
    box.textContent = text || '(无输出)';
    box.classList.toggle('err', !!isErr);
    box.scrollTop = 0;
  }

  // ============ 电源操作 ============
  async function powerAction(action, msg) {
    const sel = document.getElementById('sel-bmc');
    const id = parseInt(sel.value);
    if (!id) { toast('请先选择 BMC'); return; }
    if (action !== 'status' && action !== 'on') {
      if (!await confirmDlg(msg)) return;
    }
    const res = await api('chassis_power', { query: { id, op: action } });
    if (res.data.ok && res.data.data) {
      const d = res.data.data;
      showOutput((d.stdout || '') + (d.stderr ? '\n--- STDERR ---\n' + d.stderr : ''), !d.ok);
    } else {
      showOutput(res.data.error || '请求失败', true);
    }
  }

  // ============ 工具 ============
  function escape(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  }

  function confirmDlg(msg, title = '确认') {
    return new Promise(resolve => {
      const box = document.getElementById('confirm');
      document.getElementById('confirm-title').textContent = title;
      document.getElementById('confirm-msg').textContent = msg;
      const ok = document.getElementById('confirm-ok');
      const cancel = document.getElementById('confirm-cancel');
      const close = () => box.classList.add('hidden');
      const onOk = () => { cleanup(); close(); resolve(true); };
      const onCancel = () => { cleanup(); close(); resolve(false); };
      function cleanup() { ok.onclick = null; cancel.onclick = null; }
      ok.onclick = onOk; cancel.onclick = onCancel;
      box.classList.remove('hidden');
    });
  }

  init();
})();
