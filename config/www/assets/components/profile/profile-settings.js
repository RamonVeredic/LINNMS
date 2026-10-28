(() => {
  'use strict';

  const page = document.querySelector('[data-profile-page]');
  if (!page) return;

  const driverForm = page.querySelector('[data-driver-support-form]');
  if (driverForm) {
    const toggles = [...driverForm.querySelectorAll('input[type="checkbox"]')];
    const editableToggles = toggles.filter((toggle) => !toggle.disabled);
    let saving = false;
    driverForm.addEventListener('submit', (event) => event.preventDefault());
    driverForm.addEventListener('change', async (event) => {
      const toggle = event.target;
      if (!editableToggles.includes(toggle) || saving) return;
      const data = new FormData(driverForm);
      saving = true;
      driverForm.setAttribute('aria-busy', 'true');
      editableToggles.forEach((control) => { control.disabled = true; });
      try {
        const response = await window.linnmsAjax.request(driverForm.getAttribute('action') || window.location.href, {
          method: 'POST',
          body: data,
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok || payload.ok !== true) {
          throw new Error('Gagal mengaktifkan perangkat.');
        }
        editableToggles.forEach((control) => { control.defaultChecked = control.checked; });
        window.linnmsNotify?.('success', payload.message);
      } catch (error) {
        editableToggles.forEach((control) => { control.checked = control.defaultChecked; });
        window.linnmsNotify?.('error', 'Gagal mengaktifkan perangkat.');
      } finally {
        saving = false;
        driverForm.removeAttribute('aria-busy');
        editableToggles.forEach((control) => { control.disabled = false; });
        toggle.focus();
      }
    });
  }

  const importForm = page.querySelector('[data-onu-backup-import-form]');
  const importFile = page.querySelector('[data-onu-backup-file]');
  const importButton = page.querySelector('[data-onu-backup-file-trigger]');
  const importText = page.querySelector('[data-onu-backup-import-text]');
  const importStatus = page.querySelector('[data-onu-backup-import-status]');

  const showImportStatus = (message, state = '') => {
    if (!importStatus) return;
    importStatus.textContent = message;
    importStatus.hidden = message === '';
    importStatus.classList.toggle('is-error', state === 'error');
    importStatus.classList.toggle('is-loading', state === 'loading');
  };

  const rejectImport = (message) => {
    showImportStatus(message, 'error');
    if (importButton) importButton.disabled = false;
    if (importText) importText.textContent = 'Import CSV';
    if (importFile) importFile.value = '';
    importButton?.focus();
  };

  importButton?.addEventListener('click', () => {
    showImportStatus('');
    importFile?.click();
  });

  importFile?.addEventListener('change', async () => {
    const file = importFile.files?.[0];
    if (!file || !importForm) return;
    const name = file.name.trim();
    if (!name.toLowerCase().endsWith('.csv')) {
      rejectImport('File harus menggunakan ekstensi .csv.');
      return;
    }
    if (file.size < 1) {
      rejectImport('File CSV kosong.');
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      rejectImport('Ukuran file CSV maksimal 2 MB.');
      return;
    }

    try {
      const firstLine = (await file.slice(0, 4096).text())
        .replace(/^\uFEFF/, '')
        .split(/\r?\n/, 1)[0]
        .trim();
      const header = firstLine.split(',').map((column) =>
        column.trim().replace(/^"|"$/g, '').toLowerCase()
      );
      if (header.join(',') !== 'mac_address,nama,alamat') {
        rejectImport('Header CSV harus tepat: mac_address,nama,alamat.');
        return;
      }
    } catch {
      rejectImport('File CSV tidak dapat dibaca oleh browser.');
      return;
    }

    importButton.disabled = true;
    if (importText) importText.textContent = 'Memeriksa\u2026';
    showImportStatus(`Memeriksa ${name}\u2026`, 'loading');
    importForm.requestSubmit();
  });

  const resetSettingEdit = (form, focusButton = false) => {
    if (!form) return;
    const control = form.querySelector('[data-setting-edit-control]');
    const editButton = form.querySelector('[data-setting-edit]');
    const cancelButton = form.querySelector('[data-setting-cancel]');
    form.reset();
    if (control) control.disabled = true;
    if (editButton) {
      editButton.classList.remove('is-active');
      editButton.setAttribute('aria-pressed', 'false');
      editButton.querySelector('i')?.classList.replace('bi-floppy', 'bi-pencil');
      const label = `Edit ${editButton.dataset.settingLabel || ''}`.trim();
      editButton.setAttribute('aria-label', label);
      editButton.title = label;
      if (focusButton) editButton.focus();
    }
    if (cancelButton) cancelButton.hidden = true;
  };

  page.addEventListener('click', (event) => {
    const editButton = event.target.closest('[data-setting-edit]');
    const cancelTrigger = event.target.closest('[data-setting-cancel]');
    const trigger = editButton || cancelTrigger;
    if (!trigger || !page.contains(trigger)) return;

    const form = trigger.closest('[data-setting-edit-form]');
    const targetId = trigger.dataset.target || '';
    const control = targetId !== '' ? document.getElementById(targetId) : null;
    if (!form || !control || !form.contains(control)) return;

    const cancelButton = form.querySelector('[data-setting-cancel]');
    if (cancelTrigger) {
      resetSettingEdit(form, true);
      return;
    }

    if (editButton.classList.contains('is-active')) {
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      if (control.value === (control.dataset.originalValue ?? control.defaultValue)) {
        resetSettingEdit(form, true);
        return;
      }
      form.requestSubmit();
      return;
    }

    page.querySelectorAll('[data-setting-edit-form]').forEach((otherForm) => {
      if (otherForm !== form) resetSettingEdit(otherForm);
    });
    control.dataset.originalValue = control.value;
    control.disabled = false;
    editButton.classList.add('is-active');
    editButton.setAttribute('aria-pressed', 'true');
    editButton.querySelector('i')?.classList.replace('bi-pencil', 'bi-floppy');
    const label = `Simpan ${editButton.dataset.settingLabel || ''}`.trim();
    editButton.setAttribute('aria-label', label);
    editButton.title = label;
    if (cancelButton) cancelButton.hidden = false;
    window.requestAnimationFrame(() => {
      control.focus();
      control.select?.();
    });
  });

  let activePanel = null;
  let activeButton = null;

  const closeModal = () => {
    if (!activePanel) return;
    activePanel.hidden = true;
    activePanel.querySelector('form')?.reset();
    document.body.classList.remove('settings-modal-open');
    activeButton?.setAttribute('aria-expanded', 'false');
    activeButton?.focus();
    activePanel = null;
    activeButton = null;
  };

  const openModal = (button) => {
    const panel = document.getElementById(button.dataset.settingsModalOpen || '');
    if (!panel || !page.contains(panel) || !panel.matches('[data-settings-modal]')) return;

    if (button.hasAttribute('data-limit-edit-open')) {
      const form = panel.querySelector('form');
      const values = {
        user_id: button.dataset.limitUserId,
        olt_limit: button.dataset.limitOlt,
        mikrotik_limit: button.dataset.limitMikrotik,
        switch_limit: button.dataset.limitSwitch,
      };
      if (!form || Object.values(values).some((value) => value === undefined)) return;
      Object.entries(values).forEach(([name, value]) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (input) input.value = value;
      });
      const title = panel.querySelector('[data-limit-edit-title]');
      if (title) title.textContent = `Edit Limit Perangkat: ${button.dataset.limitName || ''}`;
    }

    panel.hidden = false;
    activePanel = panel;
    activeButton = button;
    document.body.classList.add('settings-modal-open');
    button.setAttribute('aria-expanded', 'true');
    panel.querySelector('input:not([type="hidden"])')?.focus();
  };

  page.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const openButton = event.target.closest('[data-settings-modal-open]');
    if (openButton && page.contains(openButton)) {
      openModal(openButton);
      return;
    }
    if (activePanel && (event.target === activePanel || event.target.closest('[data-settings-modal-close]'))) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!activePanel) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal();
      return;
    }
    if (event.key !== 'Tab') return;
    const controls = [...activePanel.querySelectorAll('button, input:not([type="hidden"]), [tabindex="0"]')]
      .filter((control) => !control.disabled && control.getClientRects().length);
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  });

  (() => {
    const buttons = page.querySelectorAll('[data-setting-help]');
    if (!buttons.length) return;

    const tooltip = document.createElement('div');
    tooltip.id = 'settingsHelpTooltip';
    tooltip.className = 'settings-help-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.append(tooltip);

    let activeButton = null;
    let pinned = false;

    const position = () => {
      if (!activeButton || tooltip.hidden) return;
      const anchor = activeButton.getBoundingClientRect();
      const width = tooltip.offsetWidth;
      const height = tooltip.offsetHeight;
      const left = Math.max(12, Math.min(window.innerWidth - width - 12, anchor.left + anchor.width / 2 - width / 2));
      const above = anchor.top >= height + 12;
      const top = above ? anchor.top - height - 8 : anchor.bottom + 8;
      tooltip.style.left = `${left}px`;
      tooltip.style.top = `${Math.max(8, Math.min(window.innerHeight - height - 8, top))}px`;
    };

    const close = () => {
      if (!activeButton) return;
      activeButton.setAttribute('aria-expanded', 'false');
      activeButton.removeAttribute('aria-describedby');
      activeButton = null;
      pinned = false;
      tooltip.hidden = true;
    };

    const show = (button, keepOpen = false) => {
      if (activeButton !== button) close();
      activeButton = button;
      pinned = keepOpen;
      tooltip.textContent = button.dataset.settingHelp || '';
      tooltip.hidden = false;
      button.setAttribute('aria-expanded', 'true');
      button.setAttribute('aria-describedby', tooltip.id);
      position();
    };

    page.addEventListener('pointerover', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-setting-help]') : null;
      if (button && button !== activeButton) show(button);
    });
    page.addEventListener('pointerout', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-setting-help]') : null;
      if (button === activeButton && !pinned && !button.contains(event.relatedTarget) && document.activeElement !== button) close();
    });
    page.addEventListener('focusin', (event) => {
      if (event.target instanceof Element && event.target.matches('[data-setting-help]')) show(event.target, pinned && activeButton === event.target);
    });
    page.addEventListener('focusout', (event) => {
      if (event.target === activeButton && !pinned) close();
    });
    document.addEventListener('click', (event) => {
      const button = event.target instanceof Element ? event.target.closest('[data-setting-help]') : null;
      if (!button) {
        if (pinned) close();
        return;
      }
      if (button === activeButton && pinned) close();
      else show(button, true);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && activeButton) close();
    });
    window.addEventListener('resize', position);
    window.addEventListener('scroll', position, true);
  })();
})();
