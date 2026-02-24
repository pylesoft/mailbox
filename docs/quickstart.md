# Quickstart

## 1) Get a Mailbox Resource

```php
use Pyle\Mailbox\Facades\Mailbox;

$mailbox = Mailbox::mailbox('invoices@example.com');
```

## 2) Read Messages

```php
use Pyle\Mailbox\Enums\WellKnownFolder;

$messages = $mailbox->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where('isRead', false)
    ->search('invoice')
    ->take(50)
    ->get();
```

## 3) Process One Message

```php
$message = $mailbox->message($messageId)->get();
$mailbox->message($messageId)->markAsRead();
```

## 4) Sync Incrementally

```php
$result = $mailbox->folder(WellKnownFolder::INBOX)->delta($storedDeltaToken);
```

Persist `deltaLink` from `$result` for the next sync run.

## Next

- [Messages Usage](usage/messages.md)
- [Delta Sync](usage/delta-sync.md)
