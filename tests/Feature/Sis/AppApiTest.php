<?php

namespace Tests\Feature\Sis;

use App\Models\Student;
use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** The apps' API: token sign-in, the student's portal, notices and the one-time link into the site. */
class AppApiTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function studentUser(): array
    {
        $s = $this->offeringA->enrollments()->first()->student;
        $user = User::create(['name' => $s->name_ar, 'email' => 'stu-api@sis.local', 'password' => 'student-pass-2026', 'is_active' => true]);
        $s->update(['user_id' => $user->id]);

        return [$s, $user];
    }

    public function test_a_student_signs_in_with_the_number_and_reads_the_portal(): void
    {
        [$s, $user] = $this->studentUser();

        $this->postJson('/api/v1/auth/login', ['email' => $s->university_number, 'password' => 'wrong', 'device_name' => 'phone'])->assertUnprocessable();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $s->university_number, 'password' => 'student-pass-2026', 'device_name' => 'phone'])
            ->assertOk()->assertJsonPath('user.is_student', true)->json();
        $this->assertGuest('web');
        $h = ['Authorization' => 'Bearer '.$login['token']];

        $this->getJson('/api/v1/me', $h)->assertOk()->assertJsonPath('student_number', $s->university_number);
        $this->getJson('/api/v1/student', $h)->assertOk()->assertJsonPath('student.number', $s->university_number)->assertJsonStructure(['current', 'years', 'fees', 'timetable', 'requests']);

        $user->notify(new Notice('عنوان', 'نص'));
        $this->getJson('/api/v1/notifications', $h)->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/api/v1/notifications/read', [], $h)->assertOk()->assertJsonPath('unread', 0);

        // Staff have no portal; a signed-out token stops working.
        $this->postJson('/api/v1/auth/logout', [], $h)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $h)->assertUnauthorized();
    }

    public function test_the_app_opens_the_site_once_through_a_short_link(): void
    {
        [, $user] = $this->studentUser();
        $token = $user->createToken('phone')->plainTextToken;

        $url = $this->postJson('/api/v1/web-link', ['next' => '//evil.example/x'], ['Authorization' => 'Bearer '.$token])->assertOk()->json('url');
        $this->get($url)->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user, 'web');

        auth('web')->logout();
        $this->get($url)->assertRedirect(route('login'));   // used once only
        $this->assertGuest('web');
    }

    public function test_two_step_accounts_need_their_code_and_inactive_accounts_are_refused(): void
    {
        $user = $this->teacherA;
        $user->update(['password' => 'teacher-pass-2026']);
        $this->actingAs($user)->post(route('security.two-factor.begin'));
        $secret = $user->fresh()->two_factor_secret;
        $this->actingAs($user)->post(route('security.two-factor.confirm'), ['code' => (new Google2FA)->getCurrentOtp($secret)]);
        $codes = session('recovery_codes');
        auth('web')->logout();

        $r = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'teacher-pass-2026', 'device_name' => 'pc'])
            ->assertOk()->assertJsonPath('two_factor', true)->assertJsonMissingPath('token')->json();
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $r['challenge'], 'code' => '000000', 'device_name' => 'pc'])->assertUnprocessable();
        $token = $this->postJson('/api/v1/auth/two-factor', ['challenge' => $r['challenge'], 'code' => $codes[0], 'device_name' => 'pc'])
            ->assertOk()->assertJsonPath('user.is_student', false)->json('token');

        $this->getJson('/api/v1/student', ['Authorization' => 'Bearer '.$token])->assertNotFound();
        $user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_the_download_page_lists_what_was_published(): void
    {
        $this->get('/download')->assertOk()->assertSee('لا توجد ملفات للتحميل بعد.');
        @mkdir(public_path('downloads'));
        file_put_contents(public_path('downloads/releases.json'), json_encode(['android' => ['version' => '1.0.7', 'url' => '/downloads/Sis-1.0.7.apk']]));
        try {
            $this->get('/download')->assertOk()->assertSee('/downloads/Sis-1.0.7.apk')->assertDontSee('لا توجد ملفات');
        } finally {
            @unlink(public_path('downloads/releases.json'));
            @rmdir(public_path('downloads'));
        }
        $this->getJson('/api/v1/info')->assertOk()->assertJsonPath('system', 'sis');
    }
}
