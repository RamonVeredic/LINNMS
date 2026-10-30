(() => {
  'use strict';

  const normalize = (value) => String(value || '').trim().toLowerCase();
  const modalFor = (button) => {
    const family = normalize(button.dataset.onuFamily);
    const modals = Array.from(document.querySelectorAll('[data-add-onu-modal]'));
    return modals.find((candidate) => normalize(candidate.dataset.family) === family) || modals[0];
  };

  let opener = null;
  let activeModal = null;
  const setHidden = (form, name, value) => {
    let input = form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (!(input instanceof HTMLInputElement)) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.dataset.addOnuChoiceHidden = '';
      form.append(input);
    }
    input.value = value;
  };


  const syncVlanOptions = (scope) => {
    const formEl = scope instanceof HTMLFormElement ? scope : (scope?.querySelector?.('form') || document);
    const mapEl = formEl.querySelector?.('[data-add-onu-vlan-map]');
    const oltEl = formEl.querySelector?.('[data-add-onu-olt]');
    if (!mapEl || !oltEl || typeof oltEl.value !== 'string' || oltEl.value === '') return;
    let map = {};
    try { map = JSON.parse(mapEl.textContent || '{}') || {}; } catch (error) { map = {}; }
    const vlans = Array.isArray(map[oltEl.value]) ? map[oltEl.value] : [];
    formEl.querySelectorAll('[data-add-onu-service-vlan]').forEach((select) => {
      if (!(select instanceof HTMLSelectElement)) return;
      const previous = select.value;
      Array.from(select.options).forEach((option) => { if (option.value !== '') option.remove(); });
      vlans.forEach((vlan) => {
        const option = document.createElement('option');
        option.value = String(vlan);
        option.textContent = String(vlan);
        select.append(option);
      });
      if (previous !== '' && vlans.map(String).includes(previous)) select.value = previous;
    });


    const profileEl = formEl.querySelector?.('[data-add-onu-profile-map]');
    if (!profileEl) return;
    let profiles = {};
    try { profiles = JSON.parse(profileEl.textContent || '{}') || {}; } catch (error) { profiles = {}; }
    const forOlt = profiles[oltEl.value] && typeof profiles[oltEl.value] === 'object' ? profiles[oltEl.value] : {};
    const fill = (selector, list) => {
      formEl.querySelectorAll(selector).forEach((select) => {
        if (!(select instanceof HTMLSelectElement)) return;
        const previous = select.value;
        Array.from(select.options).forEach((option) => { if (option.value !== '') option.remove(); });
        (Array.isArray(list) ? list : []).forEach((value) => {
          const option = document.createElement('option');
          option.value = String(value);
          option.textContent = String(value);
          select.append(option);
        });
        if (previous !== '' && (Array.isArray(list) ? list : []).map(String).includes(previous)) select.value = previous;
      });
    };
    fill('[data-add-onu-onu-type]', forOlt.onu_type);
    fill('[data-add-onu-service-download]', forOlt.traffic);
    fill('[data-add-onu-service-upload]', forOlt.tcont);
    fill('[data-add-onu-wan-ip-profile]', forOlt.ip_profile);
    formEl.querySelectorAll('[data-add-onu-wan-vlan-profile]').forEach((wanProfile) => {
      if (!(wanProfile instanceof HTMLSelectElement)) return;
      const previous = wanProfile.value;
      Array.from(wanProfile.options).forEach((option) => { if (option.value !== '') option.remove(); });
      (Array.isArray(forOlt.vlan_profile) ? forOlt.vlan_profile : []).forEach((profile) => {
        const name = String(profile?.name || '').trim();
        if (!name) return;
        const option = document.createElement('option');
        option.value = name;
        option.textContent = String(profile?.label || name);
        wanProfile.append(option);
      });
      if ([...wanProfile.options].some((option) => option.value === previous)) wanProfile.value = previous;
    });
  };

  const syncWanMethod = (form) => {
    const method = form.querySelector('[data-add-onu-wan-method]')?.value || 'ont';
    const profileField = form.querySelector('[data-add-onu-wan-profile-field]');
    if (profileField) {
      profileField.hidden = method === 'ont';
      const select = profileField.querySelector('select');
      if (select) { select.disabled = method === 'ont'; select.required = method !== 'ont'; }
    }
    form.querySelectorAll('[data-add-onu-wan-pppoe-field]').forEach((field) => {
      field.hidden = method !== 'omci-pppoe';
      const input = field.querySelector('input');
      if (input) { input.disabled = method !== 'omci-pppoe'; input.required = method === 'omci-pppoe'; }
    });
    form.querySelectorAll('[data-add-onu-wan-static-field]').forEach((field) => {
      field.hidden = method !== 'omci-static';
      const control = field.querySelector('input,select');
      if (control) { control.disabled = method !== 'omci-static'; control.required = method === 'omci-static'; }
    });
  };

  const syncServiceWan = (card) => {
    const method = card.querySelector('[data-add-onu-service-wan-method]')?.value || 'ont';
    card.querySelectorAll('[data-add-onu-service-wan-field]').forEach((wrapper) => {
      const kind = wrapper.dataset.addOnuServiceWanField;
      const active = kind === 'profile' ? method !== 'ont' : kind === 'pppoe' ? method === 'omci-pppoe' : method === 'omci-static';
      wrapper.hidden = !active;
      wrapper.querySelectorAll('input,select').forEach((control) => { control.disabled = !active; control.required = active; });
    });
  };

  const syncTr069Fields = (form) => {
    const active = form.querySelector('[data-add-onu-tr069-toggle]')?.checked === true;
    form.querySelectorAll('[data-add-onu-tr069-field]').forEach((field) => {
      field.hidden = !active;
      const input = field.querySelector('input');
      if (input) input.disabled = !active;
    });
  };

  const syncQinqField = (card) => {
    const active = card.querySelector('[data-onu-seg="vlan_mode:3"].is-active') !== null;
    const number = card.dataset.addOnuServiceCard;
    const form = card.closest('form');
    const primary = form?.querySelector('[data-add-onu-primary-vlan="' + number + '"]');
    if (primary) {
      primary.hidden = active;
      const select = primary.querySelector('select');
      if (select instanceof HTMLSelectElement) select.disabled = active;
    }
    card.querySelectorAll('[data-add-onu-qinq-field]').forEach((field) => {
      field.hidden = !active;
      const control = field.querySelector('input, select');
      if (control) {
        control.disabled = !active;
        if (!active) control.value = '';
      }
    });
  };

  const syncIptvFields = (card) => {
    const active = card.querySelector('[data-onu-seg="service_type:iptv"].is-active') !== null;
    const number = card.dataset.addOnuServiceCard;
    const primary = card.closest('form')?.querySelector('[data-add-onu-primary-vlan="' + number + '"]');
    if (primary) {
      const qinq = card.querySelector('[data-onu-seg="vlan_mode:3"].is-active') !== null;
      primary.hidden = active || qinq;
      const control = primary.querySelector('select');
      if (control) control.disabled = active || qinq;
    }
    card.querySelectorAll('[data-add-onu-iptv-field]').forEach((field) => {
      field.hidden = !active;
      const control = field.querySelector('input,select');
      if (control) { control.disabled = !active; control.required = active; }
    });
  };

  const open = (button) => {
    const modal = modalFor(button);
    const form = modal?.querySelector('[data-add-onu-register-form]');
    if (!(form instanceof HTMLFormElement)) return;

    opener = button;
    activeModal = modal;
    form.reset();
    form.querySelectorAll('[data-add-onu-choice-hidden]').forEach((input) => input.remove());
    form.querySelectorAll('.add-onu-segmented').forEach((group) => {
      group.querySelectorAll('button').forEach((choice, index) => choice.classList.toggle('is-active', index === 0));
    });
    form.querySelectorAll('[data-add-onu-service-card]').forEach((card) => {
      card.classList.toggle('is-open', Boolean(card.querySelector('[data-add-onu-service-toggle]')?.checked));
      syncQinqField(card);
      syncIptvFields(card);
      syncServiceWan(card);
    });
    const customId = form.querySelector('[data-add-onu-id-custom]');
    if (customId instanceof HTMLInputElement) customId.hidden = true;
    form.querySelectorAll('[data-onu-wan-tab]').forEach((tab, index) => {
      tab.classList.toggle('is-active', index === 0);
      tab.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
    });

    const setInput = (selector, value) => {
      const field = form.querySelector(selector);
      if (field instanceof HTMLInputElement) field.value = value || '';
    };
    const named = (fieldName) => form.elements.namedItem(fieldName);
    const rowId = named('onu_row_id');
    if (rowId instanceof HTMLInputElement) rowId.value = button.dataset.onuRowId || '';
    setInput('[data-add-onu-serial]', button.dataset.onuSerial);
    const registrationValue = (value) => {
      const text = String(value || '').trim();
      return ['_', '-', '—', 'NotSuporte'].includes(text) ? '' : text;
    };
    setInput('[data-add-onu-name]', registrationValue(button.dataset.onuName));
    setInput('[data-add-onu-location]', registrationValue(button.dataset.onuLocation));

    const oltInput = form.querySelector('[data-add-onu-olt]');
    if (oltInput instanceof HTMLInputElement) oltInput.value = button.dataset.onuOltId || '';
    const oltDisplay = form.querySelector('[data-add-onu-olt-display]');
    if (oltDisplay instanceof HTMLInputElement) oltDisplay.value = button.dataset.onuOlt || '';
    syncVlanOptions(form);


    const indexParts = String(button.dataset.onuIndex || '').split(':');
    const pathParts = (indexParts[0] || '').split('/').filter(Boolean);
    const ponNumber = Number(pathParts[pathParts.length - 1] || '') || 0;
    const cardNumber = Number(pathParts[pathParts.length - 2] || '') || 0;
    setInput('[data-add-onu-card]', cardNumber > 0 ? String(cardNumber) : '');
    setInput('[data-add-onu-pon]', pathParts.length ? pathParts.join('/') : '');

    const onuType = form.querySelector('[data-add-onu-onu-type]');
    if (onuType instanceof HTMLSelectElement) {
      const wanted = String(button.dataset.onuType || '').trim();
      onuType.value = wanted;
      if (onuType.value !== wanted) onuType.value = '';
    }





    const deviceEl = form.querySelector('[data-add-onu-device-map]');
    let device = null;
    if (deviceEl) {
      try {
        const map = JSON.parse(deviceEl.textContent || '{}') || {};
        device = map[button.dataset.onuOltId || ''] || null;
      } catch (error) { device = null; }
    }
    const capabilities = device && device.capabilities && typeof device.capabilities === 'object'
      ? device.capabilities : {};
    form.querySelectorAll('[data-onu-capability]').forEach((section) => {
      const enabled = capabilities[section.dataset.onuCapability] === true;
      section.hidden = !enabled;
      section.querySelectorAll('input, select, textarea, button').forEach((control) => {
        control.disabled = !enabled;
      });
    });
    form.querySelectorAll('[data-add-onu-service-card]').forEach(syncQinqField);
    const serviceSection = form.querySelector('[data-add-onu-service-section]');
    if (!serviceSection?.hidden) syncWanMethod(form);
    if (!form.querySelector('[data-onu-capability="tr069"]')?.hidden) syncTr069Fields(form);
    const provisionable = capabilities.batch_provision === true;
    form.classList.toggle('is-app-only', !provisionable);
    if (serviceSection) serviceSection.classList.toggle('is-app-only', !provisionable);

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('onu-modal-open');
    window.requestAnimationFrame(() => form.querySelector('[data-add-onu-name]')?.focus());
  };

  const close = () => {
    if (!activeModal) return;
    activeModal.hidden = true;
    activeModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('onu-modal-open');
    opener?.focus();
  };

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const register = target.closest('[data-add-onu-register]');
    if (register instanceof HTMLButtonElement) {
      open(register);
      return;
    }
    if (target.closest('[data-add-onu-modal-close]')) {
      close();
      return;
    }
    if (activeModal && target === activeModal) {
      close();
      return;
    }
    if (!activeModal) return;

    const form = activeModal.querySelector('[data-add-onu-register-form]');
    if (!(form instanceof HTMLFormElement)) return;


    const segmentButton = target.closest('[data-onu-seg]');
    if (segmentButton instanceof HTMLButtonElement) {
      const [fieldName, value] = String(segmentButton.dataset.onuSeg || '').split(':');
      const group = segmentButton.closest('.add-onu-segmented');
      group?.querySelectorAll('button').forEach((candidate) => candidate.classList.toggle('is-active', candidate === segmentButton));
      const card = segmentButton.closest('[data-add-onu-service-card]');
      const cardNo = card instanceof HTMLElement ? card.dataset.addOnuServiceCard : '';
      setHidden(form, (cardNo ? 'service' + cardNo + '_' : '') + fieldName, value || '');
      if (fieldName === 'vlan_mode' && card) { syncQinqField(card); syncIptvFields(card); }
      if (fieldName === 'service_type' && card) syncIptvFields(card);
      return;
    }


    const tabButton = target.closest('[data-onu-wan-tab]');
    if (tabButton instanceof HTMLButtonElement) {
      const tab = String(tabButton.dataset.onuWanTab || '');
      activeModal.querySelectorAll('[data-onu-wan-tab]').forEach((candidate) => {
        const active = candidate === tabButton;
        candidate.classList.toggle('is-active', active);
        candidate.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      setHidden(form, 'wan_method', tab);
      syncWanMethod(form);
      return;
    }


    const idModeButton = target.closest('[data-onu-id-mode]');
    if (idModeButton instanceof HTMLButtonElement) {
      const mode = String(idModeButton.dataset.onuIdMode || 'auto');
      activeModal.querySelectorAll('[data-onu-id-mode]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === idModeButton));
      const custom = form.querySelector('[data-add-onu-id-custom]');
      if (custom instanceof HTMLInputElement) {
        custom.hidden = mode !== 'custom';
        if (mode !== 'custom') custom.value = '';
      }
      setHidden(form, 'onu_id_mode', mode);
      if (mode === 'custom') custom?.focus();
      return;
    }

    const clearButton = target.closest('[data-add-onu-type-clear]');
    if (clearButton instanceof HTMLButtonElement) {
      const row = clearButton.closest('.add-onu-input-row');
      const select = row?.querySelector('select');
      if (select instanceof HTMLSelectElement) select.value = '';
    }
  });

  document.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.matches('[data-add-onu-tr069-toggle]')) {
      const form = target.closest('form');
      if (form) syncTr069Fields(form);
    }
    if (target.matches('[data-add-onu-service-toggle]')) {
      const card = target.closest('[data-add-onu-service-card]');
      card?.classList.toggle('is-open', target.checked);
    }
    if (target.matches('[data-add-onu-service-wan-method]')) syncServiceWan(target.closest('[data-add-onu-service-card]'));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && activeModal && !activeModal.hidden) close();
  });
})();

(() => {
  'use strict';
  const form = document.querySelector('[data-add-onu-search-form]');
  const input = form?.querySelector('[name="q"]');
  const results = document.querySelector('[data-add-onu-results]');
  if (!form || !input || !results) return;
  let timer;
  const groups = () => [...results.querySelectorAll('.add-onu-olt-group')];
  const setLoading = (targets, loading) => {
    targets.forEach((group) => {
      const list = group.querySelector('.add-onu-list');
      const indicator = group.querySelector('[data-add-onu-list-loading]');
      if (loading) group.open = true;
      if (list) list.classList.toggle('is-loading', loading);
      if (indicator) indicator.hidden = !loading;
    });
  };
  const applyFilter = () => {
    const term = input.value.trim().toLocaleLowerCase();
    groups().forEach((group) => {
      const oltText = group.querySelector('.add-onu-olt-copy')?.textContent?.toLocaleLowerCase() || '';
      const oltMatches = term !== '' && oltText.includes(term);
      let visible = 0;
      group.querySelectorAll('.add-onu-row').forEach((row) => {
        const button = row.querySelector('[data-add-onu-register]');
        const value = [row.textContent, button?.dataset.onuLocation].join(' ').toLocaleLowerCase();
        row.hidden = term !== '' && !oltMatches && !value.includes(term);
        if (!row.hidden) visible++;
      });
      const count = group.querySelector('[data-add-onu-count]');
      if (count) count.textContent = visible + ' ONU';
    });
    setLoading(groups(), false);
    results.setAttribute('aria-busy', 'false');
  };
  input.addEventListener('input', () => {
    clearTimeout(timer);
    setLoading(groups(), true);
    results.setAttribute('aria-busy', 'true');
    timer = setTimeout(applyFilter, 180);
  });
  form.addEventListener('submit', event => {
    event.preventDefault();
    clearTimeout(timer);
    applyFilter();
  });
  results.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-add-onu-unconfigured-refresh]');
    if (button) {
      if (button.disabled) return;
      const label = button.querySelector('span');
      const group = button.closest('.add-onu-olt-group');
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      if (label) label.textContent = 'Membaca Uncfg...';
      if (group) setLoading([group], true);
      const refreshUrl = new URL(document.body.dataset.liveUrl || 'live.php', location.href);
      refreshUrl.searchParams.set('type', 'onu-unconfigured-refresh');
      try {
        const response = await window.linnmsAjax.request(refreshUrl, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'fetch'},
          body: new URLSearchParams({csrf: button.dataset.csrf || '', olt_row_id: button.dataset.oltRowId || ''}).toString(),
        });
        const payload = await response.json();
        if (!response.ok || payload?.ok !== true) throw new Error(payload?.message || 'Refresh Uncfg gagal.');
        const pageUrl = new URL(form.action, location.href);
        pageUrl.searchParams.set('page', 'add-onu');
        const pageResponse = await window.linnmsAjax.request(pageUrl, {credentials: 'same-origin', cache: 'no-store'});
        if (!pageResponse.ok) throw new Error('Daftar ONU gagal dimuat ulang.');
        const pageHtml = await pageResponse.text();
        const next = new DOMParser().parseFromString(pageHtml, 'text/html').querySelector('[data-add-onu-results]');
        if (!next) throw new Error('Daftar ONU gagal dimuat ulang.');
        results.innerHTML = next.innerHTML;
        applyFilter();
        if (typeof window.linnmsNotify === 'function') window.linnmsNotify('success', payload.message || 'ONU Uncfg diperbarui.');
      } catch (error) {
        if (group) setLoading([group], false);
        button.disabled = false;
        button.removeAttribute('aria-busy');
        if (label) label.textContent = 'Refresh Uncfg';
        if (typeof window.linnmsNotify === 'function') window.linnmsNotify('error', error?.message || 'Refresh Uncfg gagal.');
      }
    }
  });
})();
