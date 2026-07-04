# Edge Cache (guest pages) — ekumanov/flarum-ext-edge-cache

Makes guest page views cookieless so Cloudflare can safely cache guest HTML —
discussion pages, the index, and tag pages — at the edge, plus a client-side
CSRF retry shim so auth flows survive landing on a cached page. It also
preloads the discussion page's boot-critical JS chunks, emits `Link:
rel=preload` headers for Cloudflare Early Hints, can purge Cloudflare on
every content change so a long edge TTL never serves stale content, and
pre-paints the server-rendered discussion content for guests so the first
post is visible (and is the LCP) at first paint instead of after the SPA
boots. Requires Flarum 2.0.

## Installation

```sh
composer require ekumanov/flarum-ext-edge-cache
```

## Components

1. **EdgeCacheMiddleware** (forum frontend, inserted *before* `StartSession` —
   StartSession attaches cookies on the response's way OUT, so only an outer
   middleware can strip them): credential-less GET/HEAD on an allowlisted path
   (`/d/*`, the index `/`, and `/t/*`) →
   strip ALL `Set-Cookie` + `X-CSRF-Token`, emit a `Server-Timing: origin`
   header and `Cache-Control: public, s-maxage=<ttl>, max-age=0,
   must-revalidate`. The edge TTL is **3600s when Cloudflare purge credentials
   are configured** (see below) — purge-on-write keeps the long tail warm
   without stale content — and a conservative **300s otherwise**. All other
   forum HTML → explicit `Cache-Control: private, no-store`.
2. **JS retry shim**: on a `400` whose JSON:API body carries
   `code: csrf_token_mismatch`, single-flight `GET /api` (refreshes session
   cookie + token via core's response-header update), then retry the original
   request once. This covers `/api/*` writes as well as login and register:
   although those POST to forum routes, Flarum 2.0's forum error handler
   content-negotiates, so an XHR (default catch-all `Accept`) receives the same
   JSON:API error the shim matches.
3. **CSRF exemption** for `forum-widgets.guest-heartbeat`, the guest presence
   beacon of ekumanov/flarum-ext-forum-widgets (spoofable anyway, and the
   highest-frequency 400 source on cached pages). A no-op when that extension
   isn't installed.
4. **Discussion chunk preload**: on `/d/*`, emits `<link rel="preload"
   as="script">` for core's `PostStream.js` + `PostStreamScrubber.js` (hashes
   read from `rev-manifest.json` at runtime, so the preload URL always matches
   what the webpack runtime fetches — no double download). These chunks are
   otherwise fetched serially *after* boot; preloading lets them download in
   parallel with `forum.js`, collapsing that render-delay tail. Helps guests and
   logged-in members alike.
5. **Cloudflare purge-on-write**: when a discussion's content changes (post
   added/edited/deleted/hidden/restored, discussion renamed/deleted/hidden/
   restored), queues a purge of every cached page the write invalidates: the
   discussion's canonical landing URL, its slugless `/d/{id}` variant (served
   directly, so it caches under its own key), the forum index, and the landing
   pages of its tags and their parents. Lets the edge TTL stay long without
   guests seeing stale pages. Best-effort and queued, so a Cloudflare hiccup
   never blocks the user's action; a no-op (with a log line) when no
   credentials are configured.
6. **Early Hints `Link` headers**: every 200 HTML response — cacheable or
   private — carries `Link: <…>; rel=preload` headers for `forum.css`,
   `forum.js`, the active locale bundle, and (on `/d/*`) the two PostStream
   chunks. With **Early Hints** enabled on Cloudflare (Speed → Optimization;
   available on the Free plan) these are replayed as a `103 Early Hints`
   response while the origin is still rendering, so the browser downloads the
   render-critical assets during server think-time. That helps most exactly
   where the edge cache can't: logged-in members (always DYNAMIC) and
   cold-MISS guests. Browsers de-duplicate against the identical in-HTML
   references, so where 103 isn't supported the headers are inert.
7. **Visible pre-paint** (guests, `/d/*`): Flarum already server-renders the
   discussion title + posts into `<noscript id="flarum-content">` — JS-enabled
   browsers never paint it. This swaps the content wrapper view so that same
   markup is emitted as a visible, styled block instead, and an inline
   MutationObserver removes it on the first childList mutation of `#content` —
   a microtask, i.e. in the same rendering frame in which Mithril's first
   render lands, so exactly one of (pre-paint | hydrated page) is ever
   painted. The first post's text becomes the LCP at ≈first paint; the
   hydrated repaint is equal-or-smaller so it cannot re-trigger LCP (removed
   elements remain LCP candidates in Chromium). In lab testing at a
   phone-calibrated CPU throttle this collapsed the LCP−FCP gap from ~3s to
   0 (LCP −58%) with zero CLS across the swap. If JS never boots, the block
   simply stays — a strictly better no-JS story than core's `<noscript>`.
   Media discipline while the block is visible: images wrapped by
   [ekumanov/flarum-ext-cls-fix](https://github.com/ekumanov/flarum-ext-cls-fix)
   keep their reserved aspect-ratio boxes (its CSS is unscoped on purpose),
   emoji are size-stable via CSS, s9e MediaEmbed iframes sit in responsive
   padding-box wrappers and are `loading="lazy"`; any other unsized `<img>` is
   hidden inside the pre-paint, which only shortens the block — the safe
   direction. Post images additionally get their `src` renamed in the
   pre-paint copy (the placeholder box stays) so nothing fetches during the
   critical window and the hydration timeline is byte-identical to a
   non-pre-painted load, and below-fold posts are render-skipped via
   `content-visibility: auto` so a 20-post page costs no more main-thread
   time than a short one. Members are never pre-painted (their hydrated page
   differs from the guest render).

## The matching Cloudflare Cache Rule (v2)

Expression: host eq "example.com" AND (path eq "/" OR starts_with(path, "/d/")
OR starts_with(path, "/t/")) AND method GET AND NOT (cookie contains
"flarum_session" OR cookie contains "flarum_remember" OR cookie contains
"locale") → Eligible for cache, **Edge TTL: respect origin**, Browser TTL:
respect origin. Adjust the host and the paths to your install (e.g. path eq
"/forum/" plus prefixes `/forum/d/`, `/forum/t/` when Flarum is mounted under
`/forum`). The `locale` clause keeps a language-switched guest render off the
shared cache — it matches both the bare `locale` cookie and prefixed variants
like `flarum_locale`, in lockstep with the origin (see Invariants).

Upgrading from the v1 rule (`/d/` only): deploying the extension first is
safe — Cloudflare doesn't cache HTML without a matching rule, so the wider
origin allowlist is inert for the extra paths until the rule covers them.
Extend the rule right after deploying, then confirm `cf-cache-status:
MISS → HIT` on `/`. Enable **Early Hints** (Speed → Optimization) at the same
time to activate the `Link`-header preloads.

## Cloudflare credentials (for purge-on-write + the long TTL)

Add to `config.php` (kept server-side, never exposed to the frontend):

```php
'cloudflare' => [
    'zone_id'   => '...',
    'api_token' => '...', // a token scoped to Zone → Cache Purge for this zone
],
```

Create a scoped token in the Cloudflare dashboard (My Profile → API Tokens →
Create Custom Token → permission **Zone : Cache Purge**, restricted to this
zone) — do not use a Global API Key. Without these keys the extension keeps the
conservative 300s edge TTL and the purge listener is a logged no-op, so it is
safe to install before (or without) Cloudflare.

## Known staleness

Purge-on-write fires on discussion **content** events only (post
added/edited/deleted/hidden/restored, discussion renamed/deleted/hidden/
restored). Anything else embedded in a cached guest payload refreshes only
within the edge TTL, not instantly — e.g. sticky/lock state, tag moves, poll
votes, and like counts. Likewise, after a rename the old-slug URL keeps
serving its cached page (then 301s) until it ages out under the TTL; after a
re-tag, the OLD tag's landing page waits for the TTL (or the next write that
touches that tag). Index/tag sort variants (`?sort=…`) cache under their own
keys and are not purge-enumerable either. All of this is bounded by the edge
TTL by design; the TTL is the freshness floor for everything the purge list
doesn't enumerate.

## Invariants — read before changing anything

- The middleware path allowlist and the CF rule scope move **in lockstep, in
  the same deploy**.
- API responses must keep their Set-Cookie forever (heartbeat session-dedupe
  and the shim's refresh GET depend on it). This middleware is forum-only.
- `/reset`, `/confirm` etc. are server-rendered Blade forms needing their
  session cookie — permanently denylisted.
- A guest-facing language switcher would poison the shared cache (CF ignores
  `Vary`). Origin-side this is now enforced — a request carrying a `locale`
  cookie is served `private, no-store` — but keep the CF rule in lockstep (it
  must also exclude the `locale` cookie, as above).
- CSRF 400s never reach flarum.log (KnownError) — monitor nginx access-log
  double-400s instead.
- The pre-paint's LCP lock depends on one geometry rule: the pre-paint stack
  above the first post must sit equal-or-HIGHER than the hydrated one (the
  CSS deliberately undershoots by ~10px), so the pre-paint copy of the LCP
  paragraph is always the equal-or-larger paint. If a theme change makes the
  pre-paint sit lower, nothing breaks visually — but LCP silently returns to
  hydration time. Re-verify after theme/hero changes (the class docblock in
  `PrePaintDiscussion` documents the tuning).
- The pre-paint view must keep the `#flarum-loading`, `#flarum-loading-error`
  and `#flarum-content` element ids — core's boot scripts `getElementById`
  them unconditionally.

## Rollback order

Disable this extension → clear the Flarum cache and purge the Cloudflare cache
**immediately** (cached HTML referencing a rebuilt forum.js without the shim
would otherwise strand guests until TTL expiry). Deleting the CF rule is safe
at any point, in any order.

## Build

```sh
cd js && npm install && npm run build
```
