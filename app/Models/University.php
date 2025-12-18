<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class University extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'state', 'district', 'code', 'location', 'website', 'established_year'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function colleges()
    {
        return $this->hasMany(College::class);
    }

}
