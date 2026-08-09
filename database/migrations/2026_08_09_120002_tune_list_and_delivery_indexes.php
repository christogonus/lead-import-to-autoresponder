<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fits the indexes to the two queries the team-level views actually run.
 *
 * The deliveries page pages through a team's whole history newest-first, which
 * the bare team_id index can only serve by sorting the team's every row on each
 * page. On contact_lists the standalone team_id index has become redundant:
 * (team_id, drafted_at) carries team_id as its leftmost column, so it answers
 * everything the old index did — including the foreign key's own lookups.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->index(['team_id', 'created_at']);
        });

        Schema::table('contact_lists', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_lists', function (Blueprint $table) {
            $table->index('team_id');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'created_at']);
        });
    }
};
