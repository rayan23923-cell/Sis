<?php

namespace App\AcademicRules;

/**
 * Starting policies for a fresh installation. Every one is marked is_official = false: they are
 * placeholders that let the system run until the university enters the official text (questions
 * Q1–Q8 of the Phase 1 document). Results computed with them are shown as "غير رسمية".
 *
 * The bands below follow the scale commonly published in Iraqi university Bologna guides
 * (A 90–100 … FX 45–49, F 0–44) and the traditional 50-mark pass line. They are not invented
 * rules, but they are also not yet confirmed for this university, which is why they are unofficial.
 */
final class Defaults
{
    /** @return list<array<string, mixed>> */
    public static function policies(): array
    {
        $bologna = [
            ['code' => 'A', 'name_ar' => 'امتياز', 'name_en' => 'Excellent', 'min' => 90, 'max' => 100, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'B', 'name_ar' => 'جيد جداً', 'name_en' => 'Very good', 'min' => 80, 'max' => 89, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'C', 'name_ar' => 'جيد', 'name_en' => 'Good', 'min' => 70, 'max' => 79, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'D', 'name_ar' => 'متوسط', 'name_en' => 'Satisfactory', 'min' => 60, 'max' => 69, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'E', 'name_ar' => 'مقبول', 'name_en' => 'Sufficient', 'min' => 50, 'max' => 59, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'FX', 'name_ar' => 'راسب (قيد المعالجة)', 'name_en' => 'Fail (resit)', 'min' => 45, 'max' => 49, 'points' => null, 'passed' => false, 'outcome' => 'resit'],
            ['code' => 'F', 'name_ar' => 'راسب', 'name_en' => 'Fail', 'min' => 0, 'max' => 44, 'points' => null, 'passed' => false, 'outcome' => 'fail'],
        ];

        $traditional = [
            ['code' => 'EX', 'name_ar' => 'امتياز', 'name_en' => 'Excellent', 'min' => 90, 'max' => 100, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'VG', 'name_ar' => 'جيد جداً', 'name_en' => 'Very good', 'min' => 80, 'max' => 89, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'G', 'name_ar' => 'جيد', 'name_en' => 'Good', 'min' => 70, 'max' => 79, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'M', 'name_ar' => 'متوسط', 'name_en' => 'Average', 'min' => 60, 'max' => 69, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'P', 'name_ar' => 'مقبول', 'name_en' => 'Pass', 'min' => 50, 'max' => 59, 'points' => null, 'passed' => true, 'outcome' => 'pass'],
            ['code' => 'FL', 'name_ar' => 'راسب', 'name_en' => 'Fail', 'min' => 0, 'max' => 49, 'points' => null, 'passed' => false, 'outcome' => 'resit'],
        ];

        $common = [
            ['type' => 'rounding', 'name_ar' => 'تقريب الدرجة النهائية', 'executor' => 'rounding@1', 'parameters' => ['mode' => 'half_up', 'precision' => 0]],
            ['type' => 'total', 'name_ar' => 'احتساب الدرجة الكلية من المكونات', 'executor' => 'total@1', 'parameters' => ['absent_counts_as_zero' => true, 'require_all_marks' => true]],
            ['type' => 'rounds', 'name_ar' => 'الأدوار الامتحانية', 'executor' => 'rounds@1', 'parameters' => ['max_rounds' => 2, 'resit_outcomes' => ['resit'], 'resit_cap' => null]],
        ];

        $out = [];
        foreach (['bologna', 'semester', 'annual'] as $system) {
            foreach ($common as $p) {
                $out[] = $p + ['study_system' => $system];
            }
            $out[] = [
                'type' => 'grade_scale', 'study_system' => $system, 'executor' => 'grade_scale@1',
                'name_ar' => $system === 'bologna' ? 'سلّم تقديرات بولونيا' : 'سلّم التقديرات التقليدي',
                'parameters' => ['bands' => $system === 'bologna' ? $bologna : $traditional],
            ];
            $out[] = [
                'type' => 'gpa', 'study_system' => $system, 'executor' => 'gpa@1',
                'name_ar' => 'المعدل الموزون',
                'parameters' => ['basis' => 'marks', 'weight' => $system === 'bologna' ? 'ects' : 'credit_hours', 'precision' => 2, 'include_failed' => true],
            ];
            if ($system !== 'bologna') {
                $out[] = [
                    'type' => 'progression', 'study_system' => $system, 'executor' => 'progression@1',
                    'name_ar' => 'الترفيع والتحميل',
                    'parameters' => ['max_carried' => 2, 'carry_in_final_stage' => false],
                ];
            }
        }

        return $out;
    }
}
