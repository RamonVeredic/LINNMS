(() => {
  'use strict';

  const notify = (type, message) => {
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(type, message);
    else window.alert(message);
  };

  const post = async (form, body) => {
    let response;
    try {
      response = await window.linnmsAjax.request(form.getAttribute('action') || window.location.href, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
    } catch {
      throw new Error('Tidak dapat menghubungi server. Coba lagi.');
    }
    const result = await response.json().catch(() => {
      throw new Error('Sesi berubah atau server tidak merespons. Muat ulang halaman lalu coba lagi.');
    });
    if (!response.ok || result.ok !== true) throw new Error(result.message || 'Perubahan belum berhasil disimpan.');
    return result;
  };

  window.linnmsInstantPost = post;
  window.linnmsInstantNotify = notify;

  document.querySelectorAll('[data-instant-toggle]').forEach((form) => {
    const toggle = form.querySelector('[data-instant-toggle-input]');
    if (!toggle) return;
    form.addEventListener('submit', (event) => event.preventDefault());
    toggle.addEventListener('change', async () => {
      const previous = !toggle.checked;
      const body = new FormData(form);
      body.set(toggle.name, toggle.checked ? '1' : '0');
      toggle.disabled = true;
      try {
        const result = await post(form, body);
        notify('success', result.message || 'Perubahan berhasil disimpan.');
      } catch (error) {
        toggle.checked = previous;
        notify('error', error instanceof Error ? error.message : 'Perubahan belum berhasil disimpan.');
      } finally {
        toggle.disabled = false;
      }
    });
  });
})();
