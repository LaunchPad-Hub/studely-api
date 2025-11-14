<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'type'     => $this->type,      // "MCQ" | "ESSAY" | ...
            'prompt'   => $this->stem,      // alias for frontend
            'marks'    => $this->marks ?? null, // if you have it, else null
            'difficulty' => $this->difficulty,
            'topic'  => $this->topic,
            'tags'   => $this->tags,
            'options'=> OptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
