<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'campaign_id',
        'title',
        'platform',
        'content',
        'content_type',
        'image_path',
        'link_url',
        'status',
        'scheduled_at',
        'published_at',
        'created_by'
    ];

    // Cada post pertenece a una campaña
    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    // Cada post tiene múltiples métricas (historial)
    public function metrics()
    {
        return $this->hasMany(Metric::class);
    }
}