# Custom Drivers

Custom providers can be added without modifying core package code.

## Steps

1. Implement package contracts (`MailboxDriver`, `MailboxResource`, message/folder/attachment contracts).
2. Register the driver via `Mailbox::extend('name', fn () => new YourDriver(...))`.
3. Add driver config under `mailbox.drivers`.
4. Use `Mailbox::driver('name')` in app code.

Keep DTO return shapes consistent with package contracts so consuming application code remains provider-agnostic.
