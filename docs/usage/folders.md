# Folders

Use folder query APIs to browse or create folders and folder paths.

## List + Tree

```php
$folders = Mailbox::mailbox($email)->folders()->get();
$tree = Mailbox::mailbox($email)->folders()->tree(maxDepth: 5);
```

## Find + Create

```php
$folder = Mailbox::mailbox($email)->folders()->find('Processed');
$created = Mailbox::mailbox($email)->folders()->create('Processed');
$path = Mailbox::mailbox($email)->folders()->createPath('Inbox/Finance/Processed');
```

## Folder Resource

```php
$folder = Mailbox::mailbox($email)->folder('inbox')->get();
$children = Mailbox::mailbox($email)->folder('inbox')->children();
```
