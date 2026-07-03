<?php

namespace Ekumanov\EdgeCache;

use Flarum\Foundation\Config;
use Flarum\Http\CookieFactory;
use Flarum\Locale\LocaleManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Makes guest page views cookieless so Cloudflare can cache them, and makes
 * the origin authoritative about what may be cached.
 *
 * Cacheable  = credential-less GET/HEAD on an allowlisted path, 200, text/html.
 *              -> strip ALL Set-Cookie + the X-CSRF-Token header, emit
 *              Cache-Control: public so the CF cache rule (Edge TTL: respect
 *              origin) stores it.
 * Everything else HTML -> explicit Cache-Control: private, no-store, so a
 *              mis-scoped CF rule can never cache a personalised response.
 *
 * Every 200 HTML response — cacheable or private — additionally gets `Link:
 * rel=preload` headers for the render-critical assets (forum.css, forum.js,
 * the locale bundle, and on /d/* the PostStream chunks). With Cloudflare
 * Early Hints enabled these are replayed as a 103 while the origin is still
 * thinking, so the browser downloads CSS/JS in parallel with the server
 * render — the biggest win exactly where the edge cache can't help: members
 * (always DYNAMIC) and cold-MISS guests.
 *
 * INVARIANTS (do not break):
 * - The path allowlist below and the CF cache-rule expression move in
 *   lockstep, in the same deploy.
 * - API responses keep their Set-Cookie (guest-heartbeat dedupe and the JS
 *   CSRF-retry shim both depend on it) — this middleware is forum-only.
 * - /reset, /confirm etc. are server-rendered Blade forms that need their
 *   session cookie; they stay denylisted forever.
 */
class EdgeCacheMiddleware implements Middleware
{
    /**
     * v2 scope: discussion pages (v1), plus the index and tag landing pages.
     * The index/tag lists change on every post, so caching them long relies on
     * PurgeDiscussionCache also purging them on write (it does since v0.3);
     * without CF credentials the short TTL bounds their staleness instead.
     * Sort/filter variants (?sort=…) carry query strings, cache under their
     * own CF keys, and are not purge-enumerable — the TTL bounds those too.
     */
    private const ALLOWED_PATH_PREFIXES = ['/d/', '/t/'];

    private const ALLOWED_EXACT_PATHS = ['/'];

    private const DENIED_PATH_PREFIXES = [
        '/reset', '/confirm', '/logout', '/unsubscribe', '/auth', '/api', '/admin',
    ];

    /**
     * Edge TTL via s-maxage (max-age=0 keeps browsers from caching).
     *
     * The long TTL only applies when Cloudflare purge credentials are
     * configured: PurgeDiscussionCache then evicts a discussion's landing page
     * (and the index/tag pages listing it) on every write, so freshness no
     * longer relies on a short TTL and the long tail of /d/* URLs can stay
     * warm (cold MISS ~1s origin TTFB -> warm HIT ~35ms). 1h bounds staleness
     * for deep-pagination and sort-variant URLs that purge-by-URL can't
     * enumerate.
     *
     * Without credentials (no purge), we keep the original 300s so a guest can
     * never see content more than 5 min stale — making this safe to ship
     * before the CF token is in place, and correct for installs not on CF.
     */
    private const LONG_TTL = 3600;
    private const SHORT_TTL = 300;

    public function __construct(
        protected CookieFactory $cookie,
        protected Config $config,
        protected AssetUrls $assets,
        protected LocaleManager $locales,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $started = microtime(true);
        $cacheable = $this->isCacheableRequest($request);

        $response = $handler->handle($request);

        $isHtml = str_starts_with($response->getHeaderLine('Content-Type'), 'text/html');
        $isPage = $response->getStatusCode() === 200 && $isHtml;

        if ($isPage) {
            $response = $this->withPreloadLinkHeaders($response, $request);
        }

        if ($cacheable && $isPage) {
            return $response
                ->withoutHeader('Set-Cookie')
                ->withoutHeader('X-CSRF-Token')
                ->withHeader('Cache-Control', 'public, s-maxage='.$this->edgeTtl().', max-age=0, must-revalidate')
                ->withHeader('Server-Timing', sprintf('origin;dur=%.0f', (microtime(true) - $started) * 1000));
        }

        // Everything that isn't the cacheable case above gets an explicit
        // private CC (HTML, JSON-API errors, redirects alike) so a mis-scoped
        // CF rule with "respect origin" can never default-cache it.
        if (! $response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    /**
     * `Link: <url>; rel=preload` headers mirroring the document's own asset
     * references. Browsers de-duplicate against the in-HTML tags (identical
     * URLs), so without Early Hints these are a no-op-cost hint at header
     * time; with CF Early Hints they become a 103 that starts the CSS/JS
     * downloads during origin think-time.
     *
     * The locale bundle is resolved from the request's negotiated locale
     * (LocaleManager holds it once the inner handler has run), so a cached
     * guest page — always rendered in the default locale, per the locale
     * cookie guard in isCacheableRequest — and a member's localised render
     * each hint their own actual bundle.
     */
    private function withPreloadLinkHeaders(Response $response, Request $request): Response
    {
        $links = [];

        if ($url = $this->assets->url('forum.css')) {
            $links[] = '<'.$url.'>; rel=preload; as=style';
        }

        if ($url = $this->assets->url('forum.js')) {
            $links[] = '<'.$url.'>; rel=preload; as=script';
        }

        $locale = $this->locales->getLocale();
        if ($locale !== '' && ($url = $this->assets->url('forum-'.$locale.'.js'))) {
            $links[] = '<'.$url.'>; rel=preload; as=script';
        }

        $path = ForumPath::relative($request->getUri()->getPath(), $this->config->url()->getPath());
        if (str_starts_with($path, '/d/')) {
            foreach (AssetUrls::DISCUSSION_CHUNKS as $key) {
                if ($url = $this->assets->url($key)) {
                    $links[] = '<'.$url.'>; rel=preload; as=script';
                }
            }
        }

        return $links === [] ? $response : $response->withAddedHeader('Link', $links);
    }

    /**
     * Long edge TTL only once Cloudflare purge-on-write is wired up (zone id +
     * api token in config.php); otherwise the conservative 5-minute TTL. Mirror
     * of CloudflareCachePurger's configured check, kept here so the cache layer
     * stays self-contained and cheap (no Guzzle client construction per render).
     */
    private function edgeTtl(): int
    {
        $cf = isset($this->config['cloudflare']) ? (array) $this->config['cloudflare'] : [];

        $purgeReady = ! empty($cf['zone_id']) && ! empty($cf['api_token']);

        return $purgeReady ? self::LONG_TTL : self::SHORT_TTL;
    }

    private function isCacheableRequest(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        $cookies = $request->getCookieParams();

        if (isset($cookies[$this->cookie->getName('session')])
            || isset($cookies[$this->cookie->getName('remember')])
            || $request->getHeaderLine('Authorization') !== '') {
            return false;
        }

        // A locale cookie yields a localised render that is still "public".
        // Cloudflare ignores Vary, so caching it shared would serve one guest's
        // language to every other guest. Core reads the guest locale from the
        // UNPREFIXED `locale` key (SetLocale: Arr::get($cookies, 'locale'));
        // locale-switcher extensions may instead set the cookie-prefixed
        // variant, so decline on either.
        if (isset($cookies['locale']) || isset($cookies[$this->cookie->getName('locale')])) {
            return false;
        }

        $path = ForumPath::relative($request->getUri()->getPath(), $this->config->url()->getPath());

        foreach (self::DENIED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        if (in_array($path, self::ALLOWED_EXACT_PATHS, true)) {
            return true;
        }

        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
