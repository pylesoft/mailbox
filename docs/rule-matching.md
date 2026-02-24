# Rule Matching

`MessageMatcher` evaluates rule trees against `MessageDto` and optional attachments.

## Supported Logic

- nested `AND` and `OR`
- string operators (`contains`, `starts_with`, `matches_regex`, etc.)
- numeric/date operators (`greater_than`, `less_than`, `between`, `before`, `after`)
- attachment predicates (`attachmentCount`, `attachmentName`, `attachmentSize`)

## Example

```php
$matcher = new \Pyle\Mailbox\Support\MessageMatcher($rules);
$isMatch = $matcher->matches($messageDto, $attachmentDtos);
```

## UI Metadata

Use `Mailbox::filterableFields()` to populate field/operator selectors in your app UI.

## Next

- [Usage: Messages](usage/messages.md)
