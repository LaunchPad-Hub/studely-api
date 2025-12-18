<?php

namespace App\Http\Requests\Universities;

use Illuminate\Foundation\Http\FormRequest;

class StoreUniversityRequest extends FormRequest
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
            'name'             => ['required', 'string', 'max:255'],
            'state'            => ['nullable', 'string', 'max:255'],
            'district'         => ['nullable', 'string', 'max:255'],
            'code'             => ['nullable', 'string', 'max:50'],
            'location'         => ['nullable', 'string', 'max:255'],
            'website'          => ['nullable', 'url', 'max:255'],
            'established_year' => ['nullable', 'integer', 'min:1000', 'max:' . date('Y')],
        ];
    }
}
