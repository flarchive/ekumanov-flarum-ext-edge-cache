<?php

namespace Ekumanov\EdgeCache;

use Ekumanov\EdgeCache\Cloudflare\CloudflareCachePurger;
use Flarum\Queue\AbstractJob;

/**
 * Queued so a Cloudflare round-trip never adds latency to the reply/edit
 * request that triggered it, and so a failed purge can be retried by the
 * worker. Carries plain URL strings only (no Eloquent models), so the slug is
 * resolved at dispatch time — correct even if the discussion is later deleted.
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
