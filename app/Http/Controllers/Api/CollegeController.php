<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Colleges\StoreCollegeRequest;
use App\Http\Requests\Colleges\UpdateCollegeRequest;
use App\Http\Resources\CollegeResource;
use App\Imports\CollegesImport;
use App\Models\College;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelType;

class CollegeController extends Controller
{
    public function index(Request $request)
    {
        $tid = Auth::user()?->tenant_id;

        // This allows your frontend 'list({ per_page: 1000 })' to work.
        $perPage = $request->input('per_page', 100);

        $paginated = College::where('tenant_id', $tid)->latest()->paginate($perPage);

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

    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:102400',
            'batch' => 'nullable|integer|min:1', // Validate batch
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation Error', 'errors' => $validator->errors()], 422);
        }

        try {
            $file = $request->file('file');
            $batch = $request->input('batch', 1); // Default to 1

            // Store file
            $filePath = $file->store('imports');

            // Determine type
            $extension = strtolower($file->getClientOriginalExtension());
            $readerType = in_array($extension, ['csv', 'txt']) ? ExcelType::CSV : ExcelType::XLSX;

            // Pass the batch number to the Import class
            // Excel::queue(new CollegesImport($batch), $filePath, null, $readerType);

            Excel::import(new CollegesImport($batch), $filePath, null, $readerType);

            return response()->json([
                'message' => "Batch $batch started (Rows " . (($batch - 1) * 15000 + 1) . " to " . ($batch * 15000) . ")."
            ]);
        } catch (\Exception $e) {
            Log::error('College Import failed: ' . $e->getMessage());
            return response()->json(['message' => 'Import failed. ' . $e->getMessage()], 422);
        }
    }
}
