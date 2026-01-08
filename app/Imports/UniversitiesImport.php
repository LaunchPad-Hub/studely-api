<?php

namespace App\Imports;

use App\Models\University;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class UniversitiesImport implements ToModel, WithHeadingRow, WithBatchInserts, WithChunkReading
{
    private $existingCodes;

    public function __construct()
    {
        // Load existing codes as keys for O(1) lookup.
        // pluck('code', 'code') creates an array like ['UNIV001' => 'UNIV001']
        $this->existingCodes = University::pluck('code', 'code')->toArray();
    }

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        // 1. Sanitize input
        $code = isset($row['code']) ? trim($row['code']) : null;
        $name = isset($row['name']) ? trim($row['name']) : null;

        // 2. Skip Empty Rows
        // If critical fields are missing, we consider the row empty/invalid
        if (empty($code) || empty($name)) {
            return null;
        }

        // 3. Duplicate Check
        // If code exists in our pre-loaded list, skip to prevent unique constraint error
        if (isset($this->existingCodes[$code])) {
            return null;
        }

        // 4. Handle non-numeric years (e.g. "-" or empty)
        $year = $row['founded'] ?? null;
        if (!is_numeric($year)) {
            $year = null;
        }

        // 5. Create Record
        return new University([
            'tenant_id'        => Auth::user()?->tenant_id,
            'code'             => $code,
            'name'             => $name,
            'state'            => $row['state'] ?? null,
            'district'         => $row['district'] ?? null,
            'location'         => $row['location'] ?? null,
            'website'          => $row['website'] ?? null,
            'established_year' => $year,
        ]);
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function chunkSize(): int
    {
        return 500;
    }
}
