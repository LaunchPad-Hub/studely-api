<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Colleges\StoreCollegeRequest;
use App\Http\Requests\Colleges\UpdateCollegeRequest;
use App\Http\Resources\CollegeResource;
use App\Models\College;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CollegeController extends Controller
{
    public function index(Request $request)
    {
        $tid = Auth::user()?->tenant_id;
        $paginated = College::where('tenant_id', $tid)->latest()->paginate(20);

        return CollegeResource::collection($paginated);
    }

    public function list(Request $request)
    {
        $paginated = College::latest()->paginate(20);

        return CollegeResource::collection($paginated);
    }

    public function store(StoreCollegeRequest $request)
    {
        $tid = Auth::user()?->tenant_id;
        $data = $request->validated();

        $college = College::create([
            'tenant_id'   => $tid,
            'university_id' => $data['university_id'],
            'name'        => $data['name'],
            'code'        => $data['code'] ?? null,
            'state'       => $data['state'] ?? null,
            'district'    => $data['district'] ?? null,
            'location'    => $data['location'] ?? null,
            'description' => $data['description'] ?? null,
            'meta'        => $data['meta'] ?? null,
        ]);

        return new CollegeResource($college);
    }

    public function show($id)
    {
        $tid = Auth::user()?->tenant_id;
        $college = College::where('tenant_id', $tid)->findOrFail($id);

        return new CollegeResource($college);
    }

    public function update(UpdateCollegeRequest $request, $id)
    {
        $tid = Auth::user()?->tenant_id;
        $college = College::where('tenant_id', $tid)->findOrFail($id);
        $college->update($request->validated());

        return new CollegeResource($college);
    }

    public function destroy($id)
    {
        $tid = Auth::user()?->tenant_id;
        $college = College::where('tenant_id', $tid)->findOrFail($id);
        $college->delete();

        return response()->json(['message' => 'deleted']);
    }
}
