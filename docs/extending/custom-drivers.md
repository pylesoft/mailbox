# Custom Drivers

Add new providers by implementing package contracts.

## Implementation Checklist

1. Implement `MailboxDriver`.
2. Implement resource/query contracts.
3. Map provider payloads to package DTOs.
4. Register driver with `Mailbox::extend(...)`.
5. Add driver config in `mailbox.drivers`.

## Registration Example

```php
use Pyle\Mailbox\Facades\Mailbox;

Mailbox::extend('my-provider', fn ($app) => new MyProviderDriver(
    config('mailbox.drivers.my-provider')
));
```

## Validation Checklist

- query and action parity
- delta sync behavior
- attachment write and dedup behavior
- exception mapping and logging

## Next

- [Stubs](stubs.md)
- [Testing](../testing.md)
