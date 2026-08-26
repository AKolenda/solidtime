<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_backup_settings', function (Blueprint $table): void {
            $table->string('root_path', 1024)->nullable()->after('subdirectory');
            $table->string('output_format', 8)->default('sql')->after('root_path');
        });
    }

    public function down(): void
    {
        Schema::table('database_backup_settings', function (Blueprint $table): void {
            $table->dropColumn(['root_path', 'output_format']);
        });
    }
};
