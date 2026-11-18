<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned academic policies (ADR-004), role assignments scoped to a college or department,
 * the audit trail and the integration log (ADR-008).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('ar')->after('email');
            $table->boolean('is_active')->default(true)->after('locale');
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // super_admin | registrar | rules_admin | dean | college_registrar | head | committee | teacher | auditor
            $table->string('role', 30);
            $table->foreignId('college_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role', 'college_id', 'department_id'], 'role_assignment_unique');
        });

        Schema::create('academic_policies', function (Blueprint $table) {
            $table->id();
            // grade_scale | rounding | total | gpa | progression | repetition | graduation | prerequisite | load
            $table->string('type', 30);
            $table->string('name_ar');
            $table->unsignedSmallInteger('version');
            $table->string('executor', 60);        // e.g. grade_scale@1 — code that interprets the parameters
            $table->json('parameters');
            $table->string('study_system', 20);    // bologna | semester | annual | any
            $table->string('scope_type', 20)->default('university'); // university | college | program | curriculum
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source_reference')->nullable(); // رقم الكتاب الرسمي وتاريخه
            $table->string('approving_authority')->nullable();
            $table->boolean('is_official')->default(false);  // false = placeholder awaiting the official text
            // draft | approved | retired
            $table->string('status', 20)->default('draft');
            $table->text('changelog')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'study_system', 'scope_type', 'scope_id', 'version'], 'policy_version_unique');
            $table->index(['type', 'study_system', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('system', 20);       // koha | moodle | erpnext | examnest | ministry
            $table->string('operation', 60);
            $table->string('idempotency_key', 120)->unique();
            $table->string('status', 20)->default('pending'); // pending | done | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['system', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('academic_policies');
        Schema::dropIfExists('role_assignments');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'is_active']);
        });
    }
};
