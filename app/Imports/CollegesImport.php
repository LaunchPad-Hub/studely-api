<?php

namespace App\Imports;

use App\Models\College;
use App\Models\University;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CollegesImport implements ToModel, WithHeadingRow, WithBatchInserts, WithChunkReading
{
    // Cache universities in memory to avoid 1000 queries
    private $universities;

    public function __construct()
    {
        // Fetch all University Codes and IDs for the current tenant (or global)
        // Adjust filtering based on your multi-tenancy needs
        $this->universities = University::pluck('id', 'code');
    }

    public function model(array $row)
    {
        // 1. Get the University Code from Excel
        $uniCode = isset($row['university_code']) ? trim($row['university_code']) : null;

        // 2. Lookup ID. If not found, skip this college (or handle error)
        if (!$uniCode || !isset($this->universities[$uniCode])) {
            return null;
        }

        $uniId = $this->universities[$uniCode];

        return new College([
            'tenant_id'     => Auth::user()?->tenant_id,
            'university_id' => $uniId,
            'name'          => $row['name'],
            'code'          => $row['code'] ?? null,
            'state'         => $row['state'] ?? null,
            'district'      => $row['district'] ?? null,
            'location'      => $row['location'] ?? null,
            'description'   => $row['description'] ?? null,
        ]);
    }

    public function batchSize(): int { return 500; }
    public function chunkSize(): int { return 500; }

}
