<?php

namespace Ekumanov\EdgeCache;

use Flarum\Frontend\Compiler\VersionerInterface;
use Illuminate\Contracts\Filesystem\Factory;

/**
 * Resolves compiled-asset URLs with their cache-busting hash, exactly the way
 * Flarum's own asset references are built:
 * `disk('flarum-assets')->url($key) . '?v=' . <revision>`.
 *
 * Because the emitted URL is byte-identical to the one core's HTML (or the
 * webpack runtime) later requests, a preload for it is de-duplicated by the
 * browser — never a double download.
 *
 * The hashes are environment- and build-specific (they change on every
 * recompile), so they are asked of core's own VersionerInterface at runtime
 * rather than read from disk. Flarum 2.0.0 moved revisions from
 * `rev-manifest.json` into the `asset_revisions` table and stopped writing
 * the file, so a manifest left on disk freezes at the last pre-2.0 hashes:
 * reading it directly made every preload point at a URL the page never
 * requests (a double download of forum.js). The versioner is the same
 * singleton the Document has just rendered with, so the lookup is free.
 * Any failure — unknown key, versioner error — degrades to null and callers
 * skip that asset rather than erroring the render.
 */
class AssetUrls
{
    /**
     * Revision keys for the two boot-critical lazy chunks that
     * discussion pages fetch serially after boot. Preloading them (tag or
     * Link header) collapses that serial tail. If core ever renames or
     * removes one, the revision lookup misses and it is silently skipped —
     * a core upgrade can never 500 the page here.
     */
    public const DISCUSSION_CHUNKS = [
        'js/core/forum/components/PostStream.js',
        'js/core/forum/components/PostStreamScrubber.js',
    ];

    public function __construct(
        protected Factory $filesystem,
        protected VersionerInterface $versioner,
    ) {
    }

    /**
     * Full URL (hash included) for an asset key, or null when the key has no
     * revision on record.
     */
    public function url(string $key): ?string
    {
        try {
            $revision = $this->versioner->getRevision($key);

            // 'empty' is RevisionCompiler::EMPTY_REVISION (2.0.0+): the bundle
            // compiled to nothing and has no file, so core emits no URL either.
            if ($revision === null || $revision === '' || $revision === 'empty') {
                return null;
            }

            return $this->filesystem->disk('flarum-assets')->url($key).'?v='.$revision;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
