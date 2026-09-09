<?php

namespace Database\Seeders;

use App\Enums\OpsRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (OpsRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
    }
}
