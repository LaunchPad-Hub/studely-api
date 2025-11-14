<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Modules\StoreModuleRequest;
use App\Http\Requests\Modules\UpdateModuleRequest;
use App\Http\Resources\ModuleResource;
use App\Models\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function index(Request $r)
    {
        $tid = app('tenant.id');

        $q = Module::where('tenant_id', $tid)
            ->with('assessment') // so assessment_title works nicely
            ->latest();

        if ($r->filled('assessment_id')) {
            $q->where('assessment_id', $r->assessment_id);
        }

        return ModuleResource::collection($q->paginate(20));
    }

    public function store(StoreModuleRequest $req)
    {
        $tid = app('tenant.id');
        $module = Module::create($req->validated() + ['tenant_id' => $tid]);

        $module->load('assessment');

        return new ModuleResource($module);
    }

    public function show($id)
    {
        $tid = app('tenant.id');

        $m = Module::where('tenant_id', $tid)
            ->with(['assessment', 'questions.options'])
            ->findOrFail($id);

        return new ModuleResource($m);
    }

    public function update(UpdateModuleRequest $req, $id)
    {
        $tid = app('tenant.id');

        $m = Module::where('tenant_id', $tid)->findOrFail($id);
        $m->update($req->validated());
        $m->load('assessment');

        return new ModuleResource($m);
    }

    public function destroy($id)
    {
        $tid = app('tenant.id');

        $m = Module::where('tenant_id', $tid)->findOrFail($id);
        $m->delete();

        return response()->json(['message' => 'deleted']);
    }
}
