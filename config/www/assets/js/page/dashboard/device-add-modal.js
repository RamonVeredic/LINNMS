(() => {
  'use strict';

  const modal = document.querySelector('[data-device-add-modal]');
  const openButton = document.querySelector('[data-device-add-open]');
  if (!(modal instanceof HTMLElement) || !(openButton instanceof HTMLElement)) return;
  const body = modal.querySelector('[data-device-add-body]');
  const closeButton = modal.querySelector('[data-device-add-close]');
  if (!(body instanceof HTMLElement)) return;

  let opener = null;
  let request = null;

  const clearUrl = () => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('add_device')) return;
    url.searchParams.delete('add_device');
    window.history.replaceState(null, '', url.toString());
  };

  const close = () => {
    if (modal.hidden) return;
    request?.abort();
    request = null;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('device-add-modal-open');
    clearUrl();
    body.replaceChildren();
    if (opener instanceof HTMLElement && opener.isConnected) opener.focus({ preventScroll: true });
    opener = null;
  };

  const open = async () => {
    if (!modal.hidden) return;
    opener = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
      ? document.activeElement : openButton;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('device-add-modal-open');
    body.innerHTML = '<p class="text-muted" role="status">Memuat formulir...</p>';
    request?.abort();
    request = new AbortController();
    const url = new URL('data.php', window.location.href);
    url.searchParams.set('type', 'device-add-form');
    try {
      const response = await window.linnmsAjax.request(url.toString(), { signal: request.signal, cache: 'no-store' });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok !== true || typeof data.html !== 'string') {
        throw new Error(String(data.message || 'Form tambah perangkat tidak dapat dimuat.'));
      }
      body.innerHTML = data.html;
      window.linnmsDeviceFormInit?.(body);
      const invalid = body.querySelector('[aria-invalid="true"]');
      const select = body.querySelector('[data-device-type] + .select-button, [data-device-type]');
      (invalid instanceof HTMLElement ? invalid : (select instanceof HTMLElement ? select : closeButton))?.focus({ preventScroll: true });
    } catch (error) {
      if (error?.name === 'AbortError' || modal.hidden) return;
      body.textContent = error instanceof Error ? error.message : 'Form tambah perangkat tidak dapat dimuat.';
      body.setAttribute('role', 'alert');
    }
  };

  openButton.addEventListener('click', open);
  closeButton?.addEventListener('click', close);
  modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
  document.addEventListener('keydown', (event) => {
    if (modal.hidden || event.defaultPrevented) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab') return;
    const items = [...modal.querySelectorAll('button, input, select, textarea, [tabindex]')]
      .filter((item) => item instanceof HTMLElement && !item.matches(':disabled, [hidden], [tabindex="-1"]') && item.getClientRects().length > 0);
    if (items.length === 0) return;
    if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1).focus(); }
    else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0].focus(); }
  });
  if (modal.dataset.openOnLoad === '1') open();
})();
