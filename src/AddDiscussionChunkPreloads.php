<?php

namespace Ekumanov\EdgeCache;

use Flarum\Foundation\Config;
use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Preloads the two boot-critical core lazy chunks on discussion pages.
 *
 * On /d/* the LCP element is the first post's TEXT, and its render is delayed
 * by a serialized JS pipeline: the browser downloads forum.js, evaluates it,
 * boots the SPA, and only THEN fetches PostStream.js + PostStreamScrubber.js
 * (a serial, post-boot tail of ~300ms in the lab — more on real mobile RTT)
 * before the post stream can render.
 *
 * Emitting `<link rel="preload" as="script">` for those two chunks lets the
 * browser fetch them in PARALLEL with forum.js instead of after boot,
 * collapsing the serial tail. This helps every visitor on a cold load —
 * guests (served from the edge cache) and logged-in members alike — because
 * it is a client-side render win, not a server/cache one.
 *
 * EdgeCacheMiddleware additionally mirrors these preloads as `Link` response
 * headers so Cloudflare Early Hints can replay them in a 103 before the
 * origin has responded; the in-document tags remain for browsers/CDNs
 * without Early Hints support. URL construction is shared via AssetUrls
 * (href built exactly the way Flarum's own asset references are), so tag and
 * header are byte-identical to what the webpack runtime later requests and
 * the browser de-duplicates — no double download.
 */
class AddDiscussionChunkPreloads
{
    public function __construct(
        protected AssetUrls $assets,
        protected Config $config,
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        if (! $this->isDiscussionPath($request->getUri()->getPath())) {
            return;
        }

        foreach (AssetUrls::DISCUSSION_CHUNKS as $path) {
            $url = $this->assets->url($path);

            if ($url === null) {
                continue;
            }

            // No `fetchpriority` on purpose: these must not contend with
            // forum.js's own high-priority download. Preloading at default
            // priority still starts them early (in parallel), which is the win.
            $document->preloads[] = [
                'href' => $url,
                'as' => 'script',
            ];
        }
    }

    /**
     * The URI path may or may not still carry the forum mount prefix depending
     * on middleware order; normalize via the shared ForumPath helper (same
     * logic EdgeCacheMiddleware uses), then match the discussion route prefix.
     */
    private function isDiscussionPath(string $path): bool
    {
        $path = ForumPath::relative($path, $this->config->url()->getPath());

        return str_starts_with($path, '/d/');
    }
}
