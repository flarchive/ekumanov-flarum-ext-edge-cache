<?php

namespace Ekumanov\EdgeCache;

/**
 * Normalizes a request URI path to its forum-relative form by stripping the
 * forum mount prefix.
 *
 * Depending on where the BasePath middleware sits relative to the forum pipe,
 * the incoming URI path may or may not still carry the mount prefix (e.g.
 * "/forum" or "/community"). Both the cacheability check
 * (EdgeCacheMiddleware) and the discussion-preload check
 * (AddDiscussionChunkPreloads) need the forum-relative path, so the tolerance
 * lives here once instead of in two divergent copies.
 *
 * The prefix is the path component of Flarum\Foundation\Config::url(), which
 * is already rtrim'd of any trailing slash and is "" for a root-mounted
 * install.
 */
class ForumPath
{
    /**
     * Strip $mountPrefix from the front of $path when present.
     *
     * - Empty prefix (root mount) -> $path unchanged.
     * - Prefix already stripped upstream (path doesn't carry it) -> unchanged.
     * - Exact prefix match ("/forum") -> "/".
     */
    public static function relative(string $path, string $mountPrefix): string
    {
        if ($mountPrefix === '') {
            return $path;
        }

        if ($path === $mountPrefix || str_starts_with($path, $mountPrefix.'/')) {
            return substr($path, strlen($mountPrefix)) ?: '/';
        }

        return $path;
    }
}
