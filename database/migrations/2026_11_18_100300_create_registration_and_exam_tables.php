<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offerings (sections), enrollments, assessment marks and final results.
 * final_results is append-only: a locked row is never updated; a correction is a new row that
 * supersedes it and points at the administrative order that allowed it (ADR-005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('stage')->nullable();
            $table->string('section', 10)->default('A');
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->default(60);
            // Status of the current exam round: open | submitted | approved | locked
            $table->unsignedTinyInteger('current_round')->default(1);
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();
            $table->unique(['course_id', 'academic_term_id', 'section']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_program_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('attempt_no')->default(1);
            $table->boolean('is_carried')->default(false); // مادة محملة
            // enrolled | withdrawn | completed
            $table->string('status', 20)->default('enrolled');
            $table->timestamps();
            $table->unique(['student_id', 'course_offering_id']);
            $table->index(['course_offering_id', 'status']);
        });

        Schema::create('assessment_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);      // coursework, midterm, final, final_r2
            $table->string('name_ar');
            $table->decimal('weight', 5, 2);  // share of the total out of 100
            $table->decimal('max_mark', 6, 2)->default(100);
            $table->unsignedTinyInteger('exam_round')->default(1); // 1 = الدور الأول, 2 = الدور الثاني
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['course_offering_id', 'code']);
        });

        Schema::create('assessment_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_component_id')->constrained()->restrictOnDelete();
            $table->decimal('mark', 6, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['enrollment_id', 'assessment_component_id']);
        });

        Schema::create('final_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('exam_round')->default(1);
            $table->unsignedSmallInteger('version')->default(1);
            $table->decimal('total_mark', 6, 2)->nullable();
            $table->decimal('rounded_mark', 6, 2)->nullable();
            $table->string('grade_code', 10)->nullable();
            $table->string('grade_name_ar')->nullable();
            $table->decimal('grade_points', 4, 2)->nullable();
            $table->boolean('passed')->default(false);
            $table->string('outcome', 20); // pass | fail | resit (FX / الدور الثاني) | absent | withdrawn
            $table->decimal('credits_earned', 4, 1)->default(0);
            $table->json('policy_snapshot');   // id + version + executor + parameters of every policy used
            $table->string('input_hash', 64);  // sha256 of the marks the result was computed from
            // draft | approved | locked
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('supersedes_id')->nullable()->constrained('final_results')->restrictOnDelete();
            $table->foreignId('administrative_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_current')->default(true);
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'exam_round', 'version']);
            $table->index(['enrollment_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_results');
        Schema::dropIfExists('assessment_marks');
        Schema::dropIfExists('assessment_components');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('course_offerings');
    }
};
