(() => {
  'use strict';

  const page = document.getElementById('notification-settings')
    || document.querySelector('[data-notification-edit-form]')?.closest('section');
  if (!page) return;

  const deviceForms = [...page.querySelectorAll('[data-notification-device-form]')];
  if (deviceForms.length > 0) {
    const deviceCheckboxes = [...page.querySelectorAll('[data-notification-device-checkbox]')];
    let savedIds = new Set(deviceCheckboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value));
    let saving = false;
    const saveDevices = async (form) => {
      if (saving) return;
      saving = true;
      const body = new FormData(form);
      body.delete('notification_device_ids[]');
      deviceCheckboxes.filter((checkbox) => checkbox.checked).forEach((checkbox) => {
        body.append('notification_device_ids[]', checkbox.value);
      });
      deviceCheckboxes.forEach((checkbox) => { checkbox.disabled = true; });
      try {
        const result = await window.linnmsInstantPost(form, body);
        savedIds = new Set(deviceCheckboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value));
        window.linnmsInstantNotify('success', result.message || 'Pilihan perangkat berhasil disimpan.');
      } catch (error) {
        deviceCheckboxes.forEach((checkbox) => { checkbox.checked = savedIds.has(checkbox.value); });
        window.linnmsInstantNotify('error', error instanceof Error ? error.message : 'Pilihan perangkat belum berhasil disimpan.');
      } finally {
        deviceCheckboxes.forEach((checkbox) => { checkbox.disabled = false; });
        saving = false;
      }
    };
    deviceForms.forEach((form) => form.addEventListener('submit', (event) => event.preventDefault()));
    deviceCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', () => saveDevices(checkbox.form)));
  }

  const resetEditor = (form, focusButton = false) => {
    const control = form?.querySelector('[data-notification-edit-control]');
    const editButton = form?.querySelector('[data-notification-edit]');
    const cancelButton = form?.querySelector('[data-notification-cancel]');
    form?.reset();
    if (control) control.disabled = true;
    if (editButton) {
      editButton.classList.remove('is-active');
      editButton.setAttribute('aria-pressed', 'false');
      editButton.querySelector('i')?.classList.replace('bi-floppy', 'bi-pencil');
      const label = `Edit ${editButton.dataset.notificationLabel || ''}`.trim();
      editButton.setAttribute('aria-label', label);
      editButton.title = label;
      if (focusButton) editButton.focus();
    }
    if (cancelButton) cancelButton.hidden = true;
  };

  page.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const editButton = event.target.closest('[data-notification-edit]');
    const cancelTrigger = event.target.closest('[data-notification-cancel]');
    const trigger = editButton || cancelTrigger;
    if (!trigger) return;

    const form = trigger.closest('[data-notification-edit-form]');
    const control = document.getElementById(trigger.dataset.target || '');
    if (!form || !control || !form.contains(control)) return;

    const cancelButton = form.querySelector('[data-notification-cancel]');
    if (cancelTrigger) {
      resetEditor(form, true);
      return;
    }
    if (editButton.classList.contains('is-active')) {
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      if (control.value === (control.dataset.originalValue ?? control.defaultValue)) {
        resetEditor(form, true);
        return;
      }
      form.requestSubmit();
      return;
    }

    page.querySelectorAll('[data-notification-edit-form]').forEach((otherForm) => {
      if (otherForm !== form) resetEditor(otherForm);
    });
    control.dataset.originalValue = control.value;
    control.disabled = false;
    editButton.classList.add('is-active');
    editButton.setAttribute('aria-pressed', 'true');
    editButton.querySelector('i')?.classList.replace('bi-pencil', 'bi-floppy');
    const label = `Simpan ${editButton.dataset.notificationLabel || ''}`.trim();
    editButton.setAttribute('aria-label', label);
    editButton.title = label;
    if (cancelButton) cancelButton.hidden = false;
    window.requestAnimationFrame(() => {
      control.focus();
      control.select();
    });
  });
})();
