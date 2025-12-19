<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollegeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'tenant_id'   => $this->tenant_id,
            'university'       => $this->whenLoaded('university', function () {
                return new UniversityResource($this->university);
            }),
            'name'        => $this->name,
            'code'        => $this->code,
            'state'       => $this->state,
            'district'    => $this->district,
            'management'   => $this->management,
            'location'    => $this->location,
            'description' => $this->description,
            'meta'        => $this->meta,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
