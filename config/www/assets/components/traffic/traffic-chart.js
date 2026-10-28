(() => {
  'use strict';

  if (typeof Chart === 'undefined') return;

  const POINT_RADIUS =1;
  const POINT_HOVER_RADIUS = 3;
  const POINT_HIT_RADIUS = 10;
  const MAX_POINTS = 12;
  const Y_STEP = 1;
  const Y_LINES = 10;
  const LOWER_INTERVALS = 4;
  const niceStep = (value) => {
    if (!Number.isFinite(value) || value <= 0) return 1;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    const scaled = value / magnitude;
    const factor = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10;
    return factor * magnitude;
  };
  const axisPosition = (value, axis) => {
    const lowerStep = axis.lowerStep ?? Y_STEP;
    const hinge = axis.base + LOWER_INTERVALS * lowerStep;
    return value <= hinge
      ? (value - axis.base) / lowerStep
      : LOWER_INTERVALS + (value - hinge) / axis.upperStep;
  };
  const axisValue = (position, axis) => {
    const lowerStep = axis.lowerStep ?? Y_STEP;
    return position <= LOWER_INTERVALS
      ? axis.base + position * lowerStep
      : axis.base + LOWER_INTERVALS * lowerStep + (position - LOWER_INTERVALS) * axis.upperStep;
  };
  const liveUrl = document.body.dataset.liveUrl || 'live.php';
  const cssVar = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
  const rxColor = cssVar('--cyan', '#4fd1c5');
  const txColor = cssVar('--amber', '#e5a95a');
  const muted = cssVar('--text-muted', '#a3aab8');
  const gridColor = cssVar('--bg-line', '#4a4f57');
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[char]));
  const normRef = (ref) => String(ref).trim()
    .replace(/^(?:gpon|epon)(?:-olt)?[_/]?/i, '')
    .replace(/_/g, '/');

  const formatRate = (value) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric < 0) return '—';
    const units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
    let rate = numeric;
    let unit = 0;
    while (rate >= 1000 && unit < units.length - 1) { rate /= 1000; unit += 1; }
    const text = rate >= 100 ? rate.toFixed(0) : rate.toFixed(2);
    return `${Number(text)} ${units[unit]}`;
  };
  const shortRate = (value) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric < 0) return '—';
    if (numeric >= 1e9) return `${Number((numeric / 1e9).toFixed(1))} G`;
    if (numeric >= 1e6) return `${Number((numeric / 1e6).toFixed(1))} M`;
    if (numeric >= 1e3) return `${Number((numeric / 1e3).toFixed(1))} K`;
    return String(Math.round(numeric));
  };
  const timeLabel = (stamp, range) => {
    const date = new Date(Number(stamp) * 1000);
    if (range >= 86400) {
      return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')} `
        + `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    }
    if (range >= 3600) {
      return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    }
    return [date.getHours(), date.getMinutes(), date.getSeconds()]
      .map((part) => String(part).padStart(2, '0')).join(':');
  };

  document.querySelectorAll('[data-traffic]').forEach((root) => {
    const mode = String(root.dataset.trafficMode || '');
    if (!['pon', 'onu', 'device'].includes(mode)) return;
    const rowId = String(root.dataset.trafficRowId || '');
    const onuId = String(root.dataset.trafficOnuId || '');
    const deviceId = String(root.dataset.trafficDeviceId || '');
    const state = root.querySelector('[data-traffic-state]');
    const rangeButtons = [...root.querySelectorAll('[data-traffic-range]')];
    const deviceEmpty = mode === 'device' ? root.querySelector('[data-device-traffic-empty]') : null;
    const deviceRanges = mode === 'device' ? root.querySelector('.pon-traffic-ranges') : null;
    const deviceGrid = mode === 'device' ? root.querySelector('[data-traffic-grid]') : null;
    let range = Number(rangeButtons.find((button) => button.classList.contains('is-active'))?.dataset.trafficRange) || 180;
    const cards = new Map();
    let lastData = new Map();
    let timer = null;

    const setState = (message) => {
      if (!state) return;
      state.textContent = message;
      state.hidden = message === '';
    };

    const chartOptions = (card) => {
      const canvas = card.querySelector('[data-traffic-canvas]');
      const tooltipBox = card.querySelector('[data-traffic-tooltip]');
      const tooltipTime = card.querySelector('[data-traffic-tooltip-time]');
      const tooltipRx = card.querySelector('[data-traffic-tooltip-rx]');
      const tooltipTx = card.querySelector('[data-traffic-tooltip-tx]');
      return {
        canvas,
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          interaction: { intersect: false, mode: 'index' },
          plugins: {
            legend: { display: false },
            tooltip: {
              enabled: false,
              external: ({ tooltip }) => {
                if (!tooltipBox || tooltip.opacity === 0) {
                  if (tooltipBox) tooltipBox.hidden = true;
                  return;
                }
                tooltipTime.textContent = tooltip.title[0] || '';
                for (const point of tooltip.dataPoints) {
                  const output = point.datasetIndex === 0 ? tooltipRx : tooltipTx;
                  const original = canvas.__rawPoints?.[point.dataIndex];
                  const rate = original?.[point.datasetIndex === 0 ? 'rx' : 'tx'];
                  output.textContent = point.dataset.label + ': ' + formatRate(rate);
                }
                tooltipBox.hidden = false;
                const wrap = tooltipBox.parentElement;
                const halfWidth = tooltipBox.offsetWidth / 2;
                const x = canvas.offsetLeft + tooltip.caretX;
                const y = canvas.offsetTop + tooltip.caretY;
                tooltipBox.style.left = Math.max(halfWidth, Math.min(x, wrap.clientWidth - halfWidth)) + 'px';
                tooltipBox.style.top = (y + tooltipBox.offsetHeight + 8 <= wrap.clientHeight
                  ? y + 8 : Math.max(0, y - tooltipBox.offsetHeight - 8)) + 'px';
                tooltipBox.style.transform = 'translateX(-50%)';
              },
            },
          },
          scales: {
            x: { type: 'category', display: false },
            y: {
              beginAtZero: true,
              min: 0,
              max: Y_LINES - 1,
              position: 'right',
              ticks: { color: muted, count: Y_LINES, stepSize: 1, autoSkip: false, callback: (value) => shortRate(axisValue(value, canvas.__axis || { base: 0, upperStep: Y_STEP })) },
              grid: { color: gridColor },
              border: { display: false },
            },
          },
        },
      };
    };

    const createCard = (ref, title, icon, role = '') => {
      const key = mode === 'onu' ? '__onu__' : normRef(ref);
      if (cards.has(key)) return cards.get(key);
      const grid = root.querySelector('[data-traffic-grid]');
      if (!grid) return null;
      const card = document.createElement('article');
      card.className = 'pon-traffic-card';
      card.dataset.trafficCard = '';
      card.dataset.trafficRef = String(ref);
      card.dataset.trafficRole = String(role || '');
      card.innerHTML = '<header class="pon-traffic-card-head">'
        + '<span class="traffic-card-title"><i class="bi ' + escapeHtml(icon || 'bi-hdd-network') + '" aria-hidden="true"></i> <span class="mono">' + escapeHtml(title || ref) + '</span></span>'
        + '<span class="pon-traffic-values">'
        + '<small class="pon-traffic-rx"><i class="bi bi-cloud-download" aria-hidden="true"></i> <b data-traffic-rx>—</b></small>'
        + '<small class="pon-traffic-tx"><i class="bi bi-cloud-upload" aria-hidden="true"></i> <b data-traffic-tx>—</b></small>'
        + '</span></header>'
        + '<div class="pon-traffic-canvas-wrap">'
        + '<span class="pon-traffic-loading" data-traffic-loading><span class="onu-loading-badge"><i class="bi bi-arrow-repeat sync-loading-spinner" aria-hidden="true"></i></span></span>'
        + '<canvas class="pon-traffic-canvas" data-traffic-canvas height="150"></canvas>'
        + '<div class="traffic-tooltip" data-traffic-tooltip hidden aria-hidden="true">'
        + '<span data-traffic-tooltip-time></span>'
        + '<span><i class="bi bi-cloud-download" aria-hidden="true"></i><span data-traffic-tooltip-rx></span></span>'
        + '<span><i class="bi bi-cloud-upload" aria-hidden="true"></i><span data-traffic-tooltip-tx></span></span>'
        + '</div></div>';
      if (mode === 'pon' && role === 'uplink') {
        const firstOther = [...grid.children].find((item) => item.dataset.trafficRole !== 'uplink');
        grid.insertBefore(card, firstOther || null);
      } else {
        grid.append(card);
      }
      return registerCard(card, key);
    };

    const registerCard = (card, key) => {
      if (cards.has(key)) return cards.get(key);
      const { canvas, options } = chartOptions(card);
      const chart = new Chart(canvas, {
        type: 'line',
        data: {
          labels: [],
          datasets: [
            { label: 'Rx', data: [], borderColor: rxColor, backgroundColor: `${rxColor}30`, fill: true, borderWidth: 1.5, tension: 0.2, pointRadius: POINT_RADIUS, pointHoverRadius: POINT_HOVER_RADIUS, pointHitRadius: POINT_HIT_RADIUS, pointBackgroundColor: rxColor, pointBorderColor: rxColor },
            { label: 'Tx', data: [], borderColor: txColor, backgroundColor: `${txColor}30`, fill: true, borderWidth: 1.5, tension: 0.2, pointRadius: POINT_RADIUS, pointHoverRadius: POINT_HOVER_RADIUS, pointHitRadius: POINT_HIT_RADIUS, pointBackgroundColor: txColor, pointBorderColor: txColor },
          ],
        },
        options,
      });
      const entry = {
        card,
        chart,
        rx: card.querySelector('[data-traffic-rx]'),
        tx: card.querySelector('[data-traffic-tx]'),
        loading: card.querySelector('[data-traffic-loading]'),
      };
      cards.set(key, entry);
      return entry;
    };

    [...root.querySelectorAll('[data-traffic-card]')].forEach((card) => {
      registerCard(card, mode === 'onu' ? '__onu__' : normRef(card.dataset.trafficRef || ''));
    });

    const placeCard = (entry, role) => {
      if (mode !== 'pon' || !entry) return;
      if (role !== 'uplink') return;
      const grid = root.querySelector('[data-traffic-grid]');
      if (!grid) return;
      const firstOther = [...grid.children].find((item) => item !== entry.card && item.dataset.trafficRole !== 'uplink');
      grid.insertBefore(entry.card, firstOther || null);
    };

    const syncDeviceEmpty = () => {
      if (mode !== 'device') return;
      const empty = cards.size === 0;
      if (deviceEmpty) deviceEmpty.hidden = !empty;
      if (deviceRanges) deviceRanges.hidden = empty;
      if (deviceGrid) deviceGrid.hidden = empty;
      if (empty) setState('');
    };

    const renderSeries = (key, series) => {
      const entry = cards.get(key);
      if (!entry) return;
      const valid = (Array.isArray(series) ? series : [])
        .map((point) => ({ at: Number(point.at), rx: Number(point.rx ?? point.rx_bps), tx: Number(point.tx ?? point.tx_bps) }))
        .filter((point) => point.at > 0 && Number.isFinite(point.rx) && Number.isFinite(point.tx));
      const points = valid.slice(-MAX_POINTS);
      const peak = points.reduce((max, point) => Math.max(max, point.rx, point.tx), 0);
      const step = niceStep(peak / (Y_LINES - 1));
      const axis = { base: 0, lowerStep: step, upperStep: step };
      entry.chart.canvas.__axis = axis;
      entry.chart.canvas.__rawPoints = points;
      entry.chart.data.labels = points.map((point) => timeLabel(point.at, range));
      entry.chart.data.datasets[0].data = points.map((point) => axisPosition(point.rx, axis));
      entry.chart.data.datasets[1].data = points.map((point) => axisPosition(point.tx, axis));
      entry.chart.update('none');
      const latest = valid.at(-1);
      entry.rx.textContent = latest ? formatRate(latest.rx) : '—';
      entry.tx.textContent = latest ? formatRate(latest.tx) : '—';
      if (entry.loading) entry.loading.hidden = true;
    };

    const render = () => {
      cards.forEach((entry, key) => {
        renderSeries(key, lastData.get(key) || []);
      });
    };

    const load = async () => {
      try {
        let endpoint = '';
        if (mode === 'pon') endpoint = `${liveUrl}?type=pon-traffic-live&olt_row_id=${encodeURIComponent(rowId)}`;
        else if (mode === 'onu') endpoint = `${liveUrl}?type=onu-traffic-live&onu_row_id=${encodeURIComponent(onuId)}`;
        else endpoint = `${liveUrl}?type=device-traffic-history&device_id=${encodeURIComponent(deviceId)}`;
        const response = await window.linnmsAjax.request(endpoint, { credentials: 'same-origin', cache: 'no-store' });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.ok !== true) {
          throw new Error((payload && payload.message) || 'Trafik belum dapat dibaca.');
        }
        lastData = new Map();
        if (mode === 'pon') {
          for (const [rawRef, series] of Object.entries(payload.series || {})) {
            const key = normRef(rawRef);
            const role = (payload.roles && payload.roles[rawRef]) ? String(payload.roles[rawRef]) : '';
            const entry = cards.get(key) || createCard(rawRef, rawRef, role === 'uplink' ? 'bi-arrow-up-right-circle' : 'bi-hdd-network', role);
            if (entry) {
              lastData.set(key, series);
              placeCard(entry, role);
            }
          }
        } else if (mode === 'device') {
          for (const [rawRef, data] of Object.entries(payload.interfaces || {})) {
            const key = normRef(rawRef);
            const entry = cards.get(key) || createCard(rawRef, rawRef, 'bi-ethernet');
            if (entry) lastData.set(key, data?.series || []);
          }
        } else {
          lastData.set('__onu__', payload.series || []);
        }
        setState('');
        render();
      } catch (error) {
        setState(String(error?.message || 'Trafik belum dapat dibaca.'));
      }
    };

    rangeButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const next = Number(button.dataset.trafficRange);
        if (!Number.isFinite(next) || next === range) return;
        range = next;
        rangeButtons.forEach((item) => item.classList.toggle('is-active', item === button));
        render();
      });
    });

    document.addEventListener('linnms:traffic-record-updated', (event) => {
      if (!['pon', 'device'].includes(mode)) return;
      const targetId = mode === 'pon' ? rowId : deviceId;
      if (String(event.detail?.deviceId || '') !== targetId) return;
      const ref = String(event.detail?.ifRef || '');
      if (ref === '') return;
      const key = normRef(ref);
      if (event.detail.enabled) {
        createCard(ref, ref, mode === 'pon' ? 'bi-hdd-network' : 'bi-ethernet', '');
        syncDeviceEmpty();
        void load();
        return;
      }
      const entry = cards.get(key);
      if (!entry) return;
      entry.chart.destroy();
      entry.card.remove();
      cards.delete(key);
      lastData.delete(key);
      syncDeviceEmpty();
    });

    const interval = mode === 'device' ? 1000 : 3000;
    const start = () => {
      if (timer !== null) return;
      void load();
      timer = window.setInterval(() => { if (!document.hidden) void load(); }, interval);
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) void load(); });
    start();
  });
})();
