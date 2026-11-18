<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-year decisions per student program: the average, the failed courses and the decision the
 * progression policy gave, with the policy snapshot. Applied decisions move the student's stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progression_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_program_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('stage');
            $table->decimal('gpa', 6, 2)->nullable();
            $table->unsignedSmallInteger('courses_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->decimal('credits_earned', 6, 1)->default(0);
            $table->json('failed_courses')->nullable();
            // promoted | promoted_with_carried | repeat | graduate
            $table->string('decision', 30);
            $table->json('policy_snapshot');
            // draft | applied
            $table->string('status', 20)->default('draft');
            $table->foreignId('administrative_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['student_program_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progression_decisions');
    }
};
