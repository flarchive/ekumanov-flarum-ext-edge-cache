# Edge Cache (guest pages) — ekumanov/flarum-ext-edge-cache

Makes guest page views cookieless so Cloudflare can safely cache guest HTML at
the edge, plus a client-side CSRF retry shim so auth flows survive landing on a
cached page. It also preloads the discussion page's boot-critical JS chunks, and
can purge Cloudflare on every post change so a long edge TTL never serves stale
content. Requires Flarum 2.0.

## Installation

```sh
composer require ekumanov/flarum-ext-edge-cache
```

## Components

1. **EdgeCacheMiddleware** (forum frontend, inserted *before* `StartSession` —
   StartSession attaches cookies on the response's way OUT, so only an outer
   middleware can strip them): credential-less GET/HEAD on allowlisted paths →
   strip ALL `Set-Cookie` + `X-CSRF-Token`, emit a `Server-Timing: origin`
   header and `Cache-Control: public, s-maxage=<ttl>, max-age=0,
   must-revalidate`. The edge TTL is **3600s when Cloudflare purge credentials
   are configured** (see below) — purge-on-write keeps the long tail warm
   without stale content — and a conservative **300s otherwise**. All other
   forum HTML → explicit `Cache-Control: private, no-store`.
2. **JS retry shim**: on `400 csrf_token_mismatch`, single-flight `GET /api`
   (refreshes session cookie + token via core's response-header update), retry
   the original request once.
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
   restored), queues a purge of that discussion's canonical landing URL. Lets
   the edge TTL stay long without guests seeing stale pages. Best-effort and
   queued, so a Cloudflare hiccup never blocks the user's action; a no-op (with
   a log line) when no credentials are configured.

## The matching Cloudflare Cache Rule (v1)

Expression: host eq "example.com" AND starts_with(path, "/d/") AND
method GET AND NOT (cookie contains "flarum_session" OR cookie contains
"flarum_remember") → Eligible for cache, **Edge TTL: respect origin**,
Browser TTL: respect origin. Adjust the host and the path prefix to your
install (e.g. `/forum/d/` when Flarum is mounted under `/forum`).

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

## Invariants — read before changing anything

- The middleware path allowlist and the CF rule scope move **in lockstep, in
  the same deploy**.
- API responses must keep their Set-Cookie forever (heartbeat session-dedupe
  and the shim's refresh GET depend on it). This middleware is forum-only.
- `/reset`, `/confirm` etc. are server-rendered Blade forms needing their
  session cookie — permanently denylisted.
- Adding any guest-facing language switcher silently poisons the cache (CF
  ignores `Vary`) — revisit the rule before shipping one.
- CSRF 400s never reach flarum.log (KnownError) — monitor nginx access-log
  double-400s instead.

## Rollback order

Disable this extension → clear the Flarum cache and purge the Cloudflare cache
**immediately** (cached HTML referencing a rebuilt forum.js without the shim
would otherwise strand guests until TTL expiry). Deleting the CF rule is safe
at any point, in any order.

## Build

```sh
cd js && npm install && npm run build
```
