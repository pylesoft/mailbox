# Quickstart

```php
use Pyle\Mailbox\Enums\WellKnownFolder;
use Pyle\Mailbox\Facades\Mailbox;

$messages = Mailbox::mailbox('invoices@example.com')
    ->messages()
    ->inFolder(WellKnownFolder::INBOX)
    ->where('isRead', false)
    ->search('invoice')
    ->take(50)
    ->get();
```

## Single Message Operations

```php
$message = Mailbox::mailbox('invoices@example.com')->message($id)->get();
Mailbox::mailbox('invoices@example.com')->message($id)->markAsRead();
Mailbox::mailbox('invoices@example.com')->message($id)->moveTo(WellKnownFolder::ARCHIVE);
```

## Sync a Folder

```php
$result = Mailbox::mailbox('invoices@example.com')
    ->folder(WellKnownFolder::INBOX)
    ->delta($storedToken);
```
