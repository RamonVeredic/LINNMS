(() => {
  'use strict';

  const data = (type, params = {}) => {
    const query = new URLSearchParams({ type, ...params });
    return `data.php?${query}`;
  };

  window.linnmsDashboardAjax = Object.freeze({
    scanStatus: (rowId) => window.linnmsAjax.request(data('olt-scan-status', { olt_row_id: rowId }), { cache: 'no-store' }),
    connectionStatus: (ids) => window.linnmsAjax.request(data('device-connection-status', { ids: ids.join(',') }), { cache: 'no-store' }),
    recentActivity: (params, signal) => window.linnmsAjax.request(`data.php?${params}`, { cache: 'no-store', signal }),
    startDiscovery: (form) => window.linnmsAjax.request((document.body.dataset.liveUrl || 'live.php') + '?type=start-olt-discovery', {
      method: 'POST', cache: 'no-store', body: new FormData(form),
    }),
  });
})();
