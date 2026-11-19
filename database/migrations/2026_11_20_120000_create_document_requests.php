<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A student's online request for a document; the registrar issues it and the student downloads it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                       // transcript | enrollment | graduation
            $table->string('addressed_to', 150)->nullable();  // الجهة المعنون إليها
            $table->string('purpose', 200)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('submitted'); // submitted | ready | delivered | rejected | cancelled
            $table->string('response', 300)->nullable();      // the registrar's note or the reason for rejecting
            $table->foreignId('issued_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
