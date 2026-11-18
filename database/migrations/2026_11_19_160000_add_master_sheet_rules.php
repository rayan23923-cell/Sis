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
        Schema::table('curriculum_courses', function (Blueprint $table) {
            // closeness to the specialty for the decision's priorities: 1 = closest (master sheet "التخصص")
            $table->unsignedTinyInteger('specialty_rank')->nullable()->after('ects');
        });
        Schema::table('progression_decisions', function (Blueprint $table) {
            $table->json('decision_awards')->nullable();   // course code → marks of "قرار" added
        });

        // Installations seeded before this policy existed get the unofficial placeholder once.
        if (DB::table('academic_policies')->exists() && ! DB::table('academic_policies')->where('type', 'decision')->exists()) {
            foreach (collect(Defaults::policies())->where('type', 'decision') as $p) {
                DB::table('academic_policies')->insert([
                    'type' => 'decision', 'name_ar' => $p['name_ar'], 'version' => 1, 'executor' => $p['executor'],
                    'parameters' => json_encode($p['parameters']), 'study_system' => $p['study_system'], 'scope_type' => 'university',
                    'effective_from' => '2000-01-01', 'is_official' => false, 'status' => 'approved', 'approved_at' => now(),
                    'changelog' => 'قيم قالب الماستر شيت المرسل من الجامعة، مؤقتة إلى حين إدخال تعليمات الوزارة للعام.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('curriculum_courses', fn (Blueprint $t) => $t->dropColumn('specialty_rank'));
        Schema::table('progression_decisions', fn (Blueprint $t) => $t->dropColumn('decision_awards'));
        DB::table('academic_policies')->where('type', 'decision')->where('is_official', false)->delete();
    }
};
