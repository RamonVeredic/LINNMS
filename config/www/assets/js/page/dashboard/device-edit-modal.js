(() => {
  'use strict';

  const modal = document.querySelector('[data-device-edit-modal]');
  if (!(modal instanceof HTMLElement)) return;
  const body = modal.querySelector('[data-device-edit-body]');
  const title = modal.querySelector('[data-device-edit-title]');
  const closeButton = modal.querySelector('[data-device-edit-close]');
  if (!(body instanceof HTMLElement) || !(title instanceof HTMLElement)) return;

  let opener = null;
  let request = null;

  const clearUrl = () => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('edit_device')) return;
    url.searchParams.delete('edit_device');
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
    if (opener instanceof HTMLElement && opener.isConnected) opener.focus({ preventScroll: true });
    opener = null;
    body.replaceChildren();
  };

  const updateFields = (form) => {
    const type = form.querySelector('[data-edit-device-type]');
    const telnet = form.querySelector('[data-cli-toggle]');
    const details = form.querySelector('[data-cli-details]');
    const snmpFields = form.querySelector('[data-device-method-fields="snmp"]');
    const telnetFields = form.querySelector('[data-device-method-fields="telnet"]');
    const snmpEnabled = type?.dataset.method === 'snmp';
    if (snmpFields) {
      snmpFields.hidden = !snmpEnabled;
      snmpFields.querySelectorAll('input').forEach((input) => { input.disabled = !snmpEnabled; });
    }
    const telnetEnabled = type?.dataset.telnetCli === '1';
    if (telnetFields) telnetFields.hidden = !telnetEnabled;
    if (telnet instanceof HTMLInputElement) telnet.disabled = !telnetEnabled;
    const detailsEnabled = telnetEnabled && telnet?.checked === true;
    if (details) details.hidden = !detailsEnabled;
    telnet?.setAttribute('aria-expanded', detailsEnabled ? 'true' : 'false');
    details?.querySelectorAll('[data-cli-field]').forEach((input) => {
      input.disabled = !detailsEnabled;
      input.required = detailsEnabled && input.dataset.secretStored !== '1';
    });
  };

  const setupForm = (form) => {
    const ip = form.querySelector('input[name="ip_address"]');
    const test = form.querySelector('[data-connection-test="device"]');
    const result = form.querySelector('[data-connection-test-result]');
    const apiForm = form.querySelector('input[name="action"][value="update_api_olt"]') !== null;
    const validIp = /^(?:(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)$/;
    const validateIp = () => {
      if (ip instanceof HTMLInputElement) ip.setCustomValidity(ip.value.trim() === '' || validIp.test(ip.value.trim()) ? '' : 'Periksa IP Anda.');
    };
    const showResult = (message, state = '') => {
      if (!(result instanceof HTMLElement)) return;
      result.textContent = message;
      result.hidden = message === '';
      result.classList.toggle('success', state === 'success');
      result.classList.toggle('danger', state === 'error');
    };
    form.querySelector('[data-cli-toggle]')?.addEventListener('change', () => updateFields(form));
    ip?.addEventListener('input', validateIp);
    form.addEventListener('invalid', () => form.classList.add('was-validated'), true);
    form.addEventListener('submit', () => {
      validateIp();
      form.classList.add('was-validated');
    });
    updateFields(form);
    validateIp();

    test?.addEventListener('click', async () => {
      if (!(test instanceof HTMLButtonElement) || test.disabled) return;
      validateIp();
      form.classList.add('was-validated');
      if (!form.reportValidity()) return;
      const url = test.dataset.connectionTestUrl;
      if (!url) return;
      const payload = new FormData();
      if (apiForm) {
        payload.append('csrf', test.dataset.connectionTestCsrf || '');
        payload.append('driver_id', 'external_olt_api');
        payload.append('type', 'device');
      } else {
        const formData = new FormData(form);
        formData.delete('action');
        formData.set('type', 'device');
        for (const [key, value] of formData.entries()) payload.append(key, value);
      }
      const original = test.innerHTML;
      test.disabled = true;
      test.classList.add('is-loading');
      test.innerHTML = '<i class="bi bi-arrow-repeat" aria-hidden="true"></i> Menguji&hellip;';
      showResult('');
      try {
        const response = await window.linnmsAjax.request(url, {
          method: 'POST', body: payload, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        const ok = response.ok && data.ok === true;
        const message = String(data.message || (ok ? 'Koneksi berhasil.' : 'Koneksi belum berhasil.'));
        if (typeof window.linnmsToast === 'function') window.linnmsToast(ok ? 'success' : 'error', message);
        else showResult(message, ok ? 'success' : 'error');
      } catch {
        showResult('Tes koneksi tidak dapat dijalankan.', 'error');
      } finally {
        test.disabled = false;
        test.classList.remove('is-loading');
        test.innerHTML = original;
      }
    });
  };

  const open = async (id, trigger = null) => {
    if (!Number.isSafeInteger(id) || id < 1) return;
    request?.abort();
    request = new AbortController();
    const link = trigger instanceof HTMLElement ? trigger : document.querySelector(`[data-device-edit-open][data-device-id="${id}"]`);
    opener = link?.closest('[data-device-action-menu]')?.querySelector('[data-device-action-trigger]') || link;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('device-add-modal-open');
    title.textContent = 'Edit Perangkat';
    body.innerHTML = '<p class="text-muted" role="status">Memuat perangkat...</p>';
    body.removeAttribute('role');
    closeButton?.focus({ preventScroll: true });
    const url = new URL('data.php', window.location.href);
    url.searchParams.set('type', 'device-edit-form');
    url.searchParams.set('id', String(id));
    try {
      const response = await window.linnmsAjax.request(url.toString(), { signal: request.signal, cache: 'no-store' });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok !== true || typeof data.html !== 'string') {
        throw new Error(String(data.message || 'Form edit perangkat tidak dapat dimuat.'));
      }
      title.textContent = String(data.title || 'Edit Perangkat');
      body.innerHTML = data.html;
      const form = body.querySelector('form');
      if (form instanceof HTMLFormElement) setupForm(form);
      const focusTarget = body.querySelector('[name="device_name"], [name="olt_name"]');
      (focusTarget instanceof HTMLElement ? focusTarget : closeButton)?.focus({ preventScroll: true });
    } catch (error) {
      if (error?.name === 'AbortError' || modal.hidden) return;
      body.textContent = error instanceof Error ? error.message : 'Form edit perangkat tidak dapat dimuat.';
      body.setAttribute('role', 'alert');
    }
  };

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const trigger = event.target.closest('[data-device-edit-open]');
    if (!(trigger instanceof HTMLAnchorElement)) return;
    const id = Number.parseInt(trigger.dataset.deviceId || '', 10);
    if (!Number.isSafeInteger(id) || id < 1) return;
    event.preventDefault();
    const url = new URL(trigger.href);
    window.history.replaceState(null, '', url.toString());
    void open(id, trigger);
  });
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
  const initialId = Number.parseInt(modal.dataset.openDeviceId || '', 10);
  if (initialId > 0) void open(initialId);
})();
