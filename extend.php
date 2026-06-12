<?php

use Ekumanov\EdgeCache\EdgeCacheMiddleware;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js'),

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
];
