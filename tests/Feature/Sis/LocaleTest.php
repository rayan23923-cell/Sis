<?php

namespace Tests\Feature\Sis;

use App\Models\User;
use App\Support\Translations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_switch_sets_direction_and_translates_server_labels(): void
    {
        $this->get('/login')->assertSee('dir="rtl"', false);
        $this->post(route('locale'), ['locale' => 'en'])->assertRedirect();
        $this->get('/login')->assertSee('dir="ltr"', false)->assertSee('Student Information System');

        $user = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($user)->post(route('locale'), ['locale' => 'en']);
        $this->assertSame('en', $user->fresh()->locale);
        $this->post(route('locale'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    public function test_server_strings_translate_with_numbers(): void
    {
        $this->assertSame('Active', Translations::text('مستمر', 'en'));
        $this->assertSame('12 marks saved.', Translations::text('حُفظت 12 درجة.', 'en'));
        $this->assertSame('أحمد علي', Translations::text('أحمد علي', 'en'), 'unknown text is left as is');
        $this->assertSame('مستمر', Translations::text('مستمر', 'ar'));
    }
}
