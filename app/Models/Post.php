<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'campaign_id', 'title', 'platform', 'content', 'image_url'
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function metrics()
    {
        return $this->hasMany(Metric::class);
    }
}
