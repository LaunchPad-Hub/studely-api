<?php

namespace App\Http\Requests\Assessments;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentRequest extends FormRequest
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
            'module_id' => ['required','integer','exists:modules,id'],
            'type' => ['required','in:MCQ,RUBRIC'],
            'title' => ['required','string'],
            'order' => ['required','integer','min:0'],
            // 'duration_minutes' => ['nullable','integer','min:0'],
            'instructions' => ['nullable','string'],
            'total_marks' => ['nullable','integer','min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
