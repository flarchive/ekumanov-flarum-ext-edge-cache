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
        ->listen(DiscussionRestored::class, PurgeDiscussionCache::class),
];
