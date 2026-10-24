<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        'views_count',
        'likes_count',
        'comments_count',
    ];

    protected function casts(): array
    {
        return [
            'episode_number' => 'integer',
            'sort_order' => 'integer',
            'video_size_bytes' => 'integer',
            'is_active' => 'boolean',
            'views_count' => 'integer',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
        ];
    }

    public function getThumbnailDisplayUrlAttribute(): ?string
    {
        if (! $this->thumbnail_url) {
            return null;
        }

        if (str_starts_with($this->thumbnail_url, 'http://')
            || str_starts_with($this->thumbnail_url, 'https://')) {
            return $this->thumbnail_url;
        }

        return Storage::disk('public')->url($this->thumbnail_url);
    }
}
