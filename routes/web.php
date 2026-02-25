<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pyle\Mailbox\Http\Controllers\MsGraphOAuthController;

Route::middleware((array) config('mailbox.oauth.route_middleware', ['web']))
    ->prefix((string) config('mailbox.oauth.route_prefix', 'mailbox/oauth'))
    ->group(function (): void {
        Route::get('ms-graph/redirect', [MsGraphOAuthController::class, 'redirect'])
            ->name('mailbox.oauth.ms-graph.redirect');

        Route::get('ms-graph/callback', [MsGraphOAuthController::class, 'callback'])
            ->name('mailbox.oauth.ms-graph.callback');
    });
