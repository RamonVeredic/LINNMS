(() => {
  'use strict';

  const form = document.querySelector('[data-channel-credentials-form]');
  const editButton = document.querySelector('[data-channel-credential-edit]');
  if (!(form instanceof HTMLFormElement) || !(editButton instanceof HTMLButtonElement)) return;

  editButton.addEventListener('click', () => {
    form.hidden = false;
    editButton.hidden = true;
    editButton.setAttribute('aria-expanded', 'true');
    const saveButton = form.querySelector('[data-channel-credential-save]');
    if (saveButton instanceof HTMLButtonElement) saveButton.hidden = false;
    const focusTarget = form.querySelector(form.dataset.channelEditFocus || 'input:not([type="hidden"]):not(:disabled)');
    if (focusTarget instanceof HTMLElement) focusTarget.focus();
  });
})();
