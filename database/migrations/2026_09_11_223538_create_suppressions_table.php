<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team's do-not-contact list: addresses that bounced or asked to be removed.
 * Blocking one deletes it from every list the team holds, and this row is what
 * keeps it out — a later import carrying the same address skips it, and no
 * delivery will ever push it again.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Who blocked the address, kept for accountability. Nulled rather
            // than cascaded: the block must outlive the person who added it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('reason')->default('unsubscribed');
            // How many contacts the block removed when it was added, so the
            // page can say what it did without re-deriving it from gone rows.
            $table->unsignedInteger('removed_count')->default(0);
            $table->timestamps();

            // One block per address per team, and the index the import and send
            // paths both look the address up by.
            $table->unique(['team_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
