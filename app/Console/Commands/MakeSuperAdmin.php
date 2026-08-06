<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MakeSuperAdmin extends Command
{
    protected $signature = 'admin:create {email} {password} {name=Super Admin}';
    protected $description = 'Creates (or promotes an existing) super_admin user for the Admin Panel.';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'password' => Hash::make($this->argument('password')),
                'role' => 'super_admin',
                'status' => 'active',
            ]);
            $this->info("Existing user [{$email}] promoted to super_admin.");
        } else {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $this->argument('name'),
                'email' => $email,
                'phone' => '0000000000_' . Str::random(6), // placeholder, unique, admin doesn't need a real phone
                'password' => Hash::make($this->argument('password')),
                'role' => 'super_admin',
                'status' => 'active',
            ]);
            $this->info("New super_admin user created: {$email}");
        }

        return self::SUCCESS;
    }
}
