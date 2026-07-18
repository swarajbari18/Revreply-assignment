<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('classification'); // interested, not_interested, meeting_request, unclear
            $table->double('confidence');
            $table->double('risk');
            $table->longText('generated_draft')->nullable();
            $table->string('prompt_version');
            $table->string('model_version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifications');
    }
};
