<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->update(['shop_report_enabled' => true]);
    }

    public function down(): void
    {
        // The previous per-organization preference cannot be reconstructed safely.
    }
};
