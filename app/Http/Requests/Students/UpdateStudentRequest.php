<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
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
            'university_id' => ['sometimes','required','exists:universities,id'],
            'college_id' => ['sometimes','required','exists:colleges,id'],
            'branch' => ['nullable','string'],
            'cohort' => ['nullable','string'],
            'meta'   => ['nullable','array'],
        ];
    }
}
