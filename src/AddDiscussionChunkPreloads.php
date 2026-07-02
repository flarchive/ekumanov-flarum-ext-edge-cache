<?php

namespace Ekumanov\EdgeCache;

use Flarum\Foundation\Config;
use Flarum\Frontend\Document;
use Illuminate\Contracts\Filesystem\Factory;
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
 * The href is built exactly the way Flarum's own asset references are
 * (`disk('flarum-assets')->url($path) . '?v=' . <rev-manifest hash>`), so each
 * preload is byte-identical to the URL the webpack runtime later requests and
 * the browser de-duplicates it — no double download. The rev-manifest hashes
 * are environment- and build-specific (they change on every recompile), so
 * they are read at runtime rather than hardcoded.
 */
class AddDiscussionChunkPreloads
{
    /**
     * rev-manifest.json keys for the chunks to preload. If core ever renames or
     * removes one, the manifest lookup misses and that preload is silently
     * skipped (see __invoke) — a core upgrade can never 500 the page here.
     */
    private const DISCUSSION_CHUNKS = [
        'js/core/forum/components/PostStream.js',
        'js/core/forum/components/PostStreamScrubber.js',
    ];

    private ?array $manifest = null;

    public function __construct(
        protected Factory $filesystem,
        protected Config $config,
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        if (! $this->isDiscussionPath($request->getUri()->getPath())) {
            return;
        }

        $disk = $this->filesystem->disk('flarum-assets');
        $manifest = $this->manifest();

        foreach (self::DISCUSSION_CHUNKS as $path) {
            if (! isset($manifest[$path])) {
                continue;
            }

            // No `fetchpriority` on purpose: these must not contend with
            // forum.js's own high-priority download. Preloading at default
            // priority still starts them early (in parallel), which is the win.
            $document->preloads[] = [
                'href' => $disk->url($path).'?v='.$manifest[$path],
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

    /**
     * Read rev-manifest.json once per request. Any failure (missing/unreadable/
     * malformed) degrades to "no preloads" rather than erroring the render.
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        try {
            $disk = $this->filesystem->disk('flarum-assets');
            $json = $disk->exists('rev-manifest.json') ? $disk->get('rev-manifest.json') : null;
            $this->manifest = $json ? (json_decode($json, true) ?: []) : [];
        } catch (\Throwable $e) {
            $this->manifest = [];
        }

        return $this->manifest;
    }
}
