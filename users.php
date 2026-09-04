<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function users_is_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (float) $value !== 0.0;
    }

    if (!is_string($value)) {
        return false;
    }

    return in_array(strtolower(trim($value)), ['true', '1', 'yes', 'active', 'online'], true);
}

function users_load_records(): array
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents('users');
        return is_array($documents) ? $documents : [];
    }

    if (firebase_enabled() && firebase_realtime_enabled()) {
        $users = firebase_get('users');
        if (!is_array($users)) {
            return [];
        }

        $records = [];
        foreach ($users as $key => $userData) {
            $records[] = array_merge(
                ['__name' => is_string($key) ? $key : ''],
                is_array($userData) ? $userData : []
            );
        }

        return $records;
    }

    return [];
}

function users_first_value(array $record, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($record[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function users_full_name(array $record): string
{
    $name = users_first_value($record, ['fullName', 'name', 'displayName', 'username']);
    if ($name !== '') {
        return $name;
    }

    $firstName = trim((string) ($record['firstName'] ?? ''));
    $lastName = trim((string) ($record['lastName'] ?? ''));
    $combined = trim($firstName . ' ' . $lastName);

    return $combined !== '' ? $combined : 'No name provided';
}

function users_last_active_raw(array $record)
{
    foreach (['lastSeen', 'lastActive', 'updatedAt', 'lastLoginAt', 'last_login_at', 'timestamp'] as $key) {
        if (!array_key_exists($key, $record)) {
            continue;
        }

        $value = $record[$key];
        if ($value === null) {
            continue;
        }

        if (is_string($value) && trim($value) === '') {
            continue;
        }

        return $value;
    }

    return null;
}

function users_parse_datetime($value): ?DateTimeImmutable
{
    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
        $timestamp = (float) $value;
        if ($timestamp > 9999999999) {
            $timestamp /= 1000;
        }

        try {
            return (new DateTimeImmutable('@' . (string) ((int) round($timestamp))))
                ->setTimezone(firebase_app_timezone());
        } catch (Exception $exception) {
            return null;
        }
    }

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->setTimezone(firebase_app_timezone());
    } catch (Exception $exception) {
        return null;
    }
}

function users_format_datetime($value): string
{
    $date = users_parse_datetime($value);
    if (!$date instanceof DateTimeImmutable) {
        return 'No activity data';
    }

    return $date->format('M d, Y h:i A');
}

function users_status_data(array $record): array
{
    if (users_is_banned($record)) {
        return ['label' => 'Banned', 'class' => 'status-danger'];
    }

    $lastActive = users_last_active_raw($record);
    $isOnline = $record['isOnline'] ?? $record['online'] ?? null;
    $lastSeen = users_parse_datetime($lastActive);

    if (users_is_truthy($isOnline)) {
        return ['label' => 'Active now', 'class' => 'status-active'];
    }

    if ($lastSeen instanceof DateTimeImmutable) {
        $minutesAgo = (time() - $lastSeen->getTimestamp()) / 60;

        if ($minutesAgo <= 10) {
            return ['label' => 'Recently active', 'class' => 'status-updated'];
        }

        return ['label' => 'Offline', 'class' => 'status-inactive'];
    }

    return ['label' => 'Unknown', 'class' => 'status-review'];
}

function users_is_banned(array $record): bool
{
    foreach (['isBanned', 'banned'] as $key) {
        if (array_key_exists($key, $record) && users_is_truthy($record[$key])) {
            return true;
        }
    }

    $status = strtolower(users_first_value($record, ['status', 'accountStatus']));
    return in_array($status, ['banned', 'blocked', 'disabled', 'suspended'], true);
}

function users_document_path(array $record): string
{
    $documentName = trim((string) ($record['__name'] ?? ''));
    if ($documentName !== '') {
        return firebase_firestore_document_path_from_name($documentName);
    }

    $userId = firebase_resolve_user_id($record);
    return $userId !== '' ? 'users/' . $userId : '';
}

function users_find_record_by_id(array $records, string $userId): ?array
{
    foreach ($records as $record) {
        if (firebase_resolve_user_id((array) $record) === $userId) {
            return (array) $record;
        }
    }

    return null;
}

function users_manage_supported(): bool
{
    return firebase_enabled() && firebase_firestore_enabled();
}

$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!users_manage_supported()) {
        $errorMessage = 'User management actions currently require Firebase Firestore mode.';
    } else {
        $action = trim((string) filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW));
        $userId = trim((string) filter_input(INPUT_POST, 'user_id', FILTER_UNSAFE_RAW));
        $records = users_load_records();

        if (in_array($action, ['ban_user', 'unban_user', 'delete_user'], true)) {
            if ($userId === '') {
                $errorMessage = 'User ID is required for this action.';
            } else {
                $record = users_find_record_by_id($records, $userId);
                if (!$record) {
                    $errorMessage = 'The selected user could not be found.';
                } else {
                    $documentPath = users_document_path($record);
                    if ($documentPath === '') {
                        $errorMessage = 'The selected user does not have a valid Firestore document path.';
                    } elseif ($action === 'delete_user') {
                        if (firebase_firestore_delete_document($documentPath)) {
                            $successMessage = 'User profile deleted successfully.';
                        } else {
                            $errorMessage = firebase_get_last_error() ?? 'The user profile could not be deleted.';
                        }
                    } else {
                        $isBanAction = $action === 'ban_user';
                        $patched = firebase_firestore_patch_document(
                            $documentPath,
                            [
                                'isBanned' => $isBanAction,
                                'status' => $isBanAction ? 'banned' : 'offline',
                                'isOnline' => false,
                                'updatedAt' => firebase_now_string(),
                            ]
                        );

                        if ($patched) {
                            $successMessage = $isBanAction
                                ? 'User has been banned.'
                                : 'User has been unbanned.';
                        } else {
                            $errorMessage = firebase_get_last_error() ?? 'The user status could not be updated.';
                        }
                    }
                }
            }
        }
    }
}

$search = trim((string) filter_input(INPUT_GET, 'q', FILTER_UNSAFE_RAW));
$statusFilter = strtolower(trim((string) filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW)));
$statusFilter = in_array($statusFilter, ['all', 'active', 'recent', 'inactive', 'unknown', 'banned'], true)
    ? $statusFilter
    : 'all';

$records = users_load_records();
$users = [];
$summary = [
    'total' => 0,
    'active' => 0,
    'recent' => 0,
    'inactive' => 0,
    'unknown' => 0,
    'banned' => 0,
];

foreach ($records as $record) {
    $record = (array) $record;
    $userId = firebase_resolve_user_id($record);
    $name = users_full_name($record);
    $email = users_first_value($record, ['email', 'userEmail', 'mail']);
    $phone = users_first_value($record, ['mobileNumber', 'phone', 'phoneNumber', 'contactNumber', 'mobile']);
    $lastActiveRaw = users_last_active_raw($record);
    $status = users_status_data($record);
    $isBanned = users_is_banned($record);

    $normalized = [
        'id' => $userId !== '' ? $userId : 'Unknown ID',
        'name' => $name,
        'email' => $email !== '' ? $email : 'No email provided',
        'phone' => $phone !== '' ? $phone : 'No phone provided',
        'is_banned' => $isBanned,
        'status_label' => $status['label'],
        'status_class' => $status['class'],
        'last_active' => users_format_datetime($lastActiveRaw),
        'search_blob' => strtolower($userId . ' ' . $name . ' ' . $email . ' ' . $phone),
    ];

    $summary['total']++;

    if ($status['label'] === 'Banned') {
        $summary['banned']++;
    } elseif ($status['label'] === 'Active now') {
        $summary['active']++;
    } elseif ($status['label'] === 'Recently active') {
        $summary['recent']++;
    } elseif ($status['label'] === 'Offline') {
        $summary['inactive']++;
    } else {
        $summary['unknown']++;
    }

    $matchesSearch = $search === '' || str_contains($normalized['search_blob'], strtolower($search));
    $matchesStatus = $statusFilter === 'all'
        || ($statusFilter === 'active' && $status['label'] === 'Active now')
        || ($statusFilter === 'recent' && $status['label'] === 'Recently active')
        || ($statusFilter === 'inactive' && $status['label'] === 'Offline')
        || ($statusFilter === 'unknown' && $status['label'] === 'Unknown')
        || ($statusFilter === 'banned' && $status['label'] === 'Banned');

    if ($matchesSearch && $matchesStatus) {
        $users[] = $normalized;
    }
}

usort(
    $users,
    static function (array $left, array $right): int {
        return strcmp($left['name'], $right['name']);
    }
);

$rowsHtml = '';
foreach ($users as $user) {
    $banButton = $user['is_banned']
        ? '<button type="submit" class="action-btn secondary">Unban</button>'
        : '<button type="submit" class="action-btn secondary">Ban</button>';
    $banAction = $user['is_banned'] ? 'unban_user' : 'ban_user';

    $rowsHtml .= '<tr>'
        . '<td><strong>' . esc($user['name']) . '</strong><div class="meta-text mono-text">' . esc($user['id']) . '</div></td>'
        . '<td>' . esc($user['email']) . '</td>'
        . '<td>' . esc($user['phone']) . '</td>'
        . '<td><span class="status-pill ' . esc($user['status_class']) . '">' . esc($user['status_label']) . '</span><div class="meta-text">' . esc($user['last_active']) . '</div></td>'
        . '<td>'
        . '<div class="button-row">'
        . '<form method="post" class="inline-form">'
        . '<input type="hidden" name="action" value="' . esc($banAction) . '">'
        . '<input type="hidden" name="user_id" value="' . esc($user['id']) . '">'
        . $banButton
        . '</form>'
        . '<form method="post" class="inline-form" onsubmit="return confirm(\'Delete this user profile from Firestore?\');">'
        . '<input type="hidden" name="action" value="delete_user">'
        . '<input type="hidden" name="user_id" value="' . esc($user['id']) . '">'
        . '<button type="submit" class="action-btn secondary">Delete</button>'
        . '</form>'
        . '</div>'
        . '</td>'
        . '</tr>';
}

if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="5"><div class="empty-state">No users matched the current filters. If you are using Firebase, make sure the `users` collection exists and is readable by the admin panel.</div></td></tr>';
}

$searchValue = esc($search);
$allSelected = $statusFilter === 'all' ? 'selected' : '';
$activeSelected = $statusFilter === 'active' ? 'selected' : '';
$recentSelected = $statusFilter === 'recent' ? 'selected' : '';
$inactiveSelected = $statusFilter === 'inactive' ? 'selected' : '';
$unknownSelected = $statusFilter === 'unknown' ? 'selected' : '';
$bannedSelected = $statusFilter === 'banned' ? 'selected' : '';
$inactiveOrUnknownCount = $summary['inactive'] + $summary['unknown'];

$noticeHtml = '';
if ($successMessage !== '') {
    $noticeHtml .= '<div class="alert-box alert-success">' . esc($successMessage) . '</div>';
}
if ($errorMessage !== '') {
    $noticeHtml .= '<div class="alert-box alert-error">' . esc($errorMessage) . '</div>';
}

$managementNote = users_manage_supported()
    ? 'Ban, unban, or delete Firestore user profiles from this page.'
    : 'This page is read-only right now. Switch Firebase mode to Firestore to enable ban, unban, and delete actions.';

$contentHtml = <<<HTML
{$noticeHtml}

<div class="alert-box alert-info">
    {$managementNote}
</div>

<div class="summary-grid">
    <div class="summary-card">
        <h3>Total Users</h3>
        <div class="stat-value">{$summary['total']}</div>
        <p>Accounts detected from Firebase</p>
    </div>
    <div class="summary-card">
        <h3>Active Now</h3>
        <div class="stat-value">{$summary['active']}</div>
        <p>Users currently marked online</p>
    </div>
    <div class="summary-card">
        <h3>Recently Active</h3>
        <div class="stat-value">{$summary['recent']}</div>
        <p>Seen in the last 10 minutes</p>
    </div>
    <div class="summary-card">
        <h3>Restrict</h3>
        <div class="stat-value">{$summary['banned']}</div>
        <p>Accounts currently restricted</p>
    </div>
    <div class="summary-card">
        <h3>Offline or Unknown</h3>
        <div class="stat-value">{$inactiveOrUnknownCount}</div>
        <p>Accounts without current active signals</p>
    </div>
</div>

<div class="filter-row">
    <form method="get" class="filter-form">
        <label for="q">Search</label>
        <input type="text" id="q" name="q" class="input-field" value="{$searchValue}" placeholder="Name, email, phone, or user ID">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="all" {$allSelected}>All</option>
            <option value="active" {$activeSelected}>Active now</option>
            <option value="recent" {$recentSelected}>Recently active</option>
            <option value="inactive" {$inactiveSelected}>Offline</option>
            <option value="banned" {$bannedSelected}>Banned</option>
            <option value="unknown" {$unknownSelected}>Unknown</option>
        </select>
        <button type="submit" class="action-btn primary">Apply</button>
        <a href="users.php" class="action-link secondary">Reset</a>
    </form>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            {$rowsHtml}
        </tbody>
    </table>
</div>
HTML;

renderAdminPage('users', 'Users', 'Manage user profiles, activity status, and account restrictions from Firebase.', $contentHtml);
