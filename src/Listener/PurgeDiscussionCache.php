<?php

namespace Ekumanov\EdgeCache\Listener;

use Ekumanov\EdgeCache\PurgeDiscussionCacheJob;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

/**
 * Evicts the cached pages a write invalidates from Cloudflare, so the long
 * edge TTL never serves stale HTML to guests. For a change to a discussion
 * that means:
 *
 *  - its canonical landing URL (`/d/{id}-{slug}`) — what Googlebot indexes
 *    and the overwhelming majority of guests hit;
 *  - the slugless `/d/{id}` variant — Flarum serves it directly (no
 *    redirect), so Cloudflare caches it under its own key;
 *  - the forum index (with and without trailing slash) — the write reorders
 *    or retitles its discussion list;
 *  - the landing page of each of the discussion's tags, and their parents —
 *    same reason, for `/t/{slug}` (a child tag's discussions also appear on
 *    the parent tag's page). Absent flarum/tags this contributes nothing.
 *
 * Registered as an invokable against each post/discussion event (a class
 * string — Flarum's Extend\Event::listen takes callable|string and rejects the
 * [Class, 'method'] array form for instance methods). Post events carry
 * ->post (and ->post->discussion); discussion events carry ->discussion;
 * __invoke handles both shapes.
 *
 * Not enumerable for a Free/Pro CF plan's purge-by-URL, hence bounded by the
 * edge TTL instead: deep-pagination URLs (`/d/{id}-{slug}/{near}`),
 * index/tag sort variants (`?sort=…`), and — after a title rename — the
 * old-slug URL (it 301s to the new slug and ages out). Re-tagging a
 * discussion likewise leaves the OLD tag's page to the TTL; the next write
 * touching that tag purges it.
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
            $forum = $this->url->to('forum');
            $urls = [];

            $slug = $this->slugManager->forResource(Discussion::class)->toSlug($discussion);

            $urls[] = $forum->route('discussion', ['id' => $slug]);

            if ($slug !== (string) $discussion->id) {
                $urls[] = $forum->route('discussion', ['id' => $discussion->id]);
            }

            $base = rtrim($forum->base(), '/');
            $urls[] = $base;
            $urls[] = $base.'/';

            // Dynamic relation contributed by flarum/tags; null when that
            // extension isn't installed.
            foreach ($discussion->tags ?? [] as $tag) {
                if (! empty($tag->slug)) {
                    $urls[] = $forum->route('tag', ['slug' => $tag->slug]);
                }
                if ($tag->parent && ! empty($tag->parent->slug)) {
                    $urls[] = $forum->route('tag', ['slug' => $tag->parent->slug]);
                }
            }

            $this->queue->push(new PurgeDiscussionCacheJob(array_values(array_unique($urls))));
        } catch (\Throwable $e) {
            // Best-effort: queueing the purge must never break the user's
            // post/edit/delete. A miss is bounded by the edge TTL anyway.
            $this->logger->warning('[edge-cache] failed to queue cache purge: '.$e->getMessage());
        }
    }
}
