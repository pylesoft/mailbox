# Attachments

## Read Metadata

```php
$attachments = Mailbox::mailbox($email)->message($messageId)->attachments();
```

## Download a Single Attachment

```php
$file = Mailbox::mailbox($email)
    ->message($messageId)
    ->attachment($attachmentId)
    ->download();
```

## Download All Message Attachments

```php
$files = Mailbox::mailbox($email)->message($messageId)->downloadAttachments();
```

## Dedup Behavior

If target path already exists, download is skipped and `alreadyExisted` is `true`.

## Next

- [Messages](messages.md)
- [Configuration](../configuration.md)
