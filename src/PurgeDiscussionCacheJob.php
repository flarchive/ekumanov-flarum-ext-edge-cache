<?php

namespace Ekumanov\EdgeCache;

use Ekumanov\EdgeCache\Cloudflare\CloudflareCachePurger;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Queued so a Cloudflare round-trip never adds latency to the reply/edit
 * request that triggered it. Carries plain URL strings only (no Eloquent
 * models), so the slug is resolved at dispatch time — correct even if the
 * discussion is later deleted.
 *
 * Note this job never fails or retries: CloudflareCachePurger deliberately
 * swallows every throwable (log-and-drop) so a CF blip can't crash-loop the
 * worker. A missed purge's staleness is bounded by the edge TTL instead.
 */
class PurgeDiscussionCacheJob extends AbstractJob
{
    /**
     * @param string[] $urls
     */
    public function __construct(
        public array $urls,
        public ?string $pendingKey = null,
    ) {
        parent::__construct();
    }

    public function handle(CloudflareCachePurger $purger, Cache $cache): void
    {
        // Release the listener's dedupe marker BEFORE purging, so any write
        // from here on queues its own purge rather than being folded into
        // this one. See PurgeDiscussionCache::queuePurge().
        // isset(), not !== null: a job queued by the previous release is
        // unserialized without this property, and reading an uninitialized
        // typed property throws.
        if (isset($this->pendingKey)) {
            $cache->forget($this->pendingKey);
        }

        $purger->purgeUrls($this->urls);
    }
}
