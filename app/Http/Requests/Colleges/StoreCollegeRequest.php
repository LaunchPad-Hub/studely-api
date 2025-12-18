<?php

namespace App\Http\Requests\Colleges;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollegeRequest extends FormRequest
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
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['nullable', 'string', 'max:50'],
            'state'       => ['nullable', 'string', 'max:255'],
            'district'    => ['nullable', 'string', 'max:255'],
            'location'    => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'meta'        => ['nullable', 'array'],
        ];
    }
}
