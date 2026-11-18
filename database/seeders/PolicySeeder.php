<?php

namespace Database\Seeders;

use App\AcademicRules\Defaults;
use App\Models\AcademicPolicy;
use Illuminate\Database\Seeder;

/** Installs the unofficial starting policies once. Never overwrites an existing policy. */
class PolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach (Defaults::policies() as $p) {
            $exists = AcademicPolicy::where('type', $p['type'])->where('study_system', $p['study_system'])
                ->where('scope_type', 'university')->exists();
            if ($exists) {
                continue;
            }
            AcademicPolicy::create($p + [
                'version' => 1,
                'scope_type' => 'university',
                'effective_from' => '2000-01-01',
                'is_official' => false,
                'status' => 'approved',
                'approved_at' => now(),
                'changelog' => 'سياسة مؤقتة لتشغيل النظام إلى حين إدخال النص الرسمي المعتمد من الجامعة.',
            ]);
        }
    }
}
