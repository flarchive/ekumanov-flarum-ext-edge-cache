<?php

namespace Ekumanov\EdgeCache;

use Flarum\Foundation\Config;
use Flarum\Http\CookieFactory;
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
     * v1 scope: discussion pages only — the GSC-flagged URL group.
     */
    private const ALLOWED_PATH_PREFIXES = ['/d/'];

    private const DENIED_PATH_PREFIXES = [
        '/reset', '/confirm', '/logout', '/unsubscribe', '/auth', '/api', '/admin',
    ];

    /**
     * Edge TTL via s-maxage (max-age=0 keeps browsers from caching).
     *
     * The long TTL only applies when Cloudflare purge credentials are
     * configured: PurgeDiscussionCache then evicts a discussion's landing page
     * on every write, so freshness no longer relies on a short TTL and the long
     * tail of /d/* URLs can stay warm (cold MISS ~1s origin TTFB -> warm HIT
     * ~35ms). 1h bounds staleness for deep-pagination URLs that purge-by-URL
     * can't enumerate.
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
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $started = microtime(true);
        $cacheable = $this->isCacheableRequest($request);

        $response = $handler->handle($request);

        $isHtml = str_starts_with($response->getHeaderLine('Content-Type'), 'text/html');

        if ($cacheable && $response->getStatusCode() === 200 && $isHtml) {
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

        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
