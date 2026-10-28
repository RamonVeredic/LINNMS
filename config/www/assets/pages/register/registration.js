(() => {
  'use strict';

  const stages = new Map(Array.from(document.querySelectorAll('[data-register-stage]')).map((node) => [node.dataset.registerStage, node]));
  const status = document.querySelector('[data-register-status]');
  const registrationLoading = document.querySelector('[data-register-loading]');
  const phoneButton = document.querySelector('[data-otp-request]');
  const phoneInput = document.querySelector('[name="phone_number"]');
  const phoneLabel = phoneButton?.querySelector('[data-otp-request-label]');
  const resendButton = document.querySelector('[data-otp-resend]');
  const resendLabel = resendButton?.querySelector('[data-otp-resend-label]');
  const normalizePhone = (value) => {
    let digits = String(value || '').replace(/\D+/g, '');
    if (digits.startsWith('0')) digits = `62${digits.slice(1)}`;
    if (digits.startsWith('8')) digits = `62${digits}`;
    return digits;
  };
  let resendTimer = 0;
  let resendDeadline = 0;
  let resendPhone = normalizePhone(phoneInput?.value);
  let rateLimitPhone = String(phoneButton?.dataset.otpRatePhone || '');
  let rateLimitDeadline = Date.now() + Math.max(0, Number(phoneButton?.dataset.otpRateWait) || 0) * 1000;
  let resendBusy = false;
  let phoneBusy = false;

  const clearStatus = () => {
    if (!(status instanceof HTMLElement)) return;
    status.hidden = true;
    status.textContent = '';
  };

  const showStatus = (message) => {
    if (!(status instanceof HTMLElement)) return;
    status.textContent = String(message || 'Permintaan belum berhasil. Silakan coba lagi.');
    status.hidden = false;
  };

  const clearField = (input) => {
    if (!(input instanceof HTMLInputElement)) return;
    if (!input.validity.valid) return;
    const field = input.closest('.field');
    if (!(field instanceof HTMLElement)) return;
    field.classList.remove('is-tip-open', 'is-tip-error');
    input.removeAttribute('aria-invalid');
    input.removeAttribute('data-register-error');
  };

  const markField = (input, message) => {
    if (!(input instanceof HTMLInputElement)) return false;
    const field = input.closest('.field');
    if (!(field instanceof HTMLElement)) return false;
    field.dataset.tip = String(message);
    field.dataset.tipPos = 'bottom';
    field.classList.add('is-tip-open', 'is-tip-error');
    input.setAttribute('aria-invalid', 'true');
    input.focus({ preventScroll: true });
    return true;
  };

  const showStage = (name, updateUrl = true) => {
    if (!stages.has(name)) return;
    clearStatus();
    for (const [key, node] of stages) node.hidden = key !== name;
    if (updateUrl) {
      const url = new URL(window.location.href);
      url.searchParams.set('step', name);
      window.history.replaceState(null, '', url);
    }
    stages.get(name)?.querySelector('input:not([type="hidden"]):not([name="website"])')?.focus({ preventScroll: true });
  };

  const updateResend = () => {
    if (!(resendButton instanceof HTMLButtonElement) || !(resendLabel instanceof HTMLElement)) return 0;
    const limitDeadline = resendPhone !== '' && resendPhone === rateLimitPhone ? rateLimitDeadline : 0;
    const remaining = Math.max(0, Math.ceil((Math.max(resendDeadline, limitDeadline) - Date.now()) / 1000));
    resendButton.disabled = remaining > 0 || resendBusy;
    resendLabel.textContent = remaining > 0 ? `Kirim ulang kode (${remaining} dtk)` : 'Kirim ulang kode';
    return remaining;
  };

  const updatePhoneRequest = () => {
    if (!(phoneButton instanceof HTMLButtonElement) || !(phoneLabel instanceof HTMLElement)) return 0;
    const remaining = rateLimitPhone !== '' && normalizePhone(phoneInput?.value) === rateLimitPhone
      ? Math.max(0, Math.ceil((rateLimitDeadline - Date.now()) / 1000)) : 0;
    phoneButton.disabled = remaining > 0 || phoneBusy;
    phoneLabel.textContent = remaining > 0 ? `Kirim kode (${remaining} dtk)` : 'Kirim kode';
    return remaining;
  };

  const updateWait = () => {
    updateResend();
    updatePhoneRequest();
    if (Math.max(resendDeadline, rateLimitDeadline) <= Date.now()) window.clearInterval(resendTimer);
  };

  const scheduleWait = () => {
    window.clearInterval(resendTimer);
    updateWait();
    if (Math.max(resendDeadline, rateLimitDeadline) > Date.now()) resendTimer = window.setInterval(updateWait, 250);
  };

  const setResendWait = (seconds) => {
    resendDeadline = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    scheduleWait();
  };

  const setRateLimitWait = (phone, seconds) => {
    rateLimitPhone = normalizePhone(phone);
    rateLimitDeadline = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    scheduleWait();
  };

  const request = async (form) => {
    const response = await window.linnmsAjax.request(window.location.href, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const payload = await response.json();
    if (!payload || typeof payload !== 'object') throw new Error('Respons pendaftaran tidak valid.');
    return payload;
  };

  document.querySelectorAll('[data-register-error]').forEach((input) => {
    if (input instanceof HTMLInputElement) markField(input, input.dataset.registerError || 'Periksa kembali isian ini.');
  });

  document.querySelectorAll('[data-register-stage] input').forEach((input) => {
    input.addEventListener('input', () => clearField(input));
  });
  phoneInput?.addEventListener('input', updatePhoneRequest);

  setResendWait(resendButton?.dataset.otpWaitSeconds || 0);

  document.querySelectorAll('[data-register-form]').forEach((form) => {
    if (!(form instanceof HTMLFormElement)) return;
    form.addEventListener('submit', async (event) => {
      if (event.defaultPrevented) return;
      event.preventDefault();
      if (!form.checkValidity()) {
        const input = form.querySelector('input:invalid');
        markField(input, input?.validity.valueMissing ? 'Kolom ini wajib diisi.' : 'Periksa kembali isian ini.');
        return;
      }
      if (form.dataset.registerForm === 'phone' && phoneBusy) return;
      if (form.dataset.registerForm === 'resend' && resendBusy) return;
      if (form.dataset.registerForm === 'phone' && updatePhoneRequest() > 0) return;
      if (form.dataset.registerForm === 'resend' && updateResend() > 0) return;
      const button = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('button[type="submit"]');
      const requestPhone = form.dataset.registerForm === 'resend' ? resendPhone : normalizePhone(phoneInput?.value);
      const showsLoading = ['account', 'phone', 'resend'].includes(form.dataset.registerForm);
      const loadingWait = showsLoading ? new Promise((resolve) => window.setTimeout(resolve, 500)) : Promise.resolve();
      if (button === resendButton) resendBusy = true;
      if (button === phoneButton) phoneBusy = true;
      if (button instanceof HTMLButtonElement) button.disabled = true;
      if (showsLoading && registrationLoading instanceof HTMLElement) registrationLoading.hidden = false;
      clearStatus();
      try {
        const [payload] = await Promise.all([request(form), loadingWait]);
        if (payload.ok !== true) {
          if (payload.step === 'form') showStage('form');
          if (Number(payload.wait_seconds) > 0) {
            setRateLimitWait(requestPhone, payload.wait_seconds);
            return;
          }
          const field = typeof payload.field === 'string' ? document.querySelector(`[name="${payload.field}"]`) : null;
          if (!markField(field, payload.message || 'Periksa kembali isian ini.')) showStatus(payload.message);
          return;
        }
        if (typeof payload.redirect === 'string' && payload.redirect !== '') {
          window.location.assign(payload.redirect);
          return;
        }
        if (payload.step === 'wa') {
          const username = document.querySelector('[data-register-username]');
          if (username) username.textContent = String(payload.username || '');
          showStage('wa');
        } else if (payload.step === 'otp') {
          const phone = document.querySelector('[data-register-phone-mask]');
          if (phone) phone.textContent = String(payload.phone_masked || '');
          showStage('otp');
          if (form.dataset.registerForm === 'phone') resendPhone = requestPhone;
          if (payload.rate_limited) setRateLimitWait(resendPhone, payload.wait_seconds || 0);
          setResendWait(payload.wait_seconds || 0);
        } else if (payload.step === 'form') {
          for (const input of document.querySelectorAll('[data-register-form="account"] input:not([type="hidden"])')) input.value = '';
          const otp = document.querySelector('[name="otp_code"]');
          if (otp instanceof HTMLInputElement) otp.value = '';
          showStage('form');
        }
      } catch (error) {
        await loadingWait;
        showStatus('Permintaan belum berhasil. Silakan coba lagi.');
      } finally {
        if (showsLoading && registrationLoading instanceof HTMLElement) registrationLoading.hidden = true;
        if (button instanceof HTMLButtonElement && button !== resendButton) button.disabled = false;
        if (button === resendButton) {
          resendBusy = false;
        }
        if (button === phoneButton) phoneBusy = false;
        updateWait();
      }
    });
  });

  document.querySelectorAll('[data-register-go]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      showStage(link.dataset.registerGo);
    });
  });
})();
