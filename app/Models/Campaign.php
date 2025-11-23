<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = ['name', 'description', 'course_id'];

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

