<?php

namespace Ekumanov\EdgeCache\Cloudflare;

use Flarum\Foundation\Config;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Purges specific URLs from Cloudflare's edge cache via the CF API.
 *
 * This is the freshness half of the "long TTL + purge-on-write" design: because
 * a write instantly evicts the affected discussion's cached HTML, the edge TTL
 * (EdgeCacheMiddleware::EDGE_TTL) can be raised far above the old 5 minutes so
 * the long tail of discussion URLs stays warm — without guests seeing stale
 * content after a reply.
 *
 * Credentials are read from the site's config.php (NOT the extension repo, NOT
 * the frontend) under a `cloudflare` key:
 *
 *     'cloudflare' => [
 *         'zone_id'   => '...',
 *         'api_token' => '...',   // scoped to Zone.Cache Purge only
 *     ],
 *
 * When they are absent — local dev, the prod-mirror, or any install not behind
 * Cloudflare — every purge is a logged no-op, so this can never reach out to a
 * CF zone it has no business touching. Purge-by-URL (Free/Pro) is used rather
 * than cache-tags/prefix (Enterprise-only); deep-pagination URLs that aren't
 * enumerated here are covered by the bounded edge TTL instead.
 */
class CloudflareCachePurger
{
    private ?string $zoneId;
    private ?string $apiToken;
    private ClientInterface $http;

    public function __construct(
        Config $config,
        private LoggerInterface $logger,
        ?ClientInterface $http = null,
    ) {
        $cf = isset($config['cloudflare']) ? (array) $config['cloudflare'] : [];
        $this->zoneId = $cf['zone_id'] ?? null;
        $this->apiToken = $cf['api_token'] ?? null;
        $this->http = $http ?? new Client(['timeout' => 8, 'connect_timeout' => 4]);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->zoneId) && ! empty($this->apiToken);
    }

    /**
     * @param string[] $urls Absolute URLs to evict from the edge.
     */
    public function purgeUrls(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));

        if (empty($urls)) {
            return;
        }

        if (! $this->isConfigured()) {
            // The intent is logged so the mirror rehearsal can confirm the
            // correct URLs are computed without any CF zone being touched.
            $this->logger->info('[edge-cache] CF purge skipped (no credentials): '.implode(' ', $urls));

            return;
        }

        try {
            $response = $this->http->request(
                'POST',
                "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->apiToken,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => ['files' => $urls],
                ]
            );

            // Cloudflare returns 200 with {"success":false,...} for things like a
            // revoked/rate-limited token, which Guzzle won't throw on. Surface
            // those as warnings so the daily flarum.log monitor catches a purge
            // outage — otherwise the long TTL would quietly serve stale pages.
            $body = json_decode((string) $response->getBody(), true);

            if (($body['success'] ?? false) === true) {
                $this->logger->info('[edge-cache] CF purge: '.implode(' ', $urls));
            } else {
                $this->logger->warning(
                    '[edge-cache] CF purge rejected (success=false): '
                    .json_encode($body['errors'] ?? $body).' for: '.implode(' ', $urls)
                );
            }
        } catch (\Throwable $e) {
            // Never let a transient CF blip crash the queue worker in a loop:
            // log and drop. The bounded edge TTL still caps staleness if an
            // occasional purge is missed.
            $this->logger->warning('[edge-cache] CF purge failed ('.$e->getMessage().') for: '.implode(' ', $urls));
        }
    }
}
