# Attachments

Attachment metadata and download operations are available from message resources.

## Metadata

```php
$attachments = Mailbox::mailbox($email)->message($messageId)->attachments();
```

## Download

```php
$file = Mailbox::mailbox($email)->message($messageId)->attachment($attachmentId)->download();
```

`AttachmentFileDto` includes:

- `disk`
- `path`
- `alreadyExisted`

## Dedup Behavior

If the same attachment file path already exists, download is skipped and `alreadyExisted=true`.
