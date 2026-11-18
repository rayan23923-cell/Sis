<?php

namespace Tests\Feature\Sis;

use App\Models\Student;
use App\Models\User;
use App\Services\GradebookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentPortalTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function issue(Student $s): string
    {
        $this->actingAs($this->admin)->post(route('students.account', $s))->assertSessionHas('credentials');

        return session('credentials')[0]['password'];
    }

    public function test_registrar_issues_account_and_student_signs_in_with_number(): void
    {
        $s = $this->offeringA->enrollments()->first()->student;
        $password = $this->issue($s);
        $s->refresh();
        $this->assertNotNull($s->user_id);
        $this->assertTrue(User::find($s->user_id)->must_change_password);
        $this->assertFalse(Hash::check('x', User::find($s->user_id)->password));

        auth()->logout();
        $this->post('/login', ['email' => $s->university_number, 'password' => $password])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('password.first'));
        $this->get(route('portal.home'))->assertRedirect(route('password.first'));

        $this->put(route('password.update'), ['current_password' => $password, 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123', 'first' => true])
            ->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertInertia(fn ($p) => $p->component('Portal/Home')->where('student.number', $s->university_number));
    }

    public function test_student_sees_only_locked_results_and_own_transcript(): void
    {
        $o = $this->offeringA;
        $e = $o->enrollments()->orderBy('id')->first();
        $s = $e->student;
        $this->issue($s);
        $user = User::find($s->fresh()->user_id);
        $user->update(['must_change_password' => false]);

        $g = app(GradebookService::class);
        $g->saveMarks($o, $this->teacherA, $this->marksFor($o, [55, 50, 50, 50]));
        $g->compute($o->fresh(), $this->teacherA);

        $this->actingAs($user)->get(route('portal.home'))->assertInertia(fn ($p) => $p->where('current.0.result', null));

        $g->submit($o->fresh(), $this->teacherA);
        $g->approve($o->fresh(), $this->committeeA);
        $g->lock($o->fresh(), $this->committeeA);
        $this->actingAs($user)->get(route('portal.home'))->assertInertia(fn ($p) => $p->where('current.0.result.mark', 85));

        $this->actingAs($user)->get(route('students.transcript', $s))->assertOk()->assertHeader('content-type', 'application/pdf');
        $other = $o->enrollments()->orderBy('id')->skip(1)->first()->student;
        $this->actingAs($user)->get(route('students.transcript', $other))->assertForbidden();
    }

    public function test_student_account_cannot_open_staff_pages(): void
    {
        $s = $this->offeringA->enrollments()->first()->student;
        $this->issue($s);
        $user = User::find($s->fresh()->user_id);
        $user->update(['must_change_password' => false]);

        foreach (['students.index', 'offerings.index', 'policies.index', 'orders.index', 'profile.edit'] as $r) {
            $this->actingAs($user)->get(route($r))->assertRedirect(route('portal.home'));
        }
        $this->actingAs($user)->get(route('offerings.show', $this->offeringA))->assertRedirect(route('portal.home'));
    }

    public function test_bulk_accounts_return_slips_once(): void
    {
        $params = ['program' => $this->offeringB->program_id, 'stage' => 1];
        $this->actingAs($this->registrarB)->post(route('students.accounts.bulk'), $params)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(4, Student::whereNotNull('user_id')->count());
        $this->actingAs($this->registrarB)->postJson(route('students.accounts.bulk'), $params)->assertStatus(422);
        $this->actingAs($this->registrarB)->post(route('students.accounts.bulk'), ['program' => $this->offeringA->program_id, 'stage' => 1])->assertNotFound();
    }
}
