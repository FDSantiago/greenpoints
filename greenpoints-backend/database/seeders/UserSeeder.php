<?php

namespace Database\Seeders;

use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // DEV ONLY: every seeded account uses the password "password".
        $groups = ClassGroup::pluck('id', 'name');

        $this->upsert('Admin',        'admin@greenpoints.test',    User::ROLE_ADMIN,   null);
        $this->upsert('Staff Member', 'staff@greenpoints.test',    User::ROLE_STAFF,   null);

        $students = [
            ['Ana Reyes',      'student1@greenpoints.test', '11-A'],
            ['Ben Cruz',       'student2@greenpoints.test', '11-A'],
            ['Carla Santos',   'student3@greenpoints.test', '11-B'],
            ['Dan Villanueva', 'student4@greenpoints.test', '11-B'],
            ['Ella Garcia',    'student5@greenpoints.test', '12-A'],
            ['Francis Lim',    'student6@greenpoints.test', '12-A'],
        ];

        foreach ($students as [$name, $email, $group]) {
            $this->upsert($name, $email, User::ROLE_STUDENT, $groups[$group] ?? null);
        }
    }

    private function upsert(string $name, string $email, string $role, ?int $groupId): void
    {
        // role is not mass-assignable on purpose, so set it via forceFill().
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => 'password', // hashed by the model cast
            'role' => $role,
            'group_id' => $groupId,
            'email_verified_at' => now(),
        ])->save();
    }
}
