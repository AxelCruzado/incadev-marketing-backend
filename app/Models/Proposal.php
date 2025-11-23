<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    protected $fillable = [
        'title', 
        'description', 
        'area', 
        'priority', 
        'status', 
        'target_audience', 
        'created_by'
    ];

    // Relación con el usuario que creó la propuesta
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}


