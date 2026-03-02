# Recipes

Every project has its own twist on email integration. This page collects battle-tested patterns that go beyond the basics -- complete, production-ready examples you can drop into your application and adapt. Each recipe combines several Mailbox features into a cohesive workflow so you can see how the pieces fit together in real-world scenarios.

## Invoice Processing Pipeline

Accounts payable teams often need to monitor a shared mailbox, identify invoices by sender or subject, download PDF attachments, and file the original message away. This recipe wires up a scheduled command that does exactly that -- sync new messages, match them against rules, archive the PDFs, and move everything to a "Processed" folder.

### The Migration

Start by creating a table to track processed invoices. This prevents duplicate downloads if a sync overlaps or a job retries.

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('from_address');
            $table->string('subject');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_disk')->nullable();
            $table->timestamps();
        });
    }
};
```

### The Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedInvoice extends Model
{
    protected $fillable = [
        'message_id',
        'from_address',
        'subject',
        'attachment_path',
        'attachment_disk',
    ];
}
```

### The Command

```php
namespace App\Console\Commands;

use App\Models\ProcessedInvoice;
use Illuminate\Console\Command;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Support\MessageMatcher;

class ProcessInvoices extends Command
{
    protected $signature = 'invoices:process';
    protected $description = 'Sync inbox, match invoice emails, download PDFs, and archive.';

    public function handle(): int
    {
        $mailbox = Mailbox::mailbox('invoices@acme.com');

        // Step 1: Fetch recent unread messages with attachments
        $messages = $mailbox->messages()
            ->inFolder(WellKnownFolder::INBOX)
            ->where(FilterableField::IS_READ, false)
            ->where(FilterableField::HAS_ATTACHMENTS, true)
            ->orderBy('receivedDateTime', 'desc')
            ->take(50)
            ->get(); // Collection<int, MessageDto>

        $this->info("Found {$messages->count()} unread messages with attachments.");

        // Step 2: Define matching rules for invoice emails
        $matcher = new MessageMatcher([
            'operator' => 'OR',
            'conditions' => [
                [
                    'field' => 'from.address',
                    'operator' => 'ends_with',
                    'value' => '@vendor.com',
                ],
                [
                    'field' => 'subject',
                    'operator' => 'contains',
                    'value' => 'invoice',
                ],
                [
                    'field' => 'subject',
                    'operator' => 'matches_regex',
                    'value' => '/INV-\d{4,}/i',
                ],
            ],
        ]);

        // Step 3: Ensure destination folder exists
        $processedFolder = $mailbox->folders()->find('Processed')
            ?? $mailbox->folders()->create('Processed');

        $processed = 0;

        foreach ($messages as $message) {
            if (ProcessedInvoice::where('message_id', $message->id)->exists()) {
                continue;
            }

            $messageResource = $mailbox->message($message->id);
            $attachments = $messageResource->attachments(); // Collection<int, AttachmentDto>

            if (! $matcher->matches($message, $attachments)) {
                continue;
            }

            // Step 4: Download PDF attachments
            $files = $messageResource->downloadAttachments();

            $pdfFile = $files->first(
                fn ($file) => $file->contentType === 'application/pdf'
            );

            // Step 5: Record and move
            ProcessedInvoice::create([
                'message_id' => $message->id,
                'from_address' => $message->from?->address ?? 'unknown',
                'subject' => $message->subject,
                'attachment_path' => $pdfFile?->path,
                'attachment_disk' => $pdfFile?->disk,
            ]);

            $messageResource->markAsRead();
            $messageResource->moveTo($processedFolder->id);
            $processed++;
        }

        $this->info("Processed {$processed} invoice(s).");

        return self::SUCCESS;
    }
}
```

### Scheduling

Register the command in your application's `routes/console.php` file:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('invoices:process')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

> **Tip** The `withoutOverlapping` guard is important here. If the provider's API is slow for a cycle, you do not want two workers processing the same messages.

> **Warning** Always check for previously processed `message_id` values before downloading attachments. Delta sync tokens can expire, and a full re-sync will surface messages you have already handled. See [delta sync](delta-sync.md) for details on token expiration.

## Multi-Mailbox Monitoring Dashboard

When your application manages several shared mailboxes -- say `support@acme.com`, `billing@acme.com`, and `invoices@acme.com` -- you need a single dashboard that shows unread counts, sync health, and folder statistics at a glance. This recipe builds a JSON API endpoint that aggregates that data from your `Mailbox` records.

### The Controller

```php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\Enums\SyncStatus;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox as MailboxFacade;
use Pyle\Mailbox\Models\Folder;
use Pyle\Mailbox\Models\Mailbox;

class MailboxDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $mailboxes = Mailbox::with('folders')
            ->active()
            ->get();

        $dashboard = $mailboxes->map(function (Mailbox $mailbox) {
            $resource = MailboxFacade::forMailbox($mailbox);
            $inbox = $resource->folder(WellKnownFolder::INBOX)->get();

            return [
                'email' => $mailbox->email_address,
                'display_name' => $mailbox->display_name,
                'last_synced_at' => $mailbox->last_synced_at?->toIso8601String(),
                'is_stale' => $mailbox->last_synced_at?->lt(now()->subMinutes(30)) ?? true,
                'inbox' => [
                    'total' => $inbox->totalItemCount,
                    'unread' => $inbox->unreadItemCount,
                ],
                'folders' => $mailbox->folders->map(fn (Folder $folder) => [
                    'name' => $folder->display_name,
                    'sync_status' => $folder->sync_status->value,
                    'last_synced_at' => $folder->last_synced_at?->toIso8601String(),
                    'has_error' => $folder->sync_status === SyncStatus::ERROR,
                    'error' => $folder->last_sync_error,
                ]),
            ];
        });

        return response()->json([
            'mailboxes' => $dashboard,
            'summary' => [
                'total_mailboxes' => $mailboxes->count(),
                'stale_count' => $mailboxes->filter(
                    fn ($m) => $m->last_synced_at?->lt(now()->subMinutes(30)) ?? true
                )->count(),
                'error_folders' => Folder::withErrors()->count(),
                'needs_sync' => Folder::needsSync()->count(),
            ],
        ]);
    }

    public function healthCheck(): JsonResponse
    {
        $result = Mailbox::healthCheck();

        return response()->json([
            'healthy' => $result->healthy,
            'token_valid' => $result->tokenValid,
            'token_expires_in' => $result->tokenExpiresIn,
            'api_reachable' => $result->apiReachable,
            'latency_ms' => $result->latencyMs,
            'secret_expires_at' => $result->secretExpiresAt?->toIso8601String(),
            'secret_expiration_warning' => $result->secretExpirationWarning,
        ]);
    }

    public function testConnection(string $email): JsonResponse
    {
        $result = Mailbox::testConnection($email);

        return response()->json([
            'success' => $result->success,
            'error' => $result->error,
            'latency_ms' => $result->latencyMs,
            'authenticated_as' => $result->authenticatedAs,
        ]);
    }
}
```

### The Routes

```php
use App\Http\Controllers\MailboxDashboardController;

Route::prefix('api/mailbox')->middleware(['auth:sanctum'])->group(function () {
    Route::get('dashboard', [MailboxDashboardController::class, 'index']);
    Route::get('health', [MailboxDashboardController::class, 'healthCheck']);
    Route::get('test/{email}', [MailboxDashboardController::class, 'testConnection']);
});
```

### Stale Mailbox Alert

You can pair the dashboard with a scheduled health check that notifies your team when a mailbox falls behind:

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use App\Notifications\StaleMailboxNotification;
use Pyle\Mailbox\Models\Mailbox;

class CheckMailboxHealth extends Command
{
    protected $signature = 'mailbox:check-health';
    protected $description = 'Alert when monitored mailboxes fall behind on sync.';

    public function handle(): int
    {
        $stale = Mailbox::active()
            ->stale(minutes: 30)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('All mailboxes are healthy.');

            return self::SUCCESS;
        }

        $this->warn("Found {$stale->count()} stale mailbox(es).");

        Notification::route('slack', config('services.slack.ops_webhook'))
            ->notify(new StaleMailboxNotification($stale));

        return self::FAILURE;
    }
}
```

> **Tip** Use the `stale()` scope on `Mailbox` and the `needsSync()` scope on `Folder` to quickly identify mailboxes and folders that have fallen behind. Both accept a configurable minute threshold.

## Graceful Rate Limit Handling in Queue Jobs

Providers like Microsoft Graph and Gmail enforce rate limits. When you process hundreds of messages in queued jobs, you will eventually hit a `429 Too Many Requests` response. Mailbox wraps these in a `RateLimitException` that includes a `retryAfter` value in seconds. This recipe shows how to build a queue job that respects that signal with exponential backoff.

### The Job

```php
namespace App\Jobs;

use App\Models\ProcessedInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Pyle\Mailbox\Exceptions\DeltaTokenExpiredException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Models\Folder;

class SyncInboxMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;

    public function __construct(
        public Folder $folder,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->folder->id),
        ];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 120, 300, 600];
    }

    public function handle(): void
    {
        $folderResource = Mailbox::forFolder($this->folder);

        try {
            $delta = $folderResource->delta($this->folder->delta_token);
        } catch (DeltaTokenExpiredException $e) {
            // Token expired -- reset and perform a full sync
            $this->folder->update([
                'delta_token' => null,
                'last_sync_error' => 'Delta token expired, performing full sync.',
            ]);

            $delta = $folderResource->delta(null);
        }

        // Process created and updated messages
        foreach ($delta->created->merge($delta->updated) as $message) {
            // Your processing logic here...
        }

        // Persist the new delta token for the next sync cycle
        $this->folder->update([
            'delta_token' => $delta->deltaLink,
            'last_synced_at' => now(),
            'sync_status' => 'idle',
            'last_sync_error' => null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->folder->update([
            'sync_status' => 'error',
            'last_sync_error' => $exception->getMessage(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }
}
```

### Releasing on Rate Limits

When a `RateLimitException` fires, the `retryAfter` property tells you exactly how many seconds the provider wants you to wait. Instead of letting the job fail and retry on the standard backoff schedule, you can release it back to the queue with the exact delay:

```php
public function handle(): void
{
    try {
        $this->processFolder();
    } catch (RateLimitException $e) {
        $delay = max($e->retryAfter, 10);

        logger()->warning("Rate limited on {$e->mailbox}. Retrying in {$delay}s.");

        $this->release($delay);

        return;
    }
}

private function processFolder(): void
{
    $folderResource = Mailbox::forFolder($this->folder);

    $delta = $folderResource->delta($this->folder->delta_token);

    foreach ($delta->created->merge($delta->updated) as $message) {
        // Process each message...
    }

    $this->folder->update([
        'delta_token' => $delta->deltaLink,
        'last_synced_at' => now(),
        'sync_status' => 'idle',
        'last_sync_error' => null,
    ]);
}
```

> **Note** The `release()` call does not count against your `$tries` limit. The job is simply placed back on the queue with the specified delay. This is the recommended approach -- see the `queue_retry_strategy` option in your [configuration](configuration.md) for Mailbox's built-in strategy.

### Dispatching Sync Jobs on a Schedule

Pair this job with a scheduled command that fans out one job per active folder:

```php
use Illuminate\Support\Facades\Schedule;
use Pyle\Mailbox\Models\Folder;

Schedule::call(function () {
    Folder::active()
        ->needsSync(minutes: 15)
        ->with('mailbox')
        ->each(fn (Folder $folder) => SyncInboxMessages::dispatch($folder));
})->everyFiveMinutes()->onOneServer();
```

> **Warning** Be mindful of the `max_concurrent_per_mailbox` config value (default: `4`). If you dispatch many folder sync jobs for the same mailbox simultaneously, Mailbox's concurrency lock will serialize them. Keep the fan-out reasonable.

## Content-Addressable Attachment Archive

When multiple vendors send the same PDF (think duplicate invoices or shared templates), you can avoid storing redundant copies by hashing each attachment's content and deduplicating at write time. This recipe pairs Mailbox's attachment streaming with a content-addressable storage pattern.

### The Migration

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_archive', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64)->unique();
            $table->string('original_name');
            $table->string('content_type');
            $table->unsignedBigInteger('size');
            $table->string('storage_path');
            $table->string('disk');
            $table->unsignedInteger('reference_count')->default(1);
            $table->timestamps();
        });

        Schema::create('attachment_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_entry_id')->constrained('attachment_archive')->cascadeOnDelete();
            $table->string('message_id');
            $table->string('attachment_id');
            $table->string('mailbox_email');
            $table->timestamps();
        });
    }
};
```

### The Models

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArchiveEntry extends Model
{
    protected $table = 'attachment_archive';

    protected $fillable = [
        'content_hash',
        'original_name',
        'content_type',
        'size',
        'storage_path',
        'disk',
        'reference_count',
    ];

    public function references(): HasMany
    {
        return $this->hasMany(AttachmentReference::class, 'archive_entry_id');
    }
}
```

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentReference extends Model
{
    protected $fillable = [
        'archive_entry_id',
        'message_id',
        'attachment_id',
        'mailbox_email',
    ];

    public function archiveEntry(): BelongsTo
    {
        return $this->belongsTo(ArchiveEntry::class, 'archive_entry_id');
    }
}
```

### The Archiver Service

```php
namespace App\Services;

use App\Models\ArchiveEntry;
use App\Models\AttachmentReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Pyle\Mailbox\Contracts\AttachmentResource;
use Pyle\Mailbox\DTOs\AttachmentDto;

class AttachmentArchiver
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $basePath = 'attachment-archive',
    ) {}

    public function archive(
        AttachmentResource $attachmentResource,
        AttachmentDto $metadata,
        string $messageId,
        string $mailboxEmail,
    ): ArchiveEntry {
        // Stream the attachment content and compute a SHA-256 hash
        $stream = $attachmentResource->stream();
        $contents = $stream->getContents();
        $hash = hash('sha256', $contents);

        return DB::transaction(function () use ($hash, $contents, $metadata, $messageId, $mailboxEmail) {
            $entry = ArchiveEntry::where('content_hash', $hash)->first();

            if ($entry) {
                // File already archived -- just add a reference
                $entry->increment('reference_count');
            } else {
                // New content -- store the file
                $extension = pathinfo($metadata->name, PATHINFO_EXTENSION) ?: 'bin';
                $storagePath = sprintf(
                    '%s/%s/%s.%s',
                    $this->basePath,
                    substr($hash, 0, 2),
                    $hash,
                    $extension,
                );

                Storage::disk($this->disk)->put($storagePath, $contents);

                $entry = ArchiveEntry::create([
                    'content_hash' => $hash,
                    'original_name' => $metadata->name,
                    'content_type' => $metadata->contentType,
                    'size' => $metadata->size,
                    'storage_path' => $storagePath,
                    'disk' => $this->disk,
                ]);
            }

            AttachmentReference::create([
                'archive_entry_id' => $entry->id,
                'message_id' => $messageId,
                'attachment_id' => $metadata->id,
                'mailbox_email' => $mailboxEmail,
            ]);

            return $entry;
        });
    }
}
```

### Using the Archiver

```php
use App\Services\AttachmentArchiver;
use Pyle\Mailbox\Facades\Mailbox;

$archiver = new AttachmentArchiver(disk: 's3', basePath: 'invoices');
$mailbox = Mailbox::mailbox('invoices@acme.com');

$message = $mailbox->messages()
    ->where('hasAttachments', true)
    ->first();

if ($message) {
    $messageResource = $mailbox->message($message->id);
    $attachments = $messageResource->attachments(); // Collection<int, AttachmentDto>

    foreach ($attachments as $attachment) {
        if ($attachment->isInline) {
            continue; // Skip inline images
        }

        $attachmentResource = $messageResource->attachment($attachment->id);
        $entry = $archiver->archive($attachmentResource, $attachment, $message->id, 'invoices@acme.com');

        if ($entry->wasRecentlyCreated) {
            logger()->info("Archived new attachment: {$attachment->name} ({$entry->content_hash})");
        } else {
            logger()->info("Deduplicated attachment: {$attachment->name} (ref #{$entry->reference_count})");
        }
    }
}
```

> **Tip** The `stream()` method on `AttachmentResource` returns a PSR-7 `StreamInterface`. For very large files, you can read the stream in chunks instead of calling `getContents()` to keep memory usage low.

> **Note** The `alreadyExisted` property on `AttachmentFileDto` (returned by `downloadAttachments()`) indicates whether Mailbox's built-in downloader found an existing file at the target path. The content-addressable pattern in this recipe goes further by deduplicating across messages and mailboxes using SHA-256 hashes.

## Rule Builder UI Backend

If your application lets users create their own email processing rules through a UI, you need an API that validates rule definitions and tests them against messages. This recipe builds a controller that accepts rule arrays from a frontend, validates the structure using Mailbox's enums, and provides a dry-run endpoint to test rules against recent messages.

### The Validation Request

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;

class StoreRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = array_column(FilterableField::cases(), 'value');
        $operators = array_column(MatchOperator::cases(), 'value');

        return [
            'name' => ['required', 'string', 'max:255'],
            'operator' => ['required', 'string', 'in:AND,OR'],
            'conditions' => ['required', 'array', 'min:1'],
            'conditions.*.field' => ['required', 'string', 'in:' . implode(',', $fields)],
            'conditions.*.operator' => ['required', 'string', 'in:' . implode(',', $operators)],
            'conditions.*.value' => ['required'],
            // Support nested groups
            'conditions.*.conditions' => ['sometimes', 'array', 'min:1'],
            'conditions.*.conditions.*.field' => ['required_with:conditions.*.conditions', 'string', 'in:' . implode(',', $fields)],
            'conditions.*.conditions.*.operator' => ['required_with:conditions.*.conditions', 'string', 'in:' . implode(',', $operators)],
            'conditions.*.conditions.*.value' => ['required_with:conditions.*.conditions'],
        ];
    }
}
```

### The Controller

```php
namespace App\Http\Controllers;

use App\Http\Requests\StoreRuleRequest;
use App\Models\EmailRule;
use Illuminate\Http\JsonResponse;
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Support\MessageMatcher;

class RuleBuilderController extends Controller
{
    /**
     * Return the available fields and their allowed operators, so the
     * frontend can build a dynamic form without hard-coding values.
     */
    public function schema(): JsonResponse
    {
        $fields = collect(FilterableField::cases())->map(fn (FilterableField $field) => [
            'value' => $field->value,
            'label' => str_replace('.', ' ', $field->value),
            'value_type' => $field->valueType(),
            'operators' => collect($field->operators())->map(fn (MatchOperator $op) => [
                'value' => $op->value,
                'label' => str_replace('_', ' ', $op->value),
            ])->values(),
        ])->values();

        $groupOperators = ['AND', 'OR'];

        return response()->json([
            'fields' => $fields,
            'group_operators' => $groupOperators,
        ]);
    }

    /**
     * Validate and store a new rule definition.
     */
    public function store(StoreRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Validate that each condition uses an operator allowed for its field
        foreach ($validated['conditions'] as $condition) {
            if (isset($condition['conditions'])) {
                continue; // Nested group -- validation handled by request rules
            }

            $field = FilterableField::from($condition['field']);
            $operator = MatchOperator::from($condition['operator']);
            $allowedOperators = $field->operators();

            if (! in_array($operator, $allowedOperators, true)) {
                return response()->json([
                    'error' => "Operator '{$operator->value}' is not allowed for field '{$field->value}'.",
                    'allowed_operators' => array_map(fn ($op) => $op->value, $allowedOperators),
                ], 422);
            }
        }

        $rule = EmailRule::create([
            'name' => $validated['name'],
            'definition' => [
                'operator' => $validated['operator'],
                'conditions' => $validated['conditions'],
            ],
        ]);

        return response()->json(['rule' => $rule], 201);
    }

    /**
     * Test a rule definition against the 20 most recent inbox messages
     * without actually saving or processing anything.
     */
    public function dryRun(StoreRuleRequest $request, string $email): JsonResponse
    {
        $validated = $request->validated();
        $mailbox = Mailbox::mailbox($email);

        $matcher = new MessageMatcher([
            'operator' => $validated['operator'],
            'conditions' => $validated['conditions'],
        ]);

        $messages = $mailbox->messages()
            ->inFolder(WellKnownFolder::INBOX)
            ->orderBy('receivedDateTime', 'desc')
            ->take(20)
            ->get(); // Collection<int, MessageDto>

        $results = $messages->map(function ($message) use ($mailbox, $matcher) {
            $attachments = $message->hasAttachments
                ? $mailbox->message($message->id)->attachments()
                : collect();

            return [
                'id' => $message->id,
                'subject' => $message->subject,
                'from' => $message->from?->address,
                'received_at' => $message->receivedAt?->toIso8601String(),
                'matched' => $matcher->matches($message, $attachments),
            ];
        });

        return response()->json([
            'total_tested' => $results->count(),
            'total_matched' => $results->where('matched', true)->count(),
            'messages' => $results,
        ]);
    }
}
```

### The Routes

```php
use App\Http\Controllers\RuleBuilderController;

Route::prefix('api/rules')->middleware(['auth:sanctum'])->group(function () {
    Route::get('schema', [RuleBuilderController::class, 'schema']);
    Route::post('/', [RuleBuilderController::class, 'store']);
    Route::post('dry-run/{email}', [RuleBuilderController::class, 'dryRun']);
});
```

### Example API Request

Here is an example request body that the `store` or `dry-run` endpoints would accept. This rule matches messages from any `@vendor.com` address that also have a PDF attachment:

```json
{
    "name": "Vendor invoices with PDF",
    "operator": "AND",
    "conditions": [
        {
            "field": "from.address",
            "operator": "ends_with",
            "value": "@vendor.com"
        },
        {
            "field": "attachmentContentType",
            "operator": "contains",
            "value": "pdf"
        }
    ]
}
```

And a more advanced example using nested groups -- match emails from the billing department OR any message with "urgent" in the subject that arrived in the last week:

```json
{
    "name": "Priority billing alerts",
    "operator": "OR",
    "conditions": [
        {
            "field": "from.address",
            "operator": "ends_with",
            "value": "@billing.vendor.com"
        },
        {
            "operator": "AND",
            "conditions": [
                {
                    "field": "subject",
                    "operator": "contains",
                    "value": "urgent"
                },
                {
                    "field": "receivedAt",
                    "operator": "after",
                    "value": "2026-02-18T00:00:00Z"
                }
            ]
        }
    ]
}
```

> **Tip** The `schema` endpoint returns each field's `valueType` (`string`, `boolean`, `integer`, `datetime`, or `enum`), which helps your frontend render the appropriate input control for each condition.

> **Warning** The dry-run endpoint fetches live messages from the provider. If the target mailbox receives high volume, consider caching recent messages or limiting the endpoint to admin users to avoid unnecessary API calls.

## What's Next

- [Rule Matching](rule-matching.md) -- deep dive into the `MessageMatcher` class, operator reference, and nested group logic.
- [Error Handling](error-handling.md) -- learn how Mailbox surfaces rate limits, expired tokens, and connectivity issues.
- [Testing](testing.md) -- mock the Mailbox facade and build integration tests for your pipelines.
