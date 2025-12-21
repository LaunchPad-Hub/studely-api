<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id','assessment_id','student_id','started_at','submitted_at','duration_sec','score', 'status', 'total_marks'
    ];
    protected $casts = [
        'started_at'=>'datetime',
        'submitted_at'=>'datetime',
        'score'=>'decimal:2',
        'meta' => 'array',
    ];

    public function assessment(){
         return $this->belongsTo(Assessment::class);
    }

    public function student(){
         return $this->belongsTo(Student::class);
    }

    public function responses(){
         return $this->hasMany(Response::class);
    }

}
