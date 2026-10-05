<?php

namespace Opotopo\SellerApi\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

class SellerApiClient
{
    public const SYNC_PATH = '/rest/v1/advertisement/seller/ads/sync';

    public const AD_PATH = '/rest/v1/advertisement/seller/ads/';

    public const UPLOAD_INITIATE_PATH = '/rest/v1/file/seller/upload/initiate';

    public const UPLOAD_COMPLETE_PATH = '/rest/v1/file/seller/upload/complete';

    public const API_KEY_HEADER = 'X-API-Key';

    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    private const RETRY_AFTER_HEADER = 'Retry-After';

    private const TOO_MANY_REQUESTS = 429;

    private const NOT_FOUND = 404;

    private const MILLIS_PER_SECOND = 1000;

    private const OCTET_STREAM = 'application/octet-stream';

    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    public function sync(array $items, string $idempotencyKey): array
    {
        return $this->api()
            ->withHeaders([self::IDEMPOTENCY_HEADER => $idempotencyKey])
            ->post(self::SYNC_PATH, array_filter([
                'items' => $items,
                'organizationId' => $this->config['organization_id'] ?? null,
                'publish' => (bool) ($this->config['publish'] ?? true),
            ], fn ($value) => $value !== null))
            ->json();
    }

    public function archive(string $advertisementId): void
    {
        try {
            $this->api()->delete(self::AD_PATH.$advertisementId);
        } catch (RequestException $failure) {
            if ($failure->response->status() !== self::NOT_FOUND) {
                throw $failure;
            }
        }
    }

    public function initiateUpload(array $request): array
    {
        return $this->api()->post(self::UPLOAD_INITIATE_PATH, $request)->json();
    }

    public function completeUpload(string $fileId, array $parts): void
    {
        $this->api()->post(self::UPLOAD_COMPLETE_PATH, array_filter([
            'fileId' => $fileId,
            'parts' => $parts,
        ]));
    }

    public function put(string $url, array $headers, string $content): Response
    {
        return $this->retrying($this->http->withHeaders($headers))
            ->withBody($content, $headers['Content-Type'] ?? self::OCTET_STREAM)
            ->put($url);
    }

    private function api(): PendingRequest
    {
        return $this->retrying($this->http
            ->baseUrl(rtrim((string) $this->config['url'], '/'))
            ->withHeaders([self::API_KEY_HEADER => (string) $this->config['key']])
            ->acceptJson());
    }

    private function retrying(PendingRequest $request): PendingRequest
    {
        $retry = $this->config['retry'] ?? [];

        return $request
            ->timeout((int) ($this->config['timeout'] ?? 0))
            ->retry(
                max(1, (int) ($retry['times'] ?? 1)),
                fn (int $attempt, Throwable $failure) => $this->pause($failure, (int) ($retry['sleep_ms'] ?? 0)),
                fn (Throwable $failure) => self::retryable($failure),
            );
    }

    private function pause(Throwable $failure, int $fallback): int
    {
        $retryAfter = $failure instanceof RequestException
            ? $failure->response->header(self::RETRY_AFTER_HEADER)
            : '';

        return is_numeric($retryAfter) ? (int) $retryAfter * self::MILLIS_PER_SECOND : $fallback;
    }

    private static function retryable(Throwable $failure): bool
    {
        if ($failure instanceof ConnectionException) {
            return true;
        }

        return $failure instanceof RequestException
            && ($failure->response->status() === self::TOO_MANY_REQUESTS || $failure->response->serverError());
    }
}
