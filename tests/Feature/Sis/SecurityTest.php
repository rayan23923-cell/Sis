<?php

namespace Tests\Feature\Sis;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function enable(User $user): array
    {
        $this->actingAs($user)->post(route('security.two-factor.begin'))->assertRedirect();
        $secret = $user->fresh()->two_factor_secret;
        $this->actingAs($user)->post(route('security.two-factor.confirm'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHas('recovery_codes');

        return [$secret, session('recovery_codes')];
    }

    public function test_two_step_sign_in_with_app_code_and_one_time_recovery_code(): void
    {
        $user = $this->teacherA;
        $user->update(['password' => 'teacher-pass-2026']);
        [$secret, $codes] = $this->enable($user);
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertCount(8, $codes);
        auth()->logout();

        // the password alone does not sign in
        $this->post('/login', ['email' => $user->email, 'password' => 'teacher-pass-2026'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'two_factor_failed']);

        // a recovery code works once
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'event' => 'success']);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'teacher-pass-2026']);
        $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(7, count($user->fresh()->two_factor_recovery_codes));
        // the secret is stored encrypted
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
    }

    public function test_required_roles_must_set_up_two_step_sign_in_and_admins_can_reset_it(): void
    {
        config(['security.two_factor_roles' => ['committee']]);
        $this->actingAs($this->committeeA)->get(route('dashboard'))->assertRedirect(route('security.show'));
        $this->actingAs($this->committeeA)->get(route('security.show'))->assertOk();
        $this->enable($this->committeeA);
        $this->actingAs($this->committeeA)->get(route('dashboard'))->assertOk();
        // cannot switch it off while the role requires it
        $this->committeeA->update(['password' => 'committee-pass-2026']);
        $this->actingAs($this->committeeA)->delete(route('security.two-factor.disable'), ['password' => 'committee-pass-2026'])->assertSessionHasErrors('password');

        $this->actingAs($this->teacherA)->delete(route('users.two-factor.reset', $this->committeeA))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('users.two-factor.reset', $this->committeeA))->assertSessionHas('success');
        $this->assertFalse($this->committeeA->fresh()->hasTwoFactor());
    }

    public function test_failed_sign_ins_are_logged_and_pages_send_security_headers(): void
    {
        $this->post('/login', ['email' => 'nobody@x.test', 'password' => 'wrong-pass-1'])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('login_events', ['login' => 'nobody@x.test', 'event' => 'failed']);
        $this->actingAs($this->admin)->get(route('logins.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Security/Logins')->where('stats.failed_24h', 1));
        $this->actingAs($this->teacherA)->get(route('logins.index'))->assertForbidden();

        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Permission sweep: a teacher (the narrowest staff role) and a student account open every page that needs
     * no parameters. Only the pages listed here may answer 200.
     */
    public function test_narrow_accounts_cannot_open_management_pages(): void
    {
        // policies.index: the approved academic rules are readable by all staff (read only)
        $open = ['dashboard', 'offerings.index', 'structure.index', 'policies.index', 'profile.edit', 'security.show', 'portal.home', 'password.first',
            'offerings.teachers', 'verify.form', 'login', 'register', 'password.request', 'verification.notice', 'password.confirm', 'two-factor.challenge'];
        $student = $this->offeringA->enrollments()->first()->student;
        $studentUser = User::create(['name' => 's', 'email' => 's@x.test', 'password' => 'student-pass-2026', 'is_active' => true]);
        $student->update(['user_id' => $studentUser->id]);

        $leaks = [];
        foreach ([$this->teacherA, $studentUser] as $user) {
            foreach (Route::getRoutes() as $r) {
                /** @var RouteDef $r */
                if (! in_array('GET', $r->methods(), true) || $r->parameterNames() !== [] || ! $r->getName() || str_starts_with($r->uri(), '_')
                    || in_array($r->getName(), $open, true) || in_array($r->getName(), ['storage.local', 'up'], true)) {
                    continue;
                }
                $status = $this->actingAs($user)->get('/'.ltrim($r->uri(), '/'))->status();
                if ($status === 200) {
                    $leaks[] = $user->email.' → '.$r->getName();
                }
            }
        }
        $this->assertSame([], $leaks);
    }
}
