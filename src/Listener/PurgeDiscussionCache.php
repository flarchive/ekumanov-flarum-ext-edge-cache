<?php

namespace Ekumanov\EdgeCache\Listener;

use Ekumanov\EdgeCache\PurgeDiscussionCacheJob;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

/**
 * Evicts a discussion's cached landing page from Cloudflare whenever its
 * content changes, so the long edge TTL never serves stale HTML to guests.
 *
 * Registered as an invokable against each post/discussion event (a class
 * string — Flarum's Extend\Event::listen takes callable|string and rejects the
 * [Class, 'method'] array form for instance methods). Post events carry
 * ->post (and ->post->discussion); discussion events carry ->discussion;
 * __invoke handles both shapes.
 *
 * Scope note: only the canonical landing URL (`/d/{id}-{slug}`) is purged —
 * the page Googlebot indexes and the overwhelming majority of guests hit.
 * Deep-pagination URLs (`/d/{id}-{slug}/{near}`) are not enumerable for a
 * Free/Pro CF plan's purge-by-URL, so they rely on the bounded edge TTL to
 * refresh. A title rename changes the slug; the old-slug URL then 301s to the
 * new one and ages out under the TTL.
 */
class PurgeDiscussionCache
{
    public function __construct(
        protected SlugManager $slugManager,
        protected UrlGenerator $url,
        protected Queue $queue,
        protected LoggerInterface $logger,
    ) {
    }

    public function __invoke(object $event): void
    {
        // ?? also suppresses the undefined-property notice for whichever
        // shape this event isn't.
        $discussion = $event->discussion ?? ($event->post->discussion ?? null);

        $this->purge($discussion);
    }

    protected function purge(?Discussion $discussion): void
    {
        if ($discussion === null || $discussion->id === null) {
            return;
        }

        try {
            $slug = $this->slugManager->forResource(Discussion::class)->toSlug($discussion);

            $url = $this->url->to('forum')->route('discussion', ['id' => $slug]);

            $this->queue->push(new PurgeDiscussionCacheJob([$url]));
        } catch (\Throwable $e) {
            // Best-effort: queueing the purge must never break the user's
            // post/edit/delete. A miss is bounded by the edge TTL anyway.
            $this->logger->warning('[edge-cache] failed to queue cache purge: '.$e->getMessage());
        }
    }
}
