(() => {
  'use strict';

  const pageSize = 10;

  document.querySelectorAll('[data-interface-list]').forEach((list) => {
    const getRows = () => Array.from(list.querySelectorAll('[data-interface-list-row]'));
    const search = list.querySelector('[data-interface-search]');
    const filterEmpty = list.querySelector('[data-interface-filter-empty]');
    const pagination = list.querySelector('[data-interface-pagination]');
    const pageInfo = list.querySelector('[data-interface-page-info]');
    const pageNumber = list.querySelector('[data-interface-page-number]');
    const previous = list.querySelector('[data-interface-page-prev]');
    const next = list.querySelector('[data-interface-page-next]');
    let currentPage = 1;

    const render = () => {
      const rows = getRows();
      const query = (search?.value || '').trim().toLocaleLowerCase('id-ID');
      const matchedRows = rows.filter((row) => {
        if (!query) return true;
        return (row.dataset.interfaceSearchName || '').toLocaleLowerCase('id-ID').includes(query);
      });
      const totalPages = Math.max(1, Math.ceil(matchedRows.length / pageSize));
      currentPage = Math.min(Math.max(1, currentPage), totalPages);
      const firstIndex = (currentPage - 1) * pageSize;
      const visibleRows = new Set(matchedRows.slice(firstIndex, firstIndex + pageSize));

      rows.forEach((row) => { row.hidden = !visibleRows.has(row); });
      if (filterEmpty) filterEmpty.hidden = matchedRows.length !== 0 || query === '';

      if (pageInfo) {
        const first = matchedRows.length === 0 ? 0 : firstIndex + 1;
        const last = Math.min(firstIndex + pageSize, matchedRows.length);
        pageInfo.textContent = `${first}-${last} dari ${matchedRows.length}`;
      }
      if (pageNumber) pageNumber.textContent = `${currentPage} / ${totalPages}`;
      if (previous) previous.disabled = currentPage <= 1;
      if (next) next.disabled = currentPage >= totalPages;
      if (pagination) pagination.hidden = rows.length === 0;
    };

    search?.addEventListener('input', () => {
      currentPage = 1;
      render();
    });
    previous?.addEventListener('click', () => {
      if (currentPage <= 1) return;
      currentPage -= 1;
      render();
    });
    next?.addEventListener('click', () => {
      const rows = getRows();
      const query = (search?.value || '').trim().toLocaleLowerCase('id-ID');
      const matchedCount = rows.filter((row) => !query || (row.dataset.interfaceSearchName || '').toLocaleLowerCase('id-ID').includes(query)).length;
      if (currentPage >= Math.max(1, Math.ceil(matchedCount / pageSize))) return;
      currentPage += 1;
      render();
    });
    render();
  });

  document.querySelectorAll('[data-vlan-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const summary = button.closest('.switch-vlan-summary');
      const preview = summary?.querySelector('[data-vlan-preview]');
      const all = summary?.querySelector('[data-vlan-all]');
      if (!preview || !all) return;
      const expanded = button.getAttribute('aria-expanded') === 'true';
      preview.hidden = !expanded;
      all.hidden = expanded;
      button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      button.textContent = expanded ? 'Lihat semua VLAN' : 'Ringkas';
    });
  });
})();

(() => {
  const temperatureClass = (value) => {
    const numeric = Number.parseFloat(String(value ?? '').replace(',', '.'));
    if (!Number.isFinite(numeric) || numeric === 0) return 'temperature-unknown';
    if (numeric >= 50) return 'temperature-hot';
    if (numeric >= 40) return 'temperature-warn';
    return 'temperature-ok';
  };
  const paintMeter = (meter) => {
    const fill = meter.querySelector(':scope > span');
    const percent = Number.parseFloat(fill?.style.width || '');
    meter.classList.remove('is-level-1', 'is-level-2', 'is-level-3', 'is-level-4');
    if (!Number.isFinite(percent) || percent <= 0) return;
    meter.classList.add(`is-level-${percent <= 25 ? 1 : (percent <= 50 ? 2 : (percent <= 75 ? 3 : 4))}`);
  };
  const paintTemperatures = () => {
    document.querySelectorAll('.olt-card-health .device-meter').forEach((meter) => meter.classList.add('heat-meter'));
    document.querySelectorAll('.device-health-card').forEach((card) => {
      const label = String(card.querySelector('.device-health-head')?.textContent || '').toLowerCase();
      if (label.includes('cpu') || label.includes('ram') || label.includes('memory')) {
        card.querySelector('.device-meter')?.classList.add('heat-meter');
      }
    });
    document.querySelectorAll('.olt-temperature, .olt-card-health > div, .olt-optical-details > div, .olt-uplink-row, .olt-pon-optics > div').forEach((entry) => {
      let valueNode = entry;
      let labelNode = null;
      if (!entry.matches('.olt-temperature')) {
        labelNode = entry.querySelector('span, dt');
        const label = String(labelNode?.textContent || '').trim().toLowerCase();
        if (!label.includes('temperature') && !label.includes('suhu')) return;
        valueNode = entry.querySelector('strong, dd, .value');
        if (!valueNode) return;
      }
      valueNode.classList.remove('temperature-ok', 'temperature-warn', 'temperature-hot', 'temperature-unknown');
      valueNode.classList.add(temperatureClass(valueNode.textContent));
      if (labelNode && !labelNode.querySelector('.bi-thermometer-half')) {
        const icon = document.createElement('i');
        icon.className = 'bi bi-thermometer-half';
        icon.setAttribute('aria-hidden', 'true');
        labelNode.prepend(icon, ' ');
      }
    });
    document.querySelectorAll('.heat-meter:not(.olt-fan-meter)').forEach(paintMeter);
  };
  paintTemperatures();
  const observer = new MutationObserver((mutations) => {
    if (mutations.some((mutation) => mutation.type === 'childList' || mutation.type === 'characterData')) paintTemperatures();
  });
  observer.observe(document.querySelector('[data-olt-view]') || document.body, { childList: true, characterData: true, attributes: true, attributeFilter: ['style'], subtree: true });
})();

(() => {
  'use strict';

  const root = document.querySelector('[data-device-view]');
  if (!root?.dataset.deviceId) return;

  const POLL_INTERVAL_MS = 1000;
  const IDLE_TIMEOUT_MS = 60000;
  const deviceId = root.dataset.deviceId;
  const deviceRef = root.dataset.deviceRef || '';
  const interfaceName = root.dataset.interfaceName || '';
  const liveUrl = document.body.dataset.liveUrl || '';
  const realtimeLabel = 'TX/RX';
  const stateNodes = Array.from(document.querySelectorAll('[data-traffic-poll-state]'));
  const chart = document.querySelector('[data-mikrotik-traffic-chart]');
  const history = [];
  const Y_LINES = 10;
  const niceStep = (value) => {
    if (!Number.isFinite(value) || value <= 0) return 1;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    const scaled = value / magnitude;
    return (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * magnitude;
  };
  let timer = 0;
  let idleTimer = 0;
  let request = null;
  let idle = false;
  let running = false;
  let lastStartedAt = 0;
  let lastActivityAt = 0;

  const formatRate = (value) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric < 0) return '—';
    const units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
    let rate = numeric;
    let unit = 0;
    while (rate >= 1000 && unit < units.length - 1) { rate /= 1000; unit += 1; }
    return `${rate >= 100 ? rate.toFixed(0) : rate.toFixed(2)} ${units[unit]}`;
  };

  const formatBytes = (value) => {
    let bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 0) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let unit = 0;
    while (bytes >= 1024 && unit < units.length - 1) { bytes /= 1024; unit += 1; }
    return `${unit === 0 ? bytes.toFixed(0) : bytes.toFixed(1)} ${units[unit]}`;
  };

  const updateHealth = (health) => {
    if (!health || typeof health !== 'object') return;
    const updatePercent = (value, textSelector, meterSelector) => {
      const numeric = Number(value);
      const valid = Number.isFinite(numeric) && (!document.querySelector('[data-olt-view]') || (value !== null && value !== undefined && value !== ''));
      const percent = valid ? Math.max(0, Math.min(100, numeric)) : 0;
      const text = document.querySelector(textSelector);
      const meter = document.querySelector(meterSelector);
      if (text) text.textContent = valid ? `${percent.toFixed(1)} %` : '—';
      if (meter) meter.style.width = `${percent}%`;
    };
    updatePercent(health.cpu_usage_percent, '[data-device-cpu-text]', '[data-device-cpu-meter]');
    updatePercent(health.memory_usage_percent, '[data-device-memory-text]', '[data-device-memory-meter]');
    const detail = document.querySelector('[data-device-memory-detail]');
    if (detail) detail.textContent = `${formatBytes(health.memory_used_bytes)} / ${formatBytes(health.memory_total_bytes)}`;
  };

  const updateFans = (fans, fanboardStatus) => {
    const list = document.querySelector('[data-olt-fan-list]');
    const statusBadge = document.querySelector('[data-olt-fan-status]');
    if (document.querySelector('[data-olt-view]')) {
      const rows = Array.isArray(fans) ? fans.filter((fan) => fan && typeof fan === 'object') : [];
      const count = document.querySelector('[data-olt-fan-count]');
      if (count) count.textContent = rows.length ? String(rows.length) : '—';
      const statusText = (value) => {
        const status = String(value || '').toLowerCase();
        return status === 'online' ? 'Online' : (status === 'offline' ? 'Offline' : '—');
      };
      if (statusBadge) {
        const status = statusText(fanboardStatus);
        statusBadge.className = `olt-fan-status ${status === 'Online' ? 'is-online' : (status === 'Offline' ? 'is-offline' : '')}`;
        const dot = document.createElement('i');
        dot.className = 'bi bi-circle-fill';
        dot.setAttribute('aria-hidden', 'true');
        statusBadge.replaceChildren(dot, document.createTextNode(`Status: ${status}`));
      }
      if (list) {
        list.replaceChildren();
        if (!rows.length) list.textContent = '—';
        rows.forEach((fan) => {
          const item = document.createElement('div');
          const online = String(fan.status || '').toLowerCase() === 'online';
          const offline = String(fan.status || '').toLowerCase() === 'offline';
          const rawLevel = Number(fan.speed_level);
          const level = Number.isFinite(rawLevel) ? Math.max(0, Math.min(4, Math.trunc(rawLevel))) : null;
          const levelClass = online && level > 0 ? ` is-level-${level}` : '';
          const rpmValue = Number(fan.rpm);
          const spinning = online && fan.rpm !== null && fan.rpm !== undefined && fan.rpm !== '' && Number.isFinite(rpmValue) && rpmValue > 0;
          item.className = `olt-fan-item${online ? ' is-online' : (offline ? ' is-offline' : '')}${spinning ? ' is-spinning' : ''}`;
          const rpm = fan.rpm !== null && fan.rpm !== undefined && fan.rpm !== '' && Number.isFinite(Number(fan.rpm))
            ? `${Number(fan.rpm).toLocaleString('id-ID')} RPM` : '—';
          const name = document.createElement('span');
          name.className = 'olt-fan-name';
          const icon = document.createElement('span');
          icon.className = 'olt-fan-icon';
          icon.setAttribute('aria-hidden', 'true');
          const fanImage = document.createElement('img');
          fanImage.src = list.dataset.fanImageUrl;
          fanImage.alt = '';
          icon.append(fanImage);
          name.append(icon, document.createTextNode(` Fan ${fan.fan_unit_id ?? '—'}`));
          const status = document.createElement('span');
          status.className = `olt-fan-status${online ? ' is-online' : (offline ? ' is-offline' : '')}`;
          status.setAttribute('aria-label', statusText(fan.status));
          status.title = statusText(fan.status);
          const dot = document.createElement('i');
          dot.className = 'bi bi-circle-fill';
          dot.setAttribute('aria-hidden', 'true');
          status.append(dot);
          const reading = document.createElement('span');
          reading.className = 'olt-fan-reading';
          const levelText = document.createElement('span');
          levelText.className = 'olt-fan-level';
          levelText.textContent = `Lv ${fan.speed_level ?? '—'}`;
          const rpmText = document.createElement('strong');
          rpmText.className = 'olt-fan-rpm mono';
          rpmText.textContent = rpm;
          reading.append(levelText, rpmText);
          item.append(name, status, reading);
          const meter = document.createElement('div');
          meter.className = `device-meter heat-meter olt-fan-meter${levelClass}`;
          meter.setAttribute('aria-hidden', 'true');
          const fill = document.createElement('span');
          fill.style.width = `${online && level !== null ? level * 25 : 0}%`;
          meter.append(fill);
          item.append(meter);
          list.append(item);
        });
      }
      return;
    }
    if (statusBadge) {
      const online = String(fanboardStatus || '').toLowerCase() === 'online';
      statusBadge.className = `olt-fan-status ${online ? 'is-online' : 'is-offline'}`;
      statusBadge.innerHTML = '<i class="bi-circle-fill" aria-hidden="true"></i>';
      statusBadge.setAttribute('aria-label', online ? 'Online' : 'Offline');
      statusBadge.title = online ? 'Online' : 'Offline';
    }
    if (!list) return;
    if (!Array.isArray(fans) || fans.length === 0) {
      list.innerHTML = '<span class="text-muted">Data fan belum tersedia.</span>';
      return;
    }
    list.innerHTML = fans.map((fan) => {
      const online = String(fan.status || '').toLowerCase() === 'online';
      return `
      <div class="olt-fan-item">
        <span><i class="bi-fan" aria-hidden="true"></i> Fan ${fan.fan_unit_id}</span>
        <span class="olt-fan-status ${online ? 'is-online' : 'is-offline'}" aria-label="${online ? 'Online' : 'Offline'}" title="${online ? 'Online' : 'Offline'}"><i class="bi-circle-fill" aria-hidden="true"></i></span>
        <span class="text-muted">Lv ${fan.speed_level}</span>
        <span class="mono">${Number(fan.rpm).toLocaleString('id-ID')} RPM</span>
      </div>
    `;
    }).join('');
  };

  const setState = (message) => stateNodes.forEach((node) => { node.textContent = message; });
  const pageActive = () => !document.hidden && document.hasFocus() && !idle;

  const drawChart = () => {
    if (!(chart instanceof HTMLCanvasElement)) return;
    const context = chart.getContext('2d');
    if (!context) return;
    const peak = Math.max(0, ...history.flatMap((point) => [point.rx, point.tx]));
    const axisStep = niceStep(peak / (Y_LINES - 1));
    const axisPosition = (value) => value / axisStep;
    const axisValue = (position) => position * axisStep;
    const width = Math.max(320, Math.floor(chart.clientWidth));
    const height = Math.max(220, Math.floor(chart.clientHeight));
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    chart.width = Math.floor(width * ratio);
    chart.height = Math.floor(height * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);
    const padding = { top: 14, right: 12, bottom: 24, left: 62 };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;
    const styles = getComputedStyle(document.documentElement);
    const grid = styles.getPropertyValue('--bg-line').trim() || 'rgba(255,255,255,.08)';
    const muted = styles.getPropertyValue('--text-faint').trim() || '#64748b';
    context.font = '10px sans-serif';
    context.textAlign = 'right';
    context.textBaseline = 'middle';
    for (let line = 0; line < Y_LINES; line += 1) {
      const y = padding.top + (plotHeight * line / (Y_LINES - 1));
      context.strokeStyle = grid;
      context.beginPath(); context.moveTo(padding.left, y); context.lineTo(width - padding.right, y); context.stroke();
      context.fillStyle = muted;
      context.fillText(formatRate(axisValue(Y_LINES - 1 - line)), padding.left - 8, y);
    }
    const draw = (key, color) => {
      if (history.length === 0) return;
      const startX = padding.left + plotWidth * Math.max(0, 1 - ((history.length - 1) / 59));
      context.strokeStyle = color; context.lineWidth = 2; context.lineJoin = 'round'; context.beginPath();
      history.forEach((point, index) => {
        const x = startX + plotWidth * index / 59;
        const y = padding.top + plotHeight * (1 - axisPosition(point[key]) / (Y_LINES - 1));
        if (index === 0) context.moveTo(x, y); else context.lineTo(x, y);
      });
      context.stroke();
    };
    context.save();
    context.beginPath();
    context.rect(padding.left, padding.top, plotWidth, plotHeight);
    context.clip();
    draw('rx', styles.getPropertyValue('--green').trim() || '#57c785');
    draw('tx', styles.getPropertyValue('--primary').trim() || '#1254d6');
    context.restore();
  };

  const stop = (message) => {
    window.clearTimeout(timer);
    timer = 0;
    request?.abort();
    request = null;
    if (message) setState(message);
  };

  const schedule = () => {
    if (!pageActive() || running || timer) return;
    const elapsed = Math.max(0, performance.now() - lastStartedAt);
    timer = window.setTimeout(() => {
      timer = 0;
      void poll();
    }, Math.max(0, POLL_INTERVAL_MS - elapsed));
  };

  const pollSingleInterface = async (signal) => {
    const response = await window.linnmsAjax.request(
      `${liveUrl}?type=device-traffic&ref=${encodeURIComponent(deviceRef)}&interface=${encodeURIComponent(interfaceName)}`,
      { credentials: 'same-origin', cache: 'no-store', signal }
    );
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'Trafik tidak tersedia');
    const rx = document.querySelector('[data-device-rx]');
    const tx = document.querySelector('[data-device-tx]');
    if (rx) rx.textContent = formatRate(data.rx_bps);
    if (tx) tx.textContent = formatRate(data.tx_bps);
    if (Number.isFinite(Number(data.rx_bps)) && Number.isFinite(Number(data.tx_bps))) {
      history.push({ rx: Math.max(0, Number(data.rx_bps)), tx: Math.max(0, Number(data.tx_bps)) });
      if (history.length > 60) history.shift();
      drawChart();
    }
  };

  const pollInterfaceTable = async (signal) => {
    const rows = Array.from(document.querySelectorAll('[data-interface-rate-row][data-if-index]'))
      .filter((row) => !row.hidden);
    const indexes = Array.from(new Set(rows.map((row) => Number.parseInt(row.dataset.ifIndex || '', 10))))
      .filter((index) => Number.isFinite(index) && index > 0)
      .slice(0, 256);
    if (indexes.length === 0) return;
    const response = await window.linnmsAjax.request(
      `${liveUrl}?type=device-interface-rates&ref=${encodeURIComponent(deviceRef)}&indexes=${encodeURIComponent(indexes.join(','))}`,
      { credentials: 'same-origin', cache: 'no-store', signal }
    );
    const data = await response.json();
    if (!response.ok || !data.ok || typeof data.rates !== 'object') {
      throw new Error(data.message || 'Trafik tidak tersedia');
    }
    rows.forEach((row) => {
      const rate = data.rates[String(row.dataset.ifIndex)];
      const rx = row.querySelector('[data-interface-rx]');
      const tx = row.querySelector('[data-interface-tx]');
      if (rx) rx.textContent = formatRate(rate?.rx_bps);
      if (tx) tx.textContent = formatRate(rate?.tx_bps);
    });
  };

  async function poll() {
    if (!pageActive() || running) return;
    if (!liveUrl) {
      stop('Endpoint realtime belum tersedia');
      return;
    }
    running = true;
    lastStartedAt = performance.now();
    request = new AbortController();
    try {
      if (interfaceName) await pollSingleInterface(request.signal);
      else await pollInterfaceTable(request.signal);
      setState(`${realtimeLabel} diperbarui setiap detik`);
    } catch (error) {
      if (error?.name !== 'AbortError') setState(`${realtimeLabel} belum dapat dibaca`);
    } finally {
      request = null;
      running = false;
      schedule();
    }
  }

  const markActive = (force = false) => {
    const now = Date.now();
    if (!force && !idle && now - lastActivityAt < 1000) return;
    lastActivityAt = now;
    idle = false;
    window.clearTimeout(idleTimer);
    idleTimer = window.setTimeout(() => {
      idle = true;
      stop(`Polling ${realtimeLabel} berhenti karena tidak ada aktivitas`);
    }, IDLE_TIMEOUT_MS);
    schedule();
  };

  ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'wheel'].forEach((eventName) => {
    window.addEventListener(eventName, () => markActive(false), { passive: true });
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop(`Polling ${realtimeLabel} berhenti saat halaman tidak aktif`);
    else markActive(true);
  });
  window.addEventListener('blur', () => stop(`Polling ${realtimeLabel} berhenti saat halaman tidak aktif`));
  window.addEventListener('focus', () => markActive(true));
  window.addEventListener('pagehide', () => stop());
  window.addEventListener('resize', drawChart);

  markActive(true);
  drawChart();
  void poll();
})();
