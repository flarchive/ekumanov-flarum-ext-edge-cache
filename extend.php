<?php

use Ekumanov\EdgeCache\AddDiscussionChunkPreloads;
use Ekumanov\EdgeCache\EdgeCacheMiddleware;
use Ekumanov\EdgeCache\Listener\PurgeDiscussionCache;
use Ekumanov\EdgeCache\PrePaintDiscussion;
use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Discussion\Event\Hidden as DiscussionHidden;
use Flarum\Discussion\Event\Renamed;
use Flarum\Discussion\Event\Restored as DiscussionRestored;
use Flarum\Extend;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Post\Event\Revised;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // Preload PostStream.js + PostStreamScrubber.js on /d/* so they fetch in
        // parallel with forum.js instead of serially after boot — collapses the
        // render-delay tail that holds back the first-post (LCP) paint.
        ->content(AddDiscussionChunkPreloads::class)
        // PROTOTYPE: paint the server-rendered discussion content immediately
        // (guests only) instead of hiding it in <noscript>; removed in the
        // same frame the SPA's first render lands. LCP ≈ FCP on /d/*.
        ->content(PrePaintDiscussion::class),

    (new Extend\View())
        ->namespace('ekumanov-edge-cache', __DIR__.'/views'),

    // Must wrap OUTSIDE StartSession (it attaches Set-Cookie/X-CSRF-Token to
    // the response *after* its inner handler returns) AND outside the error
    // handler (exception-borne responses — 404s, CSRF 400s — are built there
    // and never pass through anything deeper), so the explicit private
    // Cache-Control reaches error responses too.
    (new Extend\Middleware('forum'))
        ->insertBefore('flarum.forum.error_handler', EdgeCacheMiddleware::class),

    // The guest-heartbeat beacon is a spoofable presence ping; CSRF protects
    // nothing there, and on cached pages (stale embedded token) it is the
    // highest-frequency 400 source. Exempting it keeps the 400 monitor quiet.
    (new Extend\Csrf())
        ->exemptRoute('forum-widgets.guest-heartbeat'),

    // Purge a discussion's cached landing page from Cloudflare whenever its
    // content changes, so the long edge TTL above never serves stale HTML.
    // Queued, and a no-op without CF credentials (local/mirror).
    (new Extend\Event())
        ->listen(Posted::class, PurgeDiscussionCache::class)
        ->listen(Revised::class, PurgeDiscussionCache::class)
        ->listen(PostDeleted::class, PurgeDiscussionCache::class)
        ->listen(PostHidden::class, PurgeDiscussionCache::class)
        ->listen(PostRestored::class, PurgeDiscussionCache::class)
        ->listen(Renamed::class, PurgeDiscussionCache::class)
        ->listen(DiscussionDeleted::class, PurgeDiscussionCache::class)
        ->listen(DiscussionHidden::class, PurgeDiscussionCache::class)
        ->listen(DiscussionRestored::class, PurgeDiscussionCache::class)
        // Guest-visible changes that do not go through the events above.
        // Event posts (retag, lock, sticky…) are saved via mergePost(), which
        // never raises Posted, and the extension-level moves below delete or
        // re-home posts without Deleted/Hidden reaching listeners. Some of
        // them take content AWAY from guests — a retag into a restricted tag,
        // recipients added to a public discussion — so a missed purge here is
        // a leak for the length of the edge TTL, not just staleness. String
        // class names: these extensions are optional, and an event that is
        // never dispatched costs nothing.
        ->listen('Flarum\Tags\Event\DiscussionWasTagged', PurgeDiscussionCache::class)
        ->listen('Flarum\Approval\Event\PostWasApproved', PurgeDiscussionCache::class)
        ->listen('Flarum\Sticky\Event\DiscussionWasStickied', PurgeDiscussionCache::class)
        ->listen('Flarum\Sticky\Event\DiscussionWasUnstickied', PurgeDiscussionCache::class)
        ->listen('Flarum\Lock\Event\DiscussionWasLocked', PurgeDiscussionCache::class)
        ->listen('Flarum\Lock\Event\DiscussionWasUnlocked', PurgeDiscussionCache::class)
        ->listen('FoF\MergeDiscussions\Events\DiscussionWasMerged', PurgeDiscussionCache::class)
        ->listen('FoF\MovePosts\Event\PostsMoved', PurgeDiscussionCache::class)
        ->listen('FoF\Byobu\Events\RecipientsChanged', PurgeDiscussionCache::class)
        ->listen('FoF\Byobu\Events\DiscussionMadePublic', PurgeDiscussionCache::class)
        // ekumanov/flarum-ext-link-preview fetches cards in a queued job that
        // finishes after the Posted purge above has run, so a guest page cached
        // in between kept showing a pending skeleton for the full edge TTL.
        ->listen('Ekumanov\LinkPreview\Event\PreviewsChanged', PurgeDiscussionCache::class),
];
