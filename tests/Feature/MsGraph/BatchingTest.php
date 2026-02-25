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

it('retries failed batch subrequests individually', function (): void {
    $client = new class extends GraphClient
    {
        /** @var array<int, string> */
        public array $retriedEndpoints = [];

        public function __construct() {}

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            if ($endpoint === '/$batch') {
                return [
                    'responses' => [
                        ['id' => '1', 'status' => 200],
                        ['id' => '2', 'status' => 400],
                    ],
                ];
            }

            return ['id' => 'post'];
        }

        public function patch(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            $this->retriedEndpoints[] = $endpoint;

            return ['id' => 'retry'];
        }
    };

    $batch = new BatchRequest($client);

    $response = $batch->send([
        ['id' => '1', 'method' => 'PATCH', 'url' => '/x'],
        ['id' => '2', 'method' => 'PATCH', 'url' => '/y'],
    ]);

    expect($client->retriedEndpoints)->toBe(['/y']);
    expect((array) $response['responses'])->toHaveCount(2);
});

it('throws when an individually retried subrequest fails', function (): void {
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

        public function patch(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            throw new ApiRequestException('Retry failed', status: 500, endpoint: $endpoint);
        }
    };

    $batch = new BatchRequest($client);

    $batch->send([
        ['id' => '1', 'method' => 'PATCH', 'url' => '/x'],
        ['id' => '2', 'method' => 'PATCH', 'url' => '/y'],
    ]);
})->throws(ApiRequestException::class);
