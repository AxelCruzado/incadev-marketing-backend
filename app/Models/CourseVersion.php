<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseVersion extends Model
{
    protected $fillable = [
        'course_id',
        'version',
        'name',
        'price',
        'status'
    ];

    /**
     * Relación con Course.
     * Una versión pertenece a un curso.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Relación con Campaigns.
     * Una versión de curso puede tener varias campañas.
     */
    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }
}
