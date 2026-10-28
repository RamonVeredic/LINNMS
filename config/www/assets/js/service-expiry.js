(() => {
  const body = document.body;
  const expiration = Number(body.dataset.serviceExpiresAt) * 1000;
  if (!Number.isFinite(expiration) || expiration <= 0) return;

  const expiredUrl = new URL(body.dataset.expiredPage || '/expired.html', window.location.href);

  const checkExpiration = () => {
    const remaining = expiration - Date.now();
    if (remaining <= 0) {
      window.location.replace(expiredUrl.toString());
      return;
    }
    window.setTimeout(checkExpiration, Math.min(remaining, 2147483647));
  };

  checkExpiration();
})();
