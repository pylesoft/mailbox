<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GrantIdentity;
use Pyle\Mailbox\DTOs\GraphSubscriptionConfiguration;
use Pyle\Mailbox\DTOs\GraphSubscriptionRegistration;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\ReplyForwardContext;
use Pyle\Mailbox\DTOs\StoredGrant;
use Pyle\Mailbox\DTOs\SyncBudget;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionEvidenceOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\SqliteSubmissionHost;

it('keeps editor drafts local and restores host owned grants preparation and uncertain receipts without resending', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'mailbox-host-');
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com');
    $history = [];
    $draft = [
        'id' => 'immutable-draft=', 'isDraft' => true, 'hasAttachments' => false, 'attachments' => [],
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'sender@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Recipient', 'address' => 'to@example.com']]],
        'ccRecipients' => [], 'bccRecipients' => [], 'subject' => 'Chosen subject',
        'body' => ['contentType' => 'text', 'content' => 'Private editor body'],
        'internetMessageHeaders' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'host-operation']],
    ];
    $handler = HandlerStack::create(new MockHandler([
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new ConnectException('provider timeout', new Request('POST', 'https://graph.microsoft.com')),
        new Response(404),
    ]));
    $handler->push(Middleware::history($history));

    try {
        $host = new SqliteSubmissionHost($path);
        $host->bind($context);
        $grant = new StoredGrant(new GrantIdentity('ms-graph', 'issuer', 'subject', 'client', 'tenant'),
            'fixture-token', 'fixture-refresh', new DateTimeImmutable('+1 hour'), ['Mail.Send'], 'revision-1');
        expect($host->replace($context, null, $grant))->toBeTrue();
        expect($host->replace($context, null, $grant))->toBeFalse()
            ->and((new SqliteSubmissionHost($path))->load($context)->revision)->toBe('revision-1');
        $host->retain('editor-draft', ['subject' => 'Chosen subject', 'body' => 'Private editor body']);
        expect($history)->toBe([]);

        $host = new SqliteSubmissionHost($path);
        $editor = $host->restore('editor-draft');
        $message = new PreparedMessage(new EmailAddressDto('Sender', 'sender@example.com'),
            [new EmailAddressDto('Recipient', 'to@example.com')], $editor['subject'], 'host-operation', textBody: $editor['body']);
        $mailbox = Mailbox::forGrant($context, $host, new Client(['handler' => $handler]));
        $host->retain('preparation', $mailbox->prepareSubmission($message));

        $host = new SqliteSubmissionHost($path);
        $mailbox = Mailbox::forGrant($context, $host, new Client(['handler' => $handler]));
        $host->retain('receipt', $mailbox->submitPrepared($host->restore('preparation'), $message));
        $evidence = $mailbox->inspectSubmission($host->restore('preparation'), $message);

        expect((new SqliteSubmissionHost($path))->restore('receipt')->outcome)->toBe(SubmissionOutcome::Uncertain)
            ->and($evidence->outcome)->toBe(SubmissionEvidenceOutcome::Inconclusive)
            ->and($host->restore('editor-draft'))->toBe($editor)
            ->and($history)->toHaveCount(4)
            ->and($history[2]['request']->getUri()->getPath())->toEndWith('/immutable-draft%3D/send')
            ->and(config('database.default'))->toBe('sdk-forbidden');

        $host->bind(new GrantContext('connection', 'grant', '2', 'ms-graph', 'sender@example.com'));
        expect(fn () => $mailbox->inspectSubmission($host->restore('preparation'), $message))->toThrow(GrantAccessDeniedException::class)
            ->and($history)->toHaveCount(4);
    } finally {
        unset($host, $mailbox);
        unlink($path);
    }
});

it('restores a host retained native file submission and reconciles a missed notification from its durable checkpoint', function (ReplyForwardOperation $operation): void {
    $path = tempnam(sys_get_temp_dir(), 'mailbox-host-');
    $filePath = tempnam(sys_get_temp_dir(), 'mailbox-source-');
    $bytes = str_repeat('a', 3_000_000).'z';
    file_put_contents($filePath, $bytes);
    $context = new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com');
    $source = ['id' => 'source=', 'conversationId' => 'conversation', 'isDraft' => false,
        'body' => ['contentType' => 'text', 'content' => 'Original content']];
    $draft = ['id' => 'immutable-draft=', 'conversationId' => 'conversation', 'isDraft' => true,
        'hasAttachments' => false, 'attachments' => [],
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'sender@example.com']],
        'toRecipients' => [['emailAddress' => ['name' => 'Recipient', 'address' => 'to@example.com']]],
        'ccRecipients' => [], 'bccRecipients' => [], 'subject' => 'Chosen subject',
        'body' => ['contentType' => 'text', 'content' => 'Chosen body'],
        'internetMessageHeaders' => [['name' => 'X-Mailbox-Correlation-Id', 'value' => 'host-operation']]];
    $remote = array_replace($draft, ['hasAttachments' => true, 'attachments' => [[
        '@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'chosen-file=', 'name' => 'chosen.bin',
        'contentType' => 'application/octet-stream', 'size' => 3_000_001, 'isInline' => false, 'contentId' => null,
    ]]]);
    $url = "https://outlook.office.com/api/v2.0/Users('user')/Messages('draft')/AttachmentSessions('session')?authtoken=private-upload";
    $expiry = new DateTimeImmutable('+1 hour');
    $delta = 'https://graph.microsoft.com/v1.0/users/sender%40example.com/mailFolders/inbox/messages/delta?$deltatoken=committed%2F1';
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode($source, JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(200, [], '{"value":[]}'),
        new Response(200, [], json_encode($draft, JSON_THROW_ON_ERROR)),
        new Response(201, [], json_encode(['uploadUrl' => $url, 'expirationDateTime' => $expiry->format(DATE_ATOM), 'nextExpectedRanges' => ['0']], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['expirationDateTime' => $expiry->format(DATE_ATOM), 'nextExpectedRanges' => ['3000000']], JSON_THROW_ON_ERROR)),
        new Response(201, ['Location' => "https://outlook.office.com/api/v2.0/Users('user')/Messages('draft')/Attachments('chosen-file=')"]),
        new Response(200, [], json_encode($remote, JSON_THROW_ON_ERROR)), new Response(200, [], $bytes), new Response(202),
        new Response(200, [], json_encode(['value' => [], '@odata.deltaLink' => $delta], JSON_THROW_ON_ERROR)),
        new Response(200, [], json_encode(['value' => [['id' => 'recovered-message']], '@odata.deltaLink' => str_replace('committed%2F1', 'committed%2F2', $delta)], JSON_THROW_ON_ERROR)),
    ]));
    $handler->push(Middleware::history($history));

    try {
        $host = new SqliteSubmissionHost($path);
        $host->bind($context);
        $host->replace($context, null, new StoredGrant(new GrantIdentity('ms-graph', 'issuer', 'subject', 'client', 'tenant-1'),
            'fixture-token', null, $expiry, ['Mail.ReadWrite', 'Mail.Send'], 'revision-1'));
        $file = new PreparedFile('host-file', 'chosen.bin', 'application/octet-stream', 3_000_001,
            static fn () => Utils::streamFor(fopen($filePath, 'rb')), hash('sha256', $bytes));
        $scope = new SyncScope('inbox');
        $conversation = new ConversationRead(new SyncIdentity('ms-graph', $context, $scope), 'conversation', [MessageDto::fromMsGraph($source)], complete: true);
        $message = new PreparedMessage(new EmailAddressDto('Sender', 'sender@example.com'),
            [new EmailAddressDto('Recipient', 'to@example.com')], 'Chosen subject', 'host-operation', textBody: 'Chosen body',
            files: [$file], context: new ReplyForwardContext($operation, $conversation, 'source='));
        $mailbox = Mailbox::forGrant($context, $host, new Client(['handler' => $handler]));
        $host->retain('preparation', $mailbox->prepareSubmission($message));
        $host->retain('preparation', $mailbox->prepareAttachments($host->restore('preparation'), $message));
        $host->retain('preparation', $mailbox->prepareAttachments($host->restore('preparation'), $message));
        expect($host->restore('preparation')->attachments[0]->acknowledgedBytes)->toBe(3_000_000);

        $host = new SqliteSubmissionHost($path);
        $mailbox = Mailbox::forGrant($context, $host, new Client(['handler' => $handler]));
        $host->retain('preparation', $mailbox->prepareAttachments($host->restore('preparation'), $message));
        expect($host->restore('preparation')->outcome)->toBe(PreparationOutcome::Prepared);
        $host->retain('receipt', $mailbox->submitPrepared($host->restore('preparation'), $message));
        expect((new SqliteSubmissionHost($path))->restore('receipt')->outcome)->toBe(SubmissionOutcome::Accepted)
            ->and($history[1]['request']->getUri()->getPath())->toEndWith($operation === ReplyForwardOperation::Reply ? '/source%3D/createReply' : '/source%3D/createForward')
            ->and($history[9]['request']->getUri()->getPath())->toEndWith('/immutable-draft%3D/send');
        foreach ([5, 6] as $index) {
            expect($history[$index]['request']->hasHeader('Authorization'))->toBeFalse();
        }
        expect($history[5]['request']->getHeaderLine('Content-Range'))->toBe('bytes 0-2999999/3000001')
            ->and($history[6]['request']->getHeaderLine('Content-Range'))->toBe('bytes 3000000-3000000/3000001');

        $host->retain('checkpoint', $mailbox->initialSync($scope, new SyncBudget)->checkpoint);
        $checkpoint = $host->restore('checkpoint');
        $registration = new GraphSubscriptionRegistration($context,
            new GraphSubscriptionConfiguration('tenant-1', 'provider-user', 'https://host.example/changes', 'https://host.example/lifecycle', 'private-client-state', 'inbox'),
            'subscription-1', $expiry);
        $event = ['id' => 'missed-1', 'subscriptionId' => 'subscription-1', 'clientState' => 'private-client-state',
            'tenantId' => 'tenant-1', 'subscriptionExpirationDateTime' => $expiry->format(DATE_ATOM), 'lifecycleEvent' => 'missed'];
        $hints = $mailbox->subscriptionNotifications(new Request('POST', 'https://host.example/lifecycle', ['Content-Type' => 'application/json'], json_encode(['value' => [$event, $event]], JSON_THROW_ON_ERROR)), [$registration]);
        $host->retain('notification-receipt', $hints);
        expect($host->restore('checkpoint'))->toEqual($checkpoint)->and($history)->toHaveCount(11);
        $host = new SqliteSubmissionHost($path);
        expect($host->restore('notification-receipt')[0]->reason)->toBe('missed');
        $page = $mailbox->incrementalSync($scope, new SyncBudget, $host->restore('checkpoint'));
        $host->retain('durable-page', $page);
        $host->retain('checkpoint', $page->checkpoint);
        expect($host->restore('durable-page')->upserts)->toBe(['recovered-message'])
            ->and((string) $history[11]['request']->getUri())->toBe($delta)
            ->and($history)->toHaveCount(12)
            ->and(file_exists($filePath))->toBeTrue()
            ->and(config('database.default'))->toBe('sdk-forbidden');
        foreach ($history as $entry) {
            if ($entry['request']->getMethod() !== 'GET') {
                expect($entry['request']->getUri()->getPath())->not->toEndWith('/messages/source%3D');
            }
        }
    } finally {
        unset($host, $mailbox);
        unlink($path);
        unlink($filePath);
    }
})->with([ReplyForwardOperation::Reply, ReplyForwardOperation::Forward]);
