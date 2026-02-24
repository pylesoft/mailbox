<?php

declare(strict_types=1);

return [
    'default' => env('MAILBOX_DRIVER', 'ms-graph'),

    'drivers' => [
        'ms-graph' => [
            'driver' => 'ms-graph',
            'tenant_id' => env('MS365_TENANT_ID'),
            'client_id' => env('MS365_CLIENT_ID'),
            'client_secret' => env('MS365_CLIENT_SECRET'),
            'api_version' => 'v1.0',
            'timeout' => 30,
        ],
    ],

    'cache_store' => env('MAILBOX_CACHE_STORE'),
    'cache_prefix' => 'mailbox_token',
    'token_refresh_buffer' => 300,

    'max_retries' => 3,
    'retry_backoff_base' => 2,
    'max_concurrent_per_mailbox' => 4,
    'concurrency_lock_timeout' => 30,

    'default_page_size' => 50,
    'default_select' => [
        'id', 'subject', 'from', 'sender', 'toRecipients', 'ccRecipients',
        'receivedDateTime', 'sentDateTime', 'isRead', 'isDraft',
        'hasAttachments', 'importance', 'bodyPreview',
        'conversationId', 'internetMessageId', 'parentFolderId',
    ],

    'prefer_immutable_ids' => true,

    'attachment_disk' => env('MAILBOX_ATTACHMENT_DISK', 'local'),
    'attachment_path' => env('MAILBOX_ATTACHMENT_PATH', 'mailbox-attachments'),

    'secret_expiry_warning_days' => 30,

    'log_channel' => 'mailbox',
    'log_level' => env('MAILBOX_LOG_LEVEL', env('APP_DEBUG', false) ? 'debug' : 'info'),
];
