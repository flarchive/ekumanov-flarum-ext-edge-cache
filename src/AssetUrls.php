<?php

namespace Ekumanov\EdgeCache;

use Illuminate\Contracts\Filesystem\Factory;

/**
 * Resolves compiled-asset URLs with their cache-busting hash, exactly the way
 * Flarum's own asset references are built:
 * `disk('flarum-assets')->url($key) . '?v=' . <rev-manifest hash>`.
 *
 * Because the emitted URL is byte-identical to the one core's HTML (or the
 * webpack runtime) later requests, a preload for it is de-duplicated by the
 * browser — never a double download.
 *
 * The hashes are environment- and build-specific (they change on every
 * recompile), so they are read at runtime, once per instance. Any failure —
 * missing/unreadable/malformed manifest, unknown key — degrades to null and
 * callers skip that asset rather than erroring the render.
 */
class AssetUrls
{
    /**
     * rev-manifest.json keys for the two boot-critical lazy chunks that
     * discussion pages fetch serially after boot. Preloading them (tag or
     * Link header) collapses that serial tail. If core ever renames or
     * removes one, the manifest lookup misses and it is silently skipped —
     * a core upgrade can never 500 the page here.
     */
    public const DISCUSSION_CHUNKS = [
        'js/core/forum/components/PostStream.js',
        'js/core/forum/components/PostStreamScrubber.js',
    ];

    private ?array $manifest = null;

    public function __construct(
        protected Factory $filesystem,
    ) {
    }

    /**
     * Full URL (hash included) for a rev-manifest key, or null when the key
     * is unknown or the manifest is unavailable.
     */
    public function url(string $manifestKey): ?string
    {
        $manifest = $this->manifest();

        if (! isset($manifest[$manifestKey])) {
            return null;
        }

        try {
            return $this->filesystem->disk('flarum-assets')->url($manifestKey).'?v='.$manifest[$manifestKey];
        } catch (\Throwable $e) {
            return null;
        }
    }

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
