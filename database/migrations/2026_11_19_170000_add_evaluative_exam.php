<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The national evaluative exam (الامتحان التقويمي), reinstated for some courses and colleges.
 * - curriculum_courses.is_evaluative: the default for the course in the plan;
 * - course_offerings.is_evaluative: whether the course sits the evaluative exam in that year (the ministry's list changes yearly);
 * - decision policy versions for 2024/2025 and 2025/2026 that carry the ministry's announced treatments.
 *   They stay unofficial (is_official = false) until the registrar enters the letters' numbers and confirms them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_courses', fn (Blueprint $t) => $t->boolean('is_evaluative')->default(false)->after('specialty_rank'));
        Schema::table('course_offerings', fn (Blueprint $t) => $t->boolean('is_evaluative')->default(false)->after('shift'));

        $base = DB::table('academic_policies')->where('type', 'decision')->where('scope_type', 'university')->where('version', 1)->get();
        foreach ($base as $p) {
            $params = json_decode($p->parameters, true);
            $evaluative = ['fail_below' => 20, 'out_of' => 60, 'treatment' => 0, 'treatment_scale' => 'weight', 'final_stage_bonus' => 0, 'excluded_colleges' => []];
            $versions = [
                2 => [
                    'from' => '2024-09-01', 'to' => '2025-08-31',
                    'source' => 'وزارة التعليم العالي: 5 درجات مساعدة في الدور الثاني (25/9/2025)؛ سنة عدم رسوب (7/9/2025)؛ معالجة المواد التقويمية للحالات الحرجة (25/9/2025)؛ درجة واحدة للمراحل المنتهية في المواد التقويمية (30/10/2025)',
                    'params' => ['amounts' => ['1' => $params['amounts']['1'] ?? 5, '2' => 5], 'no_fail_year' => true, 'final_stage_extra' => 0,
                        'evaluative' => ['treatment' => 5, 'final_stage_bonus' => 1] + $evaluative],
                    'log' => 'العام 2024/2025 حسب قرارات الوزارة المعلنة. قرار الدور الأول من قالب الماستر شيت وليس من كتاب وزاري.',
                ],
                3 => [
                    'from' => '2025-09-01', 'to' => null,
                    'source' => 'وزارة التعليم العالي: معالجة مواد الامتحان التقويمي بما لا يزيد على 5 درجات أو ما يعادلها من الوزن عدا كليات الطب (15/6/2026)؛ 5 درجات لحالات المراحل المنتهية (30/6/2026)',
                    'params' => ['amounts' => ['1' => $params['amounts']['1'] ?? 5, '2' => 0], 'no_fail_year' => false, 'final_stage_extra' => 5,
                        'evaluative' => ['treatment' => 5, 'final_stage_bonus' => 0] + $evaluative],
                    'log' => 'العام 2025/2026 وما بعده إلى حين صدور تعليمات جديدة. أضف رموز كليات الطب إلى excluded_colleges.',
                ],
            ];
            foreach ($versions as $v => $def) {
                if (DB::table('academic_policies')->where('type', 'decision')->where('study_system', $p->study_system)->where('scope_type', 'university')->where('version', $v)->exists()) {
                    continue;
                }
                DB::table('academic_policies')->insert([
                    'type' => 'decision', 'name_ar' => $p->name_ar, 'version' => $v, 'executor' => 'decision@1',
                    'parameters' => json_encode(array_replace($params, $def['params']), JSON_UNESCAPED_UNICODE), 'study_system' => $p->study_system,
                    'scope_type' => 'university', 'effective_from' => $def['from'], 'effective_to' => $def['to'],
                    'source_reference' => mb_substr($def['source'], 0, 255), 'is_official' => false, 'status' => 'approved', 'approved_at' => now(),
                    'changelog' => $def['log'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('academic_policies')->where('type', 'decision')->whereIn('version', [2, 3])->where('is_official', false)->delete();
        Schema::table('course_offerings', fn (Blueprint $t) => $t->dropColumn('is_evaluative'));
        Schema::table('curriculum_courses', fn (Blueprint $t) => $t->dropColumn('is_evaluative'));
    }
};
