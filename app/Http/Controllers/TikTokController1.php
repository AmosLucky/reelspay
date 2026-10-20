<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokController1 extends Controller
{
    public function download(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $validated['url'];

        // Allow only TikTok domains.
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (
            !in_array($host, [
                'tiktok.com',
                'www.tiktok.com',
                'm.tiktok.com',
                'vm.tiktok.com',
                'vt.tiktok.com',
            ], true)
        ) {
            return response()->json([
                'success' => false,
                'error' => 'Please enter a valid TikTok URL.',
            ], 422);
        }

        try {
            // Send the TikTok URL directly to TikWM.
            $response = Http::asForm()
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                ])
                ->timeout(30)
                ->post('https://www.tikwm.com/api/', [
                    'url' => $url,
                    'hd' => 1,
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'The downloader API returned an HTTP error.',
                    'api_status' => $response->status(),
                ], 502);
            }

            $result = $response->json();

            if (
                !is_array($result) ||
                ($result['code'] ?? null) != 0 ||
                !is_array($result['data'] ?? null)
            ) {
                return response()->json([
                    'success' => false,
                    'error' => 'The API could not extract this video.',
                    'api_message' => is_array($result)
                        ? ($result['msg'] ?? 'Unknown API error')
                        : 'Invalid API response',
                ], 502);
            }

            $data = $result['data'];
            $videoUrl = $data['play'] ?? null;

            if (
                !is_string($videoUrl) ||
                !filter_var($videoUrl, FILTER_VALIDATE_URL) ||
                strtolower(parse_url($videoUrl, PHP_URL_SCHEME) ?? '') !== 'https'
            ) {
                return response()->json([
                    'success' => false,
                    'error' => 'The API did not return a valid video URL.',
                ], 502);
            }

            return response()->json([
                'success' => true,
                'title' => $data['title'] ?? null,
                'thumbnail' => $data['cover'] ?? null,
                'author' => $data['author']['unique_id'] ?? null,
                'download_url' => $videoUrl,
            ]);

        } catch (\Throwable $e) {
            Log::error('TikWM API request failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Could not connect to the downloader API. Check Laravel logs.',
            ], 502);
        }
    }
}
