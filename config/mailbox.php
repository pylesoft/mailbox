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

    'oauth' => [
        'enabled' => env('MAILBOX_OAUTH_ENABLED', false),
        'route_prefix' => env('MAILBOX_OAUTH_ROUTE_PREFIX', 'mailbox/oauth'),
        'route_middleware' => array_values(array_filter(array_map(
            static fn (string $middleware): string => trim($middleware),
            explode(',', (string) env('MAILBOX_OAUTH_ROUTE_MIDDLEWARE', 'web'))
        ), static fn (string $middleware): bool => $middleware !== '')),
        'state_ttl_seconds' => 600,
        'default_return_url' => '/',
        'allowed_return_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env(
                'MAILBOX_OAUTH_ALLOWED_RETURN_HOSTS',
                (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST),
            ))
        ), static fn (string $host): bool => $host !== '')),

        'ms_graph' => [
            // Optional explicit override. If null, route() is used.
            'redirect_uri' => env('MAILBOX_OAUTH_MS_GRAPH_REDIRECT_URI'),
            'scopes' => [
                'openid',
                'profile',
                'email',
                'offline_access',
                'Mail.ReadWrite',
            ],
        ],
    ],

    'cache_store' => env('MAILBOX_CACHE_STORE'),
    'cache_prefix' => 'mailbox_token',
    'token_refresh_buffer' => 300,

    'max_retries' => 3,
    'retry_backoff_base' => 2,
    'max_concurrent_per_mailbox' => 4,
    'concurrency_lock_timeout' => 30,
    // 'release' avoids blocking queue workers by releasing active jobs on retryable responses.
    // 'sleep' performs inline sleep-based retries.
    'queue_retry_strategy' => env('MAILBOX_QUEUE_RETRY_STRATEGY', 'release'),

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
