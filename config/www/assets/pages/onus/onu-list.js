(() => {
  'use strict';

  const oltFilter = document.querySelector('[data-onu-olt-filter]');
  const ponFilter = document.querySelector('[data-onu-pon-filter]');
  if (!(oltFilter instanceof HTMLSelectElement) || !(ponFilter instanceof HTMLSelectElement)) return;

  const syncPonOptions = () => {
    const selectedOlt = String(oltFilter.value || '');
    [...ponFilter.options].forEach((option) => {
      if (option.value === '') {
        option.disabled = false;
        return;
      }
      option.disabled = selectedOlt !== '' && option.dataset.oltDeviceId !== selectedOlt;
    });

    if (ponFilter.selectedOptions[0]?.disabled) ponFilter.value = '';
  };

  oltFilter.addEventListener('change', syncPonOptions);
  syncPonOptions();
})();

(() => {
  'use strict';
  const table = document.querySelector('[data-onu-server-search]');
  const search = document.querySelector('[data-table-search="onusTable"]');
  if (!table || !search) return;
  const body = table.tBodies[0];
  const footer = document.querySelector('[data-table-footer="onusTable"]');
  const filters = [...document.querySelectorAll('[data-table-filter="onusTable"]')];
  let timer, controller, sequence = 0;
  const area = table.closest('.table-wrap');
  const host = document.createElement('div');
  host.className = 'onu-search-host onu-search-table-host';
  area.before(host);
  host.append(area);
  const overlay = document.createElement('div');
  overlay.className = 'onu-search-status';
  overlay.hidden = true;
  overlay.setAttribute('role', 'status');
  overlay.setAttribute('aria-live', 'polite');
  const icon = document.createElement('i');
  icon.className = 'bi bi-arrow-repeat sync-loading-spinner';
  icon.setAttribute('aria-hidden', 'true');
  const label = document.createElement('span');
  const badge = document.createElement('span');
  badge.className = 'onu-loading-badge';
  badge.append(icon);
  overlay.append(badge, label);
  host.append(overlay);
  const positionOverlay = () => {
    const rect = host.getBoundingClientRect();
    const top = Math.max(rect.top, table.tHead.getBoundingClientRect().bottom, 0);
    const bottom = Math.min(rect.bottom, window.innerHeight);
    overlay.style.top = Math.max(0, top - rect.top) + 'px';
    overlay.style.height = Math.max(0, bottom - top) + 'px';
  };
  window.addEventListener('resize', () => { if (!overlay.hidden) positionOverlay(); });
  document.addEventListener('scroll', () => { if (!overlay.hidden) positionOverlay(); }, {capture: true, passive: true});
  const status = (message, loading = false) => {
    positionOverlay();
    label.hidden = loading;
    label.textContent = loading ? '' : message;
    badge.hidden = !loading;
    overlay.setAttribute('aria-label', loading ? 'Pencarian sedang diproses' : message);
    icon.hidden = !loading;
    overlay.hidden = false;
    host.classList.add('is-loading');
    table.setAttribute('aria-busy', String(loading));
    if (footer) footer.inert = true;
  };
  const clearStatus = () => {
    overlay.hidden = true;
    host.classList.remove('is-loading');
    table.setAttribute('aria-busy', 'false');
    if (footer) footer.inert = false;
  };
  const load = async (page = 1, searching = false) => {
    const id = ++sequence;
    controller?.abort();
    controller = new AbortController();
    table.setAttribute('aria-busy', 'true');
    if (searching) status('Memuat data ONU...', true);
    else clearStatus();

    const term = search.value.trim();
    const params = { p: page, q: [...term].length >= 2 ? term : '' };
    const names = ['olt', 'pon', 'status'];
    filters.forEach((filter, index) => { params[names[index]] = index === 2 ? (filter.selectedOptions[0]?.dataset.apiValue || '') : filter.value; });
    const source = new URL(location.href).searchParams.get('source');
    if (source) params.source = source;
    try {
      const [response] = await Promise.all([
        window.linnmsOnusAjax.load(params, controller.signal),
        new Promise(resolve => setTimeout(resolve, 250)),
      ]);
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Gagal memuat data ONU.');
      if (id !== sequence) return;
      body.innerHTML = data.rows_html || '';
      clearStatus();
      if (footer) {
        footer.innerHTML = data.pagination_html;
        const info = document.createElement('span');
        info.className = 'page-info-top';
        info.textContent = data.page_info;
        footer.append(info);
        footer.hidden = false;
      }

    } catch (error) {
      if (id === sequence && error.name !== 'AbortError') status('Gagal memuat data ONU. Ketik ulang atau ubah filter untuk mencoba lagi.');
    } finally {
      if (id === sequence) table.setAttribute('aria-busy', 'false');
    }
  };
  search.addEventListener('input', () => {
    clearTimeout(timer);
    ++sequence;
    controller?.abort();
    table.setAttribute('aria-busy', 'true');
    status('Memuat data ONU...', true);

    timer = setTimeout(() => load(1, true), 200);
  });
  filters.forEach(filter => filter.addEventListener('change', () => { clearTimeout(timer); load(1, true); }));
  footer?.addEventListener('click', event => {
    const button = event.target.closest('[data-onu-goto]');
    if (!button) return;
    event.preventDefault();
    clearTimeout(timer);
    load(Number(button.dataset.onuGoto) || 1);
  });
  const initial = new URL(location.href);
  search.value = initial.searchParams.get('q') || '';
  filters.forEach((filter, index) => {
    const value = initial.searchParams.get(['olt', 'pon', 'status'][index]);
    if (!value) return;
    filter.value = index === 2
      ? ([...filter.options].find((option) => option.dataset.apiValue === value)?.value || '')
      : value;
  });

})();
