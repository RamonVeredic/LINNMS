(() => {
  const inputs = [...document.querySelectorAll('[data-traffic-record]')];
  if (inputs.length === 0) return;

  const liveUrl = document.body.dataset.liveUrl || 'live.php';
  const notify = (level, message) => {
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(level, message);
  };

  inputs.forEach((input) => {
    input.addEventListener('change', async () => {
      const deviceId = String(input.dataset.deviceId || '');
      const ifRef = String(input.dataset.ifRef || '');
      if (deviceId === '' || ifRef === '') return;

      const enabled = input.checked;
      const label = input.closest('label')?.querySelector('[data-pon-catat-label]') || null;
      const syncLabel = () => { if (label) label.textContent = input.checked ? 'Enable' : 'Disable'; };
      input.disabled = true;
      try {
        const body = new URLSearchParams({ device_id: deviceId, if_ref: ifRef, enabled: enabled ? '1' : '0', csrf: input.dataset.csrf || '' });
        const response = await window.linnmsAjax.request(liveUrl + '?type=device-traffic-record', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' },
          body: body.toString()
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.ok !== true) {
          throw new Error((payload && payload.message) || 'Pilihan belum tersimpan.');
        }
        document.dispatchEvent(new CustomEvent('linnms:traffic-record-updated', {
          detail: { deviceId, ifRef, enabled },
        }));
        syncLabel();
        notify('success', enabled ? 'Pencatatan dinyalakan untuk ' + ifRef + '.' : 'Pencatatan dimatikan untuk ' + ifRef + '.');
      } catch (error) {
        input.checked = !enabled;
        syncLabel();
        notify('error', String((error && error.message) || 'Pilihan gagal disimpan.'));
      } finally {
        input.disabled = false;
      }
    });
  });
})();
