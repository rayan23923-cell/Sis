<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    private const MODELS = [
        'App\\Models\\Student' => 'طالب',
        'App\\Models\\StudentProgram' => 'برنامج طالب',
        'App\\Models\\AssessmentMark' => 'درجة',
        'App\\Models\\FinalResult' => 'نتيجة',
        'App\\Models\\AcademicPolicy' => 'سياسة أكاديمية',
        'App\\Models\\AdministrativeOrder' => 'أمر إداري',
        'App\\Models\\CourseOffering' => 'شعبة مادة',
        'App\\Models\\Enrollment' => 'تسجيل',
        'App\\Models\\User' => 'حساب مستخدم',
        'App\\Models\\RoleAssignment' => 'دور مستخدم',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('view-audit');
        $filters = $request->only(['model', 'action', 'user', 'from', 'to', 'id']);

        $logs = AuditLog::with('user:id,name')
            ->when($filters['model'] ?? null, fn ($q, $m) => $q->where('auditable_type', $m))
            ->when($filters['id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')->paginate(40)->withQueryString()
            ->through(fn (AuditLog $l) => [
                'id' => $l->id, 'at' => $l->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'user' => $l->user?->name ?? 'النظام', 'action' => $l->action,
                'model' => self::MODELS[$l->auditable_type] ?? class_basename((string) $l->auditable_type),
                'model_id' => $l->auditable_id, 'old' => $l->old_values, 'new' => $l->new_values,
                'reason' => $l->reason, 'ip' => $l->ip,
            ]);

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'models' => self::MODELS,
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
