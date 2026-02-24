# Rule Matching

`MessageMatcher` evaluates rule payloads against `MessageDto` objects.

## Supported Features

- Nested `AND` / `OR` groups
- String operators (`contains`, `starts_with`, regex, etc.)
- Numeric and datetime operators (`greater_than`, `between`, etc.)
- Attachment-aware conditions (`attachmentCount`, `attachmentName`, `attachmentSize`)

## Example

```php
$matcher = new MessageMatcher($rules);
$matches = $matcher->matches($messageDto, $attachmentDtos);
```

## Filter Metadata

Use `Mailbox::filterableFields()` to drive a rule-builder UI.
