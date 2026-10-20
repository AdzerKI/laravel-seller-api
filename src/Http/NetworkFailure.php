<?php

namespace Opotopo\SellerApi\Laravel\Http;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

final class NetworkFailure extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
