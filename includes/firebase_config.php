<?php

// Enable Firebase integration.
// Mode may be 'realtime' or 'firestore'.
define('FIREBASE_USE', filter_var(getenv('FIREBASE_USE') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('FIREBASE_MODE', getenv('FIREBASE_MODE') ?: 'firestore');

// Realtime Database settings.
define('FIREBASE_DATABASE_URL', getenv('FIREBASE_DATABASE_URL') ?: '');
define('FIREBASE_DATABASE_SECRET', getenv('FIREBASE_DATABASE_SECRET') ?: '');

// Firestore settings.
define('FIREBASE_FIRESTORE_PROJECT_ID', getenv('FIREBASE_FIRESTORE_PROJECT_ID') ?: 'mysmartiba');
// Store the raw JSON in FIREBASE_SERVICE_ACCOUNT_JSON, or use a file OUTSIDE the web root.
define('FIREBASE_SERVICE_ACCOUNT_JSON', getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: (getenv('FIREBASE_SERVICE_ACCOUNT_FILE') ?: ''));
define('FIREBASE_ACCESS_TOKEN', getenv('FIREBASE_ACCESS_TOKEN') ?: '');
define('FIREBASE_FCM_PROJECT_ID', FIREBASE_FIRESTORE_PROJECT_ID);
define('FIREBASE_FCM_TOPIC_PREFIX', 'user_');
define('FIREBASE_FCM_EVENTS_TOPIC', 'events');
define('FIREBASE_FCM_NEWS_TOPIC', 'news');
define('FIREBASE_FCM_NEWS_PUSH_TOPIC', FIREBASE_FCM_EVENTS_TOPIC);
define('FIREBASE_FCM_ANDROID_CHANNEL_ID', 'smart_iba_urgent_v1');
define('FIREBASE_FCM_NOTIFICATION_ICON', 'ibalogo');
define('APP_TIMEZONE', 'Asia/Manila');

if (defined('APP_TIMEZONE') && APP_TIMEZONE !== '') {
    date_default_timezone_set(APP_TIMEZONE);
}

function firebase_enabled(): bool
{
    return defined('FIREBASE_USE') && FIREBASE_USE;
}

function firebase_mode(): string
{
    return defined('FIREBASE_MODE') ? strtolower(FIREBASE_MODE) : 'realtime';
}

function firebase_realtime_enabled(): bool
{
    return firebase_enabled() && firebase_mode() === 'realtime' && defined('FIREBASE_DATABASE_URL') && FIREBASE_DATABASE_URL !== '';
}

function firebase_firestore_enabled(): bool
{
    return firebase_enabled() && firebase_mode() === 'firestore' && defined('FIREBASE_FIRESTORE_PROJECT_ID') && FIREBASE_FIRESTORE_PROJECT_ID !== '';
}

function firebase_database_path(string $path): string
{
    $base = rtrim(FIREBASE_DATABASE_URL, '/');
    $path = ltrim($path, '/');
    return $base . '/' . $path . '.json';
}

function firebase_firestore_base_url(): string
{
    return 'https://firestore.googleapis.com/v1/projects/' . urlencode(FIREBASE_FIRESTORE_PROJECT_ID) . '/databases/(default)/documents';
}

function firebase_app_timezone(): DateTimeZone
{
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezoneName = defined('APP_TIMEZONE') && APP_TIMEZONE !== ''
        ? APP_TIMEZONE
        : date_default_timezone_get();

    $timezone = new DateTimeZone($timezoneName);
    return $timezone;
}

function firebase_now_string(): string
{
    return (new DateTimeImmutable('now', firebase_app_timezone()))->format(DateTimeInterface::ATOM);
}

function firebase_fcm_enabled(): bool
{
    return firebase_enabled()
        && defined('FIREBASE_FCM_PROJECT_ID')
        && FIREBASE_FCM_PROJECT_ID !== '';
}

function sendPushNotification(string $userId, string $title, string $message): bool
{
    $userId = trim($userId);
    $title = trim($title);
    $message = trim($message);

    if ($userId === '' || $title === '' || $message === '') {
        firebase_set_last_error('Push notification requires a user ID, title, and message.');
        return false;
    }

    if (!firebase_fcm_enabled()) {
        firebase_set_last_error('FCM push notifications are not configured.');
        return false;
    }

    $accessToken = firebase_get_access_token();
    if ($accessToken === null) {
        firebase_set_last_error(firebase_get_last_error() ?? 'FCM access token is not available.');
        return false;
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . urlencode(FIREBASE_FCM_PROJECT_ID) . '/messages:send';
    $payload = [
        'message' => [
            'topic' => FIREBASE_FCM_TOPIC_PREFIX . $userId,
            'notification' => [
                'title' => $title,
                'body' => $message,
            ],
            'data' => [
                'title' => $title,
                'body' => $message,
            ],
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => FIREBASE_FCM_ANDROID_CHANNEL_ID,
                    'sound' => 'default',
                    'icon' => FIREBASE_FCM_NOTIFICATION_ICON,
                ],
            ],
        ],
    ];

    $result = firebase_http_request(
        $url,
        'POST',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    );

    return $result['success'];
}

function sendTopicPushNotification(string $topic, string $title, string $message, array $data = []): bool
{
    $topic = trim($topic);
    $title = trim($title);
    $message = trim($message);

    if ($topic === '' || $title === '' || $message === '') {
        firebase_set_last_error('Topic push notification requires a topic, title, and message.');
        return false;
    }

    if (!firebase_fcm_enabled()) {
        firebase_set_last_error('FCM push notifications are not configured.');
        return false;
    }

    $accessToken = firebase_get_access_token();
    if ($accessToken === null) {
        firebase_set_last_error(firebase_get_last_error() ?? 'FCM access token is not available.');
        return false;
    }

    $payloadData = array_merge(
        [
            'title' => $title,
            'body' => $message,
        ],
        $data
    );

    $url = 'https://fcm.googleapis.com/v1/projects/' . urlencode(FIREBASE_FCM_PROJECT_ID) . '/messages:send';
    $payload = [
        'message' => [
            'topic' => $topic,
            'notification' => [
                'title' => $title,
                'body' => $message,
            ],
            'data' => array_map(static fn($value): string => is_scalar($value) ? (string) $value : '', $payloadData),
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => FIREBASE_FCM_ANDROID_CHANNEL_ID,
                    'sound' => 'default',
                    'icon' => FIREBASE_FCM_NOTIFICATION_ICON,
                ],
            ],
        ],
    ];

    $result = firebase_http_request(
        $url,
        'POST',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    );

    return $result['success'];
}

function firebase_firestore_document_id_from_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $parts = explode('/', str_replace('\\', '/', $name));
    $lastPart = end($parts);

    return is_string($lastPart) ? trim($lastPart) : '';
}

function firebase_resolve_user_id(array $userData, string $fallbackId = ''): string
{
    foreach (['userId', 'user_id', 'uid', 'id'] as $key) {
        $value = trim((string) ($userData[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $documentId = firebase_firestore_document_id_from_name((string) ($userData['__name'] ?? ''));
    if ($documentId !== '') {
        return $documentId;
    }

    return trim($fallbackId);
}

function firebase_list_user_ids(): array
{
    $userIds = [];

    if (firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents('users');
        if (is_array($documents)) {
            foreach ($documents as $document) {
                $userId = firebase_resolve_user_id((array) $document);
                if ($userId !== '') {
                    $userIds[$userId] = true;
                }
            }
        }
    } elseif (firebase_realtime_enabled()) {
        $users = firebase_get('users');
        if (is_array($users)) {
            foreach ($users as $key => $userData) {
                $userId = firebase_resolve_user_id(is_array($userData) ? $userData : [], is_string($key) ? $key : '');
                if ($userId !== '') {
                    $userIds[$userId] = true;
                }
            }
        }
    }

    return array_keys($userIds);
}

function firebase_create_notification_document(string $userId, string $title, string $message, string $type = 'general', array $extra = []): bool
{
    $userId = trim($userId);
    $title = trim($title);
    $message = trim($message);
    $type = trim($type) !== '' ? trim($type) : 'general';

    if ($userId === '' || $title === '' || $message === '') {
        firebase_set_last_error('Notification document requires a user ID, title, and message.');
        return false;
    }

    if (!firebase_firestore_enabled()) {
        firebase_set_last_error('Firestore notifications are not configured.');
        return false;
    }

    $payload = array_merge(
        [
            'userId' => $userId,
            'title' => $title,
            'message' => $message,
            'isRead' => false,
            'timestamp' => new DateTimeImmutable('now', firebase_app_timezone()),
            'type' => $type,
        ],
        $extra
    );

    return firebase_firestore_create_document('notifications', $payload) !== null;
}

function firebase_create_notifications_for_all_users(string $title, string $message, string $type = 'general', array $extra = []): array
{
    $title = trim($title);
    $message = trim($message);
    $type = trim($type) !== '' ? trim($type) : 'general';

    if ($title === '' || $message === '') {
        firebase_set_last_error('Broadcast notifications require a title and message.');
        return [
            'user_ids' => [],
            'created_count' => 0,
            'failed_count' => 0,
        ];
    }

    $userIds = firebase_list_user_ids();
    if ($userIds === []) {
        firebase_set_last_error('No users were found to receive the event notification.');
        return [
            'user_ids' => [],
            'created_count' => 0,
            'failed_count' => 0,
        ];
    }

    $createdCount = 0;
    $failedCount = 0;

    foreach ($userIds as $userId) {
        if (firebase_create_notification_document($userId, $title, $message, $type, $extra)) {
            $createdCount++;
        } else {
            $failedCount++;
        }
    }

    if ($failedCount > 0) {
        firebase_set_last_error('Some notification documents could not be created.');
    }

    return [
        'user_ids' => $userIds,
        'created_count' => $createdCount,
        'failed_count' => $failedCount,
    ];
}

function firebase_create_notification(string $userId, string $title, string $message, string $type = 'general', array $extra = []): bool
{
    $userId = trim($userId);
    $title = trim($title);
    $message = trim($message);
    $type = trim($type) !== '' ? trim($type) : 'general';

    if ($userId === '' || $title === '' || $message === '') {
        return false;
    }

    $created = firebase_create_notification_document($userId, $title, $message, $type, $extra);

    $pushSent = sendPushNotification($userId, $title, $message);

    return $created || $pushSent;
}

function firebase_firestore_document_path(string $path): string
{
    return trim($path, '/');
}

function firebase_get_access_token(): ?string
{
    static $token = null;
    static $expiresAt = 0;

    if ($token !== null && time() < $expiresAt - 60) {
        return $token;
    }

    if (defined('FIREBASE_ACCESS_TOKEN') && FIREBASE_ACCESS_TOKEN !== '') {
        $token = FIREBASE_ACCESS_TOKEN;
        $expiresAt = time() + 3600;
        return $token;
    }

    if (!defined('FIREBASE_SERVICE_ACCOUNT_JSON') || FIREBASE_SERVICE_ACCOUNT_JSON === '') {
        firebase_set_last_error('Firebase service account configuration is missing.');
        return null;
    }

    $raw = FIREBASE_SERVICE_ACCOUNT_JSON;
    if (is_string($raw) && file_exists($raw)) {
        $raw = file_get_contents($raw);
    }

    $credentials = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($credentials)) {
        firebase_set_last_error('Firebase service account JSON is invalid or could not be parsed.');
        return null;
    }

    if (!isset($credentials['client_email'], $credentials['private_key'], $credentials['token_uri'])) {
        firebase_set_last_error('Firebase service account JSON is missing required credentials.');
        return null;
    }

    $jwt = firebase_build_jwt($credentials);
    if ($jwt === null) {
        firebase_set_last_error('Unable to create Firebase service account JWT. Make sure PHP OpenSSL is installed and enabled.');
        return null;
    }

    $postData = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $response = firebase_http_request('https://oauth2.googleapis.com/token', 'POST', ['Content-Type: application/x-www-form-urlencoded'], $postData);
    if (!$response['success']) {
        if (is_array($response['data']) && isset($response['data']['error_description'])) {
            firebase_set_last_error('OAuth token error: ' . $response['data']['error_description']);
        }
        return null;
    }

    if (!is_array($response['data'])) {
        firebase_set_last_error('OAuth token response was not valid JSON.');
        return null;
    }

    $accessToken = $response['data']['access_token'] ?? null;
    $expiresIn = isset($response['data']['expires_in']) ? (int) $response['data']['expires_in'] : 3600;
    if (!is_string($accessToken) || $accessToken === '') {
        firebase_set_last_error('Firebase access token was not returned by Google OAuth.');
        return null;
    }

    $token = $accessToken;
    $expiresAt = time() + $expiresIn;
    return $token;
}

function firebase_build_jwt(array $credentials): ?string
{
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (isset($credentials['private_key_id']) && $credentials['private_key_id'] !== '') {
        $header['kid'] = $credentials['private_key_id'];
    }

    $payload = [
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud' => $credentials['token_uri'],
        'exp' => $now + 300,
        'iat' => $now,
    ];

    $encodedHeader = firebase_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $encodedPayload = firebase_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $unsignedToken = $encodedHeader . '.' . $encodedPayload;

    if (!function_exists('openssl_sign')) {
        firebase_set_last_error('OpenSSL extension is not available. Install and enable PHP OpenSSL.');
        return null;
    }

    $privateKey = $credentials['private_key'];
    $privateKeyResource = openssl_pkey_get_private($privateKey);
    if ($privateKeyResource === false) {
        firebase_set_last_error('Unable to load the service account private key.');
        return null;
    }

    $signature = '';
    if (!openssl_sign($unsignedToken, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
        firebase_set_last_error('Unable to sign the JWT with the service account private key.');
        openssl_free_key($privateKeyResource);
        return null;
    }

    openssl_free_key($privateKeyResource);
    return $unsignedToken . '.' . firebase_base64url_encode($signature);
}

function firebase_base64url_encode(string $input): string
{
    return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}

function firebase_http_request(string $url, string $method = 'GET', array $headers = [], $body = null): array
{
    $defaultHeaders = ['Accept: application/json'];
    $headers = array_merge($defaultHeaders, $headers);

    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    } else {
        $contextOptions = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $contextOptions['http']['content'] = $body;
        }

        $context = stream_context_create($contextOptions);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        $error = '';

        if (isset($http_response_header) && preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }

        if ($response === false) {
            $error = 'HTTP request failed.';
        }
    }

    $decoded = null;
    if ($response !== false && $response !== null && $response !== '') {
        $decoded = json_decode($response, true);
    }

    $message = '';
    if ($response === false) {
        $message = $error !== '' ? $error : 'HTTP request failed.';
    } elseif ($status < 200 || $status >= 300) {
        $message = 'HTTP ' . $status . ': ' . ($error !== '' ? $error : ($response ?? ''));
    }

    firebase_set_last_error($message);

    return [
        'success' => $response !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $decoded,
        'body' => $response,
        'error' => $error,
    ];
}

function firebase_set_last_error(string $message = ''): void
{
    $GLOBALS['firebase_error_message'] = $message;
}

function firebase_get_last_error(): ?string
{
    return $GLOBALS['firebase_error_message'] ?? null;
}

function firebase_realtime_auth_query(): string
{
    if (defined('FIREBASE_DATABASE_SECRET') && FIREBASE_DATABASE_SECRET !== '') {
        return '?auth=' . urlencode(FIREBASE_DATABASE_SECRET);
    }

    return '';
}

function firebase_request(string $path, string $method = 'GET', $payload = null): array
{
    if (firebase_firestore_enabled()) {
        $documentPath = firebase_firestore_document_path($path);
        $url = firebase_firestore_base_url() . '/' . $documentPath;
        $headers = ['Content-Type: application/json; charset=UTF-8'];

        $accessToken = firebase_get_access_token();
        if ($accessToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        } else {
            firebase_set_last_error(firebase_get_last_error() ?? 'Firebase access token not available for Firestore request.');
        }

        if ($method === 'PATCH') {
            $url .= '?currentDocument.exists=true';
        }

        $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
        $result = firebase_http_request($url, $method, $headers, $body);
        return $result;
    }

    if (!firebase_realtime_enabled()) {
        return ['success' => false, 'status' => 0, 'data' => null, 'body' => null, 'error' => 'Firebase is not enabled or misconfigured.'];
    }

    $url = firebase_database_path($path) . firebase_realtime_auth_query();
    $headers = ['Content-Type: application/json; charset=UTF-8'];
    $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    return firebase_http_request($url, $method, $headers, $body);
}

function firebase_firestore_encode_value($value): array
{
    if ($value === null) {
        return ['nullValue' => null];
    }

    if ($value instanceof DateTimeInterface) {
        $timestamp = DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        return ['timestampValue' => $timestamp];
    }

    if (is_bool($value)) {
        return ['booleanValue' => $value];
    }

    if (is_int($value)) {
        return ['integerValue' => (string) $value];
    }

    if (is_float($value)) {
        return ['doubleValue' => $value];
    }

    if (is_array($value)) {
        if (array_keys($value) === range(0, count($value) - 1)) {
            return ['arrayValue' => ['values' => array_map('firebase_firestore_encode_value', $value)]];
        }

        $fields = [];
        foreach ($value as $key => $item) {
            $fields[$key] = firebase_firestore_encode_value($item);
        }

        return ['mapValue' => ['fields' => $fields]];
    }

    return ['stringValue' => (string) $value];
}

function firebase_firestore_encode_fields(array $data): array
{
    $fields = [];
    foreach ($data as $key => $value) {
        $fields[$key] = firebase_firestore_encode_value($value);
    }
    return $fields;
}

function firebase_firestore_decode_value(array $value)
{
    if (isset($value['stringValue'])) {
        return $value['stringValue'];
    }

    if (isset($value['integerValue'])) {
        return is_numeric($value['integerValue']) ? (int) $value['integerValue'] : $value['integerValue'];
    }

    if (isset($value['doubleValue'])) {
        return (float) $value['doubleValue'];
    }

    if (isset($value['timestampValue'])) {
        return $value['timestampValue'];
    }

    if (isset($value['booleanValue'])) {
        return $value['booleanValue'];
    }

    if (isset($value['nullValue'])) {
        return null;
    }

    if (isset($value['mapValue'])) {
        return firebase_firestore_decode_fields($value['mapValue']['fields'] ?? []);
    }

    if (isset($value['arrayValue'])) {
        $values = $value['arrayValue']['values'] ?? [];
        return array_map('firebase_firestore_decode_value', $values);
    }

    return null;
}

function firebase_firestore_decode_fields(array $fields): array
{
    $result = [];
    foreach ($fields as $key => $value) {
        $result[$key] = firebase_firestore_decode_value($value);
    }
    return $result;
}

function firebase_firestore_list_documents(string $collectionPath): ?array
{
    if (!firebase_firestore_enabled()) {
        return null;
    }

    $collectionPath = trim($collectionPath, '/');
    $url = firebase_firestore_base_url() . '/' . $collectionPath;
    $accessToken = firebase_get_access_token();
    $headers = ['Accept: application/json'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $result = firebase_http_request($url, 'GET', $headers, null);
    if (!$result['success'] || !is_array($result['data'])) {
        return null;
    }

    $documents = [];
    foreach ($result['data']['documents'] ?? [] as $document) {
        $fields = firebase_firestore_decode_fields($document['fields'] ?? []);
        $documents[] = array_merge(['__name' => $document['name'] ?? ''], $fields);
    }

    return $documents;
}

function firebase_firestore_create_document(string $collectionPath, array $data): ?array
{
    if (!firebase_firestore_enabled()) {
        return null;
    }

    $collectionPath = trim($collectionPath, '/');
    $url = firebase_firestore_base_url() . '/' . $collectionPath;
    $accessToken = firebase_get_access_token();
    $headers = ['Content-Type: application/json; charset=UTF-8'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $body = json_encode(['fields' => firebase_firestore_encode_fields($data)], JSON_UNESCAPED_UNICODE);
    $result = firebase_http_request($url, 'POST', $headers, $body);
    if (!$result['success'] || !is_array($result['data'])) {
        return null;
    }

    $fields = firebase_firestore_decode_fields($result['data']['fields'] ?? []);
    return array_merge(['__name' => $result['data']['name'] ?? ''], $fields);
}

function firebase_firestore_patch_document(string $documentPath, array $data): bool
{
    if (!firebase_firestore_enabled()) {
        return false;
    }

    $documentPath = trim(firebase_firestore_document_path_from_name($documentPath), '/');
    $url = firebase_firestore_base_url() . '/' . $documentPath;
    $query = ['currentDocument.exists=true'];
    foreach (array_keys($data) as $fieldPath) {
        $query[] = 'updateMask.fieldPaths=' . urlencode((string) $fieldPath);
    }
    $url .= '?' . implode('&', $query);

    $accessToken = firebase_get_access_token();
    $headers = ['Content-Type: application/json; charset=UTF-8'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $body = json_encode(['fields' => firebase_firestore_encode_fields($data)], JSON_UNESCAPED_UNICODE);
    $result = firebase_http_request($url, 'PATCH', $headers, $body);
    return $result['success'];
}

function firebase_firestore_document_path_from_name(string $name): string
{
    $prefix = '/documents/';
    $position = strpos($name, $prefix);
    if ($position === false) {
        return trim($name, '/');
    }
    return trim(substr($name, $position + strlen($prefix)), '/');
}

function firebase_firestore_delete_document(string $documentPath): bool
{
    if (!firebase_firestore_enabled()) {
        return false;
    }

    $documentPath = trim(firebase_firestore_document_path_from_name($documentPath), '/');
    $url = firebase_firestore_base_url() . '/' . $documentPath;
    $accessToken = firebase_get_access_token();
    $headers = ['Accept: application/json'];
    if ($accessToken !== null) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $result = firebase_http_request($url, 'DELETE', $headers, null);
    return $result['success'];
}

function firebase_get(string $path)
{
    if (!firebase_enabled()) {
        return null;
    }

    if (firebase_firestore_enabled()) {
        $result = firebase_request($path, 'GET');
        if (!$result['success'] || !is_array($result['data'])) {
            return null;
        }

        if (isset($result['data']['fields'])) {
            return firebase_firestore_decode_fields($result['data']['fields']);
        }

        return $result['data'];
    }

    $result = firebase_request($path, 'GET');
    return $result['success'] ? $result['data'] : null;
}

function firebase_put(string $path, $data): bool
{
    if (!firebase_enabled()) {
        return false;
    }

    if (firebase_firestore_enabled()) {
        $payload = ['fields' => ['payload' => ['stringValue' => json_encode($data, JSON_UNESCAPED_UNICODE)]]];
        $result = firebase_request($path, 'PATCH', $payload);
        return $result['success'];
    }

    $result = firebase_request($path, 'PUT', $data);
    return $result['success'];
}

function firebase_patch(string $path, $data): bool
{
    if (!firebase_enabled()) {
        return false;
    }

    if (firebase_firestore_enabled()) {
        $current = firebase_get($path);
        if (!is_array($current)) {
            return firebase_put($path, $data);
        }

        $merged = array_replace_recursive($current, (array) $data);
        return firebase_put($path, $merged);
    }

    $result = firebase_request($path, 'PATCH', $data);
    return $result['success'];
}
