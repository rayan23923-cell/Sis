<?php

namespace App\Http\Middleware;

use App\Models\AcademicPolicy;
use App\Models\RoleAssignment;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** Inertia visits in a non-Arabic locale: translate known server texts (labels, statuses, flash messages). */
    public function handle(Request $request, \Closure $next)
    {
        $response = parent::handle($request, $next);
        $locale = app()->getLocale();
        if ($locale !== 'ar' && $response instanceof JsonResponse && $request->header('X-Inertia')) {
            $data = $response->getData(true);
            if (isset($data['props'])) {
                $data['props'] = Translations::deep($data['props'], $locale);
                $response->setData($data);
            }
        }

        return $response;
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
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
