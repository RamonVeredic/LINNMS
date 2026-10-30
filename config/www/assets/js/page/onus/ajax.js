(() => {
  'use strict';

  window.linnmsOnusAjax = Object.freeze({
    load: (params, signal) => {
      const url = new URL('onu-list.php', location.href);
      Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
      return window.linnmsAjax.request(url, { signal });
    },
  });
})();
