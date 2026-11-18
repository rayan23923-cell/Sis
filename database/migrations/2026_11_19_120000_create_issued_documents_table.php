<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // printed under the QR, used by /verify/{code}
            $table->string('type', 20);                    // transcript | graduation
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->json('snapshot');                      // what the document said, shown on verification
            $table->string('content_hash', 64);
            $table->boolean('official')->default(false);   // computed with approved official policies only
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
    }
};
