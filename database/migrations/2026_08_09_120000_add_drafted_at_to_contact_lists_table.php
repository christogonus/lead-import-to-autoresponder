<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the draft state to lists. A drafted list is set aside: it stays intact
 * with its contacts, but is hidden from the working list index and refuses
 * imports, sends, and splits until it is restored. Drafting is also the only
 * door to permanent deletion, so a list is never one click from being gone.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_lists', function (Blueprint $table) {
            $table->timestamp('drafted_at')->nullable()->after('name');

            $table->index(['team_id', 'drafted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_lists', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'drafted_at']);
            $table->dropColumn('drafted_at');
        });
    }
};
