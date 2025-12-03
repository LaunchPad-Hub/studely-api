<?php

namespace App\Http\Requests\Assessments;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssessmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes','string'],
            'type' => ['sometimes','in:online,offline'],
            'order' => ['sometimes','integer','min:0'],
            // 'duration_minutes' => ['sometimes','nullable','integer','min:0'],
            'instructions' => ['sometimes','nullable','string'],
            'total_marks' => ['sometimes','integer','min:0'],
            'is_active' => ['sometimes','boolean'],
        ];
    }
}
