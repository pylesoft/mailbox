<?php

declare(strict_types=1);

use Pyle\Mailbox\Services\Persistence\MessageSyncRuleTree;

require_once __DIR__.'/Support/MessagePersistenceTestSupport.php';

it('normalizes extracted rule trees and detects attachment metadata requirements', function (): void {
    $ruleTree = (new MessageSyncRuleTree)->extract(
        runtimeRuleTree: [
            'operator' => 'and',
            'conditions' => [
                [
                    'field' => ' attachmentName ',
                    'operator' => 'starts_with',
                    'value' => 'Invoice-',
                ],
                'ignore-me',
                [
                    'conditions' => [
                        [
                            'field' => 'subject',
                            'operator' => 'contains',
                            'value' => 'Quarterly',
                        ],
                        [
                            'field' => '',
                            'operator' => 'equals',
                            'value' => 'ignored',
                        ],
                    ],
                ],
            ],
        ],
        storedRuleTree: [
            'operator' => 'and',
            'conditions' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'stale'],
            ],
        ],
    );

    expect($ruleTree)->toBe([
        'operator' => 'AND',
        'conditions' => [
            [
                'field' => 'attachmentName',
                'operator' => 'starts_with',
                'value' => 'Invoice-',
            ],
            [
                'operator' => 'AND',
                'conditions' => [
                    [
                        'field' => 'subject',
                        'operator' => 'contains',
                        'value' => 'Quarterly',
                    ],
                ],
            ],
        ],
    ]);
    expect((new MessageSyncRuleTree)->requiresAttachmentMetadata($ruleTree))->toBeTrue();
    expect((new MessageSyncRuleTree)->requiresHasAttachmentsTrue($ruleTree))->toBeTrue();
});

it('allows messages without attachments when the tree can still match them', function (): void {
    $ruleTree = [
        'operator' => 'OR',
        'conditions' => [
            [
                'field' => 'attachmentCount',
                'operator' => 'between',
                'value' => [0, 5],
            ],
            [
                'field' => 'subject',
                'operator' => 'contains',
                'value' => 'Invoice',
            ],
        ],
    ];

    expect((new MessageSyncRuleTree)->requiresHasAttachmentsTrue($ruleTree))->toBeFalse();
});

it('pushes down only flat server-supported conditions and expands between filters', function (): void {
    $query = new RecordingMessageQueryBuilder;

    (new MessageSyncRuleTree)->applyPushdown($query, [
        'operator' => 'AND',
        'conditions' => [
            [
                'field' => 'subject',
                'operator' => 'contains',
                'value' => 'Invoice',
            ],
            [
                'field' => 'receivedAt',
                'operator' => 'between',
                'value' => ['2026-01-01T00:00:00Z', '2026-01-31T23:59:59Z'],
            ],
            [
                'field' => 'sender.name',
                'operator' => 'contains',
                'value' => 'Team',
            ],
            [
                'field' => 'attachmentCount',
                'operator' => 'between',
                'value' => [1, 5],
            ],
        ],
    ]);

    expect($query->whereCalls)->toBe([
        [
            'field' => 'subject',
            'operator' => 'contains',
            'value' => 'Invoice',
        ],
        [
            'field' => 'receivedAt',
            'operator' => 'ge',
            'value' => '2026-01-01T00:00:00Z',
        ],
        [
            'field' => 'receivedAt',
            'operator' => 'le',
            'value' => '2026-01-31T23:59:59Z',
        ],
    ]);
});

it('does not push down unsafe Microsoft Graph field operator combinations', function (): void {
    $query = new RecordingMessageQueryBuilder;

    (new MessageSyncRuleTree)->applyPushdown($query, [
        'operator' => 'AND',
        'conditions' => [
            [
                'field' => 'from.address',
                'operator' => 'equals',
                'value' => 'sender@example.com',
            ],
            [
                'field' => 'from.address',
                'operator' => 'ends_with',
                'value' => '@biyorkcanada.com',
            ],
        ],
    ]);

    expect($query->whereCalls)->toBe([
        [
            'field' => 'from.address',
            'operator' => 'eq',
            'value' => 'sender@example.com',
        ],
    ]);
});
