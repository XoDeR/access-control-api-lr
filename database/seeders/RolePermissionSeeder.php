<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Owner', 'slug' => 'owner', 'description' => 'Full organization control'],
            ['name' => 'Admin', 'slug' => 'admin', 'description' => 'Manage members and settings'],
            ['name' => 'Member', 'slug' => 'member', 'description' => 'Standard team member'],
            ['name' => 'Viewer', 'slug' => 'viewer', 'description' => 'Read-only access'],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(['slug' => $role['slug']], $role);
        }

        $permissions = [
            ['name' => 'Users Read', 'slug' => 'users.read', 'description' => 'View organization members'],
            ['name' => 'Users Invite', 'slug' => 'users.invite', 'description' => 'Invite teammates'],
            ['name' => 'Projects Write', 'slug' => 'projects.write', 'description' => 'Create and edit projects'],
            ['name' => 'Billing Read', 'slug' => 'billing.read', 'description' => 'View billing information'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(['slug' => $permission['slug']], $permission);
        }

        $matrix = [
            'owner' => ['users.read', 'users.invite', 'projects.write', 'billing.read'],
            'admin' => ['users.read', 'users.invite', 'projects.write', 'billing.read'],
            'member' => ['users.read', 'projects.write'],
            'viewer' => ['users.read', 'billing.read'],
        ];

        foreach ($matrix as $roleSlug => $permissionSlugs) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
            $permissionIds = Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id');
            $role->permissions()->sync($permissionIds);
        }
    }
}
