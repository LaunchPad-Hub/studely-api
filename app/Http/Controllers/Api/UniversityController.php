<?php

namespace App\Http\ControllersApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Universities\StoreUniversityRequest;
use App\Http\Requests\Universities\UpdateUniversityRequest;
use App\Http\Resources\UniversityResource;
use App\Models\University;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UniversityController extends Controller
{

    public function index(Request $request)
    {
        $tid = Auth::user()?->tenant_id;
        $paginated = University::where('tenant_id', $tid)->latest()->paginate(20);

        return UniversityResource::collection($paginated);
    }

    public function list(Request $request)
    {
        $paginated = University::latest()->paginate(20);

        return UniversityResource::collection($paginated);
    }

    public function store(StoreUniversityRequest $request)
    {
        $tid = Auth::user()?->tenant_id;
        $data = $request->validated();

        $college = University::create([
            'tenant_id'        => $tid,
            'name'             => $data['name'],
            'state'            => $data['state'] ?? null,
            'district'         => $data['district'] ?? null,
            'code'             => $data['code'] ?? null,
            'location'         => $data['location'] ?? null,
            'website'          => $data['website'] ?? null,
            'established_year' => $data['established_year'] ?? null,
        ]);

        return new UniversityResource($college);
    }

    public function show($id)
    {
        $tid = Auth::user()?->tenant_id;
        $college = University::where('tenant_id', $tid)->findOrFail($id);

        return new UniversityResource($college);
    }

    public function update(UpdateUniversityRequest $request, $id)
    {
        $tid = Auth::user()?->tenant_id;
        $college = University::where('tenant_id', $tid)->findOrFail($id);
        $college->update($request->validated());

        return new UniversityResource($college);
    }

    public function destroy($id)
    {
        $tid = Auth::user()?->tenant_id;
        $college = University::where('tenant_id', $tid)->findOrFail($id);
        $college->delete();

        return response()->json(['message' => 'deleted']);
    }
}
