(() => {
  'use strict';

  const form = document.querySelector('[data-channel-credentials-form]');
  const testButton = form?.querySelector('[data-connection-test="telegram"]');
  if (!form || !testButton) return;

  const notify = (ok, message) => {
    const text = String(message || '').trim() || (ok ? 'Tes koneksi berhasil' : 'Tes koneksi gagal');
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(ok ? 'success' : 'error', text);
  };

  const tokenInput = form.querySelector('input[name="telegram_bot_token"]');
  const chatIdInput = form.querySelector('input[name="telegram_chat_id"]');

  testButton.addEventListener('click', async () => {
    if (!form.reportValidity()) return;

    const originalHtml = testButton.innerHTML;
    const tokenValue = tokenInput instanceof HTMLInputElement ? tokenInput.value : '';
    const chatIdValue = chatIdInput instanceof HTMLInputElement ? chatIdInput.value : '';
    testButton.disabled = true;
    testButton.classList.add('is-loading');
    testButton.innerHTML = '<i class="ri-refresh-line" aria-hidden="true"></i> Menguji...';

    const body = new URLSearchParams();
    for (const [name, value] of new FormData(form).entries()) {
      if (typeof value === 'string') body.set(name, value);
    }
    body.delete('action');
    body.set('type', 'telegram');

    try {
      const response = await window.linnmsAjax.request(testButton.dataset.connectionTestUrl || '', {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        }
      });
      const responseText = await response.text();
      let payload;
      try {
        payload = JSON.parse(responseText);
      } catch (_) {
        throw new Error(responseText.trim() || `HTTP ${response.status}`);
      }
      notify(
        payload.ok === true,
        String(payload.message || (payload.ok ? 'Tes koneksi berhasil' : 'Tes koneksi gagal'))
      );
    } catch (_) {
      notify(false, 'Tes koneksi gagal. Coba lagi');
    } finally {

      if (tokenInput instanceof HTMLInputElement) tokenInput.value = tokenValue;
      if (chatIdInput instanceof HTMLInputElement) chatIdInput.value = chatIdValue;
      testButton.disabled = false;
      testButton.classList.remove('is-loading');
      testButton.innerHTML = originalHtml;
    }
  });
})();
