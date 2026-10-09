{{--
    Replacement for flarum::frontend.content (see PrePaintDiscussion): emits
    the server-rendered discussion content as a VISIBLE pre-paint block
    instead of hiding it in <noscript>, so guests get first-post paint at
    ≈FCP instead of after SPA boot.

    Contract with core's app.blade.php inline scripts (they getElementById
    unconditionally, so these ids MUST exist even here):
    - #flarum-loading        (force-shown right after layout; we hide it via
                              CSS !important — the pre-paint IS the loading state)
    - #flarum-loading-error  (shown on boot exception)
    - #flarum-content        (its textContent is appended to the error box on
                              boot exception; kept as an EMPTY noscript — the
                              visible block already carries the content, for
                              no-JS users too, so duplicating it here would
                              only double the HTML bytes)
--}}
<div id="edge-prepaint">
    {!! $content !!}
</div>

<script>
    // Remove the pre-paint in the same rendering frame in which Mithril's
    // first render fills #content: MutationObserver callbacks are
    // microtasks, which run after the DOM insertion but BEFORE the browser
    // paints the frame — so exactly one of (pre-paint | hydrated page) is
    // ever visible; no duplicate, no blank gap. If boot never happens
    // (JS error), the block simply stays: graceful noscript-like fallback.
    (function () {
        var content = document.getElementById('content');
        var prepaint = document.getElementById('edge-prepaint');
        if (!content || !prepaint) return;
        new MutationObserver(function (mutations, observer) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length) {
                    observer.disconnect();
                    prepaint.remove();
                    if (window.performance && performance.mark) performance.mark('prepaint:removed');
                    return;
                }
            }
        }).observe(content, {childList: true});
    })();
</script>

<noscript>
    <div class="Alert">
        <div class="container">
            {{ $translator->trans('core.views.content.javascript_disabled_message') }}
        </div>
    </div>
</noscript>

<div id="flarum-loading" style="display: none">
    {{ $translator->trans('core.views.content.loading_text') }}
</div>

<div id="flarum-loading-error" style="display: none">
    <div class="Alert">
        <div class="container">
            {{ $translator->trans('core.views.content.load_error_message') }}
        </div>
    </div>
</div>

<noscript id="flarum-content"></noscript>
