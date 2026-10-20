<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class VideoResolverController extends Controller
{
    public function test(Request $request)
    {
        $request->validate([
            'video_url' => ['required', 'url', 'starts_with:https://'],
        ]);

        $url = $request->input('video_url');
        $host = parse_url($url, PHP_URL_HOST);

        // Restrict this test to your own approved video host.
        $allowedHosts = config('services.video.allowed_hosts', []);

        if (!in_array($host, $allowedHosts, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Video host is not approved.',
            ], 422);
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Range' => 'bytes=0-1023'])
                ->get($url);

            $contentType = $response->header('Content-Type', '');

            return response()->json([
                'success' => $response->successful(),
                'status' => $response->status(),
                'content_type' => $contentType,
                'looks_like_video' => str_starts_with(
                    strtolower($contentType),
                    'video/'
                ),
                'message' => str_starts_with(
                    strtolower($contentType),
                    'video/'
                )
                    ? 'Video response detected.'
                    : 'This URL did not return a video content type.',
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not reach the video source.',
            ], 502);
        }
    }
}