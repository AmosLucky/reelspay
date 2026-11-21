<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class DailyStatisticsController extends Controller
{
    private string $projectId;
    private string $accessToken;
    private string $timezone;

    public function index()
    {
        $timezone = config('app.timezone', 'UTC');

        $today = Carbon::now($timezone)->startOfDay();
        $yesterday = $today->copy()->subDay();
        $dayBeforeYesterday = $today->copy()->subDays(2);

        $dailyDocuments = [];
        $registeredDocuments = [];

        $dailyStatistics = [
            'today' => 0,
            'yesterday' => 0,
            'day_before_yesterday' => 0,
            'all_time' => 0,
            'today_documents' => 0,
        ];

        $registeredStatistics = [
            'today' => 0,
            'yesterday' => 0,
            'all_time' => 0,
        ];

        $registeredTodayDocuments = [];
        $error = null;

        try {
            $this->timezone = $timezone;
            $this->connectToFirebase();

            /*
             * DAILY APP-OPEN STATISTICS
             * Firestore:
             * reelspay / statistics / daily
             */
            $dailyDocuments = $this->getCollectionDocuments(
                'reelspay/statistics/daily'
            );

            $dailyUsers = [
                'today' => [],
                'yesterday' => [],
                'day_before_yesterday' => [],
                'all_time' => [],
            ];

            foreach ($dailyDocuments as $document) {
                $data = $document['data'];

                $date = $this->getFirestoreDate(
                    $data['collectionDate'] ?? null
                );

                if (!$date) {
                    continue;
                }

                $userId = (string) (
                    $data['userId'] ?? $document['documentId']
                );

                if ($userId === '') {
                    continue;
                }

                // All-time unique users who have app-open records.
                $dailyUsers['all_time'][$userId] = true;

                if ($date->isSameDay($today)) {
                    $dailyUsers['today'][$userId] = true;
                    $dailyStatistics['today_documents']++;

                    $dailyDocumentsForToday[] = [
                        'userId' => $userId,
                        'country' => $data['country'] ?? 'N/A',
                        'currency' => $data['currency'] ?? '',
                        'balance' => $data['currentBalance']
                            ?? $data['balance']
                            ?? 0,
                        'formattedDate' => $date->format('M d, Y h:i A'),
                        'timestamp' => $date->timestamp,
                    ];
                } elseif ($date->isSameDay($yesterday)) {
                    $dailyUsers['yesterday'][$userId] = true;
                } elseif ($date->isSameDay($dayBeforeYesterday)) {
                    $dailyUsers['day_before_yesterday'][$userId] = true;
                }
            }

            $dailyStatistics['today'] = count($dailyUsers['today']);
            $dailyStatistics['yesterday'] = count($dailyUsers['yesterday']);
            $dailyStatistics['day_before_yesterday'] =
                count($dailyUsers['day_before_yesterday']);
            $dailyStatistics['all_time'] = count($dailyUsers['all_time']);

            $dailyDocumentsForToday = $dailyDocumentsForToday ?? [];

            usort(
                $dailyDocumentsForToday,
                fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']
            );

            /*
             * REGISTERED USERS
             * Firestore:
             * reelspay / users / profiles
             */
            $profileDocuments = $this->getCollectionDocuments(
                'reelspay/users/profiles'
            );

            $registeredTodayDocuments = [];

            foreach ($profileDocuments as $document) {
                $data = $document['data'];

                // Each profile document counts as one registered user.
                $registeredStatistics['all_time']++;

                $registeredAt = $this->getFirestoreDate(
                    $data['registeredAt'] ?? null
                );

                if (!$registeredAt) {
                    continue;
                }

                if ($registeredAt->isSameDay($today)) {
                    $registeredStatistics['today']++;

                    $registeredTodayDocuments[] = [
                        'documentId' => $document['documentId'],
                        'userId' => $data['userId']
                            ?? $document['documentId'],
                        'country' => $data['country'] ?? 'N/A',
                        'currency' => $data['currency'] ?? '',
                        'balance' => $data['balance']
                            ?? $data['currentBalance']
                            ?? 0,
                        'deviceId' => $data['deviceId'] ?? '',
                        'referralCode' => $data['referralCode'] ?? '',
                        'formattedDate' => $registeredAt->format(
                            'M d, Y h:i A'
                        ),
                        'timestamp' => $registeredAt->timestamp,
                    ];
                } elseif ($registeredAt->isSameDay($yesterday)) {
                    $registeredStatistics['yesterday']++;
                }
            }

            usort(
                $registeredTodayDocuments,
                fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']
            );

        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('statistics.daily', [
            'today' => $today,
            'yesterday' => $yesterday,
            'dayBeforeYesterday' => $dayBeforeYesterday,
            'timezone' => $timezone,

            'dailyStatistics' => $dailyStatistics,
            'todayDocuments' => $dailyDocumentsForToday ?? [],

            'registeredStatistics' => $registeredStatistics,
            'registeredTodayDocuments' => $registeredTodayDocuments,

            'error' => $error,
        ]);
    }

    /**
     * Authenticate using the Firebase service-account JSON file.
     */
    private function connectToFirebase(): void
    {
        $credentialsPath = env(
            'FIREBASE_CREDENTIALS',
            'storage/app/firebase/service-account.json'
        );

        if (!str_starts_with($credentialsPath, DIRECTORY_SEPARATOR)
            && !preg_match('/^[A-Za-z]:\\\\/', $credentialsPath)) {
            $credentialsPath = base_path($credentialsPath);
        }

        if (!file_exists($credentialsPath)) {
            throw new \RuntimeException(
                'Firebase service account file not found: ' . $credentialsPath
            );
        }

        $serviceAccount = json_decode(
            file_get_contents($credentialsPath),
            true
        );

        if (!is_array($serviceAccount)) {
            throw new \RuntimeException(
                'Firebase service account JSON is invalid.'
            );
        }

        $this->projectId = $serviceAccount['project_id']
            ?? env('FIREBASE_PROJECT_ID');

        if (!$this->projectId) {
            throw new \RuntimeException(
                'Firebase project ID was not found.'
            );
        }

        $credentials = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/datastore'],
            $serviceAccount
        );

        $token = $credentials->fetchAuthToken();

        if (empty($token['access_token'])) {
            throw new \RuntimeException(
                'Could not obtain Firebase access token.'
            );
        }

        $this->accessToken = $token['access_token'];
    }

    /**
     * Retrieve every document in a Firestore collection, including pages.
     */
    private function getCollectionDocuments(string $collectionPath): array
    {
        $documents = [];
        $pageToken = null;

        do {
            $url = 'https://firestore.googleapis.com/v1/projects/'
                . $this->projectId
                . '/databases/(default)/documents/'
                . $collectionPath;

            $query = ['pageSize' => 1000];

            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $response = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout(60)
                ->get($url, $query);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    'Firestore request failed for '
                    . $collectionPath
                    . ': '
                    . $response->body()
                );
            }

            $result = $response->json();

            foreach ($result['documents'] ?? [] as $firestoreDocument) {
                $name = $firestoreDocument['name'] ?? '';
                $documentId = basename($name);

                $documents[] = [
                    'documentId' => $documentId,
                    'data' => $this->decodeFirestoreFields(
                        $firestoreDocument['fields'] ?? []
                    ),
                ];
            }

            $pageToken = $result['nextPageToken'] ?? null;

        } while ($pageToken);

        return $documents;
    }

    /**
     * Convert Firestore REST field values into normal PHP values.
     */
    private function decodeFirestoreFields(array $fields): array
    {
        $decoded = [];

        foreach ($fields as $key => $value) {
            $decoded[$key] = $this->decodeFirestoreValue($value);
        }

        return $decoded;
    }

    private function decodeFirestoreValue($value)
    {
        if (!is_array($value)) {
            return null;
        }

        if (array_key_exists('stringValue', $value)) {
            return $value['stringValue'];
        }

        if (array_key_exists('integerValue', $value)) {
            return (int) $value['integerValue'];
        }

        if (array_key_exists('doubleValue', $value)) {
            return (float) $value['doubleValue'];
        }

        if (array_key_exists('booleanValue', $value)) {
            return (bool) $value['booleanValue'];
        }

        if (array_key_exists('timestampValue', $value)) {
            return $value['timestampValue'];
        }

        if (array_key_exists('nullValue', $value)) {
            return null;
        }

        if (array_key_exists('mapValue', $value)) {
            return $this->decodeFirestoreFields(
                $value['mapValue']['fields'] ?? []
            );
        }

        if (array_key_exists('arrayValue', $value)) {
            $items = [];

            foreach ($value['arrayValue']['values'] ?? [] as $item) {
                $items[] = $this->decodeFirestoreValue($item);
            }

            return $items;
        }

        return null;
    }

    /**
     * Parse Firestore timestamp into the dashboard timezone.
     */
    private function getFirestoreDate($value): ?Carbon
    {
        if (!$value || !is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone($this->timezone);
        } catch (Throwable $e) {
            return null;
        }
    }
}
