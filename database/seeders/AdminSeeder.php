<?php

namespace Database\Seeders;

use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates the first administrator. The password comes from SIS_ADMIN_PASSWORD; without it a random
 * one is generated and written to storage/app/private/admin-password.txt (never to the console).
 */
class AdminSeeder extends Seeder
{
    /** The password set in this run, reused for the demo accounts. */
    public static ?string $password = null;

    public function run(): void
    {
        $email = config('sis.admin.email');
        if (User::where('email', $email)->exists()) {
            return;
        }
        $password = config('sis.admin.password') ?: Str::password(16, symbols: false);
        if (! config('sis.admin.password')) {
            Storage::disk('local')->put('admin-password.txt', "email: {$email}\npassword: {$password}\n");
        }
        self::$password = $password;
        $admin = User::create(['name' => 'مدير النظام', 'email' => $email, 'password' => $password, 'locale' => 'ar']);
        $admin->forceFill(['email_verified_at' => now()])->save();
        RoleAssignment::create(['user_id' => $admin->id, 'role' => 'super_admin']);
        $this->command?->info('Administrator created: '.$email);
    }
}
