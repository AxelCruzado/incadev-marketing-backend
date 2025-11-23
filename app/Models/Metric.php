<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    protected $fillable = [
        'post_id',
        'messages_received',
        'pre_registrations',
        'intention_percentage',
        'total_reach',
        'total_interactions',
        'ctr_percentage',
        'likes',
        'comments',
        'private_messages',
        'expected_enrollments',
        'cpa_cost'
    ];

    // Relación con post
    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
