(() => {
  'use strict';

  const modal = document.getElementById('dashboardDetailModal');
  if (!(modal instanceof HTMLElement)) return;

  const liveUrl = 'data.php';
  if (!liveUrl) {
    console.error('Endpoint aktivitas belum tersedia.');
    return;
  }

  const titleEl = modal.querySelector('[data-detail-title]');
  const subtitleEl = modal.querySelector('[data-detail-subtitle]');
  const iconEl = modal.querySelector('[data-detail-icon]');
  const listEl = modal.querySelector('[data-detail-list]');
  const pagination = modal.querySelector('[data-detail-pagination]');
  const footer = modal.querySelector('[data-detail-foot]');
  const searchInput = modal.querySelector('[data-detail-search]');
  const deviceShell = modal.querySelector('[data-detail-device-shell]');
  const deviceSelect = modal.querySelector('[data-detail-device]');
  const closeButtons = [...modal.querySelectorAll('[data-detail-close]')];
  if (!(titleEl && subtitleEl && iconEl && listEl && pagination && footer && searchInput && deviceShell && deviceSelect instanceof HTMLSelectElement)) return;
// jangan menaambah subtittel
  const CARD_META = {
    total_devices: { title: 'Total Perangkat', subtitle: '', accent: 'blue', icon: 'bi-boxes', kind: 'device' },
    total_onus: { title: 'Daftar ONU', subtitle: '', accent: 'teal', icon: 'bi-list-ul', kind: 'onu' },
    online_onus: { title: 'Online', subtitle: '', accent: 'green', icon: 'bi-wifi', kind: 'onu' },
    down_onus: { title: 'Offline', subtitle: '', accent: 'down', icon: 'bi-arrow-down-right', kind: 'onu' },
    power_down_onus: { title: 'DyingGasp', subtitle: '', accent: 'amber', icon: 'bi-plug', kind: 'onu' },
    loss_onus: { title: 'Lost', subtitle: '', accent: 'lost', icon: 'bi-broadcast', kind: 'onu' },
    problem_onus: { title: 'Bermasalah', subtitle: '', accent: 'red', icon: 'bi-exclamation-triangle', kind: 'onu' },
  };
  const TABLE_HEADS = {
    device: ['Jenis', 'Alamat', 'Model', 'Info', 'Status'],
    online_onus: ['OLT', 'Index', 'Serial', 'RX Power'],
    down_onus: ['OLT', 'Index', 'Serial'],
    loss_onus: ['OLT', 'Index', 'Serial'],
    power_down_onus: ['OLT', 'Index', 'Serial', 'RX Power'],
    problem_onus: ['OLT', 'Index', 'Serial', 'RX Power', 'Note'],
  };
  const triggers = [...document.querySelectorAll('[data-dashboard-detail]')];

  let currentCategory = '';
  let currentPage = 1, totalPages = 1;
  let rows = [];
  let request = null;
  let lastFocusTrigger = null;

  const resultsHost = document.createElement('div');
  resultsHost.className = 'dashboard-detail-results';
  listEl.before(resultsHost);
  resultsHost.append(listEl);
  const loadingOverlay = document.createElement('div');
  loadingOverlay.className = 'onu-search-status';
  loadingOverlay.hidden = true;
  loadingOverlay.setAttribute('role', 'status');
  loadingOverlay.setAttribute('aria-label', 'Data kategori sedang diproses');
  const badge = document.createElement('span');
  badge.className = 'onu-loading-badge';
  const loadingIcon = document.createElement('i');
  loadingIcon.className = 'bi bi-arrow-repeat sync-loading-spinner';
  loadingIcon.setAttribute('aria-hidden', 'true');
  badge.append(loadingIcon);
  loadingOverlay.append(badge);
  resultsHost.append(loadingOverlay);


  const makeCell = (text, className, el) => {
    const tag = document.createElement(el || 'td');
    if (className) tag.className = className;
    tag.textContent = text;
    return tag;
  };

  const stateClass = (key) => key === 'online' ? 'success' : (key === 'offline' || key === 'problem' ? 'danger' : 'neutral');
  const displayModelChars = (value) => String(value || '').replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim();

  const kindFor = (category) => (CARD_META[category] || {}).kind || 'onu';
  const headsFor = (kind) => TABLE_HEADS[kind === 'device' ? 'device' : currentCategory] || TABLE_HEADS.online_onus;

  const buildTable = (kind) => {
    const table = document.createElement('table');
    table.className = 'dashboard-detail-table';
    const head = document.createElement('thead');
    const headRow = document.createElement('tr');
    headsFor(kind).forEach((text) => {
      const th = makeCell(text, null, 'th');
      th.textContent = text;
      headRow.append(th);
    });
    head.append(headRow);
    table.append(head);
    table.append(document.createElement('tbody'));
    return table;
  };

  const listStatus = (options = {}) => {
    const wrap = document.createElement('span');
    wrap.className = `dashboard-detail-status${options.tone ? ` is-${options.tone}` : ''}`;
    wrap.setAttribute('role', options.tone === 'error' ? 'alert' : 'status');
    const glyph = document.createElement('i');
    glyph.className = `bi ${options.icon || 'bi-arrow-repeat'}`;
    glyph.setAttribute('aria-hidden', 'true');
    const label = document.createElement('span');
    label.textContent = options.text || '';
    wrap.append(glyph, label);
    if (options.retry) {
      const again = document.createElement('button');
      again.type = 'button';
      again.className = 'button secondary';
      again.textContent = 'Muat ulang';
      again.addEventListener('click', () => void load(currentCategory));
      wrap.append(again);
    }
    return wrap;
  };

  const showListStatus = (options) => {
    listEl.replaceChildren(listStatus(options));
    listEl.classList.add('is-compact');
  };

  const kindForCategory = () => kindFor(currentCategory);

  const renderPagination = () => {
    pagination.replaceChildren();
    footer.hidden = rows.length === 0 || totalPages <= 1 || kindForCategory() === 'device';
    if (footer.hidden) return;
    const controls = document.createElement('div');
    controls.className = 'page-controls';
    const arrow = (direction, page, disabled) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'button secondary table-page-button';
      button.disabled = disabled;
      button.setAttribute('aria-label', direction === 'left' ? 'Halaman sebelumnya' : 'Halaman berikutnya');
      const icon = document.createElement('i');
      icon.className = `bi bi-chevron-${direction}`;
      icon.setAttribute('aria-hidden', 'true');
      button.append(icon);
      button.addEventListener('click', () => void load(currentCategory, page));
      return button;
    };
    const numbers = new Set([1, totalPages]);
    const compact = window.matchMedia('(max-width: 480px)').matches;
    if (!compact) {
      numbers.add(2);
      numbers.add(totalPages - 1);
    }
    const around = compact ? 0 : 2;
    for (let page = currentPage - around; page <= currentPage + around; page++) {
      if (page >= 1 && page <= totalPages) numbers.add(page);
    }
    const list = document.createElement('ul');
    list.className = 'page-number-list';
    let previous = 0;
    [...numbers].filter(page => page >= 1 && page <= totalPages).sort((a, b) => a - b).forEach(page => {
      if (previous && page > previous + 1) {
        const ellipsis = document.createElement('li');
        ellipsis.className = 'page-number-ellipsis';
        ellipsis.setAttribute('aria-hidden', 'true');
        ellipsis.textContent = '…';
        list.append(ellipsis);
      }
      const item = document.createElement('li');
      const control = document.createElement(page === currentPage ? 'span' : 'button');
      control.className = 'page-number';
      control.textContent = String(page);
      if (page === currentPage) {
        control.classList.add('is-active');
        control.setAttribute('aria-current', 'page');
      } else {
        control.type = 'button';
        control.setAttribute('aria-label', `Halaman ${page}`);
        control.addEventListener('click', () => void load(currentCategory, page));
      }
      item.append(control);
      list.append(item);
      previous = page;
    });
    controls.append(arrow('left', currentPage - 1, currentPage <= 1), list, arrow('right', currentPage + 1, currentPage >= totalPages));
    pagination.append(controls);
  };

  const updateDeviceFilter = () => {
    const selected = deviceSelect.selectedOptions[0]?.textContent || 'Semua Perangkat';
    deviceShell.classList.toggle('is-filtered', deviceSelect.value !== '');
    deviceShell.title = `Filter perangkat: ${selected}`;
    deviceShell.querySelector('.select-button')?.setAttribute('aria-label', deviceShell.title);
  };

  const applyFilters = () => {
    if (rows.length === 0) return;
    const table = listEl.querySelector('table');
    const body = listEl.querySelector('tbody');
    if (!table || !body) return;
    listEl.querySelector('.dashboard-detail-status')?.remove();
    const term = searchInput.value.trim().toLowerCase();
    const device = deviceSelect.value;
    let shown = 0;
    body.querySelectorAll('tr[data-detail-row]').forEach((row) => {
      const haystack = String(row.dataset.detailSearch || '');
      const deviceLabel = String(row.dataset.detailDevice || '');
      const visible = (term === '' || haystack.includes(term)) && (device === '' || deviceLabel === device);
      row.hidden = !visible;
      if (visible) {
        shown += 1;
      }
    });
    if (shown === 0) {
      table.hidden = true;
      listEl.append(listStatus({ icon: 'bi-inbox', tone: 'empty', text: 'Tidak tersedia' }));
    } else {
      table.hidden = false;
    }
    renderPagination();
  };

  let searchDebounceTimer = 0;
  const handleSearch = () => {
    window.clearTimeout(searchDebounceTimer);
    if (modal.hidden) return;
    if (kindForCategory() === 'device') {
      searchDebounceTimer = window.setTimeout(applyFilters, 200);
      return;
    }
    request?.abort();
    request = null;
    setBusy(true);
    searchDebounceTimer = window.setTimeout(() => void load(currentCategory), 200);
  };

  const rebuildDeviceOptions = (devices) => {
    const selected = deviceSelect.value;
    deviceSelect.replaceChildren();
    const any = document.createElement('option');
    any.value = '';
    any.textContent = 'Semua Perangkat';
    deviceSelect.append(any);
    (Array.isArray(devices) ? devices : []).forEach((device) => {
      if (!device || typeof device !== 'object') return;
      const option = document.createElement('option');
      option.value = String(device.value || '');
      option.textContent = String(device.label || '');
      option.dataset.meta = String(device.meta || '');
      deviceSelect.append(option);
    });
    if (selected && ![...deviceSelect.options].some(option => option.value === selected)) {
      const option = document.createElement('option');
      option.value = selected;
      option.textContent = selected;
      deviceSelect.append(option);
    }
    deviceSelect.value = selected;
    deviceShell.hidden = deviceSelect.options.length <= 1 && !selected;
    updateDeviceFilter();
  };

  const renderList = () => {
    const kind = rows.length > 0 ? String(rows[0].kind || 'onu') : kindForCategory();
    listEl.replaceChildren();
    listEl.classList.add('is-compact');
    if (rows.length === 0) {
      showListStatus({
        icon: 'bi-inbox',
        tone: 'empty',
        text: 'Tidak tersedia',
      });
      return;
    }
    const table = buildTable(kind);
    const body = table.querySelector('tbody');
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.dataset.detailRow = '1';
      tr.dataset.href = String(row.url || '');
      tr.tabIndex = 0;
      tr.setAttribute('role', 'link');
      if (kind === 'device') {
        tr.dataset.detailSearch = String(row.search || '');
        tr.setAttribute('aria-label', `Buka detail perangkat ${row.name}`);
        const statusIcon = document.createElement('span');
        statusIcon.className = `status ${stateClass(String(row.state_key || ''))}`;
        statusIcon.textContent = String(row.status || '');
        const status = makeCell('');
        status.append(statusIcon);
        tr.append(
          makeCell(String(row.type || '')),
          makeCell(String(row.address || ''), 'mono'),
          makeCell(displayModelChars(row.model)),
          makeCell(String(row.info || ''), 'dashboard-detail-info-cell'),
          status,
        );
      } else {
        tr.dataset.detailSearch = String(row.search || '');
        tr.dataset.detailDevice = String(row.olt || '');
        tr.setAttribute('aria-label', `Buka detail ONU ${row.olt} ${row.index}`);
        const rx = document.createElement('span');
        rx.className = `signal ${String(row.rx_class || 'neutral')}`;
        const rxIcon = document.createElement('i');
        rxIcon.className = `bi ${String(row.rx_icon || 'ri-signal-cellular-off-line')}`;
        rxIcon.setAttribute('aria-hidden', 'true');
        rx.append(rxIcon, document.createElement('span'));
        rx.lastElementChild.textContent = String(row.rx || '-');
        const serial = makeCell('', 'mono uppercase');
        const serialValue = document.createElement('span');
        serialValue.className = 'mono';
        serialValue.dataset.serialValue = '';
        serialValue.textContent = String(row.serial || '');
        const copy = document.createElement('span');
        copy.className = 'serial-copy-action';
        const copyButton = document.createElement('button');
        copyButton.type = 'button';
        copyButton.className = 'icon-button';
        copyButton.dataset.copySerial = String(row.serial || '');
        copyButton.setAttribute('aria-label', `Salin serial ${row.serial || ''}`);
        const copyIcon = document.createElement('i');
        copyIcon.className = 'bi bi-clipboard';
        copyIcon.setAttribute('aria-hidden', 'true');
        copyButton.append(copyIcon);
        copy.append(copyButton);
        const serialShell = document.createElement('span');
        serialShell.className = 'serial-copy';
        serialShell.append(serialValue, copy);
        serial.append(serialShell);
        tr.append(
          makeCell(String(row.olt || '')),
          makeCell(String(row.index || ''), 'mono'),
          serial,
        );
        if (!['down_onus', 'loss_onus'].includes(currentCategory)) {
          const rxCell = makeCell('');
          rxCell.append(rx);
          tr.append(rxCell);
        }
        if (currentCategory === 'problem_onus') {
          const note = makeCell(String(row.note || ''));
          if (String(row.since || '') !== '') {
            const since = document.createElement('span');
            since.className = 'dashboard-detail-note-line';
            since.textContent = String(row.since);
            note.append(since);
          }
          tr.append(note);
        }
      }
      body.append(tr);
    });
    table.append(body);
    listEl.append(table);
  };

  const setBusy = (loading) => {
    modal.setAttribute('aria-busy', String(loading));
    loadingOverlay.hidden = !loading;
    resultsHost.classList.toggle('is-loading', loading);
    listEl.inert = loading;
    pagination.inert = loading;
    const head = listEl.querySelector('thead');
    loadingOverlay.style.top = (head?.offsetHeight || 0) + 'px';
  };

  const load = async (category, page = 1) => {
    if (category === '' || modal.hidden) return;
    request?.abort();
    const controller = new AbortController();
    request = controller;
    setBusy(true);
    const selectedDevice = deviceSelect.value;
    try {
      const query = new URLSearchParams({ type: 'dashboard-detail', category, p: String(page), per: '100' });
      if (kindFor(category) === 'onu') {
        const term = searchInput.value.trim();
        if ([...term].length >= 2) query.set('q', term);
        if (selectedDevice) query.set('device', selectedDevice);
      }
      const response = await window.linnmsAjax.request(`${liveUrl}?${query.toString()}`, {
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
      });
      if (request !== controller || modal.hidden) return;
      if (response.status === 401) throw new Error('Sesi telah berakhir. Muat ulang halaman untuk melanjutkan.');
      const payload = await response.json().catch(() => null);
      if (request !== controller || modal.hidden) return;
      if (!response.ok || !payload || payload.ok !== true || !Array.isArray(payload.rows)) {
        throw new Error(String(payload?.message || 'Data kategori belum dapat dimuat.'));
      }
      rows = payload.rows;
      currentPage = Number(payload.page) || 1;
      totalPages = Number(payload.pages) || 1;
      modal.dataset.detailTruncated = payload.truncated === true ? '1' : '0';
      rebuildDeviceOptions(payload.devices);
      renderList();
      if (kindFor(category) === 'device') applyFilters();
      renderPagination();
      listEl.scrollTop = 0;
    } catch (error) {
      if (error?.name === 'AbortError' || request !== controller || modal.hidden) return;
      showListStatus({ icon: 'bi-arrow-clockwise', tone: 'error', text: String(error?.message || 'Data kategori belum dapat dimuat.'), retry: true });
      footer.hidden = true;
      pagination.replaceChildren();
    } finally {
      if (request === controller) {
        request = null;
        setBusy(false);
      }
    }
  };

  const open = (trigger) => {
    const category = String(trigger.dataset.dashboardDetail || '').trim();
    if (category === '' || !CARD_META[category]) return;
    const meta = CARD_META[category];
    if (request) request.abort();
    currentCategory = category;
    window.clearTimeout(searchDebounceTimer);
    currentPage = 1;
    totalPages = 1;
    rows = [];
    modal.dataset.detailTruncated = '0';
    titleEl.textContent = typeof trigger.dataset.detailTitle === 'string' && trigger.dataset.detailTitle.trim() !== ''
      ? trigger.dataset.detailTitle.trim()
      : meta.title;
    subtitleEl.textContent = meta.subtitle;
    iconEl.className = `dashboard-detail-icon accent-${trigger.dataset.detailAccent || meta.accent}`;
    iconEl.replaceChildren();
    const glyph = document.createElement('i');
    glyph.className = `bi ${trigger.dataset.detailIcon || meta.icon}`;
    glyph.setAttribute('aria-hidden', 'true');
    iconEl.append(glyph);
    searchInput.value = '';
    deviceShell.hidden = true;
    deviceSelect.value = '';
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('dashboard-detail-open');
    footer.hidden = true;
    pagination.replaceChildren();
    listEl.replaceChildren();
    lastFocusTrigger = trigger;
    window.requestAnimationFrame(() => searchInput.focus({ preventScroll: true }));
    void load(category);
  };

  const close = (restoreFocus = true) => {
    if (modal.hidden) return;
    window.clearTimeout(searchDebounceTimer);
    if (request) request.abort();
    request = null;
    setBusy(false);
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('dashboard-detail-open');
    searchInput.value = '';
    deviceSelect.value = '';
    deviceShell.hidden = true;
    if (restoreFocus && lastFocusTrigger instanceof HTMLElement) lastFocusTrigger.focus({ preventScroll: true });
  };

  triggers.forEach((trigger) => trigger.addEventListener('click', () => open(trigger)));

  closeButtons.forEach((button) => button.addEventListener('click', () => close()));
  modal.addEventListener('click', (event) => {
    if (event.target === modal) close();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || event.defaultPrevented) return;
    if (modal.hidden) return;
    event.preventDefault();
    close();
  });

  const submitSearch = () => {
    window.clearTimeout(searchDebounceTimer);
    if (kindForCategory() === 'onu') void load(currentCategory);
    else applyFilters();
  };
  searchInput.addEventListener('input', handleSearch);
  searchInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      submitSearch();
    }
  });
  deviceSelect.addEventListener('change', () => {
    updateDeviceFilter();
    window.clearTimeout(searchDebounceTimer);
    if (kindForCategory() === 'onu') void load(currentCategory);
    else applyFilters();
  });

  window.addEventListener('resize', () => { if (!modal.hidden) renderPagination(); });

  listEl.addEventListener('click', (event) => {
    const row = event.target instanceof Element ? event.target.closest('tr[data-href]') : null;
    if (!(row instanceof HTMLElement)) return;
    if (event.target.closest('a, button, input, select, textarea, label')) return;
    const href = String(row.dataset.href || '');
    if (href === '') return;
    close(false);
    window.location.href = href;
  });
  listEl.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (event.target instanceof Element && event.target.closest('a, button, input, select, textarea, label')) return;
    const row = event.target instanceof Element ? event.target.closest('tr[data-href]') : null;
    if (!(row instanceof HTMLElement)) return;
    event.preventDefault();
    const href = String(row.dataset.href || '');
    if (href === '') return;
    close(false);
    window.location.href = href;
  });
})();
