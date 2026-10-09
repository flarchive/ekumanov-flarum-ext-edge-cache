<?php

namespace Ekumanov\EdgeCache\Listener;

use Ekumanov\EdgeCache\PurgeDiscussionCacheJob;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Renamed;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Post;
use Illuminate\Contracts\Cache\Repository as Cache;
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
 *  - after a rename, the old-slug URL — also served 200 without a redirect;
 *  - the forum index (with and without trailing slash) — the write reorders
 *    or retitles its discussion list;
 *  - the landing page of each of the discussion's tags, and their parents —
 *    same reason, for `/t/{slug}` (a child tag's discussions also appear on
 *    the parent tag's page). On a retag the OLD tags' pages too. Absent
 *    flarum/tags this contributes nothing.
 *
 * Registered as an invokable against each event (a class string — Flarum's
 * Extend\Event::listen takes callable|string and rejects the [Class,
 * 'method'] array form for instance methods). The events come in several
 * shapes, all handled by __invoke: ->discussion, ->post, fof/move-posts'
 * source + target, fof/merge-discussions' merged sources, and flarum/tags'
 * ->oldTags. Listening to an event class from an extension that is not
 * installed is harmless: it is never dispatched.
 *
 * Not enumerable for a Free/Pro CF plan's purge-by-URL, hence kept off the
 * 24h TTL by EdgeCacheMiddleware::edgeTtl() and bounded by the 1h one:
 * post permalinks (`/d/{id}-{slug}/{near}`) and index/tag sort variants
 * (`?sort=…`).
 */
class PurgeDiscussionCache
{
    /**
     * How long a queued-but-not-yet-run purge absorbs identical requests.
     * The job clears its key when it starts, so this only matters if the job
     * is lost; it then bounds how long later writes go unpurged.
     */
    private const PENDING_TTL = 120;

    /**
     * Events that change what is in a post, never who can see it.
     */
    private const CONTENT_EVENTS = [
        \Flarum\Post\Event\Posted::class,
        \Flarum\Post\Event\Revised::class,
        \Flarum\Post\Event\Hidden::class,
        \Flarum\Post\Event\Restored::class,
        \Flarum\Post\Event\Deleted::class,
    ];

    public function __construct(
        protected SlugManager $slugManager,
        protected UrlGenerator $url,
        protected Queue $queue,
        protected Cache $cache,
        protected LoggerInterface $logger,
    ) {
    }

    public function __invoke(object $event): void
    {
        try {
            $this->queuePurge($this->urlsFor($event));
        } catch (\Throwable $e) {
            // Best-effort: queueing the purge must never break the user's
            // post/edit/delete. A miss is bounded by the edge TTL anyway.
            $this->logger->warning('[edge-cache] failed to queue cache purge: '.$e->getMessage());
        }
    }

    /**
     * @return string[]
     */
    protected function urlsFor(object $event): array
    {
        // ?? also suppresses the undefined-property notice for whichever
        // shape this event isn't.
        $post = $event->post ?? null;
        $discussion = $event->discussion ?? ($post?->discussion ?? null);

        // A change a guest could never have seen changes no guest page: a
        // reply or edit inside a private discussion (fof/byobu PMs — about
        // one post in seven here), or to a post held for approval. Each of
        // those used to evict the index, the most valuable page in the cache.
        // Only plain content events are skipped. Everything else may be a
        // visibility transition — into private (retag, recipients changed),
        // which must purge because the cached page is now a leak, or out of
        // it (approval, whose listener that clears the discussion's flag may
        // run after this one) — and always purges.
        if (in_array($event::class, self::CONTENT_EVENTS, true) && $this->invisibleToGuests($post, $discussion)) {
            return [];
        }

        // A link-preview card settling, or being pinned/dismissed, changes the
        // discussion page only — nothing on the index or a tag page shows it.
        if ($event instanceof \Ekumanov\LinkPreview\Event\PreviewsChanged) {
            $urls = [];
            foreach ($event->posts as $p) {
                $d = $p->discussion;
                if ($d instanceof Discussion && ! $this->invisibleToGuests($p, $d)) {
                    array_push($urls, ...$this->discussionUrls($d, false));
                }
            }

            return $urls;
        }

        $urls = [];

        foreach ([
            $discussion,
            $event->sourceDiscussion ?? null,
            $event->targetDiscussion ?? null,
            ...($event->mergedDiscussions ?? []),
        ] as $d) {
            if ($d instanceof Discussion) {
                array_push($urls, ...$this->discussionUrls($d));
            }
        }

        if ($urls === []) {
            return [];
        }

        if ($event instanceof Renamed && $discussion !== null) {
            // The rename has already regenerated the slug; rebuild the old one
            // the same way core does, on a copy.
            $old = clone $discussion;
            $old->title = $event->oldTitle;
            array_push($urls, ...$this->discussionUrls($old, false));
        }

        foreach ($event->oldTags ?? [] as $tag) {
            array_push($urls, ...$this->tagUrls($tag));
        }

        $base = rtrim($this->url->to('forum')->base(), '/');
        $urls[] = $base;
        $urls[] = $base.'/';

        return $urls;
    }

    private function invisibleToGuests(?Post $post, ?Discussion $discussion): bool
    {
        return ($post !== null && (bool) $post->is_private) || ($discussion !== null && (bool) $discussion->is_private);
    }

    /**
     * @return string[]
     */
    private function discussionUrls(Discussion $discussion, bool $withTags = true): array
    {
        if ($discussion->id === null) {
            return [];
        }

        $forum = $this->url->to('forum');
        $slug = $this->slugManager->forResource(Discussion::class)->toSlug($discussion);

        $urls = [$forum->route('discussion', ['id' => $slug])];

        if ($slug !== (string) $discussion->id) {
            $urls[] = $forum->route('discussion', ['id' => $discussion->id]);
        }

        if ($withTags) {
            // Dynamic relation contributed by flarum/tags; null when that
            // extension isn't installed.
            foreach ($discussion->tags ?? [] as $tag) {
                array_push($urls, ...$this->tagUrls($tag));
            }
        }

        return $urls;
    }

    /**
     * @return string[]
     */
    private function tagUrls(object $tag): array
    {
        $forum = $this->url->to('forum');
        $urls = [];

        if (! empty($tag->slug)) {
            $urls[] = $forum->route('tag', ['slug' => $tag->slug]);
        }
        if ($tag->parent && ! empty($tag->parent->slug)) {
            $urls[] = $forum->route('tag', ['slug' => $tag->parent->slug]);
        }

        return $urls;
    }

    /**
     * Queue one purge per distinct URL set, not one per event.
     *
     * Bulk moderation fires an event per post — anti-spam's mark-as-spammer
     * hides every post a spammer wrote, one Hidden event each — and every one
     * of them computed the same URLs. A marker per URL set absorbs repeats
     * while a job for that set is still waiting in the queue. The job deletes
     * its marker as it STARTS, before calling Cloudflare, so a write landing
     * after that point always queues a fresh purge: no write can be absorbed
     * by a purge that ran before it.
     *
     * @param string[] $urls
     */
    private function queuePurge(array $urls): void
    {
        $urls = array_values(array_unique($urls));

        if ($urls === []) {
            return;
        }

        sort($urls);
        $key = 'edge-cache:pending-purge:'.sha1(implode("\n", $urls));

        if (! $this->cache->add($key, 1, self::PENDING_TTL)) {
            return;
        }

        $this->queue->push(new PurgeDiscussionCacheJob($urls, $key));
    }
}
