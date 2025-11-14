<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessments\StoreAssessmentRequest;
use App\Http\Requests\Assessments\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function index(Request $r)
    {
        $tid = app('tenant.id');

        $q = Assessment::where('tenant_id', $tid)
            ->withCount('modules')
            ->latest();

        // (optional future filters here)

        return AssessmentResource::collection($q->paginate(20));
    }

    public function store(StoreAssessmentRequest $req)
    {
        $tid = app('tenant.id');
        $data = $req->validated();

        // OLD: validating a module_id on Assessment (no longer exists)
        // Module::where('tenant_id',$tid)->findOrFail($data['module_id']);

        // Create assessment only; modules will be created separately
        $a = Assessment::create($data + ['tenant_id' => $tid]);

        // ensure modules_count is available in resource if you use it
        $a->loadCount('modules');

        return new AssessmentResource($a);
    }

    public function show($id)
    {
        $tid = app('tenant.id');

        $a = Assessment::where('tenant_id', $tid)
            ->with([
                // modules + questions + options for the engine
                'modules.questions.options',
                // direct “through” questions collection for engine convenience
                'questions.options',
                // rubric
                'rubric.criteria',
            ])
            ->findOrFail($id);

        return new AssessmentResource($a);
    }

    public function update(UpdateAssessmentRequest $req, $id)
    {
        $tid = app('tenant.id');

        $a = Assessment::where('tenant_id', $tid)->findOrFail($id);
        $a->update($req->validated());
        $a->loadCount('modules');

        return new AssessmentResource($a);
    }

    public function destroy($id)
    {
        $tid = app('tenant.id');

        $a = Assessment::where('tenant_id', $tid)->findOrFail($id);
        $a->delete();

        return response()->json(['message' => 'deleted']);
    }
}
