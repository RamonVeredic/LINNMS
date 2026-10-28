(() => {
  'use strict';

  document.querySelectorAll('[data-device-create-form], [data-device-edit-form]').forEach((form) => {
    const type = form.querySelector('[data-device-type], [data-edit-device-type]');
    const cliToggle = form.querySelector('[data-cli-toggle]');
    const cliDetails = form.querySelector('[data-cli-details]');
    const cliFields = Array.from(form.querySelectorAll('[data-cli-details] [data-cli-field]'));
    const testButton = form.querySelector('[data-connection-test="device"]');
    const testResult = form.querySelector('[data-connection-test-result]');
    const markValidated = () => form.classList.add('was-validated');
    if (form.matches('[data-device-edit-form]')) {
      form.addEventListener('invalid', markValidated, true);
      form.addEventListener('submit', markValidated);
    }
    const ipField = form.querySelector('input[name="ip_address"]');
    const ipPattern = /^(?:(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)$/;
    const syncIpValidity = () => {
      if (!(ipField instanceof HTMLInputElement)) return;
      const value = ipField.value.trim();
      ipField.setCustomValidity(value !== '' && !ipPattern.test(value) ? 'Periksa IP Anda.' : '');
    };

    const selectedOption = () => {
      if (type instanceof HTMLSelectElement) return type.options[type.selectedIndex] || null;
      if (type instanceof HTMLInputElement) return { value: type.value, dataset: type.dataset };
      return null;
    };

    const requiredMessage = (field) => {
      if (field.name === 'username') return 'Username harus diisi.';
      if (field.name === 'password') return 'Password harus diisi.';
      return 'Port Telnet harus diisi.';
    };
    const syncTelnet = () => {
      if (!cliToggle || !cliDetails) return;
      const supported = selectedOption()?.dataset.telnetCli === '1';
      const visible = supported && cliToggle.checked;
      cliDetails.hidden = !visible;
      cliToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
      cliFields.forEach((field) => {
        const hasStoredSecret = field.dataset.secretStored === '1';
        field.required = visible && !hasStoredSecret;
        field.setCustomValidity(field.required && field.value.trim() === '' ? requiredMessage(field) : '');
      });
    };
    const syncTestButton = () => {
      if (!testButton) return;
      const option = selectedOption();
      const enabled = option?.dataset.enabled !== '0';
      testButton.disabled = !(option?.value && enabled);
    };

    const setTestResult = (message, state = '') => {
      if (!testResult) return;
      testResult.textContent = message;
      testResult.hidden = message === '';
      testResult.classList.toggle('text-muted', state === '');
      testResult.classList.toggle('success', state === 'success');
      testResult.classList.toggle('danger', state === 'error');
    };


    const notify = (level, message) => {
      const text = String(message || '').trim();
      if (text === '') return;
      if (typeof window.linnmsToast === 'function') {
        window.linnmsToast(level || 'info', text);
        return;
      }
      if (typeof window.linnmsNotify === 'function') {
        window.linnmsNotify(level || 'info', text);
        return;
      }
      setTestResult(text, level === 'success' ? 'success' : (level === 'error' ? 'error' : ''));
    };


    const setBusy = (busy) => {
      if (!(testButton instanceof HTMLButtonElement)) return;
      if (busy) {
        if (!testButton.dataset.idleLabel) testButton.dataset.idleLabel = testButton.innerHTML;
        testButton.disabled = true;
        testButton.classList.add('is-loading');
        testButton.innerHTML = '<i class="bi bi-arrow-repeat" aria-hidden="true"></i> Menguji&hellip;';
      } else {
        testButton.classList.remove('is-loading');
        if (testButton.dataset.idleLabel) testButton.innerHTML = testButton.dataset.idleLabel;
        syncTestButton();
      }
    };

    cliFields.forEach((field) => {
      field.addEventListener('input', () => {
        field.setCustomValidity(field.required && field.value.trim() === '' ? requiredMessage(field) : '');
      });
    });
    ipField?.addEventListener('input', syncIpValidity);
    syncIpValidity();

    type?.addEventListener('change', () => {
      syncTelnet();
      syncTestButton();
      setTestResult('');
    });
    cliToggle?.addEventListener('change', () => {
      syncTelnet();
      setTestResult('');
    });

    testButton?.addEventListener('click', async () => {
      const endpoint = testButton.dataset.connectionTestUrl || '';
      if (!endpoint || testButton.disabled) return;
      if (form.matches('[data-device-edit-form]')) markValidated();
      if (!form.reportValidity()) return;

      const option = selectedOption();
      if (!option?.value) {
        setTestResult('Pilih jenis perangkat terlebih dahulu.', 'error');
        return;
      }

      const payload = new FormData(form);
      payload.delete('action');
      payload.set('type', 'device');

      const startedAt = Date.now();
      let resultLevel = 'error';
      let resultMessage = 'Tes koneksi tidak dapat dijalankan. Periksa koneksi aplikasi lalu coba lagi.';
      setBusy(true);

      try {
        const response = await window.linnmsAjax.request(endpoint, {
          method: 'POST',
          body: payload,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({}));
        resultMessage = String(data.message || (response.ok ? 'Koneksi berhasil.' : 'Koneksi belum berhasil.'));
        resultLevel = response.ok && data.ok ? 'success' : 'error';
      } catch (error) {
        resultLevel = 'error';
      } finally {

        const elapsed = Date.now() - startedAt;
        if (elapsed < 1000) {
          await new Promise((resolve) => window.setTimeout(resolve, 1000 - elapsed));
        }
        setBusy(false);
        notify(resultLevel, resultMessage);
      }
    });

    syncTelnet();
    syncTestButton();
  });
})();

(() => {
  'use strict';
  document.querySelectorAll('[data-device-create-form]').forEach((form) => {
    const type=form.querySelector('[data-device-type]');const submit=form.querySelector('[data-device-submit]');
    if(!type||!submit)return;
    const community=form.querySelector('[data-snmp-community]');
    const cliToggle=form.querySelector('[data-cli-toggle]');
    const cliFields=Array.from(form.querySelectorAll('[data-cli-field]'));
    let activeDriver=type.value;
    const setGroup=(group,active)=>{group.hidden=!active;group.querySelectorAll('input,select,textarea').forEach((field)=>field.disabled=!active);};
    const syncCliFields=(telnetActive)=>{const on=Boolean(telnetActive&&cliToggle?.checked);cliFields.forEach((field)=>field.disabled=!on);};
    const update=()=>{
      const option=type.options[type.selectedIndex];
      const method=option?.dataset.method||'';
      const telnetCli=option?.dataset.telnetCli==='1';
      const valid=Boolean(option?.value&&option.dataset.enabled==='1'&&option.dataset.quotaAllowed==='1');
      if(community){const nextDefault=option?.dataset.communityDefault||'public';if(activeDriver!==type.value)community.value=nextDefault;community.dataset.driverDefault=nextDefault;}
      activeDriver=type.value;
      const snmpActive=valid&&(method==='snmp'||method==='api');
      form.querySelectorAll('[data-device-method-fields="snmp"]').forEach((group)=>setGroup(group,snmpActive));
      const telnetActive=valid&&telnetCli;
      form.querySelectorAll('[data-device-method-fields="telnet"]').forEach((group)=>setGroup(group,telnetActive));
      syncCliFields(telnetActive);
      submit.disabled=Boolean(option?.value&&!valid);
    };
    type.addEventListener('change',update);cliToggle?.addEventListener('change',update);update();
  });
})();

(() => {
  'use strict';


  document.querySelectorAll('[data-device-edit-form]').forEach((form) => {
    const type = form.querySelector('[data-edit-device-type]');
    if (!type) return;
    const community = form.querySelector('[data-snmp-community]');
    const cliToggle = form.querySelector('[data-cli-toggle]');
    const cliFields = Array.from(form.querySelectorAll('[data-cli-field]'));
    let activeDriver = type.value;
    const setGroup = (group, active) => {
      group.hidden = !active;
      group.querySelectorAll('input,select,textarea').forEach((field) => { field.disabled = !active; });
    };
    const syncCliFields = (telnetActive) => {
      const on = Boolean(telnetActive && cliToggle?.checked);
      cliFields.forEach((field) => { field.disabled = !on; });
    };
    const update = () => {
      const option = type instanceof HTMLSelectElement
        ? type.options[type.selectedIndex]
        : { value: type.value, dataset: type.dataset };
      const method = option?.dataset.method || '';
      const telnetCli = option?.dataset.telnetCli === '1';
      if (community) {
        const nextDefault = option?.dataset.communityDefault || 'public';
        if (activeDriver !== type.value) community.value = nextDefault;
        community.dataset.driverDefault = nextDefault;
      }
      activeDriver = type.value;
      const snmpActive = method === 'snmp';
      form.querySelectorAll('[data-device-method-fields="snmp"]').forEach((group) => setGroup(group, snmpActive));
      form.querySelectorAll('[data-device-method-fields="telnet"]').forEach((group) => setGroup(group, telnetCli));
      syncCliFields(telnetCli);
    };
    type.addEventListener('change', update);
    cliToggle?.addEventListener('change', update);
    update();
  });
})();
