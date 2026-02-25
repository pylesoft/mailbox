# Rule Matching

Mailbox ships with a powerful, JSON-driven rule engine that lets you evaluate messages against arbitrarily nested conditions. Whether you are building an inbox automation UI, a server-side filter pipeline, or a simple "does this message match?" check, `MessageMatcher` handles the heavy lifting so you can focus on your application logic.

## The Rule Tree

Rules are expressed as a plain PHP array (or its JSON equivalent). Every rule tree is a **group** that contains an `operator` (`AND` or `OR`) and an array of `conditions`. Each condition can be a leaf rule with `field`, `operator`, and `value` keys, or another nested group -- giving you unlimited depth.

```php
$rules = [
    'operator' => 'AND',
    'conditions' => [
        ['field' => 'from.address', 'operator' => 'ends_with', 'value' => '@acme.com'],
        ['field' => 'subject',      'operator' => 'contains',  'value' => 'invoice'],
    ],
];
```

The JSON representation is identical, making it trivial to persist rules in a database `json` column or accept them from a frontend form builder:

```json
{
    "operator": "AND",
    "conditions": [
        { "field": "from.address", "operator": "ends_with", "value": "@acme.com" },
        { "field": "subject",      "operator": "contains",  "value": "invoice" }
    ]
}
```

> **Tip** An empty `conditions` array -- or a missing one -- evaluates to `true`. This makes it safe to pass a "no rules" default without special-casing.

## Basic Usage

Create a `MessageMatcher` with your rule array, then call `matches()` with a `MessageDto` and an optional collection of `AttachmentDto` objects:

```php
use Pyle\Mailbox\Support\MessageMatcher;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\AttachmentDto;

$matcher = new MessageMatcher($rules);

$matched = $matcher->matches($message); // bool
```

If your rules reference attachment fields, pass the attachments as the second argument:

```php
$matched = $matcher->matches($message, $attachments);
```

The `$attachments` parameter accepts a `Collection<int, AttachmentDto>`, a plain array, or any `iterable` -- Mailbox normalizes it internally.

## Operators

The `MatchOperator` enum defines every comparison the engine supports. Each operator is stored as a lowercase, snake_case string value:

| Enum Case | Value | Description |
|---|---|---|
| `EQUALS` | `equals` | Case-insensitive equality after trimming |
| `NOT_EQUALS` | `not_equals` | Inverse of `equals` |
| `CONTAINS` | `contains` | Case-insensitive substring match |
| `NOT_CONTAINS` | `not_contains` | Inverse of `contains` |
| `STARTS_WITH` | `starts_with` | Case-insensitive prefix match |
| `ENDS_WITH` | `ends_with` | Case-insensitive suffix match |
| `MATCHES_REGEX` | `matches_regex` | Full PCRE regex (the `value` is the pattern) |
| `GREATER_THAN` | `greater_than` | Numeric or date comparison |
| `LESS_THAN` | `less_than` | Numeric or date comparison |
| `BETWEEN` | `between` | Value falls within `[min, max]` (inclusive) |
| `BEFORE` | `before` | Date occurs before the given datetime |
| `AFTER` | `after` | Date occurs after the given datetime |

String operators (`contains`, `starts_with`, `ends_with`, `equals`, `not_equals`) are always **case-insensitive**. The `matches_regex` operator passes your pattern directly to `preg_match`, so you have full control over flags and delimiters.

### Date and Numeric Comparison

`GREATER_THAN`, `LESS_THAN`, `BEFORE`, `AFTER`, and `BETWEEN` all route through the same comparison engine. When either the actual or expected value is a `DateTimeInterface`, Mailbox parses both sides as `CarbonImmutable` instances. Otherwise, both sides are compared as floats:

```php
// Date: messages received after January 1, 2026
['field' => 'receivedAt', 'operator' => 'after', 'value' => '2026-01-01T00:00:00Z']

// Numeric: attachments larger than 5 MB
['field' => 'attachmentSize', 'operator' => 'greater_than', 'value' => 5242880]
```

The `between` operator expects a two-element array for its `value`. Both bounds are inclusive:

```php
['field' => 'attachmentCount', 'operator' => 'between', 'value' => [1, 5]]
```

## Filterable Fields

The `FilterableField` enum catalogs every field the rule engine can evaluate, along with the operators each field supports and the expected value type. This is the single source of truth for building filter UIs.

### Message Fields

| Enum Case | Field String | Value Type | Allowed Operators |
|---|---|---|---|
| `SUBJECT` | `subject` | `string` | `equals`, `contains`, `starts_with`, `ends_with`, `matches_regex` |
| `FROM_ADDRESS` | `from.address` | `string` | `equals`, `contains`, `ends_with` |
| `FROM_NAME` | `from.name` | `string` | `equals`, `contains` |
| `SENDER_ADDRESS` | `sender.address` | `string` | `equals`, `contains`, `ends_with` |
| `TO_ADDRESS` | `toRecipients.address` | `string` | `equals`, `contains` |
| `CC_ADDRESS` | `ccRecipients.address` | `string` | `equals`, `contains` |
| `RECEIVED_AT` | `receivedAt` | `datetime` | `before`, `after`, `between` |
| `IS_READ` | `isRead` | `boolean` | `equals` |
| `IS_DRAFT` | `isDraft` | `boolean` | `equals` |
| `HAS_ATTACHMENTS` | `hasAttachments` | `boolean` | `equals` |
| `IMPORTANCE` | `importance` | `enum` | `equals` |
| `BODY_PREVIEW` | `bodyPreview` | `string` | `contains`, `matches_regex` |

### Attachment Fields

| Enum Case | Field String | Value Type | Allowed Operators |
|---|---|---|---|
| `ATTACHMENT_COUNT` | `attachmentCount` | `integer` | `equals`, `greater_than`, `less_than`, `between` |
| `ATTACHMENT_NAME` | `attachmentName` | `string` | `equals`, `contains`, `starts_with`, `ends_with`, `matches_regex` |
| `ATTACHMENT_CONTENT_TYPE` | `attachmentContentType` | `string` | `equals`, `contains` |
| `ATTACHMENT_SIZE` | `attachmentSize` | `integer` | `equals`, `greater_than`, `less_than`, `between` |

> **Note** Attachment field conditions (`attachmentName`, `attachmentContentType`, `attachmentSize`) match if **any** attachment in the collection satisfies the condition. The `attachmentCount` field evaluates against the total count of the collection.

### How Recipient Fields Resolve

The `toRecipients.address` and `ccRecipients.address` fields join all addresses in the respective list into a single comma-separated string before applying the operator. This means a `contains` check against `billing@vendor.com` will match if that address appears anywhere in the To or CC list.

### Server-Pushable Fields

Not every field can be pushed to the provider's server-side filter API. You can check at runtime whether a field supports server-side filtering for a given driver:

```php
use Pyle\Mailbox\Enums\FilterableField;

FilterableField::SUBJECT->isServerPushable('ms-graph');  // true
FilterableField::BODY_PREVIEW->isServerPushable('ms-graph');  // false
FilterableField::SENDER_ADDRESS->isServerPushable('gmail');  // true
```

## Nesting Groups

Groups can be nested to any depth. Use this to express complex boolean logic:

```php
$rules = [
    'operator' => 'AND',
    'conditions' => [
        // Must be from acme.com
        ['field' => 'from.address', 'operator' => 'ends_with', 'value' => '@acme.com'],

        // AND (subject contains "invoice" OR subject contains "receipt")
        [
            'operator' => 'OR',
            'conditions' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'receipt'],
            ],
        ],
    ],
];
```

The matcher differentiates a nested group from a leaf condition by checking for the presence of a `conditions` key. If the key exists and is an array, the entry is treated as a group; otherwise it is treated as a leaf condition.

## Building a Filter UI Backend

Mailbox provides everything you need to power a dynamic rule-builder frontend. Use the `filterableFields()` method on the facade to retrieve the full field catalog, then pass it to your UI:

```php
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Enums\FilterableField;

$fields = Mailbox::filterableFields(); // Collection<int, FilterableField>

$fieldOptions = $fields->map(fn (FilterableField $field) => [
    'value'      => $field->value,
    'label'      => str($field->name)->replace('_', ' ')->title()->toString(),
    'operators'  => collect($field->operators())->map(fn ($op) => $op->value)->all(),
    'valueType'  => $field->valueType(),
]);
```

Return this from an API endpoint and your JavaScript form builder has everything it needs: the field identifiers, human-readable labels, the operators valid for each field, and the expected value type (`string`, `boolean`, `integer`, `datetime`, or `enum`).

### Validating Incoming Rules

When a user submits a rule tree from your UI, validate it before persisting:

```php
use Pyle\Mailbox\Enums\FilterableField;
use Pyle\Mailbox\Enums\MatchOperator;

$field = FilterableField::tryFrom($condition['field']);
$operator = MatchOperator::tryFrom($condition['operator']);

if ($field === null || $operator === null) {
    // Reject: unknown field or operator
}

if (! in_array($operator, $field->operators(), true)) {
    // Reject: this operator is not valid for this field
}
```

## Complex Rule Example

Here is a real-world rule tree that matches messages from a vendor domain, with a PDF attachment over 100 KB, received in the last 30 days:

```php
use Pyle\Mailbox\Support\MessageMatcher;

$rules = [
    'operator' => 'AND',
    'conditions' => [
        ['field' => 'from.address', 'operator' => 'ends_with', 'value' => '@vendor.com'],
        ['field' => 'hasAttachments', 'operator' => 'equals', 'value' => true],
        ['field' => 'receivedAt', 'operator' => 'after', 'value' => now()->subDays(30)->toIso8601String()],

        // Attachment-specific conditions
        [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'attachmentContentType', 'operator' => 'equals', 'value' => 'application/pdf'],
                ['field' => 'attachmentSize', 'operator' => 'greater_than', 'value' => 102400],
            ],
        ],
    ],
];

$matcher = new MessageMatcher($rules);

// Fetch messages and their attachments
$resource = \Pyle\Mailbox\Facades\Mailbox::mailbox('invoices@acme.com');
$messages = $resource->messages()->get(); // Collection<int, MessageDto>

foreach ($messages as $message) {
    $attachments = $resource->message($message->id)->attachments();

    if ($matcher->matches($message, $attachments)) {
        // Process the matching message
    }
}
```

> **Warning** Attachment conditions require you to pass the attachments explicitly. If your rules include attachment fields but you call `matches($message)` without the second argument, those conditions will evaluate against an empty collection -- which means `attachmentCount` equals `0` and per-attachment conditions will not match.

## What's Next

- [Messages](messages.md) -- querying, filtering, and reading message data
- [Attachments](attachments.md) -- downloading and inspecting attachments
- [Eloquent Models](eloquent-models.md) -- the database models that persist your connections, mailboxes, and folders
