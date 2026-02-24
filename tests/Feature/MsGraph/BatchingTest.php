<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\BatchRequest;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Exceptions\ApiRequestException;

it('chunks batch requests into max 20 items', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, array<string, mixed>> */
        public array $payloads = [];

        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            $this->payloads[] = $payload;

            return [
                'responses' => collect((array) ($payload['requests'] ?? []))
                    ->map(fn (array $request): array => ['id' => $request['id'] ?? '0', 'status' => 200])
                    ->all(),
            ];
        }
    };

    $batch = new BatchRequest($client);

    $requests = [];
    for ($i = 1; $i <= 45; $i++) {
        $requests[] = ['id' => (string) $i, 'method' => 'PATCH', 'url' => '/x'];
    }

    $response = $batch->send($requests);

    expect($client->payloads)->toHaveCount(3);
    expect((array) $client->payloads[0]['requests'])->toHaveCount(20);
    expect((array) $client->payloads[1]['requests'])->toHaveCount(20);
    expect((array) $client->payloads[2]['requests'])->toHaveCount(5);
    expect((array) $response['responses'])->toHaveCount(45);
});

it('throws when any batch subrequest fails', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            return [
                'responses' => [
                    ['id' => '1', 'status' => 200],
                    ['id' => '2', 'status' => 400],
                ],
            ];
        }
    };

    $batch = new BatchRequest($client);

    $batch->send([
        ['id' => '1', 'method' => 'PATCH', 'url' => '/x'],
        ['id' => '2', 'method' => 'PATCH', 'url' => '/y'],
    ]);
})->throws(ApiRequestException::class);
