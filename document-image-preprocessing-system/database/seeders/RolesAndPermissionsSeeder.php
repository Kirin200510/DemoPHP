<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'documents.upload', 'documents.view-own', 'documents.view-any',
            'documents.view-original', 'documents.view-processed',
            'documents.view-face-crop', 'documents.view-raw-ocr',
            'documents.view-structured-ocr', 'documents.edit-structured-ocr-own',
            'documents.edit-structured-ocr-any', 'documents.submit-review',
            'documents.verify', 'documents.request-resubmission',
            'documents.retry-processing', 'documents.archive', 'documents.delete',
            'users.view', 'users.create', 'users.update', 'users.lock',
            'users.unlock', 'roles.view', 'roles.assign', 'permissions.manage',
            'audit.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $adminPermissions = array_values(array_diff($permissions, [
            'documents.upload',
            'documents.edit-structured-ocr-own',
            'documents.edit-structured-ocr-any',
            'documents.submit-review',
            'documents.verify',
            'documents.request-resubmission',
        ]));
        Role::findOrCreate('admin', 'web')->syncPermissions($adminPermissions);

        $staffPermissions = [
            'documents.view-any', 'documents.view-original',
            'documents.view-processed', 'documents.view-face-crop',
            'documents.view-structured-ocr',
            'documents.verify', 'documents.request-resubmission',
        ];
        Role::findOrCreate('processor', 'web')->syncPermissions($staffPermissions);
        Role::findOrCreate('reviewer', 'web')->syncPermissions($staffPermissions);
        Role::findOrCreate('customer', 'web')->syncPermissions([
            'documents.upload', 'documents.view-own', 'documents.view-original',
            'documents.view-processed', 'documents.view-structured-ocr',
            'documents.edit-structured-ocr-own', 'documents.submit-review',
        ]);
    }
}
