<?php

namespace Database\Seeders;

use App\Enums\OpsRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class OpsAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('OPS_SEED_EMAIL', '');
        $password = (string) env('OPS_SEED_PASSWORD', '');

        if ($email === '' || $password === '') {
            $this->command?->warn('OPS_SEED_EMAIL / OPS_SEED_PASSWORD not set — skipping local super_admin seed.');

            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => env('OPS_SEED_NAME', 'Plane Super Admin'),
                'password' => $password,
            ],
        );

        $user->syncRoles([OpsRole::SuperAdmin->value]);
    }
}
