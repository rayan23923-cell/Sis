<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connectors (2026-11-19): the library (FileNest) and the exams system plug into SIS the way an MCP
 * connector plugs into Claude. An admin connects or disconnects each one and switches its tools on or off.
 * Keys are never stored in the clear: the key SIS sends is encrypted, the key SIS receives is kept as a hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();                // library | exams
            $table->string('status', 20)->default('disconnected'); // disconnected | connected
            $table->string('base_url')->nullable();             // the other system's address (calls from SIS)
            $table->text('outgoing_token')->nullable();         // encrypted: the key SIS sends
            $table->string('incoming_token_hash', 64)->nullable(); // sha256 of the key the other system sends
            $table->string('incoming_token_hint', 8)->nullable();
            $table->json('tools')->nullable();                  // enabled tool keys
            $table->json('remote')->nullable();                 // what the other side said about itself
            $table->json('state')->nullable();                  // sync cursors
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();      // last call in either direction that worked
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        // The library's answer for each student: books not returned and fines not paid.
        Schema::create('library_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_member')->default(true);
            $table->unsignedSmallInteger('active_loans')->default(0);
            $table->unsignedSmallInteger('overdue_loans')->default(0);
            $table->unsignedInteger('fines_due')->default(0);
            $table->timestamp('synced_at');
        });

        // Marks sent by the exams system. They wait here until the course teacher accepts them into the gradebook.
        Schema::create('connector_mark_batches', function (Blueprint $table) {
            $table->id();
            $table->string('connector', 30)->default('exams');
            $table->string('reference', 120);                   // the sender's id for this batch (idempotency)
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_component_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('exam_round');
            $table->json('rows');                               // enrollment id → {mark, absent, number}
            $table->json('unknown')->nullable();                // university numbers not in this course
            $table->string('status', 20)->default('pending');   // pending | applied | rejected | superseded
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->unique(['connector', 'reference']);
            $table->index(['course_offering_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_mark_batches');
        Schema::dropIfExists('library_statuses');
        Schema::dropIfExists('connectors');
    }
};
