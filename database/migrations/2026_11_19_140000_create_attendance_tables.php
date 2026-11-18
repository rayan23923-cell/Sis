<?php

use App\AcademicRules\Defaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->date('held_on');
            $table->unsignedTinyInteger('period')->default(1);      // lecture number on that day
            $table->decimal('hours', 4, 1)->default(1);
            $table->string('kind', 12)->default('lecture');         // lecture | lab
            $table->string('topic')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_offering_id', 'held_on', 'period']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);                           // present | absent | excused | late
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['attendance_session_id', 'enrollment_id']);
            $table->index(['enrollment_id', 'status']);
        });

        // Existing installations were seeded before this policy existed: add the unofficial placeholder once.
        if (Schema::hasTable('academic_policies') && ! DB::table('academic_policies')->where('type', 'attendance')->exists()) {
            $p = collect(Defaults::policies())->firstWhere('type', 'attendance');
            if ($p && DB::table('academic_policies')->exists()) {
                DB::table('academic_policies')->insert([
                    'type' => 'attendance', 'name_ar' => $p['name_ar'], 'version' => 1, 'executor' => $p['executor'],
                    'parameters' => json_encode($p['parameters']), 'study_system' => 'any', 'scope_type' => 'university',
                    'effective_from' => '2000-01-01', 'is_official' => false, 'status' => 'approved', 'approved_at' => now(),
                    'changelog' => 'سياسة مؤقتة لتشغيل النظام إلى حين إدخال النص الرسمي المعتمد من الجامعة.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        DB::table('academic_policies')->where('type', 'attendance')->where('is_official', false)->delete();
    }
};
