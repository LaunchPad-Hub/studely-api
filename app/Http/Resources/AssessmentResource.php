<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'type'  => $this->type, // e.g 'online', 'offline'
            'title' => $this->title,
            'instructions' => $this->instructions,
            'total_marks'  => $this->total_marks,
            'is_active'    => $this->is_active,

            // optional: aggregated duration from modules (or null)
            'duration_minutes' => $this->modules()->sum('per_student_time_limit_min') ?: null,

            'modules'    => ModuleResource::collection($this->whenLoaded('modules')),
            'modules_count' => $this->modules()->count(),
            'rubric'       => new RubricResource($this->whenLoaded('rubric')),
            'questions'     => QuestionResource::collection($this->whenLoaded('questions')),
            'questions_count'=> $this->questions()->count(),
            'attempts_count' => $this->attempts()->count(),
            'open_at'        => $this->open_at,
            'close_at'       => $this->close_at,
            'created_at'     => $this->created_at,
        ];
    }
}
