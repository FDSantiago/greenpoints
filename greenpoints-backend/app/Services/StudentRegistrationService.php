<?php

namespace App\Services;

use App\Integrations\SchoolRegistry\Exceptions\StudentNotFound;
use App\Integrations\SchoolRegistry\SchoolRegistryClient;
use App\Models\ClassGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentRegistrationService
{
    public function __construct(private SchoolRegistryClient $registry) {}

    /**
     * Register a student verified against the School Registry (System 2).
     *
     * 1. Ask the registry for the student (GET /api/v1/students/{student_id}).
     * 2. Unknown id or not Active: 422 on `student_id`.
     * 3. Active: create the user with the registry's name and course, and put them in the class
     *    group named after their registry section (created if needed).
     *
     * The registry is called before anything is written, so if it is down (RegistryException,
     * rendered as 503 in bootstrap/app.php) no user is created.
     *
     * @param  array{student_id: string, email: string, password: string}  $data
     */
    public function register(array $data): User
    {
        try {
            $student = $this->registry->findStudent($data['student_id']);
        } catch (StudentNotFound) {
            throw ValidationException::withMessages([
                'student_id' => ['This student ID was not found in the School Registry.'],
            ]);
        }

        if (! $student->isActive()) {
            throw ValidationException::withMessages([
                'student_id' => ["This student is not active in the School Registry (status: {$student->status})."],
            ]);
        }

        return DB::transaction(function () use ($student, $data) {
            $group = ClassGroup::firstOrCreate(['name' => $student->section], ['type' => 'class']);

            $user = new User([
                'name' => $student->fullName(),
                'email' => $data['email'],
                'password' => $data['password'],
                'group_id' => $group->id,
            ]);
            // Not mass-assignable: only registry data may set these.
            $user->forceFill(['student_id' => $student->studentId, 'course' => $student->course])->save();

            return $user;
        });
    }
}
