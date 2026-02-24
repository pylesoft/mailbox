<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_connection_id')->constrained('mailbox_connections')->cascadeOnDelete();
            $table->string('email_address');
            $table->string('display_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mailbox_connection_id', 'email_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_mailboxes');
    }
};
