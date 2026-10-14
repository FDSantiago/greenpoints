<?php

namespace App\Http\Requests\Waste;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWasteSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.category_id' => [
                'required', 'integer',
                Rule::exists('waste_categories', 'id')->where('is_active', 1),
            ],
            'items.*.weight_kg' => ['required', 'numeric', 'gt:0', 'max:100'],
            'items.*.waste_condition' => ['nullable', Rule::in(['clean', 'contaminated'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
