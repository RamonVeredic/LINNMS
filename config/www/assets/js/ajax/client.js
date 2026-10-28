(() => {
  'use strict';

  const request = (url, options = {}) => window.fetch(url, {
    credentials: 'same-origin',
    ...options,
  });

  window.linnmsAjax = Object.freeze({ request });
})();
