<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'name',
        'description',
        'image_path'
    ];

    public function versions()
    {
        return $this->hasMany(CourseVersion::class);
    }
}
