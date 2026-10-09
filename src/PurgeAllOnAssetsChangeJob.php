<?php

namespace Ekumanov\EdgeCache;

use Ekumanov\EdgeCache\Cloudflare\CloudflareCachePurger;
use Flarum\Frontend\Compiler\AssetsRevision;
use Flarum\Frontend\Compiler\DatabaseVersioner;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Purges the whole zone when the forum-facing assets token has moved since
 * the last purge. See Listener\PurgeOnAssetsRecompiled for why.
 *
 * The token is computed exactly as core computes the one it embeds in pages
 * (AssetsRevision::tokenFor, which already ignores admin-only assets), but
 * from the table rather than the versioner: the versioner memoises its map
 * for the life of the instance, and in the long-running queue worker that is
 * the life of the process.
 *
 * The last purged token is kept in settings, not the cache, so a cache:clear
 * (which pcclear follows with its own purge) does not cost a second one.
 */
class PurgeAllOnAssetsChangeJob extends AbstractJob
{
    public const TOKEN_SETTING = 'ekumanov-edge-cache.purged_assets_token';

    public function __construct(
        public ?string $pendingKey = null,
    ) {
        parent::__construct();
    }

    public function handle(
        CloudflareCachePurger $purger,
        Cache $cache,
        ConnectionInterface $db,
        SettingsRepositoryInterface $settings,
    ): void {
        if (isset($this->pendingKey)) {
            $cache->forget($this->pendingKey);
        }

        $token = AssetsRevision::tokenFor(
            $db->table(DatabaseVersioner::TABLE)->pluck('revision', 'file')->all()
        );

        if ($token === $settings->get(self::TOKEN_SETTING)) {
            return;
        }

        // Only remembered once Cloudflare accepted it, so a failed purge is
        // retried by the next rebuild instead of being forgotten.
        if ($purger->purgeEverything()) {
            $settings->set(self::TOKEN_SETTING, $token);
        }
    }
}
