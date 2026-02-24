<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('monitored_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitored_mailbox_id')->constrained('monitored_mailboxes')->cascadeOnDelete();
            $table->string('folder_id');
            $table->string('display_name');
            $table->string('path')->nullable();
            $table->string('well_known_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('delta_token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status')->default('idle');
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique(['monitored_mailbox_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_folders');
    }
};
