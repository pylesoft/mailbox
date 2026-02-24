<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('driver');
            $table->string('status')->default('pending');
            $table->json('config')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_connections');
    }
};
