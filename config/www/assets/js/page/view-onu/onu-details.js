(() => {
  'use strict';

  document.querySelector('[data-onu-wifi-ssid-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement) || !form.reportValidity()) return;
    const csrf = document.querySelector('input[name="csrf"]')?.value || '';
    const onuRowId = form.dataset.onuRowId || '';
    const button = form.querySelector('button[type="submit"]');
    if (!csrf || !onuRowId || !(button instanceof HTMLButtonElement)) return;
    button.disabled = true;
    try {
      const body = new URLSearchParams({
        csrf,
        onu_row_id: onuRowId,
        port: form.elements.namedItem('port').value,
        ssid: form.elements.namedItem('ssid').value,
      });
      const liveUrl = document.body.dataset.liveUrl || 'live.php';
      const response = await window.linnmsAjax.request(liveUrl + '?type=onu-wifi-ssid-save', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' },
        body: body.toString(),
      });
      const result = await response.json();
      if (!response.ok || result?.ok !== true) throw new Error(result?.message || 'SSID gagal disimpan.');
      if (typeof window.linnmsNotify === 'function') window.linnmsNotify('success', result.message);
    } catch (error) {
      if (typeof window.linnmsNotify === 'function') window.linnmsNotify('error', error.message || 'SSID gagal disimpan.');
    } finally {
      button.disabled = false;
    }
  });

  const focusEditControl = (control) => {
    if (!control) return;
    const focusTarget = control.matches('select')
      ? control.closest('.glass-select')?.querySelector('.select-button')
      : control;
    if (!focusTarget) return;
    focusTarget.focus();
    if (!focusTarget.matches('input')) return;
    const end = focusTarget.value.length;
    try {
      focusTarget.setSelectionRange(end, end);
    } catch {
    }
  };

  const resetOnuEditForm = (form) => {
    if (!form) return;
    form.reset();
    form.querySelectorAll('[data-onu-edit-control]').forEach((control) => {
      control.disabled = true;
    });
    form.querySelectorAll('[data-onu-field-edit]').forEach((button) => {
      button.classList.remove('is-active');
      button.setAttribute('aria-pressed', 'false');
      button.querySelector('i')?.classList.replace('bi-floppy', 'bi-pencil');
      const label = button.querySelector('span');
      if (label) label.textContent = 'Edit';
      if (button.dataset.editLabel) {
        button.setAttribute('aria-label', button.dataset.editLabel);
        button.title = button.dataset.editLabel;
      }
    });
    form.querySelectorAll('[data-onu-field-cancel]').forEach((cancel) => {
      cancel.style.display = 'none';
    });
  };

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-onu-field-edit]');
    if (!button || button.disabled) return;
    const form = button.closest('[data-onu-edit-form]');
    const field = button.closest('.onu-info-list > div');
    const control = field?.querySelector('[data-onu-edit-control]');
    if (!form || !control) return;

    if (button.classList.contains('is-active')) {
      const action = form.querySelector('input[name="action"]');
      if (action) action.value = control.matches('[data-onu-serial-replacement]')
        ? 'replace_onu_registration' : 'save_onu_control';
      form.requestSubmit();
      return;
    }

    control.disabled = false;
    const cancelButton = field?.querySelector('[data-onu-field-cancel]');
    if (cancelButton) cancelButton.style.display = '';
    button.dataset.editLabel ||= button.getAttribute('aria-label') || 'Edit';
    button.classList.add('is-active');
    button.setAttribute('aria-pressed', 'true');
    button.setAttribute('aria-label', 'Simpan perubahan');
    button.title = 'Simpan perubahan';
    button.querySelector('i')?.classList.replace('bi-pencil', 'bi-floppy');
    const label = button.querySelector('span');
    if (label) label.textContent = 'Save';
    window.requestAnimationFrame(() => focusEditControl(control));
  });

  document.addEventListener('click', (event) => {
    const cancel = event.target.closest('[data-onu-field-cancel]');
    if (!cancel) return;
    resetOnuEditForm(cancel.closest('[data-onu-edit-form]'));
  });

  const closeModal = (modal) => {
    if (!modal) return;
    resetOnuEditForm(modal.querySelector('[data-onu-edit-form]'));
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.onu-control-modal:not([hidden])')) {
      document.body.classList.remove('onu-modal-open');
    }
  };

  document.querySelectorAll('[data-onu-modal-open]').forEach((button) => {
    button.addEventListener('click', () => {
      if (button.disabled) return;
      const modal = document.getElementById(button.dataset.onuModalOpen || '');
      if (!modal) return;
      resetOnuEditForm(modal.querySelector('[data-onu-edit-form]'));
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('onu-modal-open');
      window.requestAnimationFrame(() => {
        focusEditControl(modal.querySelector('input:not([type="hidden"]), select, button'));
      });
    });
  });

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-onu-row-edit][data-onu-modal-open]');
    if (!button || button.disabled) return;
    const modal = document.getElementById(button.dataset.onuModalOpen || '');
    if (!modal || !modal.hidden) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('onu-modal-open');
    window.requestAnimationFrame(() => focusEditControl(modal.querySelector('input:not([type="hidden"]), select, button')));
  });

  document.querySelectorAll('.onu-control-modal').forEach((modal) => {
    modal.querySelectorAll('[data-onu-modal-close]').forEach((button) => {
      button.addEventListener('click', () => closeModal(modal));
    });
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeModal(modal);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const activeInlineEdit = document.querySelector('[data-onu-field-edit].is-active');
      if (activeInlineEdit) resetOnuEditForm(activeInlineEdit.closest('[data-onu-edit-form]'));
      closeModal(document.querySelector('.onu-control-modal:not([hidden])'));
    }
  });
})();

(() => {
  const root = document.querySelector('[data-onu-ethernet]');
  const modal = root?.querySelector('[data-onu-ethernet-modal]');
  const detailBody = modal?.querySelector('[data-onu-lan-detail]');
  if (!root || !modal || !detailBody) return;

  const onuRowId = root.dataset.onuRowId || '';
  const liveUrl = document.body.dataset.liveUrl || 'live.php';
  const dataUrl = new URL('data.php', new URL(liveUrl, window.location.href));
  const csrf = document.querySelector('input[name="csrf"]')?.value || '';
  let currentPort = 0;
  let currentDetail = null;
  let currentOpener = null;
  let requestId = 0;
  let saving = false;

  const notify = (level, message) => {
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(level, message);
  };
  const stateFor = (value) => {
    const state = String(value || '').toLowerCase();
    if (['up', 'online', 'linkup'].includes(state)) return ['is-up', 'Up'];
    if (state === 'disable') return ['is-down', 'Disable'];
    if (['down', 'offline', 'linkdown'].includes(state)) return ['is-down', 'Down'];
    return ['is-unknown', '—'];
  };
  const setStatus = (value) => {
    const status = modal.querySelector('[data-onu-lan-status]');
    const [className, label] = stateFor(value);
    status.className = 'onu-eth-status ' + className;
    status.textContent = label;
  };
  const showMessage = (message, loading = false) => {
    detailBody.replaceChildren();
    const row = document.createElement('p');
    row.className = 'onu-port-empty';
    row.setAttribute('role', 'status');
    if (loading) {
      const spinner = document.createElement('i');
      spinner.className = 'bi bi-arrow-repeat sync-loading-spinner';
      spinner.setAttribute('aria-hidden', 'true');
      row.append(spinner, ' ');
    }
    row.append(message);
    detailBody.append(row);
  };
  const updateCard = (port) => {
    const card = root.querySelector('[data-onu-port="' + currentPort + '"]');
    if (!card) return;
    const [className] = stateFor(String(port.interface_status || '').toLowerCase() === 'disable' ? 'disable' : port.link_status);
    card.classList.remove('is-up', 'is-down', 'is-unknown');
    card.classList.add(className);
    if (Object.hasOwn(port, 'speed')) {
      const speed = card.querySelector('.onu-lan-button > span:last-child');
      if (speed) speed.textContent = String(port.speed || '').trim() || '—';
    }
  };
  const makeField = (field) => {
    const row = document.createElement('div');
    row.className = 'field';
    row.dataset.onuLanField = field.key;
    const id = 'onu-eth-' + currentPort + '-' + field.key;
    const label = document.createElement('label');
    label.className = 'label';
    label.textContent = field.label || field.key;
    row.append(label);

    if (field.kind === 'text') {
      const value = document.createElement('span');
      value.className = 'device-info-value';
      value.textContent = String(field.value || '').trim() || '—';
      row.append(value);
      return row;
    }

    if (field.kind === 'toggle') {
      const value = String(field.value || '').toLowerCase();
      const shell = document.createElement('span');
      shell.className = 'device-info-value';
      const control = document.createElement('span');
      control.className = 'onu-service-toggle-row';
      const input = document.createElement('input');
      input.id = id;
      input.type = 'checkbox';
      input.name = field.key;
      input.setAttribute('role', 'switch');
      input.setAttribute('aria-label', field.label || field.key);
      input.checked = value === 'enable';
      input.indeterminate = !['enable', 'disable'].includes(value);
      input.disabled = !field.action && !field.preview;
      input.dataset.onuLanAction = field.action || '';
      if (field.preview) input.dataset.onuLanPreview = '';
      input.dataset.onuLanKey = field.key;
      const state = document.createElement('span');
      state.dataset.onuLanToggleLabel = '';
      state.textContent = value === 'enable' ? 'Enable' : (value === 'disable' ? 'Disable' : 'N/A');
      control.append(input, state);
      shell.append(control);
      label.htmlFor = id;
      row.append(shell);
      return row;
    }

    const select = document.createElement('select');
    select.id = id;
    select.name = field.key;
    select.dataset.onuLanKey = field.key;
    select.multiple = field.multiple === true;
    select.disabled = !field.action && !field.group && !field.preview;
    if (field.group && !currentDetail.save_action) select.disabled = true;
    if (field.key === 'mode' || field.key === 'dhcp') select.required = !!currentDetail.save_action;
    if (field.multiple) {
      select.dataset.selectCheckbox = '';
      select.dataset.selectMenuClass = 'onu-select-menu';
      select.dataset.selectLabel = field.label || 'Pilih';
      select.dataset.selectSearch = 'Cari VLAN';
    } else if (field.key === 'default_vlan') {
      select.dataset.selectCheckbox = '';
      select.dataset.selectMenuClass = 'onu-select-menu';
      select.dataset.selectLabel = 'Pilih VLAN';
      select.dataset.selectSearch = 'Cari VLAN';
    }
    const selected = new Set((Array.isArray(field.value) ? field.value : [field.value]).map(String));
    const available = new Set((Array.isArray(field.options) ? field.options : []).map((option) => String(option.value)));
    if (!field.multiple && !field.value) {
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = select.disabled ? 'N/A' : 'Pilih ' + (field.label || 'nilai');
      placeholder.disabled = true;
      placeholder.selected = true;
      select.append(placeholder);
    }
    selected.forEach((value) => {
      if (!value || available.has(value)) return;
      const item = document.createElement('option');
      item.value = value;
      item.textContent = value;
      item.selected = true;
      select.append(item);
    });
    (Array.isArray(field.options) ? field.options : []).forEach((option) => {
      const item = document.createElement('option');
      item.value = String(option.value);
      item.textContent = String(option.label);
      item.selected = selected.has(item.value);
      select.append(item);
    });
    label.htmlFor = id;
    row.append(select);
    return row;
  };
  const syncSettings = () => {
    const mode = detailBody.querySelector('[data-onu-lan-key="mode"]')?.value || '';
    (currentDetail?.fields || []).filter((field) => Array.isArray(field.modes)).forEach((field) => {
      const row = detailBody.querySelector('[data-onu-lan-field="' + field.key + '"]');
      const select = row?.querySelector('select');
      if (!row || !select) return;
      row.hidden = !field.modes.includes(mode);
      select.disabled = row.hidden || !currentDetail.save_action;
      select.required = !row.hidden && !!currentDetail.save_action;
    });
  };
  const render = (detail) => {
    currentDetail = detail;
    setStatus(String(detail.interface_status || '').toLowerCase() === 'disable' ? 'disable' : detail.link_status);
    updateCard(detail);
    detailBody.replaceChildren();
    const fields = Array.isArray(detail.fields) ? detail.fields : [];
    const form = document.createElement(detail.save_action ? 'form' : 'div');
    if (detail.save_action) {
      form.className = 'onu-eth-config-form';
      form.dataset.onuLanForm = '';
    }
    const list = document.createElement('div');
    list.className = 'device-system-info-list onu-eth-info-list';
    fields.forEach((field) => list.append(makeField(field)));
    form.append(list);
    if (detail.save_action) {
      const actions = document.createElement('div');
      actions.className = 'onu-eth-actions';
      const save = document.createElement('button');
      save.className = 'button primary';
      save.type = 'submit';
      save.textContent = 'Simpan';
      actions.append(save);
      form.append(actions);
    }
    detailBody.append(form);
    syncSettings();
  };
  const loadPort = async (port) => {
    const token = ++requestId;
    showMessage('Memuat...', true);
    try {
      const url = new URL(dataUrl);
      url.searchParams.set('type', 'onu-ethernet-port');
      url.searchParams.set('onu_row_id', onuRowId);
      url.searchParams.set('port', String(port));
      const response = await window.linnmsAjax.request(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Respons data port LAN tidak valid. Muat ulang halaman.');
      const result = await response.json();
      if (!response.ok || result?.ok !== true || !result.detail) throw new Error(result?.message || 'Data port LAN belum tersedia.');
      if (token === requestId && !modal.hidden) render(result.detail);
    } catch (error) {
      if (token === requestId && !modal.hidden) showMessage(error.message || 'Data port LAN belum tersedia.');
    }
  };
  const openPort = (button) => {
    if (saving) return;
    const port = Number(button.dataset.onuLanOpen);
    if (!Number.isInteger(port) || port < 1) return;
    currentPort = port;
    currentOpener = button;
    currentDetail = null;
    modal.querySelector('[data-onu-lan-number]').textContent = String(port);
    const card = button.closest('[data-onu-port]');
    setStatus(card?.classList.contains('is-up') ? 'up' : (card?.classList.contains('is-down') ? 'down' : ''));
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('olt-pon-modal-open');
    modal.querySelector('[data-onu-lan-close]')?.focus();
    loadPort(port);
  };
  const closeModal = () => {
    if (modal.hidden) return;
    requestId++;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('olt-pon-modal-open');
    currentOpener?.focus();
  };
  const send = async (action, field, value) => {
    if (!csrf || !onuRowId || saving) throw new Error('Pengaturan port LAN belum dapat disimpan.');
    saving = true;
    const controls = Array.from(detailBody.querySelectorAll('input, select, button'));
    const disabled = controls.map((control) => control.disabled);
    controls.forEach((control) => { control.disabled = true; });
    try {
      const body = new URLSearchParams({ csrf, onu_row_id: onuRowId, port: String(currentPort), field, value });
      const response = await window.linnmsAjax.request(liveUrl + '?type=' + encodeURIComponent(action), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' },
        body: body.toString(),
      });
      const result = await response.json();
      if (!response.ok || result?.ok !== true) throw new Error(result?.message || 'Pengaturan port LAN gagal.');
      notify(result.refreshed === false ? 'warning' : 'success', result.refreshed === false
        ? 'Perintah diterima, tetapi snapshot port LAN belum dapat diperbarui.'
        : (result.message || 'Pengaturan port LAN berhasil.'));
      if (field === 'settings') {
        const settings = JSON.parse(value);
        const [mode, defaultVlan, vlanList] = settings.vlan.split(':');
        const values = {
          mode,
          default_vlan: ['access', 'hybrid'].includes(mode) ? defaultVlan : '',
          vlans: ['trunk', 'hybrid'].includes(mode) ? vlanList.split(',').filter(Boolean) : [],
          dhcp: settings.dhcp,
        };
        currentDetail?.fields?.forEach((item) => {
          if (Object.hasOwn(values, item.key)) item.value = values[item.key];
        });
      } else {
        const currentField = currentDetail?.fields?.find((item) => item.key === field);
        if (currentField) currentField.value = value;
        if (field === 'enable' && currentDetail) currentDetail.interface_status = value;
      }
    } finally {
      saving = false;
      controls.forEach((control, index) => { control.disabled = disabled[index]; });
      syncSettings();
    }
  };

  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const opener = target.closest('[data-onu-lan-open]');
    if (opener) openPort(opener);
    else if (target.closest('[data-onu-lan-close]') || target === modal) closeModal();
  });
  root.addEventListener('change', async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const toggle = target.closest('[data-onu-lan-action]');
    if (toggle instanceof HTMLInputElement && toggle.dataset.onuLanAction) {
      const value = toggle.checked ? 'enable' : 'disable';
      const label = toggle.parentElement.querySelector('[data-onu-lan-toggle-label]');
      if (label) label.textContent = value === 'enable' ? 'Enable' : 'Disable';
      try {
        await send(toggle.dataset.onuLanAction, toggle.dataset.onuLanKey, value);
        if (currentDetail) render(currentDetail);
      } catch (error) {
        notify('error', error.message || 'Pengaturan port LAN gagal.');
        if (currentDetail) render(currentDetail);
      }
    } else if (toggle instanceof HTMLInputElement && toggle.hasAttribute('data-onu-lan-preview')) {
      const value = toggle.checked ? 'enable' : 'disable';
      toggle.indeterminate = false;
      const label = toggle.parentElement.querySelector('[data-onu-lan-toggle-label]');
      if (label) label.textContent = value === 'enable' ? 'Enable' : 'Disable';
    }
    if (target.matches('[data-onu-lan-key="mode"]')) syncSettings();
  });
  root.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-onu-lan-form]');
    if (!form || !currentDetail?.save_action) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    const mode = form.elements.namedItem('mode')?.value || '';
    const vlanDefault = ['lan', 'trunk'].includes(mode) ? '0' : (form.elements.namedItem('default_vlan')?.value || '');
    const vlans = ['lan', 'access'].includes(mode) ? '' : Array.from(form.elements.namedItem('vlans')?.selectedOptions || [], (option) => option.value).join(',');
    const dhcp = form.elements.namedItem('dhcp')?.value || '';
    if (!mode || !dhcp || (['access', 'hybrid'].includes(mode) && !vlanDefault)
      || (['trunk', 'hybrid'].includes(mode) && !vlans)) {
      notify('error', 'Lengkapi pengaturan port LAN.');
      return;
    }
    try {
      await send(currentDetail.save_action, 'settings', JSON.stringify({ vlan: [mode, vlanDefault, vlans].join(':'), dhcp }));
    } catch (error) {
      notify('error', error.message || 'Pengaturan port LAN gagal.');
      if (currentDetail) render(currentDetail);
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });
})();

(() => {
  const actionModal = document.getElementById('onuServiceActionModal');
  let pendingAction = null;
  window.onuServiceActionModal = {
    open(message, commit) {
      if (!actionModal || typeof commit !== 'function') return;
      pendingAction = commit;
      actionModal.querySelector('[data-onu-service-action-message]').textContent = message;
      actionModal.hidden = false;
      actionModal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('onu-modal-open');
      actionModal.querySelector('[data-onu-service-action-confirm]')?.focus();
    },
  };
  actionModal?.querySelector('[data-onu-service-action-confirm]')?.addEventListener('click', async (event) => {
    if (!pendingAction) return;
    const confirm = event.currentTarget;
    const commit = pendingAction;
    pendingAction = null;
    confirm.disabled = true;
    try { await commit(); }
    finally {
      confirm.disabled = false;
      actionModal.querySelector('[data-onu-modal-close]')?.click();
    }
  });
  if (actionModal) new MutationObserver(() => {
    if (actionModal.hidden) pendingAction = null;
  }).observe(actionModal, { attributes: true, attributeFilter: ['hidden'] });
  window.onuServiceRows = {
    entries(panel) {
      return [...(panel.querySelector('[data-onu-service-entries]')?.children || [])]
        .filter((node) => node.matches('[data-onu-service-entry]'));
    },
    entry(panel, id) {
      return this.entries(panel).find((node) => node.dataset.onuServiceEntry.toLowerCase() === String(id).toLowerCase());
    },
    value(entry, index) {
      return entry?.querySelector(`[data-onu-field-index="${index}"]`)?.textContent.trim() || '';
    },
    setValue(entry, index, value) {
      const target = entry?.querySelector(`[data-onu-field-index="${index}"] [data-onu-value]`);
      if (target) target.textContent = value;
    },
    render(panel, id, labels, values) {
      const container = panel.querySelector('[data-onu-service-entries]');
      if (!container) return null;
      const row = document.createElement('tr');
      row.dataset.onuServiceEntry = String(id);
      labels.forEach((label, index) => {
        const content = document.createElement('td');
        content.dataset.onuFieldIndex = String(index);
        const value = document.createElement('span');
        value.dataset.onuValue = '';
        value.textContent = String(values[index] ?? '—');
        content.append(value);
        row.append(content);
      });
      if (panel.querySelector('.onu-service-actions-heading')) {
        const actions = document.createElement('td');
        actions.className = 'onu-service-entry-actions';
        row.append(actions);
      }
      const old = this.entry(panel, id);
      if (old) old.replaceWith(row);
      else {
        container.querySelector('[data-onu-service-empty]')?.remove();
        const next = this.entries(panel).find((entry) =>
          entry.dataset.onuServiceEntry.localeCompare(String(id), undefined, { numeric: true }) > 0);
        container.insertBefore(row, next || null);
      }
      return row;
    },
  };
})();
(() => {
  const rows = window.onuServiceRows;
  const csrf = document.querySelector('input[name="csrf"]')?.value || '';
  const liveUrl = document.body.dataset.liveUrl || 'live.php';
  const definitions = {
    remote: { title: 'Remote Access', modal: 'onuRemoteAccessModal', endpoint: 'onu-remote-access-save' },
    veip: { title: 'VEIP', modal: 'onuVeipModal', endpoint: 'onu-veip-save' },
    tr069: { title: 'TR069', modal: 'onuTr069Modal', endpoint: 'onu-tr069-save' },
    wifi: { title: 'WiFi', modal: 'onuWifiModal', endpoint: 'onu-wifi-config-save' },
  };
  const clean = value => ['—', 'NotSuporte', 'N/A', 'Untag'].includes(String(value || '').trim()) ? '' : String(value ?? '').trim();
  const enabled = value => ['enable', 'enabled', 'unlock'].includes(String(value || '').toLowerCase());
  const notify = (level, message) => window.linnmsNotify?.(level, message);
  const post = async (type, data, onuId) => {
    data.set('csrf', csrf); data.set('onu_row_id', onuId);
    const response = await window.linnmsAjax.request(liveUrl + '?type=' + type, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' }, body: data.toString() });
    const result = await response.json();
    if (!response.ok || result?.ok !== true) throw Error(result?.message || 'Pengaturan gagal disimpan.');
    return result;
  };
  const actions = (panel, entry, kind, id) => {
    const cell = entry?.querySelector('.onu-service-entry-actions');
    if (!cell) return;
    cell.replaceChildren();
    if ((kind === 'veip' && id.toUpperCase() !== 'VEIP_1') || (kind === 'tr069' && id !== '1')) return;
    const controls = document.createElement('span'); controls.className = 'onu-service-entry-controls';
    [['edit', 'bi-pencil'], ['delete', 'bi-trash']].forEach(([action, icon]) => {
      const button = document.createElement('button'); button.type = 'button';
      button.className = 'button ' + (action === 'delete' ? 'button-danger' : 'secondary') + ' small';
      button.dataset.onuRowId = id;
      if (action === 'edit') { button.dataset.onuRowEdit = kind; button.dataset.onuModalOpen = definitions[kind].modal; }
      else button.dataset.onuRowDelete = kind;
      button.setAttribute('aria-label', (action === 'edit' ? 'Edit ' : 'Hapus ') + definitions[kind].title + ' ' + id);
      const glyph = document.createElement('i'); glyph.className = 'bi ' + icon; glyph.setAttribute('aria-hidden', 'true');
      button.append(glyph); controls.append(button);
    });
    cell.append(controls);
  };
  Object.entries(definitions).forEach(([kind, definition]) => {
    const panel = document.querySelector('[data-onu-service="' + kind + '"]');
    const form = document.querySelector('[data-onu-service-form="' + kind + '"]');
    if (!panel || !form) return;
    const modal = document.getElementById(definition.modal);
    const field = name => form.elements.namedItem(name);
    const onuId = form.dataset.onuRowId || '';
    let selectedId = ''; let baseline = ''; let busy = false;
    const fieldValue = (entry, index) => clean(rows.value(entry, index));
    const row = () => rows.entry(panel, selectedId);
    const headers = () => [...panel.querySelectorAll('thead th:not(.onu-service-actions-heading)')].map(e => e.textContent.trim());
    const state = value => {
      field('state').value = kind === 'remote' ? (enabled(value) ? 'enable' : 'disable') : (enabled(value) ? 'unlock' : 'lock');
      const control = form.querySelector('[data-onu-service-status]'); control.checked = enabled(value);
      form.querySelector('[data-onu-service-status-label]').textContent = control.checked ? 'Enable' : 'Disable';
    };
    const set = (name, value) => { const control = field(name); if (control) control.value = String(value ?? ''); };
    const setVlans = (name, value) => {
      const control = field(name); if (!control) return;
      const values = String(value || '').replace(/\s+/g, '').split(',').filter(Boolean);
      values.forEach(value => { if (!Array.from(control.options).some(o => o.value === value)) control.add(new Option(value, value)); });
      if (control.multiple) Array.from(control.options).forEach(o => { o.selected = values.includes(o.value); });
      else control.value = values[0] || '';
    };
    const sync = () => {
      const mode = field('mode');
      if (kind === 'veip' || kind === 'wifi') {
        const current = mode.value;
        [['default_vlan', ['access', 'hybrid']], ['vlans', ['trunk', 'hybrid']]].forEach(([name, modes]) => {
          const control = field(name); const active = modes.includes(current);
          control.disabled = !active; control.required = active; control.closest('.field').hidden = !active;
        });
      }
      form.querySelectorAll('[data-onu-wifi-security]').forEach(wrapper => {
        const active = field('security').value === 'wpa'; wrapper.hidden = !active;
        wrapper.querySelectorAll('input,select').forEach(control => { control.disabled = !active; control.required = active && control.name === 'key'; });
      });
      form.querySelectorAll('[data-onu-tr069-credential]').forEach(wrapper => {
        const active = field('auth_action').value === 'basic'; wrapper.hidden = !active;
        wrapper.querySelectorAll('input').forEach(control => { control.disabled = !active; control.required = active; });
      });
      if (kind === 'tr069') { field('priority').disabled = field('vlan').value === ''; if (field('priority').disabled) field('priority').value = '0'; }
    };
    const payload = () => {
      const data = new URLSearchParams(new FormData(form));
      const mode = field('mode')?.value;
      if (kind === 'wifi' || kind === 'veip') {
        data.set('default_vlan', mode === 'trunk' ? '0' : (field('default_vlan').value || ''));
        data.set('vlans', mode === 'access' ? '' : Array.from(field('vlans').selectedOptions, o => o.value).join(','));
      }
      return data;
    };
    const signature = () => { const data = payload(); data.delete('state'); return data.toString(); };
    const refreshOptions = () => {
      if (kind !== 'wifi') return;
      const value = field('port').value; field('port').replaceChildren();
      rows.entries(panel).forEach(entry => { const id = entry.dataset.onuServiceEntry; const number = id.split('/')[1]; if (number) field('port').add(new Option(id, number)); });
      if (value) field('port').value = value;
    };
    const fill = (id, create = false) => {
      selectedId = String(id); const entry = row(); form.reset();
      state(create && kind === 'remote' ? 'enable' : (entry?.dataset.onuEntryState || (kind !== 'wifi' ? rows.value(entry, 1) : '')));
      if (kind === 'remote') {
        set('config_action', create ? 'create' : 'update'); set('index', selectedId); field('index').readOnly = !create;
        const mode = fieldValue(entry, 2).toLowerCase(); set('mode', create ? 'forward' : (mode === 'allowed' ? 'forward' : (mode === 'blocked' ? 'discard' : '')));
        set('ingress_type', create ? 'wan' : fieldValue(entry, 3).toLowerCase()); set('protocols', fieldValue(entry, 4).toLowerCase());
        set('start_ip', fieldValue(entry, 5)); set('end_ip', fieldValue(entry, 6));
      } else if (kind === 'tr069') {
        set('acs', fieldValue(entry, 2)); set('auth_action', 'keep'); set('username', ''); set('password', '');
        set('vlan', fieldValue(entry, 5)); set('priority', fieldValue(entry, 6) || '0');
      } else {
        if (kind === 'wifi') { refreshOptions(); set('port', selectedId.split('/')[1]); set('ssid', fieldValue(entry, 6)); set('security', 'keep'); set('key', ''); }
        const mode = fieldValue(entry, 2).toLowerCase(); set('mode', mode === 'tag' ? 'access' : (['access', 'trunk', 'hybrid'].includes(mode) ? mode : ''));
        setVlans('default_vlan', fieldValue(entry, 3)); setVlans('vlans', fieldValue(entry, 4));
      }
      sync(); baseline = signature();
    };
    const render = (id, values, admin) => {
      const entry = rows.render(panel, id, headers(), values); if (!entry) return;
      entry.dataset.onuEntryState = admin || ''; actions(panel, entry, kind, id); refreshOptions();
    };
    const apply = result => {
      const current = row(); const values = headers().map((_, i) => rows.value(current, i) || '—');
      if (kind === 'remote' && result.access) {
        const a = result.access; selectedId = String(a.index);
        render(selectedId, [selectedId, enabled(a.state) ? 'Enable' : 'Disable', a.mode === 'forward' ? 'Allowed' : 'Blocked',
          String(a.ingress_type || '').toUpperCase(), String(a.protocol || '').toUpperCase(), a.start_ip || '0.0.0.0', a.end_ip || '0.0.0.0'], a.state);
      } else if (kind === 'tr069' && result.tr069) {
        const t = result.tr069; selectedId = String(t.index || 1);
        render(selectedId, [selectedId, enabled(t.state) ? 'Enable' : 'Disable', t.acs || '—', t.username || '—', t.password || '—',
          Number(t.vlan) > 0 ? String(t.vlan) : 'Untag', Number(t.vlan) > 0 ? String(t.priority || 0) : '—'], t.state);
      } else if (result.port) {
        const admin = result.port['admin status']; if (kind === 'veip') values[1] = enabled(admin) ? 'Enable' : 'Disable';
        else if ('operate status' in result.port) values[1] = ['enable', 'up'].includes(result.port['operate status']) ? 'UP' : (['disable', 'down'].includes(result.port['operate status']) ? 'Down' : '—');
        render(selectedId, values, admin);
      } else if (result[kind]) {
        const data = result[kind]; values[1] = kind === 'veip' ? (enabled(data.state) ? 'Enable' : 'Disable') : '—';
        values[2] = data.mode || '—'; values[3] = data.def_vlan || data.default_vlan || '—'; values[4] = (data.vlans || []).join(', ') || '—';
        if (kind === 'wifi') values[6] = data.ssid || '—'; render(selectedId, values, data.state);
      }
    };
    rows.entries(panel).forEach(entry => actions(panel, entry, kind, entry.dataset.onuServiceEntry));
    panel.addEventListener('click', event => {
      const target = event.target instanceof Element ? event.target : null; if (!target) return;
      const edit = target.closest('[data-onu-row-edit]');
      if (edit) { fill(edit.dataset.onuRowId || ''); return; }
      if (target.closest('[data-onu-remote-add]')) {
        const used = new Set(rows.entries(panel).map(e => Number(e.dataset.onuServiceEntry))); let id = 1; while (used.has(id)) id++;
        fill(id, true); return;
      }
      if (target.closest('[data-onu-tr069-configure]')) { fill('1'); return; }
      const remove = target.closest('[data-onu-row-delete]'); if (!remove || busy) return;
      const id = remove.dataset.onuRowId;
      window.onuServiceActionModal.open('Hapus konfigurasi ' + definition.title + ' ' + id + '?', async () => {
        if (busy) return; busy = true;
        try {
          selectedId = id;
          const data = kind === 'remote' ? new URLSearchParams({ config_action: 'delete', index: id }) : new URLSearchParams({ kind, port: id.toLowerCase() });
          const result = await post(kind === 'remote' ? definition.endpoint : 'onu-service-config-delete', data, onuId);
          if (kind === 'remote') { rows.entry(panel, id)?.remove(); if (!rows.entries(panel).length) panel.querySelector('[data-onu-service-entries]').innerHTML = '<tr data-onu-service-empty><td colspan="' + (headers().length + 1) + '" class="text-muted">Belum ada data.</td></tr>'; }
          else apply(result);
          notify(result.refreshed ? 'success' : 'warning', result.message);
        } catch (error) { notify('error', error.message); } finally { busy = false; }
      });
    });
    form.addEventListener('change', event => {
      if (event.target.matches('[data-onu-service-status]')) state(event.target.checked ? 'enable' : 'disable');
      else if (kind === 'wifi' && event.target.name === 'port') fill('WIFI_0/' + field('port').value);
      else sync();
    });
    form.addEventListener('submit', async event => {
      event.preventDefault(); if (busy || !csrf) return;
      const unchanged = signature() === baseline && !(kind === 'remote' && field('config_action').value === 'create');
      if (!unchanged && !form.reportValidity()) return;
      const data = payload(); let endpoint = definition.endpoint;
      if (unchanged && (kind === 'wifi' || kind === 'veip')) {
        endpoint = 'onu-interface-state-save'; data.set('kind', kind); data.set('port', selectedId.toLowerCase());
      } else if (unchanged && kind === 'remote') data.set('config_action', 'state');
      else if (unchanged && kind === 'tr069') data.set('config_action', 'state');
      busy = true; const button = form.querySelector('[type="submit"]'); button.disabled = true;
      try {
        const result = await post(endpoint, data, onuId); apply(result);
        modal.querySelector('[data-onu-modal-close]')?.click(); notify(result.refreshed ? 'success' : 'warning', result.message);
      } catch (error) { notify('error', error.message); } finally { busy = false; button.disabled = false; }
    });
    state(field('state').value); sync(); refreshOptions();
  });
})();

(() => {
  const panel = document.querySelector('[data-onu-service="service"]');
  const form = document.querySelector('[data-onu-data-service-form]');
  if (!panel || !(form instanceof HTMLFormElement)) return;
  const modal = form.closest('.onu-control-modal');
  const liveUrl = document.body.dataset.liveUrl || 'live.php';
  const onuRowId = form.dataset.onuRowId || '';
  const csrf = document.querySelector('input[name="csrf"]')?.value || '';
  const field = (name) => form.elements.namedItem(name);
  const notify = (level, message) => {
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(level, message);
  };
  const syncOuter = () => {
    const outer = form.querySelector('[data-onu-data-service-outer]');
    if (!outer) return;
    const visible = field('vlan_mode').value === 'qinq';
    outer.hidden = !visible;
    field('svlan').disabled = !visible;
    field('svlan').required = visible;
  };
  const openInitial = (index, toggle) => {
    form.reset();
    const partial = toggle.dataset.onuServicePartial === '1';
    field('config_action').value = partial ? 'enable' : 'create';
    field('index').value = String(index);
    field('name').value = partial ? toggle.dataset.onuServiceName || '' : '';
    field('vlan').value = partial ? toggle.dataset.onuServiceVlan || '' : '';
    field('vlan').readOnly = partial && Number(toggle.dataset.onuServiceVlan) > 0;
    document.getElementById('onuDataServiceModalTitle').textContent = (partial ? 'Aktifkan' : 'Konfigurasi awal') + ' Service ' + index;
    syncOuter();
    form.querySelectorAll('.field').forEach((wrapper) => {
      const control = wrapper.querySelector('input,select');
      if (!control) return;
      const visible = !partial || ['name', 'vlan'].includes(control.name);
      wrapper.hidden = !visible || (control.name === 'svlan' && field('vlan_mode').value !== 'qinq');
      control.disabled = !visible || (control.name === 'svlan' && field('vlan_mode').value !== 'qinq');
    });
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('onu-modal-open');
    window.requestAnimationFrame(() => field('name')?.focus());
  };
  const send = async (data) => {
    data.set('csrf', csrf);
    data.set('onu_row_id', onuRowId);
    const response = await window.linnmsAjax.request(liveUrl + '?type=onu-data-service-save', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' },
      body: data.toString(),
    });
    const result = await response.json();
    if (!response.ok || result?.ok !== true) throw new Error(result?.message || 'Perubahan Service belum berhasil.');
    if (result.refreshed === true) window.location.reload();
    else notify('warning', result.message || 'Perintah diterima, tetapi snapshot Service belum tersimpan.');
    return result;
  };
  panel.addEventListener('change', async (event) => {
    const toggle = event.target.closest('[data-onu-service-toggle]');
    if (!toggle) return;
    if (!csrf || !onuRowId) { toggle.checked = !toggle.checked; notify('error', 'Sesi atau identitas ONU tidak tersedia.'); return; }
    const index = Number(toggle.dataset.onuServiceIndex);
    const enabled = toggle.checked;
    if (!Number.isInteger(index) || index < 1) { toggle.checked = !enabled; return; }
    if (enabled && toggle.dataset.onuServiceConfigured !== '1') {
      toggle.checked = false;
      openInitial(index, toggle);
      return;
    }
    const status = toggle.closest('.onu-service-toggle-row')?.querySelector('[data-onu-service-status]');
    const oldStatus = enabled ? 'Disable' : 'Enable';
    if (status) status.textContent = 'Menyimpan…';
    toggle.disabled = true;
    try {
      await send(new URLSearchParams({ index: String(index), config_action: enabled ? 'enable' : 'disable' }));
      if (status) status.textContent = enabled ? 'Enable' : 'Disable';
    } catch (error) {
      toggle.checked = !enabled;
      if (status) status.textContent = oldStatus;
      notify('error', error.message || 'Perubahan Service belum berhasil.');
    } finally {
      toggle.disabled = false;
    }
  });
  field('vlan_mode').addEventListener('change', syncOuter);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!csrf || !onuRowId || !form.reportValidity()) return;
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      await send(new URLSearchParams(new FormData(form)));
      modal.querySelector('[data-onu-modal-close]')?.click();
      submit.disabled = false;
    } catch (error) {
      notify('error', error.message || 'Konfigurasi awal Service belum berhasil.');
      submit.disabled = false;
    }
  });
})();
