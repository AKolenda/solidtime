<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_creation_operations', function (Blueprint $table): void {
            $table->string('scope_key', 64)->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('payload_hash', 64);
            $table->text('response_json');
            $table->unsignedSmallInteger('response_status');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_creation_operations');
    }
};
