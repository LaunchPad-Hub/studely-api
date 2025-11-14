<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttemptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'student_id' => $this->student_id,
            'started_at' => optional($this->started_at)->toISOString(),
            'submitted_at' => optional($this->submitted_at)->toISOString(),
            'duration_sec' => $this->duration_sec,
            'score' => $this->score,
            'assessment' => new AssessmentResource($this->whenLoaded('assessment')),
            'responses'  => ResponseResource::collection($this->whenLoaded('responses')),

        ];
    }
}
