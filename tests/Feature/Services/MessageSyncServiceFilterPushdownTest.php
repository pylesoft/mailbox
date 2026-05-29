<?php

declare(strict_types=1);

use Pyle\Mailbox\Services\Persistence\MessageSyncService;

require_once __DIR__.'/Support/MessagePersistenceTestSupport.php';

afterEach(function (): void {
    Mockery::close();
});

it('uses provider operator tokens when applying sync filters', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Filter Connection',
        emailAddress: 'filters@example.com',
        displayName: 'Filter Mailbox',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'filters' => [
            'internet_message_id' => 'abc@example.com',
            'from_email_addresses' => ['sender-a@example.com', 'sender-b@example.com'],
            'subject_contains' => ['Invoice'],
            'has_attachments' => true,
            'importance' => 'high',
            'is_read' => false,
            'limit' => 10,
        ],
    ]);

    expect(collect($query->whereCalls)->every(fn (array $call): bool => is_string($call['operator'])))->toBeTrue();
    expect(collect($query->whereAnyCalls)->every(fn (array $call): bool => is_string($call['operator'])))->toBeTrue();

    expect($query->whereCalls)->toContain(['field' => 'internetMessageId', 'operator' => 'eq', 'value' => '<abc@example.com>']);
    expect($query->whereAnyCalls)->toContain(['field' => 'from.address', 'operator' => 'eq', 'values' => ['sender-a@example.com', 'sender-b@example.com']]);
    expect($query->whereCalls)->toContain(['field' => 'subject', 'operator' => 'contains', 'value' => 'Invoice']);
    expect($query->whereCalls)->toContain(['field' => 'hasAttachments', 'operator' => 'eq', 'value' => true]);
    expect($query->whereCalls)->toContain(['field' => 'importance', 'operator' => 'eq', 'value' => 'high']);
    expect($query->whereCalls)->toContain(['field' => 'isRead', 'operator' => 'eq', 'value' => false]);
});

it('derives has attachments filter when rule tree cannot match messages without attachments', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Rule Tree Attachment Inference Connection',
        emailAddress: 'rule-tree-attachment-inference@example.com',
        displayName: 'Rule Tree Attachment Inference Mailbox',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'attachmentName', 'operator' => 'contains', 'value' => 'invoice'],
            ],
        ],
        'filters' => ['limit' => 10],
    ]);

    expect($query->whereCalls)->toContain(['field' => 'hasAttachments', 'operator' => 'eq', 'value' => true]);
});

it('does not derive has attachments filter for zero-count attachment rule tree', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Rule Tree Zero Count Connection',
        emailAddress: 'rule-tree-zero-count@example.com',
        displayName: 'Rule Tree Zero Count Mailbox',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'attachmentCount', 'operator' => 'equals', 'value' => 0],
            ],
        ],
        'filters' => ['limit' => 10],
    ]);

    expect($query->whereCalls)->not->toContain(['field' => 'hasAttachments', 'operator' => 'eq', 'value' => true]);
});

it('does not derive has attachments filter when rule tree can match without attachments', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Rule Tree Optional Attachment Connection',
        emailAddress: 'rule-tree-optional-attachment@example.com',
        displayName: 'Rule Tree Optional Attachment Mailbox',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'OR',
            'conditions' => [
                ['field' => 'attachmentName', 'operator' => 'contains', 'value' => 'invoice'],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'newsletter'],
            ],
        ],
        'filters' => ['limit' => 10],
    ]);

    expect($query->whereCalls)->not->toContain(['field' => 'hasAttachments', 'operator' => 'eq', 'value' => true]);
});

it('prefers runtime rule tree over stored rule tree when applying pushdown filters', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Rule Tree Precedence Connection',
        emailAddress: 'rule-tree-precedence@example.com',
        displayName: 'Rule Tree Precedence Mailbox',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'runtime-subject'],
            ],
        ],
        'filters' => [
            'rule_tree' => [
                'operator' => 'AND',
                'conditions' => [
                    ['field' => 'from.address', 'operator' => 'equals', 'value' => 'stored@example.com'],
                ],
            ],
            'limit' => 10,
        ],
    ]);

    expect($query->whereCalls)->toContain(['field' => 'subject', 'operator' => 'contains', 'value' => 'runtime-subject']);
    expect($query->whereCalls)->not->toContain(['field' => 'from.address', 'operator' => 'eq', 'value' => 'stored@example.com']);
});

it('normalizes rule trees and only pushes down supported AND conditions', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Rule Tree Normalization Connection',
        emailAddress: 'rule-tree-normalization@example.com',
        displayName: 'Rule Tree Normalization Mailbox',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
                [
                    'operator' => 'AND',
                    'conditions' => [
                        ['field' => 'from.address', 'operator' => 'equals', 'value' => 'sender@example.com'],
                        ['field' => 'attachmentName', 'operator' => 'contains', 'value' => 'invoice'],
                        ['field' => 'subject', 'operator' => 'matches_regex', 'value' => '/invoice/i'],
                        ['field' => '', 'operator' => 'equals', 'value' => 'invalid'],
                        'invalid-scalar',
                    ],
                ],
                ['field' => 'subject', 'value' => 'missing-operator'],
            ],
        ],
        'filters' => ['limit' => 10],
    ]);

    expect($query->whereCalls)->toContain(['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']);
    expect($query->whereCalls)->toContain(['field' => 'from.address', 'operator' => 'eq', 'value' => 'sender@example.com']);
    expect($query->whereCalls)->not->toContain(['field' => 'attachmentName', 'operator' => 'contains', 'value' => 'invoice']);
    expect($query->whereCalls)->not->toContain(['field' => 'subject', 'operator' => 'matches_regex', 'value' => '/invoice/i']);
});

it('uses the query builder when deciding rule-tree pushdown safety', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Graph Rule Tree Operator Safety Connection',
        emailAddress: 'graph-rule-tree-operator-safety@example.com',
        displayName: 'Graph Rule Tree Operator Safety Mailbox',
        driver: 'ms-graph',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'from.address', 'operator' => 'equals', 'value' => 'sender@example.com'],
                ['field' => 'from.address', 'operator' => 'ends_with', 'value' => '@biyorkcanada.com'],
            ],
        ],
        'filters' => ['limit' => 10],
    ]);

    expect($query->whereCalls)->toContain(['field' => 'from.address', 'operator' => 'eq', 'value' => 'sender@example.com']);
    expect($query->whereCalls)->not->toContain(['field' => 'from.address', 'operator' => 'ends_with', 'value' => '@biyorkcanada.com']);
});

it('does not push down rule-tree conditions when any OR group is present', function (): void {
    $mailbox = createTestMailbox(
        connectionName: 'Rule Tree OR Connection',
        emailAddress: 'rule-tree-or@example.com',
        displayName: 'Rule Tree Or Mailbox',
    );

    $query = new RecordingMessageQueryBuilder;
    $resource = new TestMailboxResource($query, []);

    expectMailboxFacadeForMailbox($mailbox, $resource);

    $service = new MessageSyncService;
    $service->syncMailbox($mailbox, [
        'rule_tree' => [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
                [
                    'operator' => 'OR',
                    'conditions' => [
                        ['field' => 'from.address', 'operator' => 'equals', 'value' => 'a@example.com'],
                        ['field' => 'from.address', 'operator' => 'equals', 'value' => 'b@example.com'],
                    ],
                ],
            ],
        ],
        'filters' => ['limit' => 10],
    ]);

    expect($query->whereCalls)->not->toContain(['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']);
    expect($query->whereCalls)->not->toContain(['field' => 'from.address', 'operator' => 'eq', 'value' => 'a@example.com']);
    expect($query->whereCalls)->not->toContain(['field' => 'from.address', 'operator' => 'eq', 'value' => 'b@example.com']);
});
