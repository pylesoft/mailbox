# Delta Sync

Folder delta sync provides incremental message change sets.

```php
$result = Mailbox::mailbox($email)->folder('inbox')->delta($deltaToken);
```

`DeltaResultDto` contains:

- `created` (`Collection<MessageDto>`)
- `updated` (`Collection<MessageDto>`)
- `deleted` (`Collection<string>`)
- `deltaLink`
- `fullSyncRequired`

## Expired Tokens

If Graph returns `410 Gone`, `fullSyncRequired` is set so callers can restart from a full sync.
