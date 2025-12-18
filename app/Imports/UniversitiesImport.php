<?php

namespace App\Imports;

use App\Models\University;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
class UniversitiesImport implements ToModel
{
    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */public function model(array $row)
    {
        // $row keys match your Excel header names (converted to snake_case)
        return new University([
            'tenant_id'             => Auth::user()?->tenant_id, // Set tenant_id as needed
            'code'                  => $row['code'],            // Excel header: "Code"
            'name'                  => $row['name'],            // Excel header: "Name"
            'state'                 => $row['state'],           // Excel header: "State"
            'district'              => $row['district'],        // Excel header: "District"
            'location'              => $row['location'],        // Excel header: "Location"
            'website'               => $row['website'],         // Excel header: "Website"
            'established_year'      => $row['founded'],         // Excel header: "Founded"
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
