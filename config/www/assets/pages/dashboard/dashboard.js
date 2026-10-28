
(() => {
  'use strict';

  let openDropdown = null;

  const closeMenu = (restoreFocus = false) => {
    if (!openDropdown) return;
    const trigger = openDropdown.querySelector('[data-device-action-trigger]');
    const menu = openDropdown.querySelector('[data-device-action-list]');
    if (menu) menu.hidden = true;
    trigger?.setAttribute('aria-expanded', 'false');
    if (restoreFocus && trigger instanceof HTMLElement && trigger.isConnected) trigger.focus();
    openDropdown = null;
  };

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const trigger = target.closest('[data-device-action-trigger]');
    if (trigger instanceof HTMLButtonElement) {
      const dropdown = trigger.closest('[data-device-action-menu]');
      const menu = dropdown?.querySelector('[data-device-action-list]');
      if (!(dropdown instanceof HTMLElement) || !(menu instanceof HTMLElement)) return;
      if (openDropdown === dropdown) { closeMenu(); return; }
      closeMenu();
      openDropdown = dropdown;
      menu.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      const anchor = trigger.getBoundingClientRect();
      const menuWidth = menu.offsetWidth;
      const menuHeight = menu.offsetHeight;
      menu.style.left = `${Math.max(8, Math.min(anchor.right - menuWidth, window.innerWidth - menuWidth - 8))}px`;
      menu.style.top = `${anchor.bottom + menuHeight + 8 <= window.innerHeight
        ? anchor.bottom + 4 : Math.max(8, anchor.top - menuHeight - 4)}px`;
      menu.querySelector('[role="menuitem"]')?.focus({ preventScroll: true });
      return;
    }
    if (openDropdown && !openDropdown.contains(target)) closeMenu();
  });

  document.addEventListener('keydown', (event) => {
    if (!openDropdown) return;
    if (event.key === 'Escape') { event.preventDefault(); closeMenu(true); return; }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    const items = [...openDropdown.querySelectorAll('[role="menuitem"]')];
    const index = items.indexOf(document.activeElement);
    if (index < 0) return;
    event.preventDefault();
    items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
  });

  document.addEventListener('scroll', () => closeMenu(), true);
  window.addEventListener('resize', () => closeMenu());
})();

(() => {
  'use strict';

  const form = document.querySelector('[data-recent-activity-filter-form]');
  const list = document.querySelector('[data-recent-activity-list]');
  if (!(form instanceof HTMLFormElement) || !list) return;

  const search = form.querySelector('[data-recent-activity-search]');
  const filters = [...form.querySelectorAll('[data-recent-activity-filter]')];
  const pagination = document.querySelector('.recent-activity-pagination');
  const resultsHost = list.closest('[data-recent-activity-results]') || list;
  const host = document.createElement('div');
  host.className = 'onu-search-host';
  resultsHost.before(host);
  host.append(resultsHost);
  const overlay = document.createElement('div');
  overlay.className = 'onu-search-status';
  overlay.hidden = true;
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-live', 'polite');
  const badge = document.createElement('span');
  badge.className = 'onu-loading-badge';
  const spinner = document.createElement('i');
  spinner.className = 'bi bi-arrow-repeat sync-loading-spinner';
  spinner.setAttribute('aria-hidden', 'true');
  badge.append(spinner);
  overlay.append(badge);
  host.append(overlay);
  const positionOverlay = () => {
    const rect = host.getBoundingClientRect();
    const top = Math.max(rect.top, 0);
    const bottom = Math.min(rect.bottom, window.innerHeight);
    overlay.style.top = `${Math.max(0, top - rect.top)}px`;
    overlay.style.height = `${Math.max(0, bottom - top)}px`;
  };
  window.addEventListener('resize', () => { if (!overlay.hidden) positionOverlay(); });
  document.addEventListener('scroll', () => { if (!overlay.hidden) positionOverlay(); }, { capture: true, passive: true });
  const DEBOUNCE_MS = 300;
  let debounceTimer = 0;
  let request = null;
  let page = Math.max(1, Number.parseInt(list.dataset.activityPage || '1', 10) || 1);
  let stopped = false;
  let pendingRefreshTimer = 0;
  const activeDiscoveryPolls = new Set();

  const pendingDeviceRows = () => [...document.querySelectorAll('#devicesTable tr[data-device-row-id]')]
    .filter((row) => row.querySelector('.conn-time.is-pending, .conn-time .danger, .conn-chip[data-connection-state="unknown"]'));

  const setScanProgress = (time, percent, label) => {
    const value = Math.max(0, Math.min(100, Number.isFinite(Number(percent)) ? Number(percent) : 0));
    let bar = time.querySelector('.sync-progress');
    if (!bar) {
      bar = document.createElement('span');
      bar.className = 'sync-progress';
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      const fill = document.createElement('span');
      fill.className = 'sync-progress-fill';
      bar.append(fill);
      time.replaceChildren(bar);
    }
    const fill = bar.querySelector('.sync-progress-fill');
    const current = Number(bar.getAttribute('aria-valuenow') || 0);
    const next = Math.max(Number.isFinite(current) ? current : 0, value);
    if (fill) fill.style.width = `${next}%`;
    bar.setAttribute('aria-valuenow', String(next));
    if (label) bar.setAttribute('aria-label', label);
    if (label) time.setAttribute('aria-label', label);
    time.closest('.conn-cell')?.setAttribute('title', label || 'Sinkronisasi OLT sedang berjalan');
  };

  const updateScanProgress = async (row) => {
    const time = row.querySelector('.conn-time.is-pending');
    if (!time || !row.querySelector('[data-dashboard-discovery]')) return;
    const response = await window.linnmsDashboardAjax.scanStatus(row.dataset.deviceRowId || '');
    if (!response.ok) return;
    const result = await response.json();
    if (result.ok !== true) return;
    if (!result.active) {
      if (result.status === 'failed' || result.status === 'done') void load();
      return;
    }
    const label = String(result.phase || result.progress_label || 'Membaca perangkat');
    setScanProgress(time, result.progress, label);
  };

  const scheduleDeviceRefresh = () => {
    const rows = pendingDeviceRows();
    if (rows.length === 0 || stopped) return;
    const delay = rows.some((row) => row.querySelector('.conn-time.is-pending')) ? 5000 : 15000;
    pendingRefreshTimer = window.setTimeout(async () => {
      pendingRefreshTimer = 0;
      const currentRows = pendingDeviceRows();
      const ids = currentRows.map((row) => row.dataset.deviceRowId).filter(Boolean);
      if (ids.length === 0) return;
      try {
        const response = await window.linnmsDashboardAjax.connectionStatus(ids);
        if (response.status === 401) { stopped = true; return; }
        const payload = await response.json();
        if (!response.ok || payload.ok !== true) throw new Error('Status perangkat belum dapat dibaca.');
        await Promise.all(currentRows.map((row) => updateScanProgress(row)));
        const changed = currentRows.some((row) => {
          const state = payload.statuses?.[row.dataset.deviceRowId];
          if (!state) return false;
          if (row.querySelector('.conn-time.is-pending')) {
            if (state.scan_active) return false;
            return state.scan_failed || state.seen || !['', 'Belum Sync'].includes(state.status);
          }
          if (row.querySelector('.conn-time .danger')) return state.scan_active || (!state.scan_failed && state.seen);
          return !['', 'Belum Sync'].includes(state.status);
        });
        if (changed) { void load(); return; }
      } catch (error) {
        console.error(error);
      }
      scheduleDeviceRefresh();
    }, delay);
  };

  const params = () => {
    const query = new URLSearchParams({ type: 'recent-activity', activity_page: String(page), include_dashboard: '1' });
    const term = search instanceof HTMLInputElement ? search.value.trim() : '';
    if (term !== '') query.set('activity_q', term);
    filters.forEach((filter) => {
      const value = String(filter.value ?? '').trim();
      if (value !== '' && filter.name) query.set(filter.name, value);
    });
    return query;
  };

  const syncAddress = () => {
    const query = params();
    query.delete('type');
    const suffix = query.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${suffix === '' ? '' : '?' + suffix}`);
  };

  const pageInfo = document.querySelector('[data-activity-page-info]');

  const setLoading = (loading) => {
    if (loading) positionOverlay();
    overlay.hidden = !loading;
    overlay.setAttribute('aria-label', 'Pencarian aktivitas sedang diproses');
    host.classList.toggle('is-loading', loading);
    list.setAttribute('aria-busy', String(loading));
    if (pagination instanceof HTMLElement) pagination.inert = loading;
  };

  const expandedRows = () => [...list.querySelectorAll('.public-log-entry.is-expanded')]
    .map((row) => row.textContent.trim())
    .filter((text) => text !== '');

  const restoreExpanded = (keys) => {
    if (keys.length === 0) return;
    list.querySelectorAll('.public-log-entry').forEach((row) => {
      const key = row.textContent.trim();
      if (!keys.includes(key)) return;
      row.classList.add('is-expanded', 'is-expandable');
      const button = row.querySelector('[data-log-expand-toggle]');
      if (button instanceof HTMLElement) {
        button.hidden = false;
        button.setAttribute('aria-expanded', 'true');
      }
    });
  };

  const applyPayload = (payload) => {
    const html = typeof payload.html === 'string' ? payload.html : '';
    if (html.trim() === '') {



      console.warn('Aktivitas Terbaru: jawaban server tanpa isi, tampilan dipertahankan.', {
        total: payload.activity_total ?? null,
        page: payload.activity_page ?? null,
        pages: payload.activity_pages ?? null,
      });
      return false;
    }

    const expanded = expandedRows();
    list.innerHTML = html;
    restoreExpanded(expanded);
    list.dataset.activityPage = String(page);
    list.dataset.activityPages = String(payload.activity_pages || 1);
    if (pageInfo instanceof HTMLElement) pageInfo.textContent = String(payload.page_info || '');
    if (pagination instanceof HTMLElement && typeof payload.pagination_html === 'string') {
      const template = document.createElement('template');
      template.innerHTML = payload.pagination_html;
      const nextPagination = template.content.querySelector('.recent-activity-pagination');
      if (nextPagination) {
        pagination.innerHTML = nextPagination.innerHTML;
        pagination.hidden = nextPagination.hidden;
      }
    }
    syncAddress();
    const devicesTable = document.querySelector('#devicesTable');
    if (devicesTable && typeof payload.devices_html === 'string' && payload.devices_html.trim() !== '') {
      const wrapper = document.createElement('div');
      wrapper.innerHTML = payload.devices_html;
      const nextTable = wrapper.querySelector('#devicesTable');
      if (nextTable) devicesTable.replaceWith(nextTable);
    }
    if (payload.dashboard_stats && typeof payload.dashboard_stats === 'object') {
      Object.entries(payload.dashboard_stats).forEach(([key, value]) => {
        const node = document.querySelector('[data-dashboard-stat="' + key + '"]');
        if (node) node.textContent = String(value);
      });
    }
    return true;
  };

  const load = async (showLoading = false, resetScroll = false) => {
    if (stopped) return;
    window.clearTimeout(pendingRefreshTimer);
    pendingRefreshTimer = 0;
    if (request) request.abort();
    const controller = new AbortController();
    request = controller;
    let failed = false;
    let responseStatus = 0;
    if (showLoading) setLoading(true);
    try {
      const [response] = await Promise.all([
        window.linnmsDashboardAjax.recentActivity(params(), controller.signal),
        showLoading ? new Promise((resolve) => window.setTimeout(resolve, 250)) : Promise.resolve(),
      ]);
      responseStatus = response.status;
      if (response.status === 401) {
        stopped = true;
        if (typeof window.linnmsNotify === 'function') window.linnmsNotify('error', 'Sesi berakhir. Silakan login kembali untuk memuat aktivitas.');
        return;
      }
      const payload = await response.json().catch(() => null);
      if (!response.ok) throw new Error(payload?.message || `Request aktivitas gagal (HTTP ${response.status}).`);
      if (!payload || payload.ok !== true) {
        throw new Error(payload?.message || 'Respons aktivitas tidak valid.');
      }
      page = Math.max(1, Number(payload.activity_page || 1));
      if (applyPayload(payload) && resetScroll) resultsHost.scrollTop = 0;
    } catch (error) {
      if (error?.name !== 'AbortError') {
        failed = true;
        console.error(error);
        const message = String(error?.message || '').trim()
          || (responseStatus > 0 ? `Aktivitas gagal dimuat (HTTP ${responseStatus}).` : 'Aktivitas gagal dimuat.');
        if (typeof window.linnmsNotify === 'function') window.linnmsNotify('error', message);
      }
    } finally {
      if (request === controller) {
        request = null;
        if (failed) {
          host.classList.remove('is-loading');
          list.setAttribute('aria-busy', 'false');
          if (pagination instanceof HTMLElement) pagination.inert = false;
        }
        else setLoading(false);
        scheduleDeviceRefresh();
      }
    }
  };

  const notifyDiscovery = (type, message) => {
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(type, message);
  };

  const pollDiscovery = (rowId) => {
    if (activeDiscoveryPolls.has(rowId)) return;
    activeDiscoveryPolls.add(rowId);
    const check = async () => {
      try {
        const response = await window.linnmsDashboardAjax.scanStatus(rowId);
        if (response.status === 401) {
          activeDiscoveryPolls.delete(rowId);
          notifyDiscovery('error', 'Sesi berakhir. Silakan login kembali.');
          return;
        }
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(result.message || 'Status sinkronisasi belum dapat dibaca.');
        if (result.active) {
          const row = document.querySelector(`#devicesTable tr[data-device-row-id="${CSS.escape(String(rowId))}"]`);
          if (row) await updateScanProgress(row);
          window.setTimeout(check, 4000);
          return;
        }
        activeDiscoveryPolls.delete(rowId);
        notifyDiscovery(result.completion_level || 'error', result.completion_message || 'Sinkronisasi OLT selesai.');
        void load();
      } catch (error) {
        console.error(error);
        window.setTimeout(check, 5000);
      }
    };
    window.setTimeout(check, 2000);
  };

  document.addEventListener('submit', async (event) => {
    const discoveryForm = event.target instanceof HTMLFormElement && event.target.matches('[data-dashboard-discovery]')
      ? event.target : null;
    if (!discoveryForm) return;
    event.preventDefault();
    const rowId = discoveryForm.querySelector('input[name="olt_row_id"]')?.value;
    if (!rowId || activeDiscoveryPolls.has(rowId)) return;

    const row = discoveryForm.closest('tr');
    const time = row?.querySelector('.conn-time');
    if (time) {
      time.classList.add('is-pending');
      time.setAttribute('role', 'status');
      time.replaceChildren();
      setScanProgress(time, 0, 'Sinkronisasi OLT sedang berjalan');
    }
    row?.querySelector('.conn-chips')?.remove();
    const button = discoveryForm.querySelector('button[type="submit"]');
    if (button instanceof HTMLButtonElement) button.disabled = true;

    try {
      const response = await window.linnmsDashboardAjax.startDiscovery(discoveryForm);
      const result = await response.json();
      if (!response.ok || result.ok !== true) throw new Error(result.message || 'Sinkronisasi OLT gagal dimulai.');
      pollDiscovery(rowId);
      void load();
    } catch (error) {
      notifyDiscovery('error', String(error?.message || 'Sinkronisasi OLT gagal dimulai.'));
      void load();
    }
  });

  const scheduleSearch = () => {
    window.clearTimeout(debounceTimer);
    if (request) {
      request.abort();
      request = null;
    }
    setLoading(true);
    debounceTimer = window.setTimeout(() => {
      page = 1;
      void load(true, true);
    }, DEBOUNCE_MS);
  };

  search?.addEventListener('input', scheduleSearch);
  filters.forEach((filter) => filter.addEventListener('change', () => {
    page = 1;
    void load(true, true);
  }));

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    page = 1;
    void load(false, true);
  });

  pagination?.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('[data-activity-goto]') : null;
    if (!(link instanceof HTMLElement)) return;
    event.preventDefault();
    page = Math.max(1, Number.parseInt(link.dataset.activityGoto || '1', 10) || 1);
    void load(false, true);



    const panel = list.closest('.recent-activity-panel');
    (panel instanceof HTMLElement ? panel : list).scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  scheduleDeviceRefresh();
  document.querySelectorAll('form[data-dashboard-discovery][data-discovery-active="true"]')
    .forEach((activeForm) => {
      const rowId = activeForm.querySelector('input[name="olt_row_id"]')?.value;
      if (rowId) pollDiscovery(rowId);
    });
})();
