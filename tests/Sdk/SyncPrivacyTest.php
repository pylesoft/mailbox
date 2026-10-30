<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncCheckpoint;
use Pyle\Mailbox\DTOs\SyncContinuation;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;

it('redacts private sync state and malformed envelopes from rejecting SDK traces', function (string $provider, string $mode): void {
    $history = [];
    $link = 'https://graph.microsoft.com/v1.0/users/same%40example.com/mailFolders/FolderA/messages/delta?unrelated=private-link-secret';
    $body = $provider === 'gmail' ? '{"messages":{},"nextPageToken":"private-envelope-secret"}'
        : '{"value":{},"@odata.deltaLink":"'.$link.'"}';
    $stack = HandlerStack::create(new MockHandler($mode === 'envelope' ? [new Response(200, [], $body)] : []));
    $stack->push(Middleware::history($history));
    $context = new GrantContext('connection', 'grant', '1', $provider, 'same@example.com');
    $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['same@example.com'], 'token' => 'selected-token']]);
    $mailbox = Mailbox::forGrant($context, $tokens, new Client(['handler' => $stack]));
    $scope = new SyncScope('FolderA');
    $identity = new SyncIdentity($provider, $context, $scope);
    $checkpoint = new SyncCheckpoint($identity, $provider === 'gmail' ? 'private-checkpoint-secret'
        : json_encode(['folders' => [['folderId' => 'FolderA', 'deltaLink' => $link]]], JSON_THROW_ON_ERROR));
    $continuation = new SyncContinuation($identity, $provider === 'gmail' ? 'invalid-phase' : 'ms-graph.delta', $provider === 'gmail'
        ? ['baseline' => '100', 'pageToken' => 'private-continuation-secret', 'seenTokens' => []]
        : ['baseline' => null, 'enumeration' => [], 'folders' => [['folderId' => 'FolderA', 'nextLink' => $link, 'deltaLink' => null, 'seenTokens' => []]]]);
    if ($mode === 'envelope' && $provider === 'gmail') {
        $continuation = new SyncContinuation($identity, 'gmail.initial', ['baseline' => '100', 'pageToken' => null, 'seenTokens' => []]);
    }
    if ($mode === 'history') {
        $checkpoint = new SyncCheckpoint($identity, $provider === 'gmail' ? '100'
            : json_encode(['folders' => [['folderId' => 'FolderA', 'deltaLink' => str_replace('unrelated=private-link-secret', '$deltatoken=private-previous-secret', $link)]]], JSON_THROW_ON_ERROR));
    }
    if ($mode === 'generation') {
        $continuation = new SyncContinuation(new SyncIdentity($provider, new GrantContext('connection', 'grant', 'old', $provider, 'same@example.com'), $scope),
            $continuation->phase, $continuation->position);
    }
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        try {
            if (in_array($mode, ['checkpoint', 'history'], true)) {
                $mailbox->incrementalSync($scope, new SyncBudget, $checkpoint, $mode === 'history' ? $continuation : null);
            } else {
                $mailbox->initialSync($scope, new SyncBudget, $mode === 'envelope' && $provider !== 'gmail' ? null : $continuation);
            }
            $this->fail('Private malformed synchronization state was accepted.');
        } catch (MailboxException $exception) {
            $trace = array_values(array_filter($exception->getTrace(), fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Pyle\\Mailbox\\')));
            foreach (['private-link-secret', 'private-checkpoint-secret', 'private-continuation-secret', 'private-envelope-secret', 'private-previous-secret'] as $secret) {
                expect(json_encode($trace, JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain($secret);
            }
            expect($history)->toHaveCount($mode === 'envelope' ? 1 : 0);
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
})->with(['gmail', 'ms-graph'])->with(['continuation', 'checkpoint', 'envelope', 'history', 'generation']);
