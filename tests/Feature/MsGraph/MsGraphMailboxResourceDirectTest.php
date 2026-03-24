<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\MsGraph\BatchRequest;
use Pyle\Mailbox\Drivers\MsGraph\FolderIdResolver;
use Pyle\Mailbox\Drivers\MsGraph\GraphClient;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphDeltaSync;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphFolderResource;
use Pyle\Mailbox\Drivers\MsGraph\MsGraphMailboxResource;
use Pyle\Mailbox\DTOs\DeltaResultDto;
use Pyle\Mailbox\Enums\WellKnownFolder;

it('resolves graph folder ids and builds folder resources directly', function (): void {
    $client = new class extends GraphClient
    {
        public function __construct() {}

        public function get(string $endpoint, array $query = [], ?string $mailbox = null): array
        {
            return match (true) {
                str_contains($endpoint, '/childFolders') => [
                    'value' => [
                        ['id' => 'processed', 'displayName' => 'Processed', 'parentFolderId' => 'inbox', 'childFolderCount' => 0, 'totalItemCount' => 2, 'unreadItemCount' => 0],
                    ],
                ],
                str_contains($endpoint, '/mailFolders/inbox') => [
                    'id' => 'inbox',
                    'displayName' => 'Inbox',
                    'parentFolderId' => null,
                    'childFolderCount' => 1,
                    'totalItemCount' => 10,
                    'unreadItemCount' => 2,
                ],
                default => ['value' => []],
            };
        }

        public function post(string $endpoint, array $payload = [], ?string $mailbox = null): array
        {
            return [
                'id' => 'archive',
                'displayName' => 'Archive',
                'parentFolderId' => null,
                'childFolderCount' => 0,
                'totalItemCount' => 1,
                'unreadItemCount' => 0,
            ];
        }
    };

    $deltaSync = new class($client) extends MsGraphDeltaSync
    {
        public function __construct(GraphClient $client)
        {
            parent::__construct($client);
        }

        public function syncFolder(string $mailbox, string $folderId, ?string $deltaToken = null): DeltaResultDto
        {
            return new DeltaResultDto(
                created: collect(),
                updated: collect(),
                deleted: collect(),
                deltaLink: 'graph-delta-token',
                fullSyncRequired: false,
            );
        }
    };

    $batch = new BatchRequest($client);

    expect(FolderIdResolver::resolve(WellKnownFolder::SENT))->toBe('sentitems');
    expect(FolderIdResolver::resolve('custom-folder'))->toBe('custom-folder');

    $resource = new MsGraphMailboxResource($client, $batch, $deltaSync, 'folders@example.com');
    $folderResource = $resource->folder(WellKnownFolder::INBOX);

    expect($folderResource)->toBeInstanceOf(MsGraphFolderResource::class);
    expect($folderResource->get()->displayName)->toBe('Inbox');
    expect($folderResource->children()->pluck('displayName')->all())->toBe(['Processed']);
    expect($folderResource->moveTo('archive')->displayName)->toBe('Archive');
    expect($folderResource->delta('token-1')->deltaLink)->toBe('graph-delta-token');
});
