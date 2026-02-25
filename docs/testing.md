# Testing

Mailbox is designed to stay out of your way during testing. Whether you need to mock a single facade call or simulate an entire sync pipeline, the package provides first-class testing utilities so you can verify your application logic without ever hitting a live provider. This page covers the patterns you will use most often in your own test suites, plus a brief section for contributors working on the package itself.

## Facade Mocking

Since every Mailbox interaction flows through the `Mailbox` facade, you can mock it exactly the way you mock any other Laravel facade. Mailbox uses Mockery under the hood, so `shouldReceive` works out of the box.

```php
use Pyle\Mailbox\Facades\Mailbox;

it('lists inbox messages for the billing mailbox', function () {
    Mailbox::shouldReceive('mailbox')
        ->with('invoices@acme.com')
        ->once()
        ->andReturn($mockResource);

    // Call the code under test that uses Mailbox::mailbox('invoices@acme.com')
});
```

You can mock any method exposed by the facade: `driver`, `mailbox`, `forMailbox`, `forFolder`, `testConnection`, `healthCheck`, and `filterableFields`.

### Mocking a Specific Driver

If your application explicitly selects a driver, mock the `driver` method and chain from there:

```php
Mailbox::shouldReceive('driver')
    ->with('gmail')
    ->once()
    ->andReturn($fakeDriver);
```

### Mocking Connection Tests

Connection tests are a common target for mocking, especially in health-check endpoints or admin dashboards:

```php
use Pyle\Mailbox\DTOs\ConnectionTestResult;

Mailbox::shouldReceive('testConnection')
    ->with('invoices@acme.com')
    ->once()
    ->andReturn(new ConnectionTestResult(
        success: true,
        latencyMs: 42,
        driver: 'ms-graph',
        message: 'OK',
    ));
```

## MailboxMock -- The Built-in Mock Helper

When your application uses the raw provider client -- `Mailbox::driver()->raw()` -- you need a way to stub that underlying client without wiring up real credentials. The `MailboxMock` class does exactly this. It binds a Mockery mock of the raw client into the facade so your test can set expectations on low-level API calls.

### Mocking the Microsoft Graph Raw Client

```php
use Pyle\Mailbox\Testing\MailboxMock;

it('fetches messages via the raw Graph client', function () {
    $graphClient = MailboxMock::mockMsGraphRawClient();

    $graphClient->shouldReceive('get')
        ->once()
        ->with('users/invoices@acme.com/messages')
        ->andReturn(['value' => [
            ['id' => 'msg-1', 'subject' => 'Invoice #1042'],
        ]]);

    // Call the code that uses Mailbox::driver('ms-graph')->raw()->get(...)
});
```

The `mockMsGraphRawClient` method returns a `Mockery\MockInterface` wrapping the `GraphClient` class. You can set any Mockery expectation on it -- `shouldReceive`, `shouldNotReceive`, `times`, `andReturn`, `andThrow`, and so on.

### Mocking the Gmail Raw Client

The Gmail counterpart works identically:

```php
$gmailClient = MailboxMock::mockGmailRawClient();

$gmailClient->shouldReceive('get')
    ->once()
    ->with('users/me/messages')
    ->andReturn(['messages' => [
        ['id' => 'msg-abc', 'threadId' => 'thread-1'],
    ]]);
```

### Specifying a Custom Driver Name

Both methods accept an optional driver name if your configuration uses a non-default key. For example, if you registered a `google-workspace` alias:

```php
$gmailClient = MailboxMock::mockGmailRawClient('google-workspace');
```

### How MailboxMock Works Internally

When you call `MailboxMock::mockMsGraphRawClient()`, the helper:

1. Creates a Mockery mock of `GraphClient`.
2. Wraps it in an anonymous class that implements `MailboxDriver` and `SupportsRawClient`.
3. Binds that fake driver to `Mailbox::driver('ms-graph')` via `shouldReceive`.
4. If the driver name matches the default driver, also binds `Mailbox::driver()` (no arguments).
5. Returns the raw client mock for you to set expectations on.

The fake driver intentionally throws a `RuntimeException` if you call `mailbox()`, `testConnection()`, or `healthCheck()` on it. It only supports `raw()`. If you need to mock higher-level methods, use facade mocking directly instead.

> **Note** `MailboxMock` requires `mockery/mockery` in your dev dependencies. If Mockery is not installed, the helper throws a clear error telling you to add it.

## Event Faking

Mailbox dispatches events throughout the sync lifecycle, and you can use Laravel's `Event::fake()` to assert they were fired correctly.

```php
use Illuminate\Support\Facades\Event;
use Pyle\Mailbox\Events\DeltaSyncCompleted;
use Pyle\Mailbox\Events\DeltaSyncStarted;

it('dispatches sync events during a delta sync', function () {
    Event::fake([DeltaSyncStarted::class, DeltaSyncCompleted::class]);

    // Run the code that triggers a sync...

    Event::assertDispatched(DeltaSyncStarted::class, function ($event) {
        return $event->mailbox === 'invoices@acme.com'
            && $event->folder === 'Inbox';
    });

    Event::assertDispatched(DeltaSyncCompleted::class, function ($event) {
        return $event->created === 3
            && $event->updated === 1
            && $event->deleted === 0;
    });
});
```

### Available Events

Mailbox fires the following events that you can fake and assert against:

| Event | When It Fires |
| --- | --- |
| `DeltaSyncStarted` | A delta sync begins for a folder |
| `DeltaSyncCompleted` | A delta sync finishes successfully |
| `DeltaTokenExpired` | A stored delta token is no longer valid |
| `TokenAcquired` | An OAuth token is obtained from the provider |
| `TokenRefreshFailed` | A token refresh attempt fails |
| `RateLimitHit` | The provider returns a 429 response |
| `AccessDenied` | The provider returns a 403 response |
| `ApiError` | Any non-success API response |
| `AttachmentDownloaded` | An attachment is saved to disk |
| `AttachmentSkipped` | An attachment download is skipped (already exists) |
| `ConnectionTestCompleted` | A connection test finishes |
| `SecretExpirationWarning` | A client secret is approaching its expiry date |

> **Tip** You can fake only the events you care about and let others dispatch normally. Pass an array of event classes to `Event::fake()` to be selective.

## Testing Sync Jobs

If your application dispatches sync work through a queued job, you can test the job in isolation using facade mocking and `Bus::fake()`:

```php
use Illuminate\Support\Facades\Bus;

it('dispatches a sync job for each monitored folder', function () {
    Bus::fake();

    // Trigger the code that dispatches sync jobs...

    Bus::assertDispatched(SyncFolderJob::class, function ($job) {
        return $job->folder->id === 1;
    });
});
```

For synchronous testing where you want the job to actually execute, mock the Mailbox facade and let the job run inline:

```php
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\Facades\Mailbox;

it('processes a sync job and updates the folder', function () {
    Mailbox::shouldReceive('forFolder->delta')
        ->once()
        ->andReturn(new DeltaResultDto(
            created: collect([]),
            updated: collect([]),
            deleted: collect([]),
            deltaLink: 'new-delta-token',
        ));

    // Dispatch and run the job synchronously...
});
```

## Testing Rule Matching

The `MessageMatcher` class evaluates filter rules against messages and attachments. You can test your rules directly by constructing a `MessageDto` and passing it through the matcher.

```php
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\Importance;
use Pyle\Mailbox\Support\MessageMatcher;

it('matches invoices from a specific sender', function () {
    $rules = [
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'from.address', 'operator' => 'contains', 'value' => '@vendor.com'],
            ['field' => 'subject', 'operator' => 'starts_with', 'value' => 'INV-'],
        ],
    ];

    $matcher = new MessageMatcher($rules);

    $message = new MessageDto(
        id: 'msg-1',
        subject: 'INV-2024-0042',
        bodyPreview: 'Please find attached...',
        body: null,
        from: new EmailAddressDto('billing@vendor.com', 'Vendor Billing'),
        sender: null,
        toRecipients: [new EmailAddressDto('invoices@acme.com', 'Acme Invoices')],
        ccRecipients: [],
        bccRecipients: [],
        receivedAt: now()->toImmutable(),
        sentAt: null,
        isRead: false,
        isDraft: false,
        hasAttachments: true,
        importance: Importance::NORMAL,
        conversationId: null,
        internetMessageId: null,
        parentFolderId: 'inbox',
    );

    expect($matcher->matches($message))->toBeTrue();
});
```

### Testing Attachment Rules

When your rules include attachment-based conditions, pass attachments as the second argument:

```php
use Pyle\Mailbox\DTOs\AttachmentDto;

$rules = [
    'operator' => 'AND',
    'conditions' => [
        ['field' => 'attachmentName', 'operator' => 'ends_with', 'value' => '.pdf'],
        ['field' => 'attachmentCount', 'operator' => 'greater_than', 'value' => 0],
    ],
];

$matcher = new MessageMatcher($rules);

$attachments = [
    new AttachmentDto(
        id: 'att-1',
        name: 'invoice-2024.pdf',
        contentType: 'application/pdf',
        size: 102400,
    ),
];

expect($matcher->matches($message, $attachments))->toBeTrue();
```

### Testing Nested Rule Groups

The matcher supports nested groups with `AND` / `OR` logic. You can test complex rule trees:

```php
$rules = [
    'operator' => 'OR',
    'conditions' => [
        [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'from.address', 'operator' => 'contains', 'value' => '@vendor.com'],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
            ],
        ],
        [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'importance', 'operator' => 'equals', 'value' => 'high'],
                ['field' => 'hasAttachments', 'operator' => 'equals', 'value' => true],
            ],
        ],
    ],
];

$matcher = new MessageMatcher($rules);

// Matches if EITHER group is satisfied
expect($matcher->matches($invoiceMessage))->toBeTrue();
expect($matcher->matches($urgentAttachmentMessage))->toBeTrue();
```

### Available Match Operators

The `MatchOperator` enum defines every comparison you can use in rules:

| Operator | Description |
| --- | --- |
| `equals` | Case-insensitive equality |
| `not_equals` | Case-insensitive inequality |
| `contains` | Substring match (case-insensitive) |
| `not_contains` | Negated substring match |
| `starts_with` | String prefix match |
| `ends_with` | String suffix match |
| `matches_regex` | Full regex pattern match |
| `greater_than` | Numeric or date comparison |
| `less_than` | Numeric or date comparison |
| `before` | Date comparison (alias for `less_than`) |
| `after` | Date comparison (alias for `greater_than`) |
| `between` | Range check (expects a two-element array) |

## Testing Exception Handling

Mailbox throws specific exceptions that your application should handle gracefully. You can test your error-handling logic by having mocks throw these exceptions:

```php
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Facades\Mailbox;

it('handles rate limiting gracefully', function () {
    Mailbox::shouldReceive('mailbox->messages->get')
        ->once()
        ->andThrow(new RateLimitException(
            retryAfter: 30,
            mailbox: 'invoices@acme.com',
            message: 'Rate limit exceeded',
        ));

    // Assert your application retries or shows a user-friendly error...
});
```

The full exception hierarchy extends from `MailboxException`:

| Exception | When It Is Thrown |
| --- | --- |
| `AuthenticationException` | Invalid credentials or expired tokens |
| `MailboxAccessDeniedException` | No permission to access a mailbox |
| `RateLimitException` | Provider returns 429 (includes `retryAfter`) |
| `DeltaTokenExpiredException` | Stored delta token is stale |
| `ResourceNotFoundException` | Message, folder, or attachment not found |
| `ApiRequestException` | General API error (includes `status` and `endpoint`) |
| `ProviderServerException` | Provider returned 5xx after all retries |
| `DriverNotConfiguredException` | Referenced driver has no configuration |

## Package-Level Testing

If you are contributing to the Mailbox package itself, the test suite uses [Pest](https://pestphp.com) and [Orchestra Testbench](https://github.com/orchestral/testbench).

### Running the Suite

```bash
vendor/bin/pest
```

Static analysis and code style checks:

```bash
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

### Parallel Tests

When `TEST_TOKEN` is set (parallel mode), the harness switches from in-memory SQLite to per-worker file databases at `storage/framework/testing/test-{TEST_TOKEN}.sqlite`:

```bash
vendor/bin/testbench package:test --parallel --recreate-databases
```

### Lowest-Dependency Verification

CI runs a lowest-dependency lane to catch compatibility issues early:

```bash
composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist
vendor/bin/pest
```

### CI Matrix

The continuous integration pipeline runs three jobs:

- **tests-latest** -- PHP 8.2, 8.3, 8.4 with latest locked dependencies
- **qa-static-style** -- PHP 8.2 for PHPStan and Pint
- **tests-lowest** -- PHP 8.2 with `--prefer-lowest --prefer-stable`

### Test Categories

- **Unit** -- DTO mapping, enums, matcher, filter compiler
- **Feature** -- Driver behavior, commands, sync, attachments, batching
- **Feature Infrastructure** -- Test harness boot, publish tags, OAuth route gating
- **Architecture** -- Contract and structural guardrails

## What's Next

- [Error Handling](error-handling.md) -- learn about the exception hierarchy and retry strategies
- [Rule Matching](rule-matching.md) -- deep dive into the `MessageMatcher` and filter conditions
- [Events](events.md) -- full reference for every event Mailbox dispatches
