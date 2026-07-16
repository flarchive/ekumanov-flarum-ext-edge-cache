<?php

namespace Ekumanov\EdgeCache;

use Ekumanov\EdgeCache\Cloudflare\CloudflareCachePurger;
use Flarum\Queue\AbstractJob;

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
        public array $urls
    ) {
        parent::__construct();
    }

    public function handle(CloudflareCachePurger $purger): void
    {
        $purger->purgeUrls($this->urls);
    }
}
