<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id','type','title','instructions','total_marks','is_active', 'open_at','close_at'
    ];
    protected $casts = [
        'open_at'=>'datetime',
        'close_at'=>'datetime'
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function modules()
    {
        return $this->hasMany(Module::class);
    }

    public function rubric()
    {
        return $this->hasOne(Rubric::class);
    }

    public function evaluators()
    {
        return $this->belongsToMany(Evaluator::class, 'assessment_evaluators');
    }

    public function attempts()
    {
        return $this->hasMany(Attempt::class);
    }

    // All questions through modules (for engine + show)
    public function questions()
    {
        return $this->hasManyThrough(Question::class, Module::class);
    }

}
