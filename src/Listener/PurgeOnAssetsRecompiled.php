<?php

namespace Ekumanov\EdgeCache\Listener;

use Ekumanov\EdgeCache\PurgeAllOnAssetsChangeJob;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

/**
 * Every cached page embeds the assets token it was rendered with, and the
 * forum compares it against the X-Flarum-Assets-Revision header of each API
 * response (and realtime's broadcast of it). When the compiled forum assets
 * change, a page served from the edge therefore prompts the guest to reload,
 * the reload is served the same cached page, and the prompt comes back, for
 * as long as the edge TTL lasts, on every cached URL.
 *
 * Deploys were already covered (pcclear purges the whole zone), but a rebuild
 * started from the admin panel was not: toggling an extension, saving the
 * colours or custom LESS, clearing the cache from the admin modal.
 *
 * Two events lead here. AssetsRecompiled fires after the deferred rebuild
 * that admin saves and extension toggles trigger, once per asset set and
 * also when nothing changed. cache:clear (console and the admin modal)
 * rebuilds without firing it; it only fires ClearingCache, and BEFORE the
 * rebuild. So this only queues a check, delayed past the end of any rebuild,
 * and the job decides whether the forum-facing token actually moved.
 */
class PurgeOnAssetsRecompiled
{
    private const PENDING_KEY = 'edge-cache:pending-assets-check';
    private const PENDING_TTL = 120;

    /**
     * A full rebuild takes a few seconds; cache:clear's starts after the
     * ClearingCache event this listens to.
     */
    private const DELAY = 30;

    public function __construct(
        protected Queue $queue,
        protected Cache $cache,
        protected LoggerInterface $logger,
    ) {
    }

    public function __invoke(object $event): void
    {
        try {
            // One check absorbs the burst of events from a single rebuild. The
            // job releases the key as it starts, so an event fired after that
            // queues its own check.
            if ($this->cache->add(self::PENDING_KEY, 1, self::PENDING_TTL)) {
                $this->queue->later(self::DELAY, new PurgeAllOnAssetsChangeJob(self::PENDING_KEY));
            }
        } catch (\Throwable $e) {
            // Must never break the rebuild, an admin save or cache:clear.
            $this->logger->warning('[edge-cache] failed to queue assets check: '.$e->getMessage());
        }
    }
}
