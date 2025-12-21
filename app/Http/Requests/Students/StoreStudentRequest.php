<?php

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
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
            'reg_no' => ['nullable','string'],
            'university_id' => ['required','exists:universities,id'],
            'college_id' => ['required','exists:colleges,id'],
            'branch' => ['nullable','string'],
            'cohort' => ['nullable','string'],
            'meta'   => ['nullable','array'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],

            'user' => ['required','array'],
            'user.name' => ['required','string','min:2'],
            'user.email' => ['required','email','max:255','unique:users,email'],
            'user.phone' => ['nullable','string','max:30'],
        ];
    }
}
