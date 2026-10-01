{{--
  The pages talk to /api with fetch(). When the session has expired those calls
  return 401 (logged out) or 419 (stale CSRF token) and the UI used to just stop
  working. Send the user to log in again, or reload for a fresh token.
--}}
<script>
(() => {
  const nativeFetch = window.fetch.bind(window);
  let leaving = false;
  window.fetch = async (input, init) => {
    const res = await nativeFetch(input, init);
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    if (!leaving && url.origin === location.origin && url.pathname.startsWith('/api/')) {
      if (res.status === 401) { leaving = true; location.href = '/login?expired=1'; }
      if (res.status === 419) { leaving = true; location.reload(); }
    }
    return res;
  };
})();
</script>
