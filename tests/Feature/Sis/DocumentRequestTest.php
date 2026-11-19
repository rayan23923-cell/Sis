<?php

namespace Tests\Feature\Sis;

use App\Models\DocumentRequest;
use App\Models\User;
use App\Services\GradebookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentRequestTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
        $student = $this->offeringB->enrollments()->first()->student;
        $this->studentUser = User::create(['name' => $student->name_ar, 'email' => 'st@x.test', 'password' => 'student-pass-2026', 'is_active' => true]);
        $student->update(['user_id' => $this->studentUser->id]);
    }

    public function test_student_requests_registrar_issues_and_student_downloads(): void
    {
        $this->actingAs($this->studentUser)->post(route('portal.requests.store'), ['type' => 'enrollment', 'addressed_to' => 'مديرية التقاعد العامة'])->assertSessionHas('success');
        $r = DocumentRequest::first();
        $this->assertSame('submitted', $r->status);
        // the college registrar is told
        $this->assertSame(1, $this->registrarB->unreadNotifications()->count());

        // another college's staff cannot touch it
        $this->actingAs($this->committeeA)->post(route('requests.issue', $r))->assertForbidden();
        $this->actingAs($this->registrarB)->get(route('requests.index'))->assertOk()->assertInertia(fn ($p) => $p->has('rows.data', 1));
        $this->actingAs($this->registrarB)->post(route('requests.issue', $r))->assertSessionHas('success');
        $r->refresh();
        $this->assertSame('ready', $r->status);
        $this->assertNotNull($r->document);
        $this->assertSame('enrollment', $r->document->type);

        // the student is told and downloads the same file; nobody else can
        $this->assertSame(1, $this->studentUser->unreadNotifications()->count());
        $this->actingAs($this->studentUser)->get(route('requests.file', $r))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $other = User::create(['name' => 'o', 'email' => 'o@x.test', 'password' => 'other-pass-2026', 'is_active' => true]);
        $this->actingAs($other)->get(route('requests.file', $r))->assertForbidden();
        $this->get(route('verify', $r->document->code))->assertOk();

        // the bell lists it and opening marks it read
        $list = $this->actingAs($this->studentUser)->getJson(route('notifications.index'))->assertOk()->json();
        $this->assertSame(1, $list['unread']);
        $this->actingAs($this->studentUser)->get(route('notifications.open', $list['items'][0]['id']))->assertRedirect();
        $this->assertSame(0, $this->studentUser->unreadNotifications()->count());
    }

    public function test_rejection_reason_reaches_the_student_and_graduation_needs_graduates(): void
    {
        $this->actingAs($this->studentUser)->post(route('portal.requests.store'), ['type' => 'graduation'])->assertSessionHas('error');
        $this->actingAs($this->studentUser)->post(route('portal.requests.store'), ['type' => 'transcript']);
        $r = DocumentRequest::first();
        $this->actingAs($this->registrarB)->post(route('requests.reject', $r), ['reason' => 'رسوم غير مسددة'])->assertSessionHas('success');
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertStringContainsString('رسوم غير مسددة', $this->studentUser->notifications()->first()->data['body']);
        $this->actingAs($this->studentUser)->post(route('portal.requests.cancel', $r))->assertSessionHas('error');
    }

    public function test_locking_results_notifies_the_students(): void
    {
        $o = $this->offeringB;
        $g = app(GradebookService::class);
        $g->saveMarks($o, $o->teacher, $this->marksFor($o, [50, 40, 30, 20]));
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->admin);
        $g->lock($o->fresh(), $this->admin);
        $this->assertSame('أُعلنت نتيجة', $this->studentUser->notifications()->first()->data['title']);
    }
}
