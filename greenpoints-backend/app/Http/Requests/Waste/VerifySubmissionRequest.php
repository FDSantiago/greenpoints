<?php

namespace App\Http\Requests\Waste;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifySubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    public function rules(): array
    {
        return [
            // Optional per-item corrections made by the staff member who weighed the waste.
            'items' => ['nullable', 'array', 'max:10'],
            'items.*.id' => ['required', 'integer'],
            'items.*.weight_kg' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'items.*.waste_condition' => ['nullable', Rule::in(['clean', 'contaminated'])],
        ];
    }
}
