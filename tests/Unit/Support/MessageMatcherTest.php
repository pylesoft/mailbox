<?php

declare(strict_types=1);

use Pyle\Mailbox\Support\MessageMatcher;

it('evaluates simple contains condition', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
        ],
    ]);

    expect($matcher->matches(messageDto(subject: 'Your invoice #123')))->toBeTrue();
    expect($matcher->matches(messageDto(subject: 'Hello world')))->toBeFalse();
});

it('evaluates nested groups', function (): void {
    $matcher = new MessageMatcher([
        'operator' => 'AND',
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
            [
                'operator' => 'OR',
                'conditions' => [
                    ['field' => 'from.address', 'operator' => 'contains', 'value' => 'vendor'],
                    ['field' => 'from.address', 'operator' => 'contains', 'value' => 'billing'],
                ],
            ],
        ],
    ]);

    expect($matcher->matches(messageDto(subject: 'Invoice ready')))->toBeTrue();
});
