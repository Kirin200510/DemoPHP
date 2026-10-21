<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DatabaseBootstrapTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_accounts_receive_their_permissions_when_the_database_starts_empty(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('permissions', 0);
        app(PermissionRegistrar::class)->getPermissions();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('roles', 4);
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $processor = User::where('email', 'processor@example.com')->firstOrFail();
        $customer = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->can('documents.export-management-csv'));
        $this->assertTrue($admin->can('documents.export-ocr-csv'));
        $this->assertTrue($processor->can('documents.verify'));
        $this->assertTrue($customer->can('documents.view-own'));
        $this->assertTrue($customer->can('documents.upload'));
        $this->assertFalse($customer->can('documents.export-ocr-csv'));
    }
}
