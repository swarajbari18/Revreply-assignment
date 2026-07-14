<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();


            $table->string('gmail_email')->unique();


            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->timestamp('token_expires_at')->nullable();


            $table->string('status')->default('connected');

            $table->timestamp('watch_expiration')->nullable();

            $table->string('last_history_id')->nullable();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connected_accounts');
    }
};
