(() => {
  'use strict';

  const button = document.querySelector('[data-connection-test="device"]');
  const result = document.querySelector('[data-connection-test-result]');
  if (!(button instanceof HTMLButtonElement) || !(result instanceof HTMLElement)) return;

  const setResult = (message, state = '') => {
    result.textContent = message;
    result.hidden = message === '';
    result.classList.toggle('text-muted', state === '');
    result.classList.toggle('success', state === 'success');
    result.classList.toggle('danger', state === 'error');
  };

  button.addEventListener('click', async () => {
    const endpoint = button.dataset.connectionTestUrl || '';
    if (endpoint === '') return;

    button.disabled = true;
    setResult('Menguji koneksi API...', '');

    const payload = new FormData();
    payload.append('csrf', button.dataset.connectionTestCsrf || '');
    payload.append('driver_id', 'external_olt_api');
    payload.append('type', 'device');

    try {
      const response = await window.linnmsAjax.request(endpoint, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await response.json().catch(() => ({}));
      const ok = response.ok && data.ok === true;
      setResult(String(data.message || (ok ? 'Koneksi API berhasil.' : 'Koneksi API gagal.')), ok ? 'success' : 'error');
    } catch (error) {
      setResult('Koneksi API gagal.', 'error');
    } finally {
      button.disabled = false;
    }
  });
})();
