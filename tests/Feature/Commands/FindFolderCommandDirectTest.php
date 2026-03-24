<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Pyle\Mailbox\Commands\FindFolderCommand;
use Pyle\Mailbox\Contracts\FolderQueryBuilder;
use Pyle\Mailbox\Contracts\FolderResource;
use Pyle\Mailbox\Contracts\MailboxDriver;
use Pyle\Mailbox\Contracts\MailboxResource;
use Pyle\Mailbox\Contracts\MessageQueryBuilder;
use Pyle\Mailbox\Contracts\MessageResource;
use Pyle\Mailbox\DTOs\ConnectionTestResult;
use Pyle\Mailbox\DTOs\FolderDto;
use Pyle\Mailbox\DTOs\HealthCheckResult;
use Pyle\Mailbox\MailboxManager;
use Pyle\Mailbox\Enums\WellKnownFolder;

beforeEach(function (): void {
    config()->set('mailbox.default', 'find-folder-direct');
    config()->set('mailbox.drivers.find-folder-direct', ['driver' => 'find-folder-direct']);

    /** @var MailboxManager $manager */
    $manager = app(MailboxManager::class);
    $manager->extend('find-folder-direct', fn (): MailboxDriver => new class implements MailboxDriver
    {
        public function mailbox(string $emailAddress): MailboxResource
        {
            return new class implements MailboxResource
            {
                public function messages(): MessageQueryBuilder
                {
                    throw new RuntimeException('Not needed for this test.');
                }

                public function message(string $messageId): MessageResource
                {
                    throw new RuntimeException('Not needed for this test.');
                }

                public function folders(): FolderQueryBuilder
                {
                    return new class implements FolderQueryBuilder
                    {
                        public function get(): \Illuminate\Support\Collection
                        {
                            return collect();
                        }

                        public function tree(int $maxDepth = 10): \Illuminate\Support\Collection
                        {
                            return collect();
                        }

                        public function find(string $name, string|WellKnownFolder|null $root = null, bool $caseSensitive = true): ?FolderDto
                        {
                            return new FolderDto(
                                id: 'processed',
                                displayName: 'Processed',
                                parentFolderId: 'INBOX',
                                childFolderCount: 0,
                                totalItemCount: 2,
                                unreadItemCount: 0,
                                path: 'Inbox/Processed',
                                wellKnownName: null,
                                children: [],
                            );
                        }

                        public function create(string $name, ?string $parentId = null): FolderDto
                        {
                            throw new RuntimeException('Not needed for this test.');
                        }

                        public function createPath(string $path): FolderDto
                        {
                            throw new RuntimeException('Not needed for this test.');
                        }
                    };
                }

                public function folder(string|WellKnownFolder $folderId): FolderResource
                {
                    throw new RuntimeException('Not needed for this test.');
                }
            };
        }

        public function testConnection(?string $emailAddress = null): ConnectionTestResult
        {
            return new ConnectionTestResult(success: true, error: null, latencyMs: 1, authenticatedAs: null, accessibleMailboxes: []);
        }

        public function healthCheck(): HealthCheckResult
        {
            return new HealthCheckResult(true, true, 60, true, 1, null, false);
        }
    });
});

it('finds a mailbox folder through the command class', function (): void {
    expect(app(FindFolderCommand::class))->toBeInstanceOf(FindFolderCommand::class);

    $exitCode = Artisan::call('mailbox:find-folder', [
        'email' => 'folders@example.com',
        'name' => 'Processed',
        '--driver' => 'find-folder-direct',
        '--root' => 'Inbox',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(FindFolderCommand::SUCCESS);
    expect($output)->toContain('Found folder: Processed (processed)');
    expect($output)->toContain('Path: Inbox/Processed');
});
