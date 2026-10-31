(() => {
  'use strict';

  const form = document.querySelector('[data-whatsapp-credentials-form]');
  if (!(form instanceof HTMLFormElement)) return;

  const find = (selector) => form.querySelector(selector);
  const notify = (ok, message) => {
    const text = String(message || (ok ? 'Berhasil.' : 'Gagal.'));
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(ok ? 'success' : 'error', text);
  };
  const requestOptions = {
    method: 'POST',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  };

  const credentialFields = find('[data-whatsapp-credential-fields]');
  const provider = find('[data-whatsapp-provider]');
  const providerInfo = find('[data-whatsapp-provider-info]');
  const providerLink = find('[data-whatsapp-provider-link]');
  const providerLabel = find('[data-whatsapp-provider-label]');
  const targetType = find('[data-whatsapp-target-type-input]');
  const targetChoices = [...form.querySelectorAll('[data-whatsapp-target-type]')];
  const groupChoice = targetChoices.find((choice) => choice.value === 'group');
  const phoneChoice = targetChoices.find((choice) => choice.value === 'phone');
  const phoneField = find('[data-whatsapp-phone-field]');
  const phoneInput = find('[data-whatsapp-target-input]');
  const groupField = find('[data-whatsapp-group-field]');
  const groupSelect = find('[data-whatsapp-group-select]');
  const groupManual = find('[data-whatsapp-group-manual]');
  const groupManualLabel = find('[data-whatsapp-group-manual-label]');
  const groupButton = find('[data-whatsapp-load-groups]');
  const groupHint = find('[data-whatsapp-group-hint]');
  const testButton = find('[data-connection-test="whatsapp"]');
  const savedType = targetType instanceof HTMLInputElement ? targetType.value : 'phone';
  let targetSaved = phoneInput instanceof HTMLInputElement && phoneInput.dataset.whatsappTargetSaved === '1';
  if (savedType === 'group' && groupSelect instanceof HTMLSelectElement && groupSelect.value !== '') targetSaved = true;
  let supportsGroupListing = form.dataset.whatsappSupportsGroupListing === '1';

  const selectedType = () => targetChoices.find((choice) => choice.checked)?.value === 'group' ? 'group' : 'phone';
  const resetGroupOptions = () => {
    if (!(groupSelect instanceof HTMLSelectElement)) return;
    groupSelect.replaceChildren(
      new Option('Minta daftar grup', ''),
      new Option('Masukkan Group ID manual', '__manual__')
    );
    groupSelect.value = '';
  };

  const updateTarget = () => {
    const group = selectedType() === 'group';
    const manual = group && groupSelect instanceof HTMLSelectElement && groupSelect.value === '__manual__';
    if (targetType instanceof HTMLInputElement) targetType.value = group ? 'group' : 'phone';
    if (phoneField instanceof HTMLElement) phoneField.hidden = group;
    if (groupField instanceof HTMLElement) groupField.hidden = !group;
    if (phoneInput instanceof HTMLInputElement) {
      phoneInput.disabled = group;
      phoneInput.required = !group && !(targetSaved && savedType === 'phone');
    }
    if (groupSelect instanceof HTMLSelectElement) {
      groupSelect.disabled = !group;
      groupSelect.name = group && !manual ? 'whatsapp_target' : '';
      groupSelect.required = group && !manual;
    }
    if (groupManual instanceof HTMLInputElement) {
      groupManual.hidden = !manual;
      groupManual.disabled = !manual;
      groupManual.required = manual;
    }
    if (groupManualLabel instanceof HTMLElement) groupManualLabel.hidden = !manual;
    if (groupButton instanceof HTMLButtonElement) groupButton.hidden = !group || !supportsGroupListing;
    if (groupHint instanceof HTMLElement) {
      groupHint.textContent = supportsGroupListing
        ? 'Minta daftar grup dari gateway, lalu pilih tujuan.'
        : 'Gateway ini tidak menyediakan daftar grup. Pilih opsi Group ID manual.';
    }
  };

  targetChoices.forEach((choice) => choice.addEventListener('change', () => {
    if (choice.checked && choice.value === 'group' && !supportsGroupListing
      && groupSelect instanceof HTMLSelectElement && groupSelect.value === '') {
      groupSelect.value = '__manual__';
      groupSelect.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    updateTarget();
  }));
  groupSelect?.addEventListener('change', updateTarget);
  updateTarget();

  groupButton?.addEventListener('click', async () => {
    if (!(groupButton instanceof HTMLButtonElement)) return;
    const invalid = [...credentialFields.querySelectorAll('[data-whatsapp-field] input')]
      .find((input) => input instanceof HTMLInputElement && input.required && input.value.trim() === '');
    if (invalid instanceof HTMLInputElement && invalid.readOnly) {
      notify(false, 'Buat token terlebih dahulu sebelum meminta daftar grup.');
      return;
    }
    if (invalid instanceof HTMLInputElement) {
      invalid.reportValidity();
      return;
    }
    const original = groupButton.innerHTML;
    groupButton.disabled = true;
    groupButton.innerHTML = '<i class="ri-refresh-line" aria-hidden="true"></i> Meminta...';
    try {
      const body = new FormData(form);
      body.set('action', 'list_whatsapp_groups');
      const response = await window.linnmsAjax.request(window.location.href, { ...requestOptions, body });
      const result = await response.json().catch(() => ({}));
      if (result?.ok !== true) throw new Error(String(result?.message || 'Gagal meminta daftar grup.'));
      const groups = Array.isArray(result.groups) ? result.groups : [];
      if (groups.length === 0) throw new Error('Grup tidak ditemukan pada gateway.');
      const previous = groupSelect instanceof HTMLSelectElement ? groupSelect.value : '';
      const options = [new Option('Pilih grup WhatsApp', '')];
      groups.forEach((item) => options.push(new Option(item.label, item.id)));
      options.push(new Option('Masukkan Group ID manual', '__manual__'));
      if (groupSelect instanceof HTMLSelectElement) {
        groupSelect.replaceChildren(...options);
        groupSelect.value = groups.length === 1 ? groups[0].id : groups.some((item) => item.id === previous) ? previous : '';
        groupSelect.dispatchEvent(new Event('change', { bubbles: true }));
        if (groups.length > 1) groupSelect.focus();
      }
      notify(true, result.message);
    } catch (error) {
      notify(false, error instanceof Error ? error.message : 'Gagal meminta daftar grup.');
    } finally {
      groupButton.disabled = false;
      groupButton.innerHTML = original;
    }
  });

  form.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-whatsapp-generate-token]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    const required = [...credentialFields.querySelectorAll('[name="whatsapp_base_url"], [data-whatsapp-generator-session], [data-whatsapp-generator-secret]')];
    const invalid = required.find((input) => input instanceof HTMLInputElement && input.value.trim() === '');
    if (invalid instanceof HTMLInputElement) {
      invalid.setCustomValidity('Kolom ini wajib diisi untuk membuat token.');
      invalid.reportValidity();
      invalid.addEventListener('input', () => invalid.setCustomValidity(''), { once: true });
      return;
    }
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="ri-refresh-line" aria-hidden="true"></i> Membuat token...';
    try {
      const body = new FormData(form);
      body.set('action', 'generate_whatsapp_token');
      const response = await window.linnmsAjax.request(window.location.href, { ...requestOptions, body });
      const result = await response.json().catch(() => ({}));
      if (result?.ok !== true || typeof result.token !== 'string' || result.token === '') {
        throw new Error(String(result?.message || 'Token belum berhasil dibuat.'));
      }
      const token = credentialFields.querySelector('[data-whatsapp-token-input]');
      if (token instanceof HTMLInputElement) token.value = result.token;
      // Token sudah dibuat: simpan nilai session/secret dan jangan wajibkan lagi.
      credentialFields.querySelectorAll('[data-whatsapp-generator-session], [data-whatsapp-generator-secret]').forEach((input) => {
        if (input instanceof HTMLInputElement) input.required = false;
      });
      notify(true, String(result.message || 'Token session berhasil dibuat.'));
    } catch (error) {
      notify(false, error instanceof Error ? error.message : 'Token belum berhasil dibuat.');
    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
  });

  const updateProviderInfo = (result) => {
    if (providerInfo instanceof HTMLElement) providerInfo.textContent = String(result?.info || '');
    if (providerLabel instanceof HTMLElement && result?.label) providerLabel.textContent = String(result.label);
    if (providerLink instanceof HTMLAnchorElement) {
      const url = String(result?.info_url || '').trim();
      providerLink.href = url || '#';
      providerLink.textContent = url;
      providerLink.hidden = url === '';
      providerLink.style.display = url === '' ? 'none' : '';
    }
  };

  provider?.addEventListener('change', async () => {
    if (!(provider instanceof HTMLSelectElement) || !(credentialFields instanceof HTMLElement)) return;
    try {
      const response = await window.linnmsAjax.request(`data.php?type=whatsapp-credential-fields&provider=${encodeURIComponent(provider.value)}`, {
        credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const result = await response.json().catch(() => ({}));
      if (result?.ok !== true || typeof result.fields_html !== 'string') throw new Error(String(result?.message || `HTTP ${response.status}`));
      credentialFields.innerHTML = result.fields_html;
      updateProviderInfo(result);
      supportsGroupListing = result.supports_groups === true && result.supports_group_listing === true;
      form.dataset.whatsappSupportsGroupListing = supportsGroupListing ? '1' : '0';
      if (groupChoice instanceof HTMLInputElement) groupChoice.disabled = result.supports_groups !== true;
      if (groupChoice?.disabled && groupChoice.checked && phoneChoice instanceof HTMLInputElement) phoneChoice.checked = true;
      targetSaved = false;
      resetGroupOptions();
      updateTarget();
    } catch {
      updateProviderInfo({ info: 'Field gateway belum dapat dimuat. Muat ulang halaman.' });
    }
  });

  testButton?.addEventListener('click', async () => {
    if (!(testButton instanceof HTMLButtonElement) || !form.reportValidity()) return;
    const original = testButton.innerHTML;
    testButton.disabled = true;
    testButton.innerHTML = '<i class="ri-refresh-line" aria-hidden="true"></i> Menguji...';
    try {
      const body = new URLSearchParams();
      for (const [key, value] of new FormData(form).entries()) {
        if (typeof value === 'string') body.set(key, value);
      }
      body.delete('action');
      body.set('type', 'whatsapp');
      const response = await window.linnmsAjax.request(testButton.dataset.connectionTestUrl || '', {
        ...requestOptions, body,
        headers: { ...requestOptions.headers, 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      });
      const result = await response.json().catch(() => ({}));
      notify(result?.ok === true, String(result?.message || (result?.ok ? 'Tes koneksi berhasil' : 'Tes koneksi gagal')));
    } catch {
      notify(false, 'Tes koneksi belum dapat diproses. Periksa koneksi lalu coba kembali');
    } finally {
      testButton.disabled = false;
      testButton.innerHTML = original;
    }
  });
})();
