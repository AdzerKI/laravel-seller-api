<?php

namespace Opotopo\SellerApi\Laravel\Http;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Opotopo\SellerApi\Webhooks\WebhookReceiver;

class WebhookController
{
    public function __construct(
        private readonly WebhookReceiver $receiver,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(Request $request): Response
    {
        $headers = [];
        foreach (WebhookReceiver::HEADERS as $name) {
            $headers[$name] = $request->header($name);
        }
        $result = $this->receiver->receive(
            $request->getContent(),
            $headers,
            fn (object $event) => $this->events->dispatch($event),
        );

        return response()->noContent($result->status());
    }
}
