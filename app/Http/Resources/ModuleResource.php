<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModuleResource extends JsonResource
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
            'assessment_title'   => $this->assessment?->title ?? null,
            'title'   => $this->title,
            'code'    => $this->code,
            'start_at'=> optional($this->start_at)->toISOString(),
            'end_at'  => optional($this->end_at)->toISOString(),
            'time_limit_min' => $this->per_student_time_limit_min,
            'order'   => $this->order,
            'assessment'       => new AssessmentResource($this->whenLoaded('assessment')),
            'questions'        => QuestionResource::collection($this->whenLoaded('questions')),
            'status'   => $this->status,
        ];
    }
}
