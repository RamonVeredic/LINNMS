(() => {
  'use strict';

  const form = document.querySelector('[data-channel-credentials-form]');
  if (!(form instanceof HTMLFormElement)) return;

  const modal = document.querySelector('[data-channel-credential-modal]');
  const menu = document.querySelector('[data-channel-credential-menu]');
  const menuToggle = document.querySelector('[data-channel-credential-menu-toggle]');
  const menuList = document.querySelector('[data-channel-credential-menu-list]');
  const edit = document.querySelector('[data-channel-credential-edit]');
  const save = form.querySelector('[data-channel-credential-save]');

  const closeMenu = () => {
    if (menuList instanceof HTMLElement) menuList.hidden = true;
    if (menuToggle instanceof HTMLElement) menuToggle.setAttribute('aria-expanded', 'false');
  };

  const openMenu = () => {
    if (menuList instanceof HTMLElement) menuList.hidden = false;
    if (menuToggle instanceof HTMLElement) menuToggle.setAttribute('aria-expanded', 'true');
  };

  menuToggle?.addEventListener('click', (event) => {
    event.stopPropagation();
    if (menuList instanceof HTMLElement && menuList.hidden) openMenu();
    else closeMenu();
  });

  document.addEventListener('click', (event) => {
    if (!(menu instanceof HTMLElement) || !(event.target instanceof Node)) return;
    if (!menu.contains(event.target)) closeMenu();
  });

  const closeModal = () => {
    if (!(modal instanceof HTMLElement) || modal.hidden) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('channel-credential-modal-open');
    if (menuToggle instanceof HTMLElement) menuToggle.focus();
  };

  const openModal = () => {
    if (!(modal instanceof HTMLElement)) return;
    closeMenu();
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('channel-credential-modal-open');
    if (save instanceof HTMLElement) save.hidden = false;
    const focusTarget = form.querySelector(form.dataset.channelEditFocus || 'input:not([type="hidden"]):not(:disabled)');
    if (focusTarget instanceof HTMLElement) focusTarget.focus();
  };

  edit?.addEventListener('click', openModal);

  modal?.querySelectorAll('[data-channel-credential-modal-close]').forEach((button) => {
    button.addEventListener('click', closeModal);
  });

  modal?.addEventListener('click', (event) => {
    if (event.target === modal) closeModal();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeMenu();
    closeModal();
  });
})();
