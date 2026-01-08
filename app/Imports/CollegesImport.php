<?php

namespace App\Imports;

use App\Models\College;
use App\Models\University;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithLimit;    // 1. Add Limit
use Maatwebsite\Excel\Concerns\WithStartRow; // 2. Add StartRow

class CollegesImport implements
    ToModel,
    WithHeadingRow,
    WithBatchInserts,
    WithChunkReading,
    WithCustomCsvSettings,
    ShouldQueue,
    WithLimit,    // Implement
    WithStartRow  // Implement
{
    private $universities;
    private $tenant_id;
    private $batch;

    // Constructor accepts the batch number (1, 2, 3...)
    public function __construct($batch = 1)
    {
        $this->tenant_id = Auth::user()?->tenant_id;
        $this->batch = max(1, intval($batch)); // Ensure at least 1

        // Force High Memory/Time Limits for this job
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', 3600);

        $this->universities = Cache::remember('uni_map_' . $this->tenant_id, 3600, function () {
            return University::pluck('id', 'code')->toArray();
        });
    }

    // Limit to 15,000 rows per run
    public function limit(): int
    {
        return 15000;
    }

    // Calculate where to start based on the batch number
    public function startRow(): int
    {
        // Batch 1: Start at row 2 (Row 1 is header)
        // Batch 2: Start at row 15002
        // Formula: (Batch - 1) * Limit + Start_Offset
        return ($this->batch - 1) * 15000 + 2;
    }

    public function model(array $row)
    {
        // 1. Sanitize & Empty Check
        $code = isset($row['code']) ? trim($row['code']) : null;
        $name = isset($row['name']) ? trim($row['name']) : null;

        // Skip row if essential data is missing
        if (empty($code) || empty($name)) {
            return null;
        }

        // 2. Duplicate Check
        // If this college code already exists, skip it.
        if (isset($this->existingColleges[$code])) {
            return null;
        }

        // 3. Validation: University Code
        $uniCode = isset($row['university_code']) ? trim($row['university_code']) : null;

        // Skip if university code is missing or doesn't exist in our DB
        if (!$uniCode || !isset($this->universities[$uniCode])) {
            // Optional: Log::warning("Skipping College {$code}: University code {$uniCode} not found.");
            return null;
        }

        // 4. Data Normalization
        $location = isset($row['location']) ? trim($row['location']) : 'Urban';
        if (in_array($location, ['-', '.', ''])) $location = 'Urban';

        if (strlen($name) > 250) $name = substr($name, 0, 250);

        return new College([
            'tenant_id'     => $this->tenant_id,
            'university_id' => $this->universities[$uniCode],
            'name'          => $name,
            'code'          => $code,
            'state'         => $row['state'] ?? null,
            'district'      => $row['district'] ?? null,
            'location'      => $location,
            'description'   => $row['description'] ?? null,
        ]);
    }

    public function batchSize(): int { return 1000; }
    public function chunkSize(): int { return 1000; }

    public function getCsvSettings(): array
    {
        return [
            'input_encoding' => 'UTF-8',
            'delimiter' => ',',
        ];
    }
}
