<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic calendar, courses and versioned curricula. A curriculum version is never edited once
 * approved: a change makes a new version, and students keep the version they were admitted on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->unique(); // e.g. 2025-2026
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('code', 10); // S1, S2, Y (annual), SUM
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // planning | registration | teaching | exams | closed
            $table->string('status', 20)->default('planning')->index();
            $table->timestamps();
            $table->unique(['academic_year_id', 'code']);
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->decimal('credit_hours', 4, 1)->default(0);
            $table->decimal('ects', 4, 1)->nullable();
            $table->unsignedTinyInteger('theory_hours')->default(0);
            $table->unsignedTinyInteger('practical_hours')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('curriculum_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('name');
            $table->foreignId('effective_from_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            // draft | approved | retired
            $table->string('status', 20)->default('draft');
            $table->string('approval_reference')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['program_id', 'version']);
        });

        Schema::create('curriculum_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('stage');            // المرحلة 1..6
            $table->unsignedTinyInteger('term_no')->nullable(); // 1, 2, or null for an annual course
            $table->boolean('is_mandatory')->default(true);
            $table->decimal('credit_hours', 4, 1)->default(0);
            $table->decimal('ects', 4, 1)->nullable();
            $table->timestamps();
            $table->unique(['curriculum_version_id', 'course_id']);
            $table->index(['curriculum_version_id', 'stage']);
        });

        Schema::create('curriculum_course_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_course_id')->constrained('courses')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['curriculum_course_id', 'prerequisite_course_id'], 'cc_prereq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_course_prerequisites');
        Schema::dropIfExists('curriculum_courses');
        Schema::dropIfExists('curriculum_versions');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('academic_terms');
        Schema::dropIfExists('academic_years');
    }
};
