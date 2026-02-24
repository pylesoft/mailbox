# Folders

Use folders APIs for discovery and organization.

## Discover Folders

```php
$folders = Mailbox::mailbox($email)->folders()->get();
$tree = Mailbox::mailbox($email)->folders()->tree(maxDepth: 5);
```

## Find and Create

```php
$found = Mailbox::mailbox($email)->folders()->find('Processed');
$created = Mailbox::mailbox($email)->folders()->create('Processed');
$path = Mailbox::mailbox($email)->folders()->createPath('Inbox/Finance/Processed');
```

## Folder Resource

```php
$folder = Mailbox::mailbox($email)->folder('inbox')->get();
$children = Mailbox::mailbox($email)->folder('inbox')->children();
```

## Next

- [Messages](messages.md)
- [Delta Sync](delta-sync.md)
