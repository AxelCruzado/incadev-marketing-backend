<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    protected $fillable = [
        'post_id',          // Métrica asociada al post
        'platform',         // 'facebook' o 'instagram'
        'meta_post_id',
        'views',
        'likes',
        'comments',
        'shares',
        'engagement',
        'reach',
        'impressions',
        'saves',            // Solo Instagram
        'metric_date',
        'metric_type',      // 'daily', 'weekly', 'monthly', 'cumulative'
    ];

    protected $casts = [
        'metric_date' => 'date',
    ];

    /**
     * Relación con el Post.
     * La campaña se obtiene automáticamente desde el Post, por lo que NO es necesario un campaign_id.
     */
    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Scope: métricas de un post específico.
     */
    public function scopePostMetrics($query)
    {
        return $query->whereNotNull('post_id');
    }

    /**
     * Scope: métricas por plataforma.
     */
    public function scopePlatform($query, $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope: métricas por tipo.
     */
    public function scopeMetricType($query, $type)
    {
        return $query->where('metric_type', $type);
    }

    /**
     * Cálculo automático del engagement
     */
    public function calculateEngagement()
    {
        return
            ($this->likes ?? 0) +
            ($this->comments ?? 0) +
            ($this->shares ?? 0) +
            ($this->saves ?? 0);
    }

    /**
     * Evento para guardar engagement automáticamente.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($metric) {
            // Si no viene engagement, lo calcula
            if (is_null($metric->engagement)) {
                $metric->engagement = $metric->calculateEngagement();
            }
        });
    }
}
