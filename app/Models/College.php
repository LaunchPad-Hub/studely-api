<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class College extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'university_id', 'state', 'district', 'management', 'location', 'description'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function university(){
         return $this->belongsTo(University::class);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }
}
