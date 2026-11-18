<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAssignment extends Model
{
    public const ROLES = [
        'super_admin' => 'مدير النظام',
        'registrar' => 'مسجل الجامعة',
        'rules_admin' => 'مسؤول القواعد الأكاديمية',
        'dean' => 'العميد',
        'college_registrar' => 'مسجل الكلية',
        'head' => 'رئيس القسم',
        'committee' => 'اللجنة الامتحانية',
        'teacher' => 'تدريسي',
        'auditor' => 'مدقق',
    ];

    /** Roles that see the whole university regardless of college. */
    public const UNIVERSITY_WIDE = ['super_admin', 'registrar', 'rules_admin', 'auditor'];

    protected $fillable = ['user_id', 'role', 'college_id', 'department_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
