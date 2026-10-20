<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirestoreService
{
    private string $projectId = 'data-miner-game';

    private function getAccessToken(): string
    {
        $path = config('firebase.credentials');

        if (is_array($path)) {
            $path = $path['file'] ?? null;
        }

        if (!$path) {
            $path = env('FIREBASE_CREDENTIALS');
        }

        if (!$path) {
            throw new RuntimeException(
                'Firebase credentials path is not configured.'
            );
        }

        // Support absolute paths and paths relative to the Laravel project.
        $fullPath = str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path)
                ? $path
                : base_path($path);

        if (!is_file($fullPath)) {
            throw new RuntimeException(
                'Firebase service-account file was not found.'
            );
        }

        $serviceAccount = json_decode(
            file_get_contents($fullPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $credentials = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/datastore'],
            $serviceAccount
        );

        $token = $credentials->fetchAuthToken();

        if (empty($token['access_token'])) {
            throw new RuntimeException(
                'Could not obtain a Google access token.'
            );
        }

        return $token['access_token'];
    }

    public function createDocument(
        string $collection,
        array $fields,
        ?string $documentId = null
    ): array {
        $collection = trim($collection, '/');

        if ($collection === '') {
            throw new RuntimeException(
                'A Firestore collection name is required.'
            );
        }

        $url = "https://firestore.googleapis.com/v1/projects/"
            . $this->projectId
            . "/databases/(default)/documents/"
            . $collection;

        if ($documentId !== null) {
            $url .= '?documentId=' . urlencode($documentId);
        }

        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->post($url, ['fields' => $this->encodeFields($fields)]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'Firestore create failed (HTTP '
                . $response->status()
                . '): '
                . $response->json('error.message', 'Unknown API error')
            );
        }

        return $response->json();
    }

    private function encodeFields(array $fields): array
    {
        $encoded = [];

        foreach ($fields as $name => $value) {
            if (is_string($value)) {
                $encoded[$name] = ['stringValue' => $value];
            } elseif (is_int($value)) {
                $encoded[$name] = ['integerValue' => (string) $value];
            } elseif (is_float($value)) {
                $encoded[$name] = ['doubleValue' => $value];
            } elseif (is_bool($value)) {
                $encoded[$name] = ['booleanValue' => $value];
            } elseif ($value === null) {
                $encoded[$name] = ['nullValue' => null];
            } elseif (is_array($value)) {
                $encoded[$name] = [
                    'arrayValue' => [
                        'values' => array_map(
                            fn ($item) => $this->encodeFields(
                                ['value' => $item]
                            )['value'],
                            $value
                        ),
                    ],
                ];
            }
        }

        return $encoded;
    }
}
