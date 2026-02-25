<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/workbench/health', static fn () => response()->json([
    'ok' => true,
]))->name('workbench.health');
