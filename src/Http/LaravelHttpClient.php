<?php

namespace Opotopo\SellerApi\Laravel\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class LaravelHttpClient implements ClientInterface
{
    public function __construct(
        private readonly Factory $http,
        private readonly int $timeout,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $pending = $this->http->withHeaders($request->getHeaders())->timeout($this->timeout);
        $body = $request->getBody();
        if ($body->getSize() !== 0) {
            $pending->withBody($body, $request->getHeaderLine('Content-Type'));
        }
        try {
            return $pending->send($request->getMethod(), (string) $request->getUri())->toPsrResponse();
        } catch (ConnectionException $failure) {
            throw new NetworkFailure($request, $failure);
        }
    }
}
