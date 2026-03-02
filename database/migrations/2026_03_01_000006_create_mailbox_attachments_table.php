<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->string('provider_attachment_id');
            $table->string('name');
            $table->string('content_type');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_inline')->default(false);
            $table->text('content_id')->nullable();
            $table->longText('content_bytes')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_message_id', 'provider_attachment_id'], 'mailbox_attachments_message_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_attachments');
    }
};
