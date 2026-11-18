<?php

namespace App\Http\Controllers;

use App\Models\AdministrativeOrder;
use App\Models\College;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('issue-orders');
        $filters = $request->only(['q', 'type']);

        return Inertia::render('Orders/Index', [
            'orders' => AdministrativeOrder::with(['college:id,name_ar'])
                ->when($request->user()->collegeScope() !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('college_id')->orWhereIn('college_id', $request->user()->collegeScope())))
                ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
                ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
                ->latest('issued_on')->latest('id')->paginate(25)->withQueryString()
                ->through(fn ($o) => [
                    'id' => $o->id, 'number' => $o->number, 'issued_on' => $o->issued_on->toDateString(), 'type' => $o->type,
                    'title' => $o->title, 'body' => $o->body, 'authority' => $o->issued_by_authority, 'college' => $o->college?->name_ar,
                ]),
            'filters' => $filters,
            'types' => AdministrativeOrder::TYPES,
            'colleges' => College::visibleTo($request->user())->orderBy('name_ar')->get(['id', 'name_ar']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('issue-orders');
        $data = $request->validate([
            'number' => ['required', 'string', 'max:50', Rule::unique('administrative_orders')->where('issued_on', $request->input('issued_on'))],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'type' => ['required', Rule::in(array_keys(AdministrativeOrder::TYPES))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'issued_by_authority' => ['required', 'string', 'max:255'],
            'college_id' => ['nullable', 'exists:colleges,id'],
        ], ['number.unique' => 'يوجد أمر بالرقم والتاريخ نفسيهما.'], ['number' => 'رقم الأمر', 'issued_on' => 'تاريخ الأمر', 'title' => 'العنوان', 'issued_by_authority' => 'الجهة المصدرة']);

        $scope = $request->user()->collegeScope();
        if ($scope !== null && ! in_array((int) ($data['college_id'] ?? 0), $scope, true)) {
            $data['college_id'] = $scope[0] ?? null;
        }
        $order = AdministrativeOrder::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'سُجّل الأمر الإداري '.$order->reference().'.')->with('created_order_id', $order->id);
    }
}
