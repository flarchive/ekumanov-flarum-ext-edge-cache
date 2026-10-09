import app from 'flarum/forum/app';

// Mithril is not in flarum-webpack-config's externals map; core exposes the
// global `m`, which is what the compiled core bundle itself uses at runtime.
const m = window.m;

/**
 * CSRF recovery for edge-cached guest pages.
 *
 * A guest landing on a Cloudflare-cached page has no session cookie and a
 * stale CSRF token baked into the cached payload. Their first POST (login,
 * register, any XHR write) is rejected with a 400 CSRF mismatch — and that
 * error response carries NO fresh token: the TokenMismatchException unwinds
 * past StartSession, so neither Set-Cookie nor X-CSRF-Token are attached to
 * it. A manual retry or hard refresh re-serves the same cached page with the
 * same stale token, so without this shim the guest is stuck until purge/TTL.
 *
 * We detect the failure by the JSON:API error code: 400 with
 * `error.response.errors[].code === 'csrf_token_mismatch'`. This covers login
 * and register as well as /api writes. Login and register POST to the forum
 * routes baseUrl+'/login' / '/register' (core's Session.login / SignUpModal),
 * but on Flarum 2.0 the forum error handler content-negotiates
 * (ContentNegotiationFormatter → RequestUtil::isApiRequest): an XHR — which
 * core's app.request sends with the browser's default catch-all Accept, since
 * core never sets Accept and Mithril only sets its own when `deserialize`
 * isn't a function — is treated as an API request and receives the same
 * JSON:API error body. (Only a real navigation, with an Accept preferring
 * text/html, gets the HTML error page; login and register are never
 * navigations.) Verified in-browser against 2.0.0-rc.4.
 *
 * Recovery: one cheap GET to the API root. Its 2xx response DOES pass through
 * StartSession, which sets a fresh session cookie, and core's request
 * extract() already updates app.session.csrfToken from the X-CSRF-Token
 * response header. Then the original request is retried once
 * (transformRequestOptions re-reads the fresh token at send time).
 *
 * The refresh is single-flight: concurrent boot-time failures (e.g. several
 * widgets POSTing at once) share one refresh GET.
 *
 * NOTE: the refresh MUST target an API route. A forum HTML route would have
 * its Set-Cookie stripped by our own EdgeCacheMiddleware.
 */
app.initializers.add('ekumanov-edge-cache', () => {
  const originalCatch = app.requestErrorCatch.bind(app);
  let refreshing = null;

  app.requestErrorCatch = (error, customErrorHandler) => {
    const isCsrfMismatch =
      error?.status === 400 && !!error?.response?.errors?.some((e) => e.code === 'csrf_token_mismatch');

    const options = error?.options;

    if (!isCsrfMismatch || !options || options.edgeCacheCsrfRetried) {
      return originalCatch(error, customErrorHandler);
    }

    if (!refreshing) {
      refreshing = app
        .request({ method: 'GET', url: app.forum.attribute('apiUrl') })
        .finally(() => {
          refreshing = null;
        });
    }

    return refreshing.then(
      () => {
        // Retry with the SAME (already-transformed) options via bare
        // m.request: re-passing them through app.request would chain the
        // config callback a second time, making XHR comma-join two
        // X-CSRF-Token headers — which the server rejects. The transformed
        // options' config closure reads app.session.csrfToken at send time,
        // so the retry picks up the refreshed token; extract/method-override
        // behavior is preserved. The flag must live on this same object:
        // the extract closure throws RequestError with it, which is what a
        // failed retry hands back to this catch — guaranteeing single retry.
        options.edgeCacheCsrfRetried = true;

        // A failed retry must still surface through the default handler
        // (alert + rethrow), since bare m.request has no catch attached.
        return m.request(options).catch((retryError) => originalCatch(retryError, customErrorHandler));
      },
      // Refresh itself failed: surface the original error through the
      // default handler rather than masking it.
      () => originalCatch(error, customErrorHandler)
    );
  };
});
