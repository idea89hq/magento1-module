<?php
declare(strict_types=1);
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

/**
 * IP-bucketed throttle for the guest order-lookup endpoint. Prevents
 * order-number enumeration by capping attempts per IP per hour. Storage
 * piggybacks on Magento's cache (file or Redis, depending on env) so we
 * don't need a new schema or table.
 *
 * Window: 1 hour rolling, anchored to first attempt in the window.
 * Limit: 4 attempts/IP/hour — tight enough to make enumeration
 *        economically infeasible with 8–10 digit order numbers
 *        (keyspace ~10^8 → ~96 guesses/day means >2M days to exhaust).
 *
 * On successful match, callers should invoke reset() to clear the
 * bucket — legit users finding their order shouldn't be penalised
 * for prior typos when they come back to check the next order.
 *
 * The bucket auto-expires via Magento's cache TTL once the window
 * passes; no explicit cleanup needed.
 *
 * M2→M1 substitutions:
 *   CacheInterface (constructor DI) → Mage::app()->getCache() (static access)
 *   $this->cache->save/load/remove  → Mage::app()->getCache()->save/load/remove
 *   Cache key ':' separator changed to '_' for Zend_Cache ID compatibility
 */
class Idea89_Assistant_Model_GuestLookupRateLimit
{
    // 10/IP/hour — matches M2 (bumped from 4 to avoid legit-user lockouts).
    const LIMIT_PER_WINDOW = 10;
    const WINDOW_SECONDS   = 3600;

    private const CACHE_KEY_PREFIX = 'idea89_guest_order_lookup_';
    /**
     * Cache tag so a future "wipe rate limits" action can nuke just our
     * buckets without touching everything else in the cache.
     */
    private const CACHE_TAG = 'IDEA89_RATE_LIMIT';

    /**
     * @return array{allowed: bool, attempts: int, retry_after: int}
     *         retry_after is seconds until the window resets (0 when allowed).
     */
    public function check(string $ip): array
    {
        $key = self::CACHE_KEY_PREFIX . $this->hashIp($ip);
        $raw = Mage::app()->getCache()->load($key);
        $now = time();

        $attempts    = 0;
        $windowStart = $now;

        if (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)
                && isset($parsed['attempts'], $parsed['window_start'])
                && is_int($parsed['attempts'])
                && is_int($parsed['window_start'])
            ) {
                $attempts    = $parsed['attempts'];
                $windowStart = $parsed['window_start'];
            }
        }

        // Window expired → reset.
        if ($now - $windowStart >= self::WINDOW_SECONDS) {
            $attempts    = 0;
            $windowStart = $now;
        }

        $attempts++;
        $allowed    = $attempts <= self::LIMIT_PER_WINDOW;
        $retryAfter = $allowed
            ? 0
            : max(0, ($windowStart + self::WINDOW_SECONDS) - $now);

        // Persist the new state. TTL = remaining window so the bucket
        // auto-clears at the boundary.
        $remaining = ($windowStart + self::WINDOW_SECONDS) - $now;
        Mage::app()->getCache()->save(
            (string) json_encode(['attempts' => $attempts, 'window_start' => $windowStart]),
            $key,
            [self::CACHE_TAG],
            max(1, $remaining)
        );

        return [
            'allowed'     => $allowed,
            'attempts'    => $attempts,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Clear the bucket for an IP. Call this after a SUCCESSFUL match so
     * legitimate users who fat-fingered earlier attempts don't have
     * their next order-tracking session counting from a stale base.
     * Idempotent — safe to call when there's no bucket.
     */
    public function reset(string $ip): void
    {
        Mage::app()->getCache()->remove(self::CACHE_KEY_PREFIX . $this->hashIp($ip));
    }

    /**
     * Hash the IP so cache keys aren't browseable as raw IPs in any
     * cache backend's CLI. Uses sha256 truncated to 24 chars — entropy
     * is plenty against collisions in a per-IP bucket.
     */
    private function hashIp(string $ip): string
    {
        return substr(hash('sha256', $ip), 0, 24);
    }
}
