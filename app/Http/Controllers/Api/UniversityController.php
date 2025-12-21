<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Universities\StoreUniversityRequest;
use App\Http\Requests\Universities\UpdateUniversityRequest;
use App\Http\Resources\UniversityResource;
use App\Imports\UniversitiesImport;
use App\Models\University;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class UniversityController extends Controller
{

    public function index(Request $request)
    {
        $tid = Auth::user()?->tenant_id;

        // Get per_page from request, default to 20 if missing.
        // This allows your frontend 'list({ per_page: 1000 })' to work.
        $perPage = $request->input('per_page', 100);

        $paginated = University::where('tenant_id', $tid)
            ->latest()
            ->paginate($perPage);

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

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // Max 10MB
        ]);

        $tid = Auth::user()?->tenant_id;

        // We pass the tenant_id to the Import class if needed,
        // or ensure the Import class handles authentication context.
        try {
            Excel::import(new UniversitiesImport(), $request->file('file'));
            return response()->json(['message' => 'Universities imported successfully.']);
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Import failed: ' . $e->getMessage());
            return response()->json(['message' => 'Import failed. Check your file format.'], 422);
        }
    }
}
