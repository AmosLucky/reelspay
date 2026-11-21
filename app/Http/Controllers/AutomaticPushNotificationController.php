<?php

namespace App\Http\Controllers;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

class AutomaticPushNotificationController extends Controller
{
    public function __construct(
        private Messaging $messaging
    ) {}

    /**
     * Get Firebase service account credentials.
     */
    private function getServiceAccount(): array
    {
        $credentialsPath = env('FIREBASE_CREDENTIALS');

        if (!$credentialsPath) {
            $credentialsPath = env('GOOGLE_APPLICATION_CREDENTIALS');
        }

        if (!$credentialsPath) {
            throw new \RuntimeException(
                'FIREBASE_CREDENTIALS is not configured.'
            );
        }

        // Resolve relative paths from Laravel project directory.
        if (!is_file($credentialsPath)) {
            $credentialsPath = base_path($credentialsPath);
        }

        if (!is_file($credentialsPath)) {
            throw new \RuntimeException(
                'Firebase service account file not found: '
                . $credentialsPath
            );
        }

        $serviceAccount = json_decode(
            file_get_contents($credentialsPath),
            true
        );

        if (!is_array($serviceAccount)) {
            throw new \RuntimeException(
                'Invalid Firebase service account JSON.'
            );
        }

        return $serviceAccount;
    }

    /**
     * Get Google OAuth access token.
     */
    private function getGoogleAccessToken(
        array $serviceAccount
    ): string {

        $credentials = new ServiceAccountCredentials(
            [
                'https://www.googleapis.com/auth/datastore'
            ],
            $serviceAccount
        );

        $auth = $credentials->fetchAuthToken();

        if (empty($auth['access_token'])) {
            throw new \RuntimeException(
                'Unable to obtain Google access token.'
            );
        }

        return $auth['access_token'];
    }

    /**
     * Retrieve all unique FCM tokens from Firestore.
     *
     * reelspay (collection)
     *   └── users (document)
     *        └── profiles (collection)
     *             └── documents
     *                  └── fcmToken
     */
    private function getUniqueFcmTokens(): array
    {
        $serviceAccount = $this->getServiceAccount();

        $projectId = $serviceAccount['project_id'] ?? null;

        if (!$projectId) {
            throw new \RuntimeException(
                'Firebase project ID not found.'
            );
        }

        $accessToken = $this->getGoogleAccessToken(
            $serviceAccount
        );

        $url = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/reelspay/users/profiles";

        $tokens = [];
        $pageToken = null;

        do {

            $params = [
                'pageSize' => 1000,
            ];

            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(60)
                ->get($url, $params);

            if (!$response->successful()) {

                Log::error('Firestore REST API error', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                $response->throw();
            }

            $result = $response->json();

            foreach ($result['documents'] ?? [] as $document) {

                $fields = $document['fields'] ?? [];

                $token = $fields['fcmToken']['stringValue']
                    ?? null;

                if (
                    is_string($token) &&
                    trim($token) !== ''
                ) {
                    $tokens[] = trim($token);
                }
            }

            $pageToken = $result['nextPageToken'] ?? null;

        } while ($pageToken);

        // Remove duplicate tokens.
        return array_values(array_unique($tokens));
    }

    /**
     * Notification messages and images.
     */
    private function getNotifications(): array
    {
        return [

            [
                'id' => 'keep_earning',
                'title' => '💰 Keep Earning! Your Next Reward Awaits',
                'body' => 'Keep playing, keep making progress and discover more exciting reward opportunities. Open the app today! 🚀',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQYJZe-5iMA0BK1smfeXHszMzkEkotXC6NUDjAPxrpcw7RJBJdKWH_AnKCQ&s=10',
            ],

            [
                'id' => 'double_rewards',
                'title' => '🔥 DOUBLE REWARDS PROMOTION!',
                'body' => 'Ready for more? Enjoy our special double rewards promotion when eligible. Open the app and check out the offer! 🎁',
                'image' => 'https://alidropship.com/wp-content/uploads/2026/06/Cover_Make-money-watching-movies.webp',
            ],

            [
                'id' => 'new_opportunities',
                'title' => '🎬 Play More, Discover More!',
                'body' => 'Your next exciting opportunity is waiting. Come back, enjoy the experience and keep earning eligible rewards! 💰',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcThTWL4yQTVu9SXFAIjNDRSeoAJFTsLG1VRghduuzJVxg&s=10',
            ],

            [
                'id' => 'withdraw_100',
                'title' => '💵 YOUR $100 MILESTONE!',
                'body' => 'Working towards $100? Keep making progress and check your wallet to see how close you are to your withdrawal goal! 🎉',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRJNnZ12Oyd8mlzDyKyv6dlF2S9p1mcg_018g5mBOuM0A&s=10',
            ],

            [
                'id' => 'earn_more',
                'title' => '🚀 Your Rewards Journey Continues!',
                'body' => 'There are more opportunities waiting for you. Open the app, take on another challenge and keep progressing! 💎',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR_tFwPyA16OtbO4Ck3NG-yO4F0jaAKG_Xjntm3o2prDA&s=10',
            ],

            [
                'id' => 'withdrawal_progress',
                'title' => '💰 Is Your Balance Ready?',
                'body' => 'Check your wallet today! See your available balance, review withdrawal eligibility and plan your next milestone. 💵',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRQHZbB8XGXE11ze70IK-dr-TBU1DwFzgwxB8wEEY725w&s=10',
            ],

            [
                'id' => 'daily_motivation',
                'title' => '🔥 Don\'t Stop Now!',
                'body' => 'Every new challenge is another opportunity to make progress. Come back and keep your rewards journey moving! 🏆',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTWYb_ey2AdtarbQjiC5wTGS0rlPAJ1oLiEYPTKxfri1g&s=10',
            ],

            [
                'id' => 'two_hundred_goal',
                'title' => '💎 DREAM BIGGER — $200!',
                'body' => 'Have you set your next reward milestone? Keep making progress and work towards your personal goals! 🚀',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR-X7G1pjo0SeWBT99fw9WKIQCM2odT6ZArmQp20OdTMg&s=10',
            ],

            [
                'id' => 'come_back',
                'title' => '😍 We Have Missed You!',
                'body' => 'Your next challenge is waiting. Come back today, enjoy the experience and continue your rewards journey! 🎁',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTqetZM7PbNa-_9lDF4OuyyRosGSp6We-UyK932y2eAwg&s=10',
            ],

            [
                'id' => 'special_rewards',
                'title' => '🎉 More Rewards, More Excitement!',
                'body' => 'Check out the latest opportunities in the app. Your next milestone could be closer than you think! 💰',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSK3eiCbbAaAMxD0uxbrVW2b--JZKf5J7tya7pRhKhoOg&s',
            ],

            [
                'id' => 'keep_progressing',
                'title' => '🏆 Your Next Big Milestone Awaits!',
                'body' => 'Stay active, explore new challenges and keep building your progress. Open the app and see what\'s waiting! 🔥',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQPQ2cJVfrAzO14nZY3u36VVaqvrADroXW3Kzh7aTePNg&s=10',
            ],

            [
                'id' => 'reward_opportunity',
                'title' => '💸 Ready for More Rewards?',
                'body' => 'Don\'t miss the latest activities and reward opportunities. Open the app and see what you can achieve today! 🎁',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQcbUPcP5L7by3gEHg_RdNvejjWl_OiS7fWQUnwWlLMeA&s=10',
            ],

            [
                'id' => 'big_goals',
                'title' => '🚀 $100, $200 AND BIGGER GOALS!',
                'body' => 'Set your sights on your next milestone. Keep progressing and check your wallet for your current reward balance! 💎',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQzlL7YQyApVhENks7zhG_NCCPfDTNBiifeiB1KhGs1Yw&s=10',
            ],

        ];
    }

    /**
     * Send notification to every unique device.
     */
    public function send(): JsonResponse
    {
        try {

            // 1. Get all unique device tokens from Firestore.
            $tokens = $this->getUniqueFcmTokens();

            if (empty($tokens)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid FCM device tokens found.',
                    'total_unique_devices' => 0,
                ], 404);
            }

            // 2. Select a random notification.
            // Use file cache instead of database cache.
            $lastId = Cache::store('file')->get(
                'lux_puzzle_last_notification'
            );

            $notifications = $this->getNotifications();

            $available = collect($notifications)
                ->reject(fn ($item) => $item['id'] === $lastId)
                ->values();

            $selected = $available->random();

            // 3. Generate a notification ID without SQL.
            $notificationId = (string) Str::uuid();

            // 4. Prepare counters.
            $successCount = 0;
            $failedCount = 0;
            $failedTokens = [];

            // 5. Send individually to each device.
            foreach ($tokens as $token) {

                try {

                    $message = CloudMessage::withTarget(
                        'token',
                        $token
                    )
                        ->withNotification(
                            Notification::create(
                                $selected['title'],
                                $selected['body'],
                                $selected['image']
                            )
                        )
                        ->withData([
                            'type' => 'automatic_promotion',
                            'notification_id' => $notificationId,
                            'notification_key' => $selected['id'],
                            'title' => $selected['title'],
                            'body' => $selected['body'],
                            'image' => $selected['image'],
                        ]);

                    $this->messaging->send($message);

                    $successCount++;

                } catch (Throwable $e) {

                    $failedCount++;

                    $failedTokens[] = [
                        'token' => substr($token, 0, 15) . '...',
                        'error' => $e->getMessage(),
                    ];

                    Log::warning(
                        'Individual FCM notification failed',
                        [
                            'token_prefix' => substr($token, 0, 15),
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }

            // 6. Update last notification using FILE CACHE.
            // No MySQL query is performed.
            if ($successCount > 0) {

                Cache::store('file')->put(
                    'lux_puzzle_last_notification',
                    $selected['id'],
                    now()->addDays(30)
                );
            }

            // 7. Return result.
            return response()->json([

                'success' => $successCount > 0,

                'message' => 'Automatic push notification process completed.',

                'notification' => [
                    'id' => $selected['id'],
                    'notification_id' => $notificationId,
                    'title' => $selected['title'],
                    'body' => $selected['body'],
                    'image' => $selected['image'],
                ],

                'statistics' => [
                    'total_unique_devices' => count($tokens),
                    'successful_sends' => $successCount,
                    'failed_sends' => $failedCount,
                ],

                'failed_tokens' => $failedTokens,

            ]);

        } catch (Throwable $e) {

            Log::error(
                'Automatic push notification process failed',
                [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Automatic notification process failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
