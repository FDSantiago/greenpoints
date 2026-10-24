<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'group_id' => ['nullable', 'integer', 'exists:class_groups,id'],
        ];

        if (config('services.school_registry.enabled')) {
            // The School Registry is the source of truth for name, course and section (class group),
            // so the request only needs to say who the student is.
            $rules['student_id'] = ['required', 'string', 'regex:/^\d{4}-\d{5}$/', 'unique:users,student_id'];
            $rules['name'] = ['nullable', 'string', 'max:255'];
            unset($rules['group_id']);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'Your student ID is required.',
            'student_id.regex' => 'The student ID must look like 2026-00123.',
            'student_id.unique' => 'An account with this student ID already exists.',
        ];
    }
}
