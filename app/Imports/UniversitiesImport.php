<?php

namespace App\Imports;

use App\Models\University;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
class UniversitiesImport implements ToModel, WithHeadingRow
{
    private $existingCodes;

    public function __construct()
    {
        // OPTIMIZATION: Load all existing codes into an array key-map for instant lookup.
        // This prevents creating duplicates and avoids querying the DB for every single row.
        $this->existingCodes = University::pluck('code')->toArray();
    }

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */public function model(array $row)
    {
        // Sanitize Code
        $code = isset($row['code']) ? trim($row['code']) : null;

        // Duplicate Check
        // If this code already exists in our DB list, SKIP this row (return null).
        if ($code && isset($this->existingCodes[$code])) {
            return null;
        }

        // Handle non-numeric years like "-"
        $year = $row['founded'];
        if (!is_numeric($year)) {
            $year = null;
        }

        // $row keys match your Excel header names (converted to snake_case)
        return new University([
            'tenant_id'             => Auth::user()?->tenant_id, // Set tenant_id as needed
            'code'                  => $row['code'],            // Excel header: "Code"
            'name'                  => $row['name'],            // Excel header: "Name"
            'state'                 => $row['state'],           // Excel header: "State"
            'district'              => $row['district'],        // Excel header: "District"
            'location'              => $row['location'],        // Excel header: "Location"
            'website'               => $row['website'],         // Excel header: "Website"
            'established_year'      => $year,         // Excel header: "Founded"
        ]);
    }

    // Processing 1000 records at once is fast, but let's batch them to be safe
    public function batchSize(): int
    {
        return 500;
    }

    // Read the file in chunks to keep memory usage low
    public function chunkSize(): int
    {
        return 500;
    }
}
