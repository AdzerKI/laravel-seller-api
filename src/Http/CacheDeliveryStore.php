<?php

namespace Opotopo\SellerApi\Laravel\Http;

use Illuminate\Contracts\Cache\Repository;
use Opotopo\SellerApi\Webhooks\DeliveryStore;

final class CacheDeliveryStore implements DeliveryStore
{
    private const PREFIX = 'opotopo:webhook:';

    private const LOCK_SUFFIX = ':lock';

    private const LOCK_SECONDS = 60;

    private array $locks = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttl,
    ) {}

    public function acquire(string $webhookId): bool
    {
        $lock = $this->cache->lock(self::PREFIX.$webhookId.self::LOCK_SUFFIX, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return false;
        }
        $this->locks[$webhookId] = $lock;

        return true;
    }

    public function release(string $webhookId): void
    {
        $lock = $this->locks[$webhookId] ?? null;
        unset($this->locks[$webhookId]);
        $lock?->release();
    }

    public function processed(string $webhookId): bool
    {
        return $this->cache->has(self::PREFIX.$webhookId);
    }

    public function markProcessed(string $webhookId): void
    {
        $this->cache->put(self::PREFIX.$webhookId, true, $this->ttl);
    }
}
