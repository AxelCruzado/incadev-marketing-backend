<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = [
        'proposal_id',
        'course_version_id',
        'name',
        'objective',
        'start_date',
        'end_date'
    ];

    /**
     * Relación con Proposal.
     * Una campaña pertenece a una propuesta.
     */
    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * Relación con CourseVersion (si existe un modelo CourseVersion).
     * Una campaña pertenece a una versión de curso.
     */
    public function courseVersion()
    {
        return $this->belongsTo(CourseVersion::class);
    }

    public function posts()
{
    return $this->hasMany(Post::class);
}
}
