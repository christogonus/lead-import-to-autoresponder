<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rows turned away by the do-not-contact list are counted apart from ordinary
 * duplicates: "skipped" already means "this list has them", and a blocked
 * address needs to be reported as the deliberate exclusion it is.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('imports', 'suppressed_count')) {
            return;
        }

        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedInteger('suppressed_count')->default(0)->after('skipped_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn('suppressed_count');
        });
    }
};
