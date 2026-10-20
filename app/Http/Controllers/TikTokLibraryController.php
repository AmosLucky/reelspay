<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TikTokLibraryController extends Controller
{
    public function index()
    {
        return view('tiktok-library');
    }

    public function import(Request $request)
    {
        $validated = $request->validate([
            'urls' => ['required', 'string', 'max:50000'],
            'collection_title' => ['nullable', 'string', 'max:200'],
        ]);

        // Accept URLs on separate lines or separated by commas.
        $urls = preg_split('/[\r\n,]+/', $validated['urls']);

        $urls = array_values(array_unique(array_filter(
            array_map('trim', $urls),
            fn ($url) => $url !== ''
        )));

        if (count($urls) === 0) {
            throw ValidationException::withMessages([
                'urls' => 'Please enter at least one TikTok URL.',
            ]);
        }

        if (count($urls) > 100) {
            throw ValidationException::withMessages([
                'urls' => 'Please import no more than 100 links at once.',
            ]);
        }

        $collectionTitle = trim(
            $validated['collection_title'] ?? ''
        );

        $collectionId = $collectionTitle !== ''
            ? Str::slug($collectionTitle)
            : null;

        $sortOrder = (int) Video::max('sort_order');

        $episodeOrder = $collectionId
            ? (int) Video::where(
                'collection_id',
                $collectionId
            )->max('episode_number')
            : 0;

        $results = [];

        foreach ($urls as $url) {
            if (!$this->isTikTokUrl($url)) {
                $results[] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'This is not a valid TikTok URL.',
                ];

                continue;
            }

            // Avoid downloading a URL that is already in the library.
            if (Video::where('source_url', $url)->exists()) {
                $results[] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => 'This URL is already in your library.',
                ];

                continue;
            }

            $savedPath = null;

            try {
                $video = $this->extractVideo($url);

                // Each downloaded file gets its own unique filename.
                $filename = 'video_' . Str::uuid() . '.mp4';

                $download = $this->downloadVideo(
                    $video['download_url'],
                    $filename
                );

                $savedPath = $download['path'];

                $nextSortOrder = $sortOrder + 1;
                $nextEpisodeOrder = $episodeOrder + 1;

                $fields = [
                    'source_url' => $url,
                    'title' => Str::limit($video['title'], 500, ''),
                    'author' => $video['author'],
                    'thumbnail_url' => $video['thumbnail'],

                    // Don't rely on TikWM's temporary URL.
                    'video_url' => null,

                    // This relative path points to the local MP4 file.
                    'video_path' => $download['path'],
                    'video_size_bytes' => $download['size'],

                    'provider' => 'tikwm',
                    'sort_order' => $nextSortOrder,
                    'is_active' => true,
                ];

                if ($collectionId !== null) {
                    $fields['collection_id'] = $collectionId;
                    $fields['collection_title'] = $collectionTitle;
                    $fields['episode_number'] = $nextEpisodeOrder;
                }

                Video::create($fields);

                $sortOrder = $nextSortOrder;

                if ($collectionId !== null) {
                    $episodeOrder = $nextEpisodeOrder;
                }

                $results[] = [
                    'url' => $url,
                    'status' => 'success',
                    'title' => $video['title'] ?: 'Untitled video',
                    'message' => 'Video downloaded and saved locally.',
                ];
            } catch (\Throwable $e) {
                // Remove the file if the database save failed.
                if (
                    $savedPath !== null
                    && Storage::disk('public')->exists($savedPath)
                ) {
                    Storage::disk('public')->delete($savedPath);
                }

                report($e);

                $results[] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'Could not download or save this video. Check storage/logs/laravel.log.',
                ];
            }
        }

        return redirect()
            ->route('tiktok-library.index')
            ->with('import_results', $results);
    }

    private function isTikTokUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower(
            (string) parse_url($url, PHP_URL_SCHEME)
        );

        return is_string($host)
            && preg_match('/(^|\.)tiktok\.com$/i', $host) === 1
            && in_array($scheme, ['http', 'https'], true);
    }

    
private function extractVideo(string $url): array
{
    $response = Http::asForm()
        ->timeout(30)
        ->post('https://www.tikwm.com/api/', [
            'url' => $url,
            'hd' => 1,
        ]);

    if (!$response->successful()) {
        throw new RuntimeException(
            'TikWM returned HTTP ' . $response->status()
        );
    }

    $json = $response->json();

    // TikWM uses code = 0 and msg = success.
    if (
        !is_array($json) ||
        (int) ($json['code'] ?? -1) !== 0 ||
        strtolower((string) ($json['msg'] ?? '')) !== 'success'
    ) {
        throw new RuntimeException(
            'TikWM extraction failed: ' .
            (string) ($json['msg'] ?? 'Unknown API error')
        );
    }

    $data = $json['data'] ?? [];

    if (!is_array($data)) {
        throw new RuntimeException(
            'TikWM returned invalid video information.'
        );
    }

    // Prefer the HD link, then fall back to other available links.
    $downloadUrl = $data['play']
    ?? $data['hdplay']
    ?? $data['download_url']
    ?? null;

    if (!is_string($downloadUrl) || $downloadUrl === '') {
        throw new RuntimeException(
            'TikWM did not provide a video download URL.'
        );
    }

    $author = $data['author'] ?? '';

    if (is_array($author)) {
        $author = $author['unique_id']
            ?? $author['nickname']
            ?? '';
    }

    if (!is_string($author)) {
        $author = '';
    }

    $thumbnail = $data['cover']
        ?? $data['origin_cover']
        ?? '';

    return [
        'title' => is_string($data['title'] ?? null)
            ? $data['title']
            : '',
        'author' => $author,
        'thumbnail' => is_string($thumbnail)
            ? $thumbnail
            : '',
        'download_url' => $downloadUrl,
    ];
}


    private function extractVideo2(string $url): array
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post('https://www.tikwm.com/api/', [
                'url' => $url,
                'hd' => 1,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'TikWM returned HTTP ' . $response->status()
            );
        }

        $json = $response->json();

        if (!is_array($json) || !($json['success'] ?? false)) {
            throw new RuntimeException(
                'TikWM could not extract this URL.'
            );
        }

        $data = $json['data'] ?? [];

        if (!is_array($data)) {
            throw new RuntimeException(
                'TikWM returned invalid video information.'
            );
        }

        $downloadUrl = $data['download_url']
            ?? $data['play']
            ?? null;

        if (!is_string($downloadUrl) || $downloadUrl === '') {
            throw new RuntimeException(
                'TikWM did not provide a download URL.'
            );
        }

        $author = $data['author'] ?? '';

        if (is_array($author)) {
            $author = $author['unique_id'] ?? '';
        }

        if (!is_string($author)) {
            $author = '';
        }

        $thumbnail = $data['cover']
            ?? $data['origin_cover']
            ?? $data['thumbnail']
            ?? '';

        return [
            'title' => is_string($data['title'] ?? null)
                ? $data['title']
                : '',
            'author' => $author,
            'thumbnail' => is_string($thumbnail)
                ? $thumbnail
                : '',
            'download_url' => $downloadUrl,
        ];
    }

    private function downloadVideo(
        string $downloadUrl,
        string $filename
    ): array {
        // Only allow HTTPS download links.
        $parts = parse_url($downloadUrl);

        if (
            !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
        ) {
            throw new RuntimeException(
                'The returned download URL is invalid.'
            );
        }

        // Download to a temporary file first.
        $tempPath = tempnam(storage_path('app'), 'tiktok_');

        if ($tempPath === false) {
            throw new RuntimeException(
                'Could not create a temporary download file.'
            );
        }

        try {
            // Stream to disk instead of loading the entire video into RAM.
            $response = Http::withOptions([
                'sink' => $tempPath,
            ])
                ->timeout(120)
                ->get($downloadUrl);

            if (!$response->successful()) {
                throw new RuntimeException(
                    'Video download returned HTTP '
                    . $response->status()
                );
            }

            $contentType = strtolower(
                (string) $response->header('Content-Type')
            );

            if (
                str_contains($contentType, 'text/html')
                || str_contains($contentType, 'application/json')
            ) {
                throw new RuntimeException(
                    'The download URL returned a webpage, not a video.'
                );
            }

            $size = filesize($tempPath);

            if ($size === false || $size < 1024) {
                throw new RuntimeException(
                    'The downloaded file is empty or too small.'
                );
            }

            // Keep individual downloads within a reasonable limit.
            $maxBytes = 300 * 1024 * 1024;

            if ($size > $maxBytes) {
                throw new RuntimeException(
                    'The video exceeds the 300 MB download limit.'
                );
            }

            $relativePath = 'videos/' . $filename;

            $disk = Storage::disk('public');
            $disk->makeDirectory('videos');

            $destination = $disk->path($relativePath);

            if (!rename($tempPath, $destination)) {
                throw new RuntimeException(
                    'Could not move the video into local storage.'
                );
            }

            return [
                'path' => $relativePath,
                'size' => $size,
            ];
        } finally {
            // Remove temporary files left behind after errors.
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }


    
public function apiVideos(Request $request)
{
    $videos = Video::query()
        ->where('is_active', true)
        ->when(
            $request->filled('collection_id'),
            fn ($query) => $query->where(
                'collection_id',
                $request->query('collection_id')
            )
        )
        ->orderBy('sort_order')
        ->orderBy('id')
        ->paginate(20);

    return $videos->through(function (Video $video) {
        return [
            'id' => $video->id,
            'title' => $video->title,
            'author' => $video->author,
            'thumbnail_url' => $video->thumbnail_url,
            'video_url' => $video->video_path
                ? url(Storage::disk('public')->url($video->video_path))
                : null,
            'collection_id' => $video->collection_id,
            'collection_title' => $video->collection_title,
            'episode_number' => $video->episode_number,
        ];
    });
}

}
