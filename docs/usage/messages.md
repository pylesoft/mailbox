# Messages

Use `messages()` for list queries and bulk operations.

## Query Builder

```php
$messages = Mailbox::mailbox($email)
    ->messages()
    ->inFolder('inbox')
    ->where('isRead', false)
    ->search('invoice')
    ->orderBy('receivedAt', 'desc')
    ->take(100)
    ->get();
```

## Single Message Resource

```php
$message = Mailbox::mailbox($email)->message($messageId)->get();
$body = Mailbox::mailbox($email)->message($messageId)->body();
```

## Actions

- `markAsRead()`
- `markAsUnread()`
- `moveTo()`
- `copyTo()`
- `delete()`

## Bulk Actions

Bulk operations use Graph batch requests with chunking.
