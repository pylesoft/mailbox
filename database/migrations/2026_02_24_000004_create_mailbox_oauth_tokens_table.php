<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_oauth_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_connection_id')->nullable()->constrained('mailbox_connections')->nullOnDelete();
            $table->string('provider');
            $table->string('external_user_id')->nullable();
            $table->string('email')->nullable();
            $table->string('tenant_id')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('token_type')->default('Bearer');
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider', 'external_user_id']);
            $table->index(['provider', 'email']);
            $table->index(['provider', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_oauth_tokens');
    }
};
