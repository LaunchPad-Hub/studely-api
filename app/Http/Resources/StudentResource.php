<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'name'    => $this->user->name,
            'email'    => $this->user->email,
            'phone'    => $this->user->phone,
            'reg_no'  => $this->reg_no,
            'branch'  => $this->branch,
            'cohort'  => $this->cohort,
            'meta'    => $this->meta,
            'institution_name'    => $this->institution_name,
            'university_name'    => $this->college?->name ?? null,
            'gender'    => $this->gender,
            'dob'    => $this->dob,
            'admission_year'    => $this->admission_year,
            'current_semester'    => $this->current_semester,
            'tenant_id' => $this->tenant_id,
            'college_id' => $this->college_id,
            'training_status' => $this->training_status,
            'created_at' => $this->created_at,
        ];
    }
}
