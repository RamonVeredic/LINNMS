(() => {
  const root = document.querySelector('[data-olt-pon-config]');
  if (!root) return;

  const rowId = Number.parseInt(root.dataset.oltRowId || '', 10);
  const rowRef = root.dataset.oltRef || '';
  const csrf = root.dataset.csrf || '';
  const grid = root.querySelector('[data-olt-pon-grid]');
  const status = root.querySelector('[data-olt-pon-status]');
  if (!grid || !status || !Number.isFinite(rowId) || rowId < 1) return;

  const node = (tag, className, text) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  };

  const hidden = (name, value) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    return input;
  };

  const selectField = (labelText, name, current, options, spanAll = false) => {
    const wrap = node('label', `field${spanAll ? ' olt-pon-field-wide' : ''}`);
    wrap.append(node('span', '', labelText));

    const select = document.createElement('select');
    select.name = name;

    const normalized = String(current ?? '');
    const values = options.includes(normalized) || normalized === ''
      ? options
      : [normalized, ...options];

    values.forEach((value) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = value;
      option.selected = value === normalized;
      select.append(option);
    });

    wrap.append(select);
    return wrap;
  };

  const valueText = (value, unit) => {
    if (value === null || value === undefined || value === '') return '—S';
    return `${value}${unit ? ` ${unit}` : ''}`;
  };

  const infoItem = (label, value, unit) => {
    const item = node('div');
    item.append(node('span', '', label));
    item.append(node('strong', 'mono', valueText(value, unit)));
    return item;
  };

  const ponNumber = (port) => {
    const raw = String(port.pon || port.port_ref || '').trim();
    const match = raw.match(/(?:\/|_)(\d+)$/);
    if (match) return match[1];
    return raw.toLowerCase() === 'pon' ? '-' : (raw || '-');
  };

  const displayPortRef = (port) => {
    const raw = String(port.pon || port.port_ref || '-');
    return raw.replace('_', '/');
  };

  const linkLabel = (link) => {
    const raw = String(link ?? '').trim();
    if (raw.toLowerCase() === 'linkup') return 'Link Up';
    if (raw.toLowerCase() === 'linkdown') return 'Link Down';
    return '—S';
  };

  const renderPort = (port, startOpen = false) => {
    const card = node('article', 'config-card olt-pon-card');
    if (startOpen) card.classList.add('is-open');

    const details = node('details', 'olt-pon-details');
    details.setAttribute('name', 'olt-pon-accordion');
    if (startOpen) details.open = true;

    const summary = node('summary', 'olt-pon-card-head');
    const titleWrap = node('span', 'olt-pon-card-title');
    titleWrap.append(node('span', 'olt-pon-card-name', `PON ${ponNumber(port)}`));
    titleWrap.append(node(
      'span',
      'olt-pon-card-subtitle mono',
      `${displayPortRef(port)}${port.port_type ? ` · ${port.port_type}` : ''}`
    ));

    const link = String(port.link_status || '').trim();
    const meta = node('span', 'olt-pon-card-meta');
    meta.append(node(
      'span',
      `olt-pon-link ${link.toLowerCase() === 'linkup' ? 'is-up' : 'is-down'}`,
      linkLabel(link)
    ));
    summary.append(titleWrap, meta);
    details.append(summary);

    const body = node('div', 'olt-pon-card-body');

    const form = document.createElement('form');
    form.method = 'post';
    form.className = 'olt-pon-form';
    form.dataset.oltPonForm = '1';
    form.append(
      hidden('csrf', csrf),
      hidden('olt_row_id', String(rowId)),
      hidden('olt_port_ref', String(port.port_ref || ''))
    );

    const opticsTitle = node('div', 'olt-pon-section-title');
    opticsTitle.append(node('i', 'bi bi-broadcast-pin'));
    opticsTitle.append(node('span', '', 'Informasi Modul Optik PON'));
    form.append(opticsTitle);

    const optics = port.optics || {};
    const opticGrid = node('div', 'detail-list olt-pon-optics');
    opticGrid.append(
      infoItem('Suhu Modul', optics.temperature, '°C'),
      infoItem('Tegangan Modul', optics.voltage, 'V'),
      infoItem('Arus Modul', optics.current, 'mA'),
      infoItem('TX Power', optics.tx_power, 'dBm')
    );
    form.append(opticGrid);

    const actions = node('div', 'olt-pon-card-actions');
    actions.append(node('small', 'olt-pon-source', 'Status saat ini'));
    form.append(actions);

    body.append(form);

    const ponIndex = Number.parseInt(String(ponNumber(port)), 10);
    const ponValue = Number.isFinite(ponIndex) ? ponIndex : 0;
    const labelFieldId = `oltPonLocalDescription${ponValue > 0 ? ponValue : 'PON'}`;

    const labelForm = document.createElement('form');
    labelForm.method = 'post';
    labelForm.className = 'olt-pon-form olt-pon-inline-form';
    labelForm.dataset.oltPonEditForm = '1';
    labelForm.append(
      hidden('csrf', csrf),
      hidden('action', 'save_olt_pon_description_local'),
      hidden('olt_row_id', String(rowId)),
      hidden('olt_port_ref', String(port.port_ref || '')),
      hidden('olt_port_number', String(ponValue))
    );

    const labelCopy = node('label', 'olt-pon-inline-copy');
    labelCopy.setAttribute('for', labelFieldId);
    labelCopy.append(node('strong', '', 'Keterangan PON'));
    labelCopy.append(node('small', '', 'Nama yang membantu mengenali PON ini di halaman OLT dan daftar ONU.'));

    const labelControl = node('div', 'olt-pon-inline-control');
    const labelShell = node('span', 'olt-pon-text-shell');
    const labelInput = document.createElement('input');
    labelInput.type = 'text';
    labelInput.name = 'olt_port_description';
    labelInput.id = labelFieldId;
    labelInput.maxLength = 128;
    labelInput.value = String(port.description || '');
    labelInput.placeholder = 'mis. PON 1 - KADUSIRUNG';
    labelInput.disabled = true;
    labelInput.dataset.oltPonEditControl = '1';
    labelShell.append(labelInput);

    const labelEdit = node('button', 'button secondary olt-pon-edit-button');
    labelEdit.type = 'button';
    labelEdit.dataset.oltPonEdit = '1';
    labelEdit.dataset.target = labelFieldId;
    labelEdit.setAttribute('aria-controls', labelFieldId);
    labelEdit.setAttribute('aria-pressed', 'false');
    labelEdit.append(node('i', 'bi bi-pencil'), node('span', '', 'Edit'));

    const labelCancel = node('button', 'button secondary olt-pon-cancel-button');
    labelCancel.type = 'button';
    labelCancel.dataset.oltPonCancel = '1';
    labelCancel.dataset.target = labelFieldId;
    labelCancel.hidden = true;
    labelCancel.setAttribute('aria-label', 'Batal mengedit keterangan PON');
    labelCancel.append(node('i', 'bi ri-close-line'));

    labelControl.append(labelShell, labelEdit, labelCancel);
    labelForm.append(labelCopy, labelControl);
    body.append(labelForm);

    details.append(body);
    card.append(details);
    return card;
  };

  const liveUrl = document.body.dataset.liveUrl || '';

  const setStatus = (message, state = '') => {
    status.textContent = message;
    status.dataset.state = state;

    status.classList.toggle('hidden', message === '');
  };

  root.addEventListener('submit', async (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form || form.dataset.oltPonForm !== '1') return;

    event.preventDefault();
    const save = form.querySelector('button[type="submit"]');
    if (save) save.disabled = true;
    setStatus(`Menyimpan perubahan ${form.elements.olt_port_ref?.value || 'PON'}...`, 'loading');

    try {
      const response = await window.linnmsAjax.request(`${liveUrl}?type=olt-port-update&ref=${encodeURIComponent(rowRef)}`, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        body: new FormData(form),
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.ok !== true) {
        throw new Error(payload?.message || 'Data PON belum tersedia.');
      }

      const card = form.closest('.olt-pon-card');
      if (card && payload.port) {
        card.replaceWith(renderPort(payload.port, true));
      }
      setStatus(payload.message || 'PON berhasil diperbarui dan diverifikasi.', 'ok');
    } catch (error) {
      setStatus('Perubahan PON belum berhasil disimpan.', 'error');
      if (save) save.disabled = false;
    }
  });

  const initialNode = root.querySelector('[data-olt-pon-initial]');
  let initialPorts = [];
  try {
    const parsed = JSON.parse(initialNode?.textContent || '[]');
    if (Array.isArray(parsed)) initialPorts = parsed;
  } catch (_) {
    initialPorts = [];
  }

  if (initialPorts.length === 0) {

    initialPorts = [{ port_ref: '0/1_1', pon: '0/1/1', port_type: 'PON' }];
  }

  const renderPorts = (ports, message = '') => {
    const usable = (Array.isArray(ports) ? ports : []).filter((port) => port && typeof port === 'object');
    grid.replaceChildren(...usable.map((port) => renderPort(port)));
    const withStatus = usable.filter((port) => String(port.link_status || '').trim() !== '').length;
    setStatus(
      message !== ''
        ? message
        : (withStatus > 0
            ? `${usable.length} PON`
            : `${usable.length} PON status belum tersedia`),
      withStatus > 0 ? 'ok' : 'error'
    );
  };

  renderPorts(initialPorts);
})();

(() => {
  const grids = [...document.querySelectorAll('.olt-pon-grid')];
  if (grids.length === 0) return;

  const syncCardState = (details, open = details.open) => {
    details.open = open;
    const card = details.closest('.olt-pon-card');
    if (card) card.classList.toggle('is-open', open);
  };

  const closeSiblings = (grid, details) => {
    grid.querySelectorAll('details.olt-pon-details[open]').forEach((other) => {
      if (other === details) return;
      syncCardState(other, false);
    });
  };

  grids.forEach((grid) => {
    grid.querySelectorAll('details.olt-pon-details').forEach((details) => {
      syncCardState(details);
    });

    grid.addEventListener('click', (event) => {
      const summary = event.target.closest('summary.olt-pon-card-head');
      if (!summary || !grid.contains(summary)) return;
      const details = summary.parentElement;
      if (!(details instanceof HTMLDetailsElement) || !details.classList.contains('olt-pon-details')) return;

      event.preventDefault();
      const shouldOpen = !details.open;
      if (shouldOpen) closeSiblings(grid, details);
      syncCardState(details, shouldOpen);
    });

    grid.addEventListener('toggle', (event) => {
      const details = event.target;
      if (!(details instanceof HTMLDetailsElement) || !details.classList.contains('olt-pon-details')) return;
      if (details.open) closeSiblings(grid, details);
      syncCardState(details);
    }, true);
  });
})();

(() => {
  const root = document;

  const controlsOf = (form) => [...form.querySelectorAll('[data-olt-pon-edit-control]')];

  const resetEditState = (form, focusButton = false) => {
    if (!form) return;
    const editButton = form.querySelector('[data-olt-pon-edit]');
    const cancelButton = form.querySelector('[data-olt-pon-cancel]');
    form.reset();
    form.classList.remove('is-editing');
    form.querySelectorAll('[data-olt-pon-edit-display]').forEach((display) => { display.hidden = false; });
    controlsOf(form).forEach((control) => {
      control.disabled = true;
      if (form.hasAttribute('data-pon-description-form')) control.hidden = true;
      delete control.dataset.originalValue;
    });
    if (editButton) {
      editButton.classList.remove('is-active');
      editButton.setAttribute('aria-pressed', 'false');
      editButton.querySelector('i')?.classList.replace('bi-floppy', 'bi-pencil');
      if (editButton.dataset.editLabel) {
        editButton.setAttribute('aria-label', 'Edit ' + editButton.dataset.editLabel);
        editButton.title = 'Edit ' + editButton.dataset.editLabel;
      }
      const label = editButton.querySelector('span');
      if (label) label.textContent = 'Edit';
      if (focusButton) editButton.focus();
    }
    if (cancelButton) cancelButton.hidden = true;
  };

  root.addEventListener('click', (event) => {
    const editButton = event.target.closest('[data-olt-pon-edit]');
    const cancelTrigger = event.target.closest('[data-olt-pon-cancel]');
    const trigger = editButton || cancelTrigger;
    if (!trigger) return;

    const form = trigger.closest('[data-olt-pon-edit-form]');
    const controls = form ? controlsOf(form) : [];
    if (!form || controls.length === 0) return;
    const cancelButton = form.querySelector('[data-olt-pon-cancel]');

    if (cancelTrigger) {
      resetEditState(form, true);
      return;
    }

    if (editButton.classList.contains('is-active')) {
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      const unchanged = controls.every(
        (control) => control.value === (control.dataset.originalValue ?? control.defaultValue)
      );
      if (unchanged) {
        resetEditState(form, true);
        return;
      }
      form.requestSubmit();
      return;
    }

    root.querySelectorAll('[data-olt-pon-edit-form]').forEach((otherForm) => {
      if (otherForm !== form) resetEditState(otherForm);
    });
    controls.forEach((control) => {
      control.dataset.originalValue = control.value;
      control.disabled = false;
      if (form.hasAttribute('data-pon-description-form')) control.hidden = false;
    });
    form.classList.add('is-editing');
    form.querySelectorAll('[data-olt-pon-edit-display]').forEach((display) => { display.hidden = true; });
    editButton.classList.add('is-active');
    editButton.setAttribute('aria-pressed', 'true');
    editButton.querySelector('i')?.classList.replace('bi-pencil', 'bi-floppy');
    if (editButton.dataset.editLabel) {
      editButton.setAttribute('aria-label', 'Simpan ' + editButton.dataset.editLabel);
      editButton.title = 'Simpan ' + editButton.dataset.editLabel;
    }
    const label = editButton.querySelector('span');
    if (label) label.textContent = 'Simpan';
    if (cancelButton) cancelButton.hidden = false;
    window.requestAnimationFrame(() => {
      const first = controls[0];
      if (!first) return;
      const visibleControl = first instanceof HTMLSelectElement
        ? (first.closest('.select')?.querySelector('.select-button') ?? first)
        : first;
      visibleControl?.focus();
      if (first instanceof HTMLInputElement) first.select();
    });
  });
})();

(() => {
  const root = document.querySelector('[data-olt-view]');
  if (!root) return;
  const tabs = [...root.querySelectorAll('[data-olt-tab]')];
  const panels = [...root.querySelectorAll('[data-olt-panel]')];
  const storageKey = 'linnms.olt.tab';
  const activate = (key, focus = false) => {
    if (!tabs.some((tab) => tab.dataset.oltTab === key)) return;
    try { window.localStorage.setItem(storageKey, key); } catch (error) {}
    tabs.forEach((tab) => {
      const selected = tab.dataset.oltTab === key;
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      if (selected && focus) tab.focus();
    });
    panels.forEach((panel) => { panel.hidden = panel.dataset.oltPanel !== key; });
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab.dataset.oltTab));
    tab.addEventListener('keydown', (event) => {
      let next;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      if (next === undefined) return;
      event.preventDefault();
      activate(tabs[next].dataset.oltTab, true);
    });
  });
  const followAnchor = () => {
    const id = window.location.hash.slice(1);
    const target = document.getElementById(id);
    const panel = target?.closest('[data-olt-panel]');
    if (panel) {
      activate(panel.dataset.oltPanel);
      requestAnimationFrame(() => target.scrollIntoView({ block: 'nearest' }));
    }
  };
  window.addEventListener('hashchange', followAnchor);
  const anchorTarget = document.getElementById(window.location.hash.slice(1));
  const hasAnchorPanel = Boolean(anchorTarget?.closest('[data-olt-panel]'));
  followAnchor();
  if (!hasAnchorPanel) {
    let savedTab = '';
    try { savedTab = window.localStorage.getItem(storageKey) || ''; } catch (error) {}
    if (savedTab !== '' && savedTab !== 'pon') activate(savedTab);
  }
})();

(() => {
  const root = document.querySelector('[data-olt-view]');
  if (!root) return;
  let opener = null;

  const close = (modal, restoreFocus = true) => {
    if (!(modal instanceof HTMLElement)) return;
    modal.querySelectorAll('[data-pon-description-form] [data-olt-pon-cancel]:not([hidden])').forEach((button) => button.click());
    modal.querySelector('form')?.reset();
    const description = modal.querySelector('input[name="olt_port_description"]');
    const count = modal.querySelector('[data-olt-description-count]');
    if (count && description) count.textContent = String(description.value.length);
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    if (!root.querySelector('[data-olt-pon-modal]:not([hidden])')) {
      document.body.classList.remove('olt-pon-modal-open');
    }
    if (restoreFocus && opener instanceof HTMLElement) opener.focus();
  };
  const open = (modal, trigger = null) => {
    if (!(modal instanceof HTMLElement)) return;
    root.querySelectorAll('[data-olt-pon-modal]:not([hidden])').forEach((other) => close(other, false));
    opener = trigger instanceof HTMLElement ? trigger : opener;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('olt-pon-modal-open');
    window.requestAnimationFrame(() => modal.querySelector('[data-olt-pon-modal-close], input, textarea, button')?.focus());
  };

  root.querySelectorAll('[data-olt-pon-modal-open]').forEach((button) => {
    button.addEventListener('click', () => open(document.getElementById(button.dataset.oltPonModalOpen || ''), button));
  });
  root.querySelectorAll('[data-olt-pon-modal]').forEach((modal) => {
    modal.querySelectorAll('[data-olt-pon-modal-close]').forEach((button) => button.addEventListener('click', () => close(modal)));
    modal.addEventListener('click', (event) => { if (event.target === modal) close(modal); });
    modal.querySelector('input[name="olt_port_description"]')?.addEventListener('input', (event) => {
      const count = modal.querySelector('[data-olt-description-count]');
      if (count) count.textContent = String(event.target.value.length);
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close(root.querySelector('[data-olt-pon-modal]:not([hidden])'));
  });

  const focused = root.querySelector('[data-olt-pon-modal-focused]');
  if (focused) open(focused, root.querySelector(`[data-olt-pon-modal-open="${focused.id}"]`));
})();

(() => {
  'use strict';


  const forms = [...document.querySelectorAll('form[data-olt-refresh]')];
  if (forms.length === 0) return;
  const liveUrl = document.body.dataset.liveUrl || '';
  if (liveUrl === '') return;

  forms.forEach((form) => {
    const button = form.querySelector('[data-olt-refresh-button]');
    const label = form.querySelector('[data-olt-refresh-label]');
    const status = form.querySelector('[data-olt-refresh-status]');
    const defaultLabel = label ? label.textContent : 'Perbarui';

    const setStatus = (message) => {
      if (!status) return;
      status.textContent = message;
      status.hidden = message === '';
    };

    const setBusy = (busy) => {
      form.dataset.oltRefreshBusy = busy ? '1' : '';
      if (!button) return;
      button.disabled = busy;
      button.setAttribute('aria-busy', busy ? 'true' : 'false');
      const spinner = button.querySelector('.sync-loading-spinner');
      const icon = button.querySelector('i');
      if (busy && icon) {
        const replacement = document.createElement('span');
        replacement.className = 'sync-loading-spinner';
        replacement.setAttribute('aria-hidden', 'true');
        icon.replaceWith(replacement);
      } else if (!busy && spinner) {
        const replacement = document.createElement('i');
        replacement.className = 'bi ri-refresh-line';
        replacement.setAttribute('aria-hidden', 'true');
        spinner.replaceWith(replacement);
      }
      if (label) label.textContent = busy ? 'Memperbarui...' : defaultLabel;
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (form.dataset.oltRefreshBusy === '1') return;
      const ref = form.dataset.oltRef || '';
      if (ref === '') return;

      setBusy(true);
      setStatus('Memperbarui data OLT...');

      try {
        const response = await window.linnmsAjax.request(
          `${liveUrl}?type=olt-view-refresh&ref=${encodeURIComponent(ref)}`,
          {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
            body: new FormData(form),
          }
        );
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.ok !== true) {
          throw new Error(payload?.message || 'Data OLT belum dapat diperbarui.');
        }

        const warnings = Array.isArray(payload.warnings) ? payload.warnings.filter(Boolean) : [];
        setStatus(warnings.length ? warnings.join(' ') : (payload.message || 'Data OLT diperbarui.'));



        const target = form.dataset.oltRefreshTarget || '';
        const url = new URL(window.location.href);
        if (target) url.hash = target;
        window.location.replace(url.toString());
      } catch (error) {
        setBusy(false);
        setStatus(error instanceof Error ? error.message : 'Data OLT belum dapat diperbarui.');
      }
    });
  });
})();


document.addEventListener('change', async (event) => {
  const toggle = event.target.closest?.('[data-pon-flow-control-switch]');
  if (!toggle) return;
  const notify = (level, message) => {
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify(level, message);
  };
  if (toggle.dataset.ponFlowControlKnown !== '1') {
    toggle.checked = false;
    notify('warning', 'Status Flow Control belum tersedia. Perubahan tidak dikirim.');
    return;
  }
  const rowId = toggle.dataset.oltRowId || '';
  const pon = toggle.dataset.ponNumber || '';
  const ref = toggle.dataset.ponRef || '';
  const csrf = toggle.dataset.csrf || '';
  const value = toggle.checked ? 'Enable' : 'Disable';
  const previous = !toggle.checked;
  if (!rowId || !pon || !csrf) {
    toggle.checked = previous;
    notify('error', 'Data PON tidak lengkap.');
    return;
  }
  toggle.disabled = true;
  try {
    const body = new URLSearchParams({ olt_row_id: rowId, olt_port_number: pon, olt_port_ref: ref, value, csrf });
    const response = await window.linnmsAjax.request((document.body.dataset.liveUrl || 'live.php') + '?type=olt-pon-flow-control-save', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' },
      body: body.toString(),
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok !== true) throw new Error(payload?.message || 'Flow Control gagal disimpan.');
    const label = toggle.closest('label')?.querySelector('[data-pon-flow-control-label]');
    if (label) label.textContent = value;
    notify('success', payload.message || 'Flow Control tersimpan.');
  } catch (error) {
    toggle.checked = previous;
    notify('error', error instanceof Error ? error.message : 'Flow Control gagal disimpan.');
  } finally {
    toggle.disabled = false;
  }
});

document.addEventListener('change', (event) => {
  const toggle = event.target.closest?.('[data-pon-unsupported-toggle]');
  if (!toggle) return;
  toggle.checked = !toggle.checked;
  if (typeof window.linnmsNotify === 'function') {
    window.linnmsNotify('warning', (toggle.dataset.ponUnsupportedToggle || 'Konfigurasi PON') + ' belum didukung untuk perangkat ini.');
  }
});

document.addEventListener('change', (event) => {
  const toggle = event.target.closest?.('[data-pon-status-switch]');
  if (!toggle) return;
  const form = toggle.closest('form') || (toggle.dataset.formId ? document.getElementById(toggle.dataset.formId) : null);
  if (!form) return;
  const value = toggle.checked ? 'Enable' : 'Disable';
  const hidden = form.querySelector('[data-pon-status-value]');
  const label = toggle.closest('label')?.querySelector('[data-pon-status-label], span');
  const rowId = form.querySelector('[name="olt_row_id"]')?.value || '';
  const pon = form.querySelector('[name="olt_port_number"]')?.value || '';
  const ref = form.querySelector('[name="olt_port_ref"]')?.value || '';
  const csrf = form.querySelector('[name="csrf"]')?.value || '';
  if (!rowId || !pon) return;
  const notify = (level, msg) => { if (typeof window.linnmsNotify === 'function') window.linnmsNotify(level, msg); };
  const modal = toggle.closest('[data-olt-pon-modal]');
  const modalId = modal ? modal.id : '';
  const openButton = modalId ? document.querySelector('[data-olt-pon-modal-open="' + modalId + '"]') : null;
  const ponCard = openButton ? openButton.closest('.olt-pon-card') : null;
  const cardGroup = ponCard ? ponCard.closest('.olt-card') : null;
  const wasUp = ponCard?.classList.contains('is-up');
  const wasDown = ponCard?.classList.contains('is-down');
  const oldState = wasUp ? 'Up' : (wasDown ? 'Down' : 'Shutdown');
  const newState = value === 'Disable' ? 'Shutdown' : 'Down';
  toggle.disabled = true;
  const liveUrl = document.body.dataset.liveUrl || 'live.php';
  const body = new URLSearchParams({ olt_row_id: rowId, olt_port_number: pon, olt_port_ref: ref, olt_port_enable: value, csrf });
  window.linnmsAjax.request(liveUrl + '?type=olt-pon-status-save', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'fetch'}, body: body.toString() })
    .then((response) => response.json().then((payload) => ({ response, payload })))
    .then(({ response, payload }) => {
      if (!response.ok || !payload || payload.ok !== true) throw new Error((payload && payload.message) || 'Status PON gagal diubah.');
      if (hidden) hidden.value = value;
      if (label) label.textContent = value;
      if (ponCard) {
        ponCard.classList.remove('is-up', 'is-down', 'is-shutdown', 'is-unknown');
        ponCard.classList.add(value === 'Disable' ? 'is-shutdown' : 'is-down');
      }
      const counts = cardGroup ? cardGroup.querySelector('.olt-port-counts') : null;
      if (counts && oldState !== newState) {
        counts.querySelectorAll('div').forEach((row) => {
          const key = row.querySelector('span')?.textContent.trim() || '';
          const strong = row.querySelector('strong');
          if (!strong || !key) return;
          const n = parseInt(strong.textContent, 10);
          if (Number.isNaN(n)) return;
          if (key === oldState) strong.textContent = String(Math.max(0, n - 1));
          if (key === newState) strong.textContent = String(n + 1);
        });
      }
      notify('success', payload.message);
    })
    .catch((error) => {
      toggle.checked = value === 'Disable';
      notify('error', String((error && error.message) || 'Status PON gagal diubah.'));
    })
    .finally(() => { toggle.disabled = false; });
});

(() => {
  'use strict';
  const dialog = document.querySelector('[data-olt-terminal-dialog]');
  if (!dialog) return;
  const screen = dialog.querySelector('[data-olt-terminal-screen]');
  const status = dialog.querySelector('[data-olt-terminal-status]');
  let session = null;
  let opener = null;
  const encoder = new TextEncoder();
  const setStatus = (text, error = false, connected = false) => {
    status.textContent = text;
    status.classList.toggle('is-error', error);
    status.classList.toggle('is-connected', connected);
  };
  const encode = (bytes) => btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(''));
  const decode = (data) => Uint8Array.from(atob(data || ''), (char) => char.charCodeAt(0));
  const payload = (state, action, data = new Uint8Array()) => {
    const body = new FormData();
    body.set('csrf', dialog.dataset.terminalCsrf);
    body.set('ref', dialog.dataset.terminalRef);
    body.set('token', state.token);
    body.set('action', action);
    body.set('data', encode(data));
    body.set('cols', String(state.terminal.cols));
    body.set('rows', String(state.terminal.rows));
    return body;
  };
  const request = async (state, action, data) => {
    const response = await window.linnmsAjax.request(dialog.dataset.terminalUrl, {
      method: 'POST', credentials: 'same-origin', body: payload(state, action, data),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.ok) throw new Error(result.message || 'Koneksi terminal terputus. Buka kembali terminal.');
    return result;
  };
  const disconnect = (state, beacon = false) => {
    if (!state || state.closed) return;
    state.closed = true;
    state.ready = false;
    clearTimeout(state.timer);
    const body = payload(state, 'close');
    if (beacon) navigator.sendBeacon(dialog.dataset.terminalUrl, body);
    else window.linnmsAjax.request(dialog.dataset.terminalUrl, { method: 'POST', credentials: 'same-origin', body, keepalive: true }).catch(() => {});
  };
  const stop = (state, message) => {
    if (session !== state) return;
    disconnect(state);
    state.terminal.options.disableStdin = true;
    setStatus(message, true);
  };
  const schedule = (state, delay = 300) => {
    if (session !== state || !state.ready || state.closed) return;
    clearTimeout(state.timer);
    state.timer = setTimeout(() => exchange(state), delay);
  };
  const exchange = async (state) => {
    if (session !== state || !state.ready || state.closed || state.busy) return;
    state.busy = true;
    const data = state.pending.slice(0, 8192);
    state.pending = state.pending.slice(data.length);
    try {
      const result = await request(state, 'exchange', data);
      if (session !== state || state.closed) return;
      if (result.output) state.terminal.write(decode(result.output));
      if (!result.active) { stop(state, 'Koneksi terminal terputus. Buka kembali terminal.'); return; }
    } catch (error) {
      stop(state, error.message || 'Koneksi terminal terputus. Buka kembali terminal.');
    } finally {
      state.busy = false;
      schedule(state, state.pending.length ? 0 : 60);
    }
  };
  const open = async (button) => {
    if (dialog.open) return;
    opener = button;
    dialog.showModal();
    setStatus('Menghubungkan…');
    if (!window.Terminal || !window.FitAddon) { setStatus('Terminal belum dapat dimuat. Muat ulang halaman.', true); return; }
    screen.replaceChildren();
    const terminal = new window.Terminal({
      cursorBlink: true, cursorStyle: 'bar', fontSize: 14, lineHeight: 1.25, fontFamily: 'Consolas, monospace',
      scrollback: 5000, disableStdin: true, screenReaderMode: true,
      theme: { background: '#070d17', foreground: '#dbe7f3', cursor: '#67e8f9', selectionBackground: '#26435c' },
    });
    const fit = new window.FitAddon.FitAddon();
    terminal.loadAddon(fit);
    terminal.open(screen);
    fit.fit();
    const state = { terminal, fit, token: Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join(''), ready: false, closed: false, busy: false, pending: new Uint8Array(), timer: null };
    session = state;
    state.observer = new ResizeObserver(() => { if (dialog.open && session === state) fit.fit(); });
    state.observer.observe(screen);
    terminal.onData((data) => {
      if (!state.ready || state.closed) return;
      const bytes = encoder.encode(data);
      if (state.pending.length + bytes.length > 65536) { setStatus('Input terlalu panjang. Kirim dalam beberapa bagian.', true); return; }
      const merged = new Uint8Array(state.pending.length + bytes.length);
      merged.set(state.pending); merged.set(bytes, state.pending.length);
      state.pending = merged;
      schedule(state, 25);
    });

    terminal.attachCustomKeyEventHandler((event) => !(event.ctrlKey && event.shiftKey && ['C', 'V'].includes(event.key.toUpperCase())));
    try {
      const result = await request(state, 'open');
      if (session !== state || state.closed || !dialog.open) {

        state.closed = false;
        disconnect(state);
        return;
      }
      if (!result.active) { stop(state, 'Koneksi terminal terputus. Buka kembali terminal.'); return; }
      state.ready = true;
      terminal.options.disableStdin = false;
      if (result.output) terminal.write(decode(result.output));
      setStatus('Terhubung', false, true);
      terminal.focus();
      schedule(state);
    } catch (error) { stop(state, error.message || 'Terminal belum dapat dibuka.'); }
  };
  document.querySelectorAll('[data-olt-terminal-open]').forEach((button) => button.addEventListener('click', () => open(button)));
  dialog.querySelector('[data-olt-terminal-close]').addEventListener('click', () => dialog.close());

  const expandButton = dialog.querySelector('[data-olt-terminal-expand]');
  expandButton.addEventListener('click', () => {
    const expanded = dialog.classList.toggle('is-expanded');
    expandButton.setAttribute('aria-pressed', String(expanded));
    expandButton.setAttribute('aria-label', expanded ? 'Kecilkan terminal' : 'Perbesar terminal');
    expandButton.title = expanded ? 'Kecilkan terminal' : 'Perbesar terminal';
    session?.fit.fit();
  });

  dialog.addEventListener('cancel', (event) => { if (screen.contains(document.activeElement)) event.preventDefault(); });
  dialog.addEventListener('close', () => {
    const state = session;
    session = null;
    if (state) {
      disconnect(state);
      state.observer.disconnect();
      state.terminal.dispose();
    }
    opener?.focus();
  });
  window.addEventListener('pagehide', () => disconnect(session, true));
})();

(() => {
  'use strict';

  const gridSelector = '.olt-pon-grid';

  const focusWithoutScroll = (summary) => {
    try {
      summary.focus({ preventScroll: true });
    } catch (_) {
      summary.focus();
    }
  };

  const setFocusedCard = (grid, details) => {
    grid.querySelectorAll('.olt-pon-card.is-focused').forEach((card) => {
      card.classList.remove('is-focused');
    });

    const card = details.closest('.olt-pon-card');
    if (card && details.open) card.classList.add('is-focused');
  };




  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const summary = target?.closest('summary.olt-pon-card-head');
    if (!summary) return;

    const grid = summary.closest(gridSelector);
    const details = summary.parentElement;
    if (!grid || !(details instanceof HTMLDetailsElement) || !details.classList.contains('olt-pon-details')) return;

    const beforeTop = summary.getBoundingClientRect().top;

    window.requestAnimationFrame(() => {
      if (!summary.isConnected) return;

      const afterTop = summary.getBoundingClientRect().top;
      const delta = afterTop - beforeTop;
      if (Math.abs(delta) > 0.5) {
        window.scrollBy({ top: delta, left: 0, behavior: 'auto' });
      }

      setFocusedCard(grid, details);
      focusWithoutScroll(summary);
    });
  }, true);



  const alignInitialFocusedCard = () => {
    const card = document.querySelector(`${gridSelector} .olt-pon-card.is-focused`);
    const summary = card?.querySelector('summary.olt-pon-card-head');
    if (!summary) return;

    window.requestAnimationFrame(() => {
      if (!summary.isConnected) return;

      const topbar = document.querySelector('.topbar');
      const topOffset = Math.max(0, topbar?.getBoundingClientRect().height || 0) + 12;
      const rect = summary.getBoundingClientRect();
      const visibleTop = topOffset;
      const visibleBottom = window.innerHeight - 12;

      if (rect.top < visibleTop || rect.bottom > visibleBottom) {
        window.scrollTo({
          top: Math.max(0, window.scrollY + rect.top - topOffset),
          left: 0,
          behavior: 'auto',
        });
      }

      focusWithoutScroll(summary);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', alignInitialFocusedCard, { once: true });
  } else {
    alignInitialFocusedCard();
  }
})();

(() => {
  'use strict';

  const updateOnuTypeProtocol = (form) => {
    if (!(form instanceof HTMLFormElement)) return;
    const pon = form.querySelector('[name="pon_type"]')?.value;
    ['gpon', 'epon'].forEach((protocol) => {
      const group = form.querySelector(`[data-onu-type-${protocol}]`);
      if (!(group instanceof HTMLElement)) return;
      group.hidden = pon !== protocol;
      group.querySelectorAll('input, select').forEach((field) => {
        field.disabled = pon !== protocol || Boolean(form.querySelector('[data-onu-type-edit-fields]')?.disabled);
        if (field instanceof HTMLSelectElement) field.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  };

  document.querySelectorAll('[data-onu-type-editor]').forEach((form) => {
    const fields = form.querySelector('[data-onu-type-edit-fields]');
    const edit = form.querySelector('[data-onu-type-edit]');
    const cancel = form.querySelector('[data-onu-type-cancel]');
    const save = form.querySelector('[data-onu-type-save]');
    if (!(fields instanceof HTMLFieldSetElement) || !edit || !cancel || !save) return;
    const setEditing = (editing) => {
      fields.disabled = !editing;
      edit.hidden = editing;
      cancel.hidden = !editing;
      save.hidden = !editing;
      form.classList.toggle('is-editing', editing);
      updateOnuTypeProtocol(form);
      if (editing) fields.querySelector('[name="description"]')?.focus();
    };
    setEditing(false);
    edit.addEventListener('click', () => setEditing(true));
    cancel.addEventListener('click', () => { form.reset(); setEditing(false); });
  });

  document.querySelectorAll('[data-onu-type-delete]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const name = form.querySelector('[name="name"]')?.value || '';
      if (!window.confirm(`Hapus ONU Type "${name}"?`)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    });
  });

  document.querySelectorAll('[data-onu-type-pon]').forEach((select) => {
    select.addEventListener('change', () => updateOnuTypeProtocol(select.closest('form')));
  });

  document.querySelectorAll('.olt-profile-table').forEach((table) => {
    if (!(table instanceof HTMLTableElement)) return;
    const labels = Array.from(table.querySelectorAll('thead th'), (cell) => cell.textContent.trim());
    table.querySelectorAll('tbody tr').forEach((row) => {
      Array.from(row.cells).forEach((cell, index) => {
        if (cell instanceof HTMLTableCellElement && !cell.dataset.label) cell.dataset.label = labels[index] || '';
      });
    });
  });

  document.querySelectorAll('[data-olt-profile-form]').forEach((form) => {
    const scope = form.parentElement || document;
    const openForm = () => {
      form.hidden = false;
      const field = form.querySelector('input[name="vlan_id"], input[name="name"]');
      if (field instanceof HTMLElement) field.focus();
    };
    scope.querySelectorAll('[data-olt-profile-create]').forEach((button) => {
      button.addEventListener('click', openForm);
    });
    form.querySelector('[data-olt-profile-cancel]')?.addEventListener('click', () => {
      form.hidden = true;
    });
  });


  document.querySelectorAll('[data-olt-chips]').forEach((list) => {
    if (!(list instanceof HTMLElement)) return;
    if (list.scrollHeight <= list.clientHeight + 2) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'olt-profile-chips-toggle';
    button.textContent = 'Lihat semua';
    button.addEventListener('click', () => {
      const expanded = list.classList.toggle('is-expanded');
      button.textContent = expanded ? 'Ringkas' : 'Lihat semua';
    });
    list.after(button);
  });

  const modals = Array.from(document.querySelectorAll('[data-olt-profile-modal]'));
  const closeModal = (modal) => {
    if (!(modal instanceof HTMLElement)) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('olt-modal-open');
  };

  document.querySelectorAll('[data-olt-profile-modal-open]').forEach((button) => {
    button.addEventListener('click', () => {
      const modal = document.getElementById(button.dataset.oltProfileModalOpen || '');
      if (!(modal instanceof HTMLElement)) return;
      const form = modal.querySelector('form');
      form?.reset();
      if (form?.matches('[data-onu-type-create]')) updateOnuTypeProtocol(form);
      modal.querySelectorAll('[data-olt-onu-type-select]').forEach((select) => {
        if (select instanceof HTMLSelectElement) select.disabled = true;
      });
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('olt-modal-open');
      window.requestAnimationFrame(() => modal.querySelector('[data-olt-onu-type-name]')?.focus());
    });
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const editMembers = target.closest('[data-vlan-members-edit]');
    if (editMembers instanceof HTMLButtonElement) {
      const modeBlock = editMembers.closest('.olt-vlan-edit-area');
      const editor = modeBlock?.querySelector('.olt-vlan-members-editor');
      if (editor instanceof HTMLFormElement) {
        editor.hidden = !editor.hidden;
        editMembers.setAttribute('aria-expanded', editor.hidden ? 'false' : 'true');
        editMembers.innerHTML = editor.hidden
          ? '<i class="bi bi-pencil" aria-hidden="true"></i> Edit'
          : '<i class="bi bi-x-lg" aria-hidden="true"></i> Tutup';
      }
      return;
    }
    if (target.closest('[data-vlan-members-cancel]')) {
      const editor = target.closest('.olt-vlan-members-editor');
      if (editor instanceof HTMLFormElement) {
        editor.reset();
        editor.hidden = true;
        const editButton = editor.closest('.olt-vlan-edit-area')?.querySelector('[data-vlan-members-edit]');
        editButton?.setAttribute('aria-expanded', 'false');
        if (editButton instanceof HTMLButtonElement) editButton.innerHTML = '<i class="bi bi-pencil" aria-hidden="true"></i> Edit';
      }
      return;
    }
    if (target.closest('[data-olt-profile-modal-close]')) {
      closeModal(target.closest('[data-olt-profile-modal]'));
      return;
    }
    const modal = target.closest('[data-olt-profile-modal]');
    if (modal && target === modal) closeModal(modal);
  });

  document.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.matches('[data-olt-onu-type-toggle]')) return;
    const control = target.closest('.olt-onu-type-control');
    const select = control?.querySelector('[data-olt-onu-type-select]');
    if (select instanceof HTMLSelectElement) {
      select.disabled = !target.checked;
      if (!target.checked) select.value = '';
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    modals.forEach((modal) => { if (!(modal instanceof HTMLElement) || modal.hidden) return; closeModal(modal); });
  });
})();

(() => {
  const modal = document.querySelector('[data-olt-uplink-modal]');
  if (!modal) return;
  const dataEl = document.querySelector('[data-olt-uplink-data]');
  let portData = {};
  try { portData = JSON.parse(dataEl ? dataEl.textContent : '{}') || {}; } catch (error) { portData = {}; }
  const titleEl = modal.querySelector('[data-uplink-modal-title]');
  const graphToggle = modal.querySelector('[data-uplink-graph-toggle]');
  const graphSection = modal.querySelector('[data-uplink-graph]');
  const graphRoot = graphSection?.querySelector('[data-traffic]');
  const opticsSuffix = { temperature: ' °C', voltage: ' V', tx_power: ' dBm', rx_power: ' dBm', current: ' mA', wavelength: ' NM' };
  const setField = (field, value, suffix) => {
    modal.querySelectorAll('[data-uplink-field="' + field + '"]').forEach((el) => {
      const text = (value === null || value === undefined) ? '' : String(value).trim();
      el.textContent = text === '' ? 'NotSuporte' : text + (suffix || '');
    });
  };
  const syncGraph = (enabled) => {
    if (graphSection) graphSection.hidden = !enabled;
    if (!graphRoot) return;
    graphRoot.dataset.trafficIfRef = enabled ? String(titleEl?.textContent || '').trim() : '';
    graphRoot.dispatchEvent(new CustomEvent('linnms:traffic-source-changed'));
  };
  const close = () => {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('olt-pon-modal-open');
  };
  const open = async (ref) => {
    ref = String(ref || '').trim();
    if (ref === '') return;
    if (titleEl) titleEl.textContent = ref;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('olt-pon-modal-open');
    activateTab('port');
    const entry = portData[ref] || {};
    const config = entry.config && typeof entry.config === 'object' ? entry.config : {};
    const optics = entry.optics && typeof entry.optics === 'object' ? entry.optics : {};
    const vlan = entry.vlan && typeof entry.vlan === 'object' ? entry.vlan : {};
    modal.querySelectorAll('[data-uplink-field]').forEach((el) => { el.textContent = '_'; });
    Object.keys(config).forEach((key) => setField(key, config[key], ''));
    Object.keys(optics).forEach((key) => setField(key, optics[key], opticsSuffix[key] || ''));
    modal.querySelectorAll('[data-uplink-input]').forEach((input) => {
      const key = input.dataset.uplinkInput;
      const source = Object.prototype.hasOwnProperty.call(vlan, key) ? vlan : config;
      input.value = source[key] === null || source[key] === undefined ? '' : String(source[key]);
    });
    modal.querySelectorAll('[data-uplink-config-toggle]').forEach((input) => {
      const key = input.dataset.uplinkConfigToggle;
      const active = /^(enable|enabled|on)$/i.test(String(config[key] || ''));
      input.checked = active;
      const label = modal.querySelector('[data-uplink-toggle-label="' + key + '"]');
      if (label) label.textContent = active ? 'Enable' : 'Disable';
    });
    setField('mode', vlan.mode, '');
    setField('tagged_vlan', vlan.tagged_vlan, '');
    setField('untagged_vlan', vlan.untagged_vlan, '');
    setField('pvid', vlan.pvid, '');
    if (graphToggle) {
      graphToggle.dataset.ifRef = ref;
      graphToggle.checked = entry.graph_enabled === true;
    }
    syncGraph(entry.graph_enabled === true);
    selectVlanKind('trunk');
  };
  const selectVlanKind = (kind) => {
    const entry = portData[titleEl?.textContent || ''] || {};
    const vlan = entry.vlan && typeof entry.vlan === 'object' ? entry.vlan : {};
    modal.querySelectorAll('[data-uplink-vlan-kind]').forEach((button) => button.classList.toggle('is-active', button.dataset.uplinkVlanKind === kind));
    const label = modal.querySelector('[data-uplink-allowed-label]');
    const input = modal.querySelector('[data-uplink-allowed-vlan]');
    if (label) label.textContent = 'Allowed VLAN ' + (kind === 'access' ? 'Access' : 'Trunk');
    if (input) { input.dataset.vlanKind = kind; input.value = String(kind === 'access' ? (vlan.untagged_vlan || '') : (vlan.tagged_vlan || '')); }
  };
  const activateTab = (name) => {
    modal.querySelectorAll('[data-uplink-tab]').forEach((button) => {
      const active = button.dataset.uplinkTab === name;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    modal.querySelectorAll('[data-uplink-panel]').forEach((panel) => { panel.hidden = panel.dataset.uplinkPanel !== name; });
  };
  document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-olt-uplink-open]');
    if (opener) { open(opener.getAttribute('data-olt-uplink-open')); return; }
    const tab = event.target.closest('[data-uplink-tab]');
    if (tab) { activateTab(tab.dataset.uplinkTab); return; }
    const vlanKind = event.target.closest('[data-uplink-vlan-kind]');
    if (vlanKind) { selectVlanKind(vlanKind.dataset.uplinkVlanKind); return; }
    if (event.target.closest('[data-olt-uplink-close]')) close();
  });
  modal.addEventListener('change', async (event) => {
    const toggle = event.target.closest('[data-uplink-config-toggle]');
    if (toggle) {
      const key = toggle.dataset.uplinkConfigToggle;
      const label = modal.querySelector('[data-uplink-toggle-label="' + key + '"]');
      if (label) label.textContent = toggle.checked ? 'Enable' : 'Disable';
      await submitConfig(key, toggle.checked ? 'Enable' : 'Disable');
      return;
    }
    const input = event.target.closest('[data-uplink-input]');
    if (input) {
      const key = input.dataset.uplinkInput;
      if (key === 'mode' || key === 'pvid') { await submitVlan(); return; }
      await submitConfig(key, input.value);
      return;
    }
    if (event.target.closest('[data-uplink-allowed-vlan]')) { await submitVlan(); return; }
  });
  const post = async (config, vlan) => {
    const ref = String(titleEl?.textContent || '').trim();
    const body = new URLSearchParams({
      csrf: modal.querySelector('[data-uplink-csrf]')?.value || '',
      olt_row_id: modal.dataset.oltUplinkRowId || '',
      port_ref: ref,
      config: JSON.stringify(config || {}),
      vlan: JSON.stringify(vlan || {})
    });
    const response = await window.linnmsAjax.request((document.body.dataset.liveUrl || 'live.php') + '?type=olt-uplink-save', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'fetch'},body:body.toString()});
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Perubahan belum berhasil disimpan.');
    if (payload.detail && typeof payload.detail === 'object') portData[ref] = Object.assign({}, portData[ref] || {}, payload.detail);
    if (typeof window.linnmsNotify === 'function') window.linnmsNotify('success', payload.message);
    return payload;
  };
  const runAction = async (fn) => {
    if (actionBusy) return;
    actionBusy = true;
    try { await fn(); }
    catch (error) { if (typeof window.linnmsNotify === 'function') window.linnmsNotify('error', String(error.message || error)); }
    finally { actionBusy = false; }
  };
  const submitConfig = (field, value) => runAction(async () => { await post({ [field]: value }, {}); });
  const submitVlan = () => runAction(async () => {
    const get = (key) => modal.querySelector('[data-uplink-input="' + key + '"]')?.value || '';
    const allowed = modal.querySelector('[data-uplink-allowed-vlan]');
    const current = portData[String(titleEl?.textContent || '').trim()]?.vlan || {};
    const vlan = { mode:get('mode'), pvid:get('pvid'), tagged_vlan:String(current.tagged_vlan || ''), untagged_vlan:String(current.untagged_vlan || '') };
    if (allowed?.dataset.vlanKind === 'access') vlan.untagged_vlan = allowed.value; else vlan.tagged_vlan = allowed?.value || '';
    await post({}, vlan);
  });
  let actionBusy = false;
  modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
  document.addEventListener('linnms:traffic-record-updated', (event) => {
    const ref = String(titleEl?.textContent || '').trim();
    if (ref === '') return;
    if (String(event.detail?.deviceId || '') !== String(modal.dataset.oltUplinkRowId || '')) return;
    if (String(event.detail?.ifRef || '') !== ref) return;
    if (portData[ref] && typeof portData[ref] === 'object') portData[ref].graph_enabled = event.detail?.enabled === true;
    syncGraph(event.detail?.enabled === true);
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });
  document.addEventListener('keydown', (event) => {
    const opener = event.target.closest?.('[data-olt-uplink-open]');
    if (opener && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); open(opener.getAttribute('data-olt-uplink-open')); }
  });
})();

(() => {
  const modal = document.querySelector('[data-olt-speed-modal]');
  if (!modal) return;
  const liveUrl = document.body.dataset.liveUrl || 'live.php';
  const rowId = modal.dataset.oltSpeedRowId || '';
  const notify = (level, message) => { if (typeof window.linnmsNotify === 'function') window.linnmsNotify(level, message); };
  const kindLabels = { traffic: 'Traffic Profile', tcont: 'T-CONT Profile', vlan_profile: 'VLAN Profile', ip: 'WAN IP' };
  const setKind = (kind, editing = false, showChoices = true) => {
    const k = Object.prototype.hasOwnProperty.call(kindLabels, kind) ? kind : 'traffic';
    modal.querySelector('[data-speed-kind]').value = k;
    modal.querySelectorAll('[data-speed-fields]').forEach((el) => { el.hidden = el.dataset.speedFields !== k; });
    modal.querySelector('[data-speed-title]').textContent = (editing ? 'Edit ' : 'Tambah ') + kindLabels[k];
    const choiceGroup = ['vlan_profile', 'ip'].includes(k) ? 'wan' : 'speed';
    modal.querySelectorAll('[data-speed-profile-choices]').forEach((group) => {
      const visible = showChoices && group.dataset.speedProfileChoices === choiceGroup;
      group.hidden = !visible;
      group.querySelectorAll('[data-speed-profile-choice]').forEach((input) => {
        input.disabled = !visible;
        input.checked = visible && input.value === k;
      });
    });
  };
  const open = (kind, data) => {
    const k = Object.prototype.hasOwnProperty.call(kindLabels, kind) ? kind : 'traffic';
    modal.querySelectorAll('[data-speed-input]').forEach((input) => {
      const key = input.dataset.speedInput;
      input.value = (data && data[key] !== null && data[key] !== undefined) ? String(data[key]) : '';
    });
    setKind(k, Boolean(data && data.name), !(data && data.name));
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('olt-pon-modal-open');
  };
  const close = () => { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('olt-pon-modal-open'); };
  modal.addEventListener('change', (event) => {
    const choice = event.target.closest('[data-speed-profile-choice]');
    if (choice && choice.checked) setKind(choice.value);
  });
  document.addEventListener('click', (event) => {
    const add = event.target.closest('[data-olt-speed-add]');
    if (add) { open(add.dataset.oltSpeedAdd, null); return; }
    const edit = event.target.closest('[data-olt-speed-edit]');
    if (edit) { let data = {}; try { data = JSON.parse(edit.dataset.oltSpeedEdit || '{}'); } catch (error) { data = {}; } open(edit.dataset.oltSpeedKind, data); return; }
    if (event.target.closest('[data-olt-speed-close]')) { close(); return; }
    const remove = event.target.closest('[data-olt-profile-delete]');
    if (remove) {
      const name = remove.dataset.oltProfileName || '';
      const kind = remove.dataset.oltProfileKind || '';
      if (!window.confirm('Hapus profil ' + name + '?')) return;
      (async () => {
        try {
          const body = new URLSearchParams({ csrf: modal.querySelector('input[name="csrf"]').value, olt_row_id: rowId, kind, name });
          const response = await window.linnmsAjax.request(liveUrl + '?type=olt-profile-delete', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' }, body: body.toString() });
          const payload = await response.json().catch(() => null);
          if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Profil belum berhasil dihapus.');
          notify('success', payload.message);
          window.setTimeout(() => window.location.reload(), 400);
        } catch (error) {
          notify('error', String(error.message || error));
        }
      })();
      return;
    }
    if (event.target === modal) close();
  });
  modal.querySelector('[data-olt-speed-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const kind = modal.querySelector('[data-speed-kind]').value;
    const data = {};
    modal.querySelectorAll('[data-speed-input]').forEach((input) => { data[input.dataset.speedInput] = input.value; });
    try {
      const body = new URLSearchParams({
        csrf: modal.querySelector('input[name="csrf"]').value,
        olt_row_id: rowId,
        kind,
        data: JSON.stringify(data)
      });
      const response = await window.linnmsAjax.request(liveUrl + '?type=olt-profile-save', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch' }, body: body.toString() });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Profil belum berhasil disimpan.');
      notify('success', payload.message);
      close();
      window.setTimeout(() => window.location.reload(), 400);
    } catch (error) {
      notify('error', String(error.message || error));
    }
  });
})();
