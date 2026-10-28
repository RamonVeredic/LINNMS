(() => {
  'use strict';





  const STAGE_ID = 'linnms-toast-stage';
  const MAX_TOASTS = 4;
  const META = {
    success: { icon: 'bi-check-circle', color: 'var(--green, #57c785)', timeout: 3600 },
    error: { icon: 'bi-x-circle', color: 'var(--red, #ef6b6b)', timeout: 6000 },
    warning: { icon: 'bi-exclamation-triangle', color: 'var(--orange, #f0a35e)', timeout: 5200 },
    waiting: { icon: 'bi-arrow-repeat', color: 'var(--primary, #5b8def)', timeout: 5200 },
    info: { icon: 'bi-info-circle', color: 'var(--primary, #5b8def)', timeout: 3600 }
  };

  const ensureStage = () => {
    let stage = document.getElementById(STAGE_ID);
    if (stage) return stage;
    stage = document.createElement('div');
    stage.id = STAGE_ID;
    stage.className = 'linnms-toast-stage';
    stage.setAttribute('aria-live', 'polite');
    stage.setAttribute('aria-atomic', 'true');
    document.body.appendChild(stage);
    return stage;
  };

  const remove = (card) => {
    if (!card || card.dataset.leaving === '1') return;
    card.dataset.leaving = '1';
    window.clearTimeout(card.__timer);
    card.__timer = null;
    card.classList.remove('is-visible');
    window.setTimeout(() => card.remove(), 200);
  };

  const schedule = (card, ms) => {
    window.clearTimeout(card.__timer);
    card.__remaining = ms;
    card.__startedAt = Date.now();
    card.__timer = window.setTimeout(() => remove(card), ms);
  };

  const pause = (card) => {
    if (!card.__timer) return;
    window.clearTimeout(card.__timer);
    card.__timer = null;
    const elapsed = Date.now() - (card.__startedAt || Date.now());
    card.__remaining = Math.max(0, (card.__remaining || 0) - elapsed);
  };

  const resume = (card) => {
    if (card.__timer || card.dataset.leaving === '1') return;
    if ((card.__remaining || 0) <= 0) { remove(card); return; }
    schedule(card, card.__remaining);
  };

  window.linnmsToast = (type, message, options = {}) => {
    const text = String(message || '').trim();
    if (text === '') return null;
    const kind = META[type] ? type : 'info';
    const meta = META[kind];
    const timeout = Number.isFinite(options.timeout) ? Number(options.timeout) : meta.timeout;

    const mount = options.mount instanceof Element ? options.mount : null;
    const stage = mount || ensureStage();


    const existing = Array.from(stage.children).find((card) =>
      card.dataset.leaving !== '1' && card.dataset.kind === kind && card.dataset.message === text
    );
    if (existing) { schedule(existing, timeout); return existing; }


    const toastCards = () => Array.from(stage.children).filter((child) => child.classList.contains('linnms-toast'));
    while (toastCards().length >= (mount ? 1 : MAX_TOASTS)) {
      const oldest = toastCards()[0];
      if (!oldest) break;
      remove(oldest);
      oldest.remove();
    }

    const card = document.createElement('div');
    card.className = 'linnms-toast ' + kind;
    card.dataset.kind = kind;
    card.dataset.message = text;
    card.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    card.innerHTML = '<i class="bi ' + meta.icon + '" aria-hidden="true"></i>'
      + '<span class="linnms-toast-message"></span>'
      + '<button type="button" class="linnms-toast-close" aria-label="Tutup">&times;</button>';
    const messageElement = card.querySelector('.linnms-toast-message');
    messageElement.textContent = '';
    text.split(/\r?\n/).forEach((line, index) => {
      if (index > 0) messageElement.append(document.createElement('br'));
      messageElement.append(document.createTextNode(line));
    });
    card.style.setProperty('--linnms-toast-accent', meta.color);
    if (options.closeable === false) card.querySelector('.linnms-toast-close')?.remove();

    card.querySelector('.linnms-toast-close')?.addEventListener('click', () => remove(card));
    card.addEventListener('mouseenter', () => pause(card));
    card.addEventListener('mouseleave', () => resume(card));

    stage.appendChild(card);
    window.requestAnimationFrame(() => card.classList.add('is-visible'));

    if (timeout > 0) schedule(card, timeout);
    return card;
  };
})();

(() => {
  'use strict';




  const toast = (type, message, options) => {
    if (typeof window.linnmsToast === 'function') {
      return window.linnmsToast(type || 'info', message, options || {});
    }
    return null;
  };

  window.linnmsNotify = (type, message) => toast(type, message);

  const consumeFlashToasts = () => {
    document.querySelectorAll('template[data-flash-toast]').forEach((item) => {
      const message = item.content.textContent?.trim() || '';
      const type = item.dataset.type === 'scanning' ? 'waiting' : item.dataset.type;
      item.remove();
      if (message !== '') toast(type, message);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', consumeFlashToasts);
  } else {
    consumeFlashToasts();
  }

  const expiryNotice = document.querySelector('[data-service-expiry-notice]');
  if (expiryNotice) {
    expiryNotice.querySelector('[data-service-expiry-dismiss]')?.addEventListener('click', () => expiryNotice.remove());
  }
})();
