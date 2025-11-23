<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    protected $fillable = ['post_id', 'views', 'likes', 'comments', 'shares'];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
