<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_connections', function (Blueprint $table): void {
            $table->string('selected_grant_id')->nullable();
            $table->string('lifecycle_generation')->nullable();
        });

        Schema::table('mailbox_oauth_tokens', function (Blueprint $table): void {
            $table->string('grant_id')->nullable();
            $table->string('lifecycle_generation')->nullable();
            $table->text('issuer')->nullable();
            $table->text('client_id')->nullable();
            $table->string('grant_revision')->nullable();
            $table->text('nonce_hash')->nullable();
            $table->longText('grant_revision_history')->nullable();
            $table->unique(['mailbox_connection_id', 'grant_id'], 'mailbox_oauth_connection_grant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_oauth_tokens', function (Blueprint $table): void {
            $table->dropUnique('mailbox_oauth_connection_grant_unique');
            $table->dropColumn(['grant_id', 'lifecycle_generation', 'issuer', 'client_id', 'grant_revision', 'nonce_hash', 'grant_revision_history']);
        });

        Schema::table('mailbox_connections', function (Blueprint $table): void {
            $table->dropColumn(['selected_grant_id', 'lifecycle_generation']);
        });
    }
};
