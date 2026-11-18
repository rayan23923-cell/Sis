<?php

namespace App\Http\Middleware;

use App\Models\AcademicPolicy;
use App\Models\RoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'university' => config('sis.university_name'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => collect($user->roles())->map(fn ($r) => RoleAssignment::ROLES[$r] ?? $r)->values(),
                ] : null,
            ],
            'can' => $user ? [
                'students' => Gate::allows('view-students'),
                'admit' => Gate::allows('admit-students'),
                'structure' => Gate::allows('manage-structure'),
                'policies' => true,
                'managePolicies' => Gate::allows('manage-policies'),
                'orders' => Gate::allows('issue-orders'),
                'audit' => Gate::allows('view-audit'),
                'users' => Gate::allows('manage-users'),
                'progression' => Gate::allows('view-progression'),
                'offerings' => Gate::allows('manage-offerings'),
            ] : [],
            'unofficialPolicies' => fn () => $user ? AcademicPolicy::where('status', 'approved')->where('is_official', false)->exists() : false,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'problems' => fn () => $request->session()->get('problems'),
                'created_order_id' => fn () => $request->session()->get('created_order_id'),
                'credentials' => fn () => $request->session()->get('credentials'),
            ],
        ];
    }
}
