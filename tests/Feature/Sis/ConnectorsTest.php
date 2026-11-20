<?php

namespace Tests\Feature\Sis;

use App\Models\AssessmentMark;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Models\ConnectorMarkBatch;
use App\Models\LibraryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Library (FileNest) and exams connectors: connect, tools on/off, disconnect, and what each may do. */
class ConnectorsTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function connectExams(): string
    {
        $this->actingAs($this->admin)->post(route('connectors.connect', 'exams'), [])->assertSessionHasNoErrors();
        $key = session('connector_key')['key'];
        $this->assertStringStartsWith('sisc_', $key);
        auth()->logout();

        return $key;
    }

    private function api(string $key): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);
    }

    public function test_only_the_admin_manages_connectors(): void
    {
        $this->actingAs($this->teacherA)->get(route('connectors.index'))->assertForbidden();
        $this->actingAs($this->committeeA)->post(route('connectors.connect', 'exams'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('connectors.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Connectors/Index')->has('connectors', 2));
        $this->actingAs($this->admin)->post(route('connectors.connect', 'nope'))->assertNotFound();
    }

    public function test_exams_key_opens_only_the_tools_that_are_on_and_disconnect_closes_everything(): void
    {
        $this->getJson('/api/connectors/v1/exams/offerings')->assertUnauthorized();
        $key = $this->connectExams();
        $this->assertSame(64, strlen(Connector::where('key', 'exams')->first()->incoming_token_hash), 'only a hash is stored');
        $this->assertStringNotContainsString($key, AuditLog::all()->toJson());

        $this->api('sisc_wrong')->getJson('/api/connectors/v1/exams/offerings')->assertUnauthorized();
        $this->api($key)->getJson('/api/connectors/v1/exams/manifest')->assertOk()->assertJsonPath('system', 'sis');
        $res = $this->api($key)->getJson('/api/connectors/v1/exams/offerings')->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame(['coursework', 'final'], collect($res->json('data.0.components'))->where('accepts_marks', true)->pluck('code')->sort()->values()->all());
        $this->api($key)->getJson("/api/connectors/v1/exams/offerings/{$this->offeringA->id}/students")->assertOk()->assertJsonCount(4, 'data');

        // switch the roster tool off
        $this->actingAs($this->admin)->put(route('connectors.tools', 'exams'), ['tools' => ['marks_inbox']])->assertSessionHasNoErrors();
        auth()->logout();
        $this->api($key)->getJson('/api/connectors/v1/exams/offerings')->assertForbidden()->assertJsonPath('tool', 'rosters');

        // a new key stops the old one
        $this->actingAs($this->admin)->post(route('connectors.rotate', 'exams'));
        $newKey = session('connector_key')['key'];
        auth()->logout();
        $this->api($key)->getJson('/api/connectors/v1/exams/manifest')->assertUnauthorized();
        $this->api($newKey)->getJson('/api/connectors/v1/exams/manifest')->assertOk();

        $this->actingAs($this->admin)->post(route('connectors.disconnect', 'exams'));
        auth()->logout();
        $this->api($newKey)->getJson('/api/connectors/v1/exams/manifest')->assertUnauthorized();
    }

    public function test_marks_from_the_exams_system_wait_for_the_teacher(): void
    {
        $key = $this->connectExams();
        $enrollments = $this->offeringA->enrollments()->with('student')->orderBy('id')->get();
        $marks = $enrollments->map(fn ($e, $i) => ['university_number' => $e->student->university_number, 'mark' => 30 + $i])->all();
        $marks[3] = ['university_number' => $enrollments[3]->student->university_number, 'absent' => true];
        $marks[] = ['university_number' => '999999', 'mark' => 50];

        $url = "/api/connectors/v1/exams/offerings/{$this->offeringA->id}/marks";
        $this->api($key)->postJson($url, ['component' => 'final', 'reference' => 'exam-1', 'marks' => [['university_number' => $enrollments[0]->student->university_number, 'mark' => 61]]])
            ->assertStatus(422);
        $this->api($key)->postJson($url, ['component' => 'final_r2', 'reference' => 'exam-x', 'marks' => $marks])->assertStatus(422);
        $res = $this->api($key)->postJson($url, ['component' => 'final', 'reference' => 'exam-1', 'marks' => $marks])->assertStatus(202)
            ->assertJsonPath('accepted', 4)->assertJsonPath('unknown', ['999999']);
        $this->api($key)->postJson($url, ['component' => 'final', 'reference' => 'exam-1', 'marks' => $marks])->assertOk()->assertJsonPath('batch_id', $res->json('batch_id'));

        $this->assertSame(0, AssessmentMark::count(), 'nothing enters the gradebook before the teacher accepts');
        $this->assertSame(1, $this->teacherA->notifications()->count());

        $batch = ConnectorMarkBatch::firstOrFail();
        $this->flushHeaders(); // back to a browser
        $this->actingAs($this->teacherA)->get(route('offerings.show', $this->offeringA))
            ->assertInertia(fn ($p) => $p->has('incoming', 1)->where('incoming.0.can_review', true));
        // another college's registrar and the admin cannot accept for the teacher
        $this->actingAs($this->admin)->post(route('offerings.incoming.apply', [$this->offeringA, $batch]))->assertForbidden();
        $this->actingAs($this->teacherA)->post(route('offerings.incoming.apply', [$this->offeringB, $batch]))->assertForbidden();

        $this->actingAs($this->teacherA)->post(route('offerings.incoming.apply', [$this->offeringA, $batch]))->assertSessionHas('success');
        $this->assertSame('applied', $batch->fresh()->status);
        $final = $this->offeringA->components()->where('code', 'final')->first();
        $this->assertSame(30.0, AssessmentMark::where('enrollment_id', $enrollments[0]->id)->where('assessment_component_id', $final->id)->value('mark'));
        $this->assertTrue((bool) AssessmentMark::where('enrollment_id', $enrollments[3]->id)->value('is_absent'));
        $this->actingAs($this->teacherA)->post(route('offerings.incoming.apply', [$this->offeringA, $batch]))->assertSessionHas('error');

        $this->api($key)->getJson("/api/connectors/v1/exams/batches/{$batch->id}")->assertOk()->assertJsonPath('status', 'applied');
    }

    public function test_library_connect_checks_the_address_then_syncs_readers_and_clearance(): void
    {
        $students = $this->offeringA->enrollments()->with('student')->get()->pluck('student');
        $owing = (string) $students[0]->university_number;
        Http::fake([
            'filenest.test/api/sis/v1/manifest' => Http::response(['system' => 'filenest', 'name' => 'مكتبة الجامعة', 'version' => '1']),
            'filenest.test/api/sis/v1/members' => Http::response(['created' => 8, 'updated' => 0]),
            'filenest.test/api/sis/v1/clearance' => Http::response(['members' => [$owing => ['active_loans' => 2, 'overdue_loans' => 1, 'fines_due' => 1500]], 'missing' => []]),
            'wrong.test/*' => Http::response(['system' => 'other']),
        ]);

        $this->actingAs($this->admin)->post(route('connectors.connect', 'library'), ['base_url' => 'https://wrong.test', 'token' => str_repeat('k', 30)])
            ->assertSessionHasErrors('base_url');
        $this->actingAs($this->admin)->post(route('connectors.connect', 'library'), ['base_url' => 'https://filenest.test/', 'token' => str_repeat('k', 30)])
            ->assertSessionHasNoErrors();

        $c = Connector::where('key', 'library')->first();
        $this->assertTrue($c->isConnected());
        $this->assertNotNull($c->last_sync_at, 'the first sync ran');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/members') && $r->hasHeader('Authorization', 'Bearer '.str_repeat('k', 30))
            && collect($r['members'])->contains(fn ($m) => $m['card_number'] === $owing && $m['active'] === true));

        $this->assertSame(1500, LibraryStatus::where('student_id', $students[0]->id)->value('fines_due'));
        $this->assertFalse(LibraryStatus::where('student_id', $students[1]->id)->first()->is_member);
        $this->actingAs($this->admin)->get(route('students.show', $students[0]))
            ->assertInertia(fn ($p) => $p->where('library.clear', false)->where('library.loans', 2));

        // the clearance tool off: the student page no longer shows library data
        $this->actingAs($this->admin)->put(route('connectors.tools', 'library'), ['tools' => ['members_sync']]);
        $this->actingAs($this->admin)->get(route('students.show', $students[0]))->assertInertia(fn ($p) => $p->where('library', null));
    }

    public function test_a_library_that_refuses_the_key_is_reported_on_the_connector(): void
    {
        Http::fake([
            'filenest.test/api/sis/v1/manifest' => Http::response(['system' => 'filenest']),
            'filenest.test/*' => Http::response(['message' => 'no'], 401),
        ]);
        $this->actingAs($this->admin)->post(route('connectors.connect', 'library'), ['base_url' => 'https://filenest.test', 'token' => str_repeat('k', 30)]);
        $c = Connector::where('key', 'library')->first();
        $this->assertStringContainsString('رفضت المكتبة المفتاح', (string) $c->last_error);
        $this->artisan('sis:connectors-sync')->assertSuccessful();

        $this->actingAs($this->admin)->post(route('connectors.disconnect', 'library'));
        $this->assertNull($c->fresh()->outgoing_token);
        $this->artisan('sis:connectors-sync')->expectsOutput('The library connector is not connected.');
    }
}
