<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'owner@example.test'],
            [
                'name' => 'Centre Owner',
                'password' => 'change-this-password',
                'locale' => 'en',
                'is_active' => true,
                'must_change_password' => true,
            ],
        );

        app(SystemRoleWriter::class)->assignRoles($user, 'super_admin');
    }
}
