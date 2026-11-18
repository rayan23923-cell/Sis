<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Department;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Accounts and their roles. A role is held university-wide, for one college, or for one department. */
class UserController extends Controller
{
    /** Which scope each role takes. */
    public const ROLE_SCOPE = [
        'super_admin' => 'university', 'registrar' => 'university', 'rules_admin' => 'university', 'auditor' => 'university',
        'dean' => 'college', 'college_registrar' => 'college', 'committee' => 'college',
        'head' => 'department', 'teacher' => 'department',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('manage-users');
        $filters = $request->only(['q', 'role', 'status']);

        $users = User::with(['roleAssignments.college:id,name_ar', 'roleAssignments.department:id,name_ar'])
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when($filters['role'] ?? null, fn ($q, $r) => $q->whereHas('roleAssignments', fn ($a) => $a->where('role', $r)))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->paginate(25)->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'active' => (bool) $u->is_active,
                'roles' => $u->roleAssignments->map(fn ($a) => [
                    'role' => $a->role, 'college_id' => $a->college_id, 'department_id' => $a->department_id,
                    'scope' => $a->department?->name_ar ?? $a->college?->name_ar,
                ])->values(),
                'self' => $u->id === $request->user()->id,
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => RoleAssignment::ROLES,
            'roleScope' => self::ROLE_SCOPE,
            'colleges' => College::orderBy('name_ar')->get(['id', 'name_ar']),
            'departments' => Department::with('college:id,name_ar')->orderBy('name_ar')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name_ar, 'college_id' => $d->college_id, 'college' => $d->college->name_ar]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-users');
        $data = $this->validated($request);
        AuditContext::as($request->user()->id, 'إنشاء حساب', function () use ($data) {
            DB::transaction(function () use ($data) {
                $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'is_active' => $data['active'] ?? true, 'locale' => 'ar']);
                $user->forceFill(['email_verified_at' => now()])->save();
                $this->syncRoles($user, $data['roles']);
            });
        });

        return back()->with('success', 'أُنشئ حساب '.$data['name'].'.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-users');
        $data = $this->validated($request, $user);
        if ($user->id === $request->user()->id && (! ($data['active'] ?? true) || ! collect($data['roles'])->contains('role', 'super_admin'))) {
            throw ValidationException::withMessages(['roles' => 'لا يمكنك تعطيل حسابك أو إزالة دور مدير النظام عن نفسك.']);
        }
        DB::transaction(function () use ($user, $data) {
            $user->fill(['name' => $data['name'], 'email' => $data['email'], 'is_active' => $data['active'] ?? true]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $this->syncRoles($user, $data['roles']);
        });

        return back()->with('success', 'حُفظت بيانات '.$user->name.'.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', Password::min(10)],
            'active' => ['boolean'],
            'roles' => ['array'],
            'roles.*.role' => ['required', Rule::in(array_keys(RoleAssignment::ROLES))],
            'roles.*.college_id' => ['nullable', 'exists:colleges,id'],
            'roles.*.department_id' => ['nullable', 'exists:departments,id'],
        ], [], ['name' => 'الاسم', 'email' => 'البريد', 'password' => 'كلمة المرور']);

        foreach ($data['roles'] ?? [] as $i => $r) {
            $scope = self::ROLE_SCOPE[$r['role']];
            if ($scope === 'college' && empty($r['college_id'])) {
                throw ValidationException::withMessages(["roles.{$i}.college_id" => 'حدد الكلية لدور «'.RoleAssignment::ROLES[$r['role']].'».']);
            }
            if ($scope === 'department' && empty($r['department_id'])) {
                throw ValidationException::withMessages(["roles.{$i}.department_id" => 'حدد القسم لدور «'.RoleAssignment::ROLES[$r['role']].'».']);
            }
        }
        $data['roles'] = $data['roles'] ?? [];

        return $data;
    }

    private function syncRoles(User $user, array $roles): void
    {
        $user->roleAssignments()->delete();
        $seen = [];
        foreach ($roles as $r) {
            $scope = self::ROLE_SCOPE[$r['role']];
            $row = [
                'role' => $r['role'],
                'college_id' => $scope === 'college' ? (int) $r['college_id'] : null,
                'department_id' => $scope === 'department' ? (int) $r['department_id'] : null,
            ];
            $key = implode('|', $row);
            if (! isset($seen[$key])) {
                $user->roleAssignments()->create($row);
                $seen[$key] = true;
            }
        }
        $user->forgetRoles();
    }
}
