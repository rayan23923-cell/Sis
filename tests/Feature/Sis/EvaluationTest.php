<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicTerm;
use App\Models\EvaluationForm;
use App\Models\EvaluationRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EvaluationTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    private User $quality;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
        $this->quality = $this->user('q@t', 'quality');
        foreach ($this->offeringA->enrollments()->orderBy('id')->get() as $i => $e) {
            $u = User::create(['name' => "s{$i}", 'email' => "s{$i}@x.test", 'password' => 'student-pass-2026', 'is_active' => true]);
            $e->student->update(['user_id' => $u->id]);
            $this->students[] = $u;
        }
    }

    private function openRound(): EvaluationRound
    {
        $this->actingAs($this->quality)->post(route('evaluations.rounds.store'), [
            'name' => 'تقييم الفصل الأول', 'evaluation_form_id' => EvaluationForm::first()->id, 'academic_term_id' => AcademicTerm::first()->id,
            'college_id' => null, 'opens_on' => today()->subDay()->toDateString(), 'closes_on' => today()->addWeek()->toDateString(), 'min_responses' => 3,
        ])->assertSessionHas('success');

        return EvaluationRound::firstOrFail();
    }

    private function answer(User $u, EvaluationRound $round, int $score, ?string $comment = null)
    {
        $form = EvaluationForm::first();
        $answers = [];
        foreach ($form->questions as $i => $q) {
            $answers[$i] = $q['kind'] === 'scale' ? $score : $comment;
        }

        return $this->actingAs($u)->post(route('portal.evaluations.submit'), ['round' => $round->id, 'offering' => $this->offeringA->id, 'answers' => $answers]);
    }

    public function test_students_evaluate_once_and_anonymously(): void
    {
        $round = $this->openRound();
        $this->actingAs($this->students[0])->get(route('portal.home'))->assertInertia(fn ($p) => $p->has('evaluations', 1)->where('evaluations.0.offering', $this->offeringA->id));

        $this->answer($this->students[0], $round, 5, 'شرح ممتاز')->assertSessionHas('success');
        $this->answer($this->students[0], $round, 1)->assertSessionHas('error');
        $this->actingAs($this->students[0])->get(route('portal.home'))->assertInertia(fn ($p) => $p->has('evaluations', 0));

        // a student of another course cannot answer for this one
        $other = $this->offeringB->enrollments()->first()->student;
        $ou = User::create(['name' => 'o', 'email' => 'o@x.test', 'password' => 'student-pass-2026', 'is_active' => true]);
        $other->update(['user_id' => $ou->id]);
        $this->answer($ou, $round, 1)->assertSessionHas('error');

        // a missing answer is refused
        $this->actingAs($this->students[1])->post(route('portal.evaluations.submit'), ['round' => $round->id, 'offering' => $this->offeringA->id, 'answers' => [0 => 4]])->assertSessionHas('error');

        // the answers hold no student and cannot be joined to who answered
        $this->assertSame(1, DB::table('evaluation_answers')->count());
        $this->assertSame(['id', 'evaluation_round_id', 'course_offering_id', 'teacher_id', 'scores', 'comment', 'answered_on'], array_keys((array) DB::table('evaluation_answers')->first()));
        $this->assertSame(['id', 'evaluation_round_id', 'course_offering_id', 'student_id'], array_keys((array) DB::table('evaluation_submissions')->first()));
    }

    public function test_results_appear_only_from_the_minimum_and_teacher_sees_them_after_closing(): void
    {
        $round = $this->openRound();
        $this->answer($this->students[0], $round, 5, 'ممتاز');
        $this->answer($this->students[1], $round, 4);

        $row = fn () => collect($this->actingAs($this->quality)->get(route('evaluations.index'))->assertOk()->viewData('page')['props']['results']['rows'])->firstWhere('offering_id', $this->offeringA->id);
        $this->assertFalse($row()['shown']);
        $this->assertNull($row()['average']);
        $this->assertSame([], $row()['comments']);

        $this->answer($this->students[2], $round, 3);
        $this->assertTrue($row()['shown']);
        $this->assertEquals(4.0, $row()['average']);
        $this->assertSame(['ممتاز'], collect($row()['comments'])->values()->all());

        // the teacher sees nothing while the round is open, then their own course only
        $mine = fn () => $this->actingAs($this->teacherA)->get(route('evaluations.index'))->assertOk()->viewData('page')['props']['mine'];
        $this->assertSame([], $mine());
        $round->update(['opens_on' => today()->subWeeks(2), 'closes_on' => today()->subDay()]);
        $this->assertEquals(4.0, $mine()[0]['rows'][0]['average']);
        $this->assertCount(1, $mine()[0]['rows']);

        // other colleges' staff and teachers cannot manage evaluations
        $this->actingAs($this->teacherA)->post(route('evaluations.rounds.store'), [])->assertForbidden();
        $this->actingAs($this->registrarB)->get(route('evaluations.index'))->assertInertia(fn ($p) => $p->where('perm.view', false)->where('results', null));
    }

    public function test_college_quality_is_kept_to_its_college_and_used_forms_keep_their_questions(): void
    {
        $collegeB = $this->offeringB->collegeId();
        $cq = $this->user('cq@t', 'college_quality', $collegeB);
        $data = ['name' => 'x', 'evaluation_form_id' => EvaluationForm::first()->id, 'academic_term_id' => AcademicTerm::first()->id,
            'opens_on' => today()->toDateString(), 'closes_on' => today()->addDay()->toDateString(), 'min_responses' => 5];
        $this->actingAs($cq)->post(route('evaluations.rounds.store'), $data + ['college_id' => null])->assertSessionHas('error');
        $this->actingAs($cq)->post(route('evaluations.rounds.store'), $data + ['college_id' => $collegeB])->assertSessionHas('success');

        // a round of college B is not offered to college A students
        $this->actingAs($this->students[0])->get(route('portal.home'))->assertInertia(fn ($p) => $p->has('evaluations', 0));

        $form = EvaluationForm::first();
        $this->actingAs($this->quality)->put(route('evaluations.forms.update', $form), ['name' => 'جديد', 'questions' => [['text' => 'سؤال', 'kind' => 'scale']]])->assertSessionHas('error');
        $this->actingAs($this->quality)->put(route('evaluations.forms.update', $form), ['name' => 'اسم جديد', 'questions' => $form->questions, 'is_active' => true])->assertSessionHas('success');
        $this->actingAs($this->quality)->post(route('evaluations.forms.store'), ['name' => 'رسمية', 'questions' => [['text' => 'سؤال', 'kind' => 'scale']], 'is_official' => true])->assertSessionHasErrors('source_reference');
    }
}
