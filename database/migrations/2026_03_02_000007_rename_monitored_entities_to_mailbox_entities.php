<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_folders', function (Blueprint $table): void {
            $table->dropForeign(['monitored_mailbox_id']);
            $table->dropUnique('monitored_folders_monitored_mailbox_id_folder_id_unique');
        });

        Schema::table('mailbox_messages', function (Blueprint $table): void {
            $table->dropForeign(['monitored_mailbox_id']);
            $table->dropUnique('mailbox_messages_mailbox_canonical_unique');
            $table->dropIndex('mailbox_messages_mailbox_provider_index');
        });

        Schema::rename('monitored_mailboxes', 'mailbox_mailboxes');
        Schema::rename('monitored_folders', 'mailbox_folders');

        Schema::table('mailbox_folders', function (Blueprint $table): void {
            $table->renameColumn('monitored_mailbox_id', 'mailbox_id');
        });

        Schema::table('mailbox_messages', function (Blueprint $table): void {
            $table->renameColumn('monitored_mailbox_id', 'mailbox_id');
        });

        Schema::table('mailbox_folders', function (Blueprint $table): void {
            $table->unique(['mailbox_id', 'folder_id'], 'mailbox_folders_mailbox_id_folder_id_unique');
            $table->foreign('mailbox_id')->references('id')->on('mailbox_mailboxes')->cascadeOnDelete();
        });

        Schema::table('mailbox_messages', function (Blueprint $table): void {
            $table->unique(['mailbox_id', 'canonical_message_key'], 'mailbox_messages_mailbox_canonical_unique');
            $table->index(['mailbox_id', 'provider_message_id'], 'mailbox_messages_mailbox_provider_index');
            $table->foreign('mailbox_id')->references('id')->on('mailbox_mailboxes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table): void {
            $table->dropForeign(['mailbox_id']);
            $table->dropUnique('mailbox_folders_mailbox_id_folder_id_unique');
        });

        Schema::table('mailbox_messages', function (Blueprint $table): void {
            $table->dropForeign(['mailbox_id']);
            $table->dropUnique('mailbox_messages_mailbox_canonical_unique');
            $table->dropIndex('mailbox_messages_mailbox_provider_index');
        });

        Schema::table('mailbox_folders', function (Blueprint $table): void {
            $table->renameColumn('mailbox_id', 'monitored_mailbox_id');
        });

        Schema::table('mailbox_messages', function (Blueprint $table): void {
            $table->renameColumn('mailbox_id', 'monitored_mailbox_id');
        });

        Schema::rename('mailbox_folders', 'monitored_folders');
        Schema::rename('mailbox_mailboxes', 'monitored_mailboxes');

        Schema::table('monitored_folders', function (Blueprint $table): void {
            $table->unique(['monitored_mailbox_id', 'folder_id'], 'monitored_folders_monitored_mailbox_id_folder_id_unique');
            $table->foreign('monitored_mailbox_id')->references('id')->on('monitored_mailboxes')->cascadeOnDelete();
        });

        Schema::table('mailbox_messages', function (Blueprint $table): void {
            $table->unique(['monitored_mailbox_id', 'canonical_message_key'], 'mailbox_messages_mailbox_canonical_unique');
            $table->index(['monitored_mailbox_id', 'provider_message_id'], 'mailbox_messages_mailbox_provider_index');
            $table->foreign('monitored_mailbox_id')->references('id')->on('monitored_mailboxes')->cascadeOnDelete();
        });
    }
};
