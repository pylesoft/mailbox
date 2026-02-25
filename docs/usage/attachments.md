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

Attachment downloads use content-addressable dedup:

- If the preferred path exists and content hash matches, download is skipped and `alreadyExisted` is `true`.
- If the preferred path exists but content hash differs, the file is stored at a hash-suffixed path.
- If that hash-suffixed path already exists with matching content, download is skipped and `alreadyExisted` is `true`.

## Next

- [Messages](messages.md)
- [Configuration](../configuration.md)
