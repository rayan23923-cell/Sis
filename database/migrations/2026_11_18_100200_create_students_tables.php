<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person = one student row; program changes are rows in student_programs, so a change of
 * department never creates a second student. Status only changes through an administrative order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrative_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50);
            $table->date('issued_on');
            // admission | postponement | transfer | hosting | dismissal (ترقين) | reinstatement | result_correction | other
            $table->string('type', 30)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('issued_by_authority');
            $table->foreignId('college_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['number', 'issued_on']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('university_number', 30)->unique();
            $table->string('national_id', 30)->nullable()->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('mother_name_ar')->nullable();
            $table->date('birth_date')->nullable();
            $table->char('gender', 1)->nullable(); // m | f
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            // active | postponed | hosted | transferred_out | dismissed | graduated | withdrawn
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('admission_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('admission_channel', 30)->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
            // Duplicate check without a national ID: same name + birth date + mother's name.
            $table->index(['name_ar', 'birth_date']);
        });

        Schema::create('student_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_version_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('stage')->default(1);
            $table->foreignId('start_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignId('end_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->boolean('is_current')->default(true);
            $table->string('end_reason', 30)->nullable();
            $table->foreignId('administrative_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'is_current']);
            $table->index(['program_id', 'is_current', 'stage']);
        });

        Schema::create('student_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->date('effective_on');
            $table->foreignId('administrative_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['student_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_status_changes');
        Schema::dropIfExists('student_programs');
        Schema::dropIfExists('students');
        Schema::dropIfExists('administrative_orders');
    }
};
