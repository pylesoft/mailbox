<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitored_mailbox_id')->constrained('monitored_mailboxes')->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('canonical_message_key');
            $table->string('internet_message_id')->nullable();
            $table->string('parent_folder_id')->nullable();
            $table->string('subject')->nullable();
            $table->json('body')->nullable();
            $table->text('body_preview')->nullable();
            $table->json('from_address')->nullable();
            $table->json('sender')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->string('importance')->default('normal');
            $table->string('conversation_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['monitored_mailbox_id', 'canonical_message_key'], 'mailbox_messages_mailbox_canonical_unique');
            $table->index(['monitored_mailbox_id', 'provider_message_id'], 'mailbox_messages_mailbox_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_messages');
    }
};
