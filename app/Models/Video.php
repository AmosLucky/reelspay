<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $fillable = [
        'source_url',
        'title',
        'author',
        'thumbnail_url',
        'video_url',
        'video_path',
        'video_size_bytes',
        'collection_id',
        'collection_title',
        'episode_number',
        'sort_order',
        'is_active',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'episode_number' => 'integer',
            'sort_order' => 'integer',
            'video_size_bytes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
