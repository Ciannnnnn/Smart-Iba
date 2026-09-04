<?php
session_start();

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

$dataFile = __DIR__ . '/includes/manage_requests_data.json';
$firestoreError = null;

function getManageRequestBuckets(): array
{
    return ['pending', 'processing', 'completed', 'rejected', 'cancelled'];
}

function getManageRequestEmptyData(): array
{
    $data = [];

    foreach (getManageRequestBuckets() as $bucket) {
        $data[$bucket] = [];
    }

    return $data;
}

function normalizeManageRequestStatus($status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        '', 'pending' => 'Pending',
        'processing', 'in_progress', 'in progress' => 'Processing',
        'approved', 'completed', 'complete', 'done', 'finished' => 'Completed',
        'declined', 'rejected', 'reject' => 'Rejected',
        'cancelled', 'canceled', 'cancel' => 'Cancelled',
        default => trim((string) $status) !== '' ? trim((string) $status) : 'Completed',
    };
}

function getManageRequestBucketForStatus(string $status): string
{
    return match (normalizeManageRequestStatus($status)) {
        'Pending' => 'pending',
        'Processing' => 'processing',
        'Rejected' => 'rejected',
        'Cancelled' => 'cancelled',
        default => 'completed',
    };
}

function getManageRequestServiceName(array $request): string
{
    return trim((string) ($request['service_name'] ?? $request['serviceName'] ?? $request['category'] ?? $request['serviceType'] ?? $request['title'] ?? ''));
}

function getManageRequestStatusMeta(string $status): array
{
    return match (normalizeManageRequestStatus($status)) {
        'Pending' => ['label' => 'Pending', 'class' => 'status-review'],
        'Processing' => ['label' => 'Processing', 'class' => 'status-updated'],
        'Rejected' => ['label' => 'Rejected', 'class' => 'status-danger'],
        'Cancelled' => ['label' => 'Cancelled', 'class' => 'status-inactive'],
        default => ['label' => 'Completed', 'class' => 'status-active'],
    };
}

function normalizeManageRequestDoc(array $doc): array
{
    $serviceName = trim((string) ($doc['serviceName'] ?? $doc['category'] ?? $doc['serviceType'] ?? $doc['title'] ?? $doc['purpose'] ?? $doc['request_title'] ?? ''));

    return [
        'id' => isset($doc['id']) ? (int) $doc['id'] : 0,
        'user_id' => $doc['userId'] ?? $doc['user_id'] ?? '',
        'title' => $doc['title'] ?? $doc['purpose'] ?? $doc['request_title'] ?? $serviceName,
        'service_name' => $serviceName,
        'category' => $serviceName,
        'content' => $doc['content'] ?? $doc['purpose'] ?? '',
        'requested_by' => $doc['requested_by'] ?? $doc['userName'] ?? $doc['fullName'] ?? $doc['requester_name'] ?? 'Unknown User',
        'contact' => $doc['contact'] ?? $doc['mobileNumber'] ?? $doc['phoneNumber'] ?? $doc['userEmail'] ?? $doc['requester_contact'] ?? '',
        'source' => $doc['source'] ?? 'User Request',
        'status' => normalizeManageRequestStatus($doc['status'] ?? 'Pending'),
        'submitted_at' => $doc['submitted_at'] ?? $doc['timestamp'] ?? '',
        'processing_at' => $doc['processing_at'] ?? '',
        'processing_by' => $doc['processing_by'] ?? '',
        'completed_at' => $doc['completed_at'] ?? $doc['approved_at'] ?? '',
        'approved_at' => $doc['approved_at'] ?? '',
        'rejected_at' => $doc['rejected_at'] ?? $doc['declined_at'] ?? '',
        'declined_at' => $doc['declined_at'] ?? '',
        'cancelled_at' => $doc['cancelled_at'] ?? '',
        'completed_by' => $doc['completed_by'] ?? $doc['approved_by'] ?? '',
        'approved_by' => $doc['approved_by'] ?? '',
        '__name' => $doc['__name'] ?? '',
    ];
}

function getUserContactById(string $userId): string
{
    static $cache = [];

    $userId = trim($userId);
    if ($userId === '') {
        return '';
    }

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $cache[$userId] = '';
    if (!firebase_enabled() || !firebase_firestore_enabled()) {
        return $cache[$userId];
    }

    $userData = firebase_get('users/' . $userId);
    if (!is_array($userData)) {
        return $cache[$userId];
    }

    $contact = $userData['phoneNumber'] ?? $userData['phone'] ?? $userData['contact'] ?? $userData['contact_number'] ?? $userData['mobile'] ?? $userData['mobileNumber'] ?? '';
    $cache[$userId] = trim($contact);
    return $cache[$userId];
}

function getNextManageRequestId(array $data): int
{
    $highestId = 0;

    foreach (getManageRequestBuckets() as $bucket) {
        foreach ($data[$bucket] ?? [] as $item) {
            $highestId = max($highestId, (int) ($item['id'] ?? 0));
        }
    }

    return $highestId + 1;
}

function getManageRequestCategories(array $data): array
{
    $knownServices = [
        'Barangay Clearance',
        'Certificate of Indigency',
        'Business Permit',
        'Community Tax Certificate',
        'Certificate of Recidence',
        'Certificate of No Marriage',
    ];

    $categories = array_combine($knownServices, $knownServices);
    foreach (getManageRequestBuckets() as $bucket) {
        foreach ($data[$bucket] ?? [] as $item) {
            $category = getManageRequestServiceName((array) $item);
            if ($category !== '' && !isset($categories[$category])) {
                $categories[$category] = $category;
            }
        }
    }

    $categories = array_values($categories);
    sort($categories, SORT_STRING | SORT_FLAG_CASE);
    return $categories;
}

function filterManageRequestDataByCategory(array $data, string $category): array
{
    if ($category === '') {
        return $data;
    }

    $filtered = getManageRequestEmptyData();
    foreach (getManageRequestBuckets() as $bucket) {
        foreach ($data[$bucket] as $item) {
            if (strcasecmp(getManageRequestServiceName((array) $item), $category) === 0) {
                $filtered[$bucket][] = $item;
            }
        }
    }

    return $filtered;
}

function getManageRequestApprovedSortOptions(): array
{
    return [
        'recent' => 'Latest completed first',
        'oldest' => 'Oldest completed first',
        'service' => 'Service type A-Z',
        'requester' => 'Requester name A-Z',
    ];
}

function getManageRequestSortableDate(array $request): int
{
    $value = (string) ($request['completed_at'] ?? $request['approved_at'] ?? $request['submitted_at'] ?? '');
    $timestamp = strtotime($value);
    return $timestamp !== false ? $timestamp : 0;
}

function sortManageRequestApprovedItems(array $requests, string $sort): array
{
    usort($requests, static function (array $a, array $b) use ($sort): int {
        $serviceA = strtolower(getManageRequestServiceName($a));
        $serviceB = strtolower(getManageRequestServiceName($b));
        $requesterA = strtolower(trim((string) ($a['requested_by'] ?? $a['userName'] ?? $a['fullName'] ?? '')));
        $requesterB = strtolower(trim((string) ($b['requested_by'] ?? $b['userName'] ?? $b['fullName'] ?? '')));
        $dateA = getManageRequestSortableDate($a);
        $dateB = getManageRequestSortableDate($b);

        if ($sort === 'oldest') {
            return ($dateA <=> $dateB) ?: strcmp($serviceA, $serviceB);
        }

        if ($sort === 'service') {
            return strcmp($serviceA, $serviceB) ?: ($dateB <=> $dateA);
        }

        if ($sort === 'requester') {
            return strcmp($requesterA, $requesterB) ?: ($dateB <=> $dateA);
        }

        return ($dateB <=> $dateA) ?: strcmp($serviceA, $serviceB);
    });

    return $requests;
}

function buildManageRequestRedirectUrl(string $category = '', string $approvedSort = 'recent'): string
{
    $query = [];

    if ($category !== '') {
        $query['category'] = $category;
    }

    if ($approvedSort !== '' && $approvedSort !== 'recent') {
        $query['approved_sort'] = $approvedSort;
    }

    return 'manage_request.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function loadManageRequestData(string $dataFile): array
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents('service_requests');
        if (is_array($documents)) {
            $data = getManageRequestEmptyData();

            foreach ($documents as $document) {
                $request = normalizeManageRequestDoc($document);
                if ($request['contact'] === '' && $request['user_id'] !== '') {
                    $userContact = getUserContactById($request['user_id']);
                    if ($userContact !== '') {
                        $request['contact'] = $userContact;
                    }
                }

                $data[getManageRequestBucketForStatus((string) ($request['status'] ?? 'Pending'))][] = $request;
            }

            usort($data['pending'], static fn(array $a, array $b): int =>
                strcmp(getManageRequestServiceName($a), getManageRequestServiceName($b)) ?: strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? '')
            );
            usort($data['processing'], static fn(array $a, array $b): int =>
                strcmp(getManageRequestServiceName($a), getManageRequestServiceName($b)) ?: strcmp($b['processing_at'] ?? ($b['submitted_at'] ?? ''), $a['processing_at'] ?? ($a['submitted_at'] ?? ''))
            );
            usort($data['completed'], static fn(array $a, array $b): int =>
                strcmp(getManageRequestServiceName($a), getManageRequestServiceName($b)) ?: strcmp($b['completed_at'] ?? ($b['submitted_at'] ?? ''), $a['completed_at'] ?? ($a['submitted_at'] ?? ''))
            );
            usort($data['rejected'], static fn(array $a, array $b): int =>
                strcmp(getManageRequestServiceName($a), getManageRequestServiceName($b)) ?: strcmp($b['rejected_at'] ?? ($b['submitted_at'] ?? ''), $a['rejected_at'] ?? ($a['submitted_at'] ?? ''))
            );
            usort($data['cancelled'], static fn(array $a, array $b): int =>
                strcmp(getManageRequestServiceName($a), getManageRequestServiceName($b)) ?: strcmp($b['cancelled_at'] ?? ($b['submitted_at'] ?? ''), $a['cancelled_at'] ?? ($a['submitted_at'] ?? ''))
            );

            return $data;
        }

        global $firestoreError;
        $firestoreError = firebase_get_last_error() ?: 'Unable to load Firestore collection service_requests.';
        return getManageRequestEmptyData();
    }

    if (!file_exists($dataFile)) {
        $legacyFile = dirname($dataFile) . '/manage_posts_data.json';
        if (file_exists($legacyFile)) {
            $dataFile = $legacyFile;
        } else {
            return getManageRequestEmptyData();
        }
    }

    $raw = file_get_contents($dataFile);
    if ($raw === false || $raw === '') {
        return getManageRequestEmptyData();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return getManageRequestEmptyData();
    }

    return [
        'pending' => array_values($decoded['pending'] ?? []),
        'processing' => array_values($decoded['processing'] ?? []),
        'completed' => array_values($decoded['completed'] ?? $decoded['published'] ?? []),
        'rejected' => array_values($decoded['rejected'] ?? $decoded['declined'] ?? []),
        'cancelled' => array_values($decoded['cancelled'] ?? []),
    ];
}

function saveManageRequestData(string $dataFile, array $data): bool
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        return false;
    }

    if (firebase_enabled()) {
        if (firebase_put('manage_requests', $data)) {
            return true;
        }

        return firebase_put('manage_posts', $data);
    }

    return file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function findManageRequestDocument(array $data, int $requestId): ?array
{
    foreach (getManageRequestBuckets() as $bucket) {
        foreach ($data[$bucket] ?? [] as $request) {
            if ((int) ($request['id'] ?? 0) === $requestId) {
                return $request;
            }
        }
    }

    return null;
}

function findManageRequestDocumentByDocName(array $data, string $docName): ?array
{
    $docName = trim($docName);
    if ($docName === '') {
        return null;
    }

    foreach (getManageRequestBuckets() as $bucket) {
        foreach ($data[$bucket] ?? [] as $request) {
            if (($request['__name'] ?? '') === $docName) {
                return $request;
            }
        }
    }

    return null;
}

function deleteManageRequestHistoryItem(array &$data, string $bucket, int $requestId): bool
{
    if (!isset($data[$bucket]) || !is_array($data[$bucket])) {
        return false;
    }

    foreach ($data[$bucket] as $index => $request) {
        if ((int) ($request['id'] ?? 0) !== $requestId) {
            continue;
        }

        unset($data[$bucket][$index]);
        $data[$bucket] = array_values($data[$bucket]);
        return true;
    }

    return false;
}

function formatManageRequestDate(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(firebase_app_timezone())
            ->format('M d, Y h:i A');
    } catch (Exception $exception) {
        return $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = loadManageRequestData($dataFile);
    $action = $_POST['action'] ?? '';
    $flash = ['type' => 'error', 'text' => 'Unable to process the request.'];

    if ($action === 'submit_request') {
        $requesterName = trim($_POST['requester_name'] ?? '');
        $requesterContact = trim($_POST['requester_contact'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $content = trim($_POST['content'] ?? '');

        if ($requesterName === '' || $title === '' || $content === '') {
            $flash = ['type' => 'error', 'text' => 'Please complete the name, title, and message fields.'];
        } else {
            $newRequest = [
                'id' => getNextManageRequestId($data),
                'title' => $title,
                'serviceName' => $category !== '' ? $category : 'General',
                'category' => $category !== '' ? $category : 'General',
                'content' => $content,
                'requested_by' => $requesterName,
                'contact' => $requesterContact,
                'source' => 'User Request',
                'status' => 'Pending',
                'submitted_at' => firebase_now_string(),
            ];

            if (firebase_enabled() && firebase_firestore_enabled()) {
                $firestoreRequest = $newRequest;
                $firestoreRequest['submitted_at'] = new DateTimeImmutable('now', firebase_app_timezone());
                $created = firebase_firestore_create_document('service_requests', $firestoreRequest);
                $flash = $created !== null
                    ? ['type' => 'success', 'text' => 'Your request was sent to the admin for approval.']
                    : ['type' => 'error', 'text' => 'The request could not be saved to Firestore. Please try again.'];
            } else {
                $data['pending'][] = $newRequest;
                $flash = saveManageRequestData($dataFile, $data)
                    ? ['type' => 'success', 'text' => 'Your request was sent to the admin for approval.']
                    : ['type' => 'error', 'text' => 'The request could not be saved. Please try again.'];
            }
        }
    } elseif ($isAdmin && in_array($action, ['start_processing', 'complete_request', 'reject_request', 'cancel_request'], true)) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $docName = trim((string) ($_POST['doc_name'] ?? ''));
        $selectedRequest = null;

        if ($docName === '') {
            $selectedRequest = findManageRequestDocument($data, $requestId);
            $docName = $selectedRequest['__name'] ?? '';
        } else {
            $selectedRequest = findManageRequestDocumentByDocName($data, $docName);
        }

        if ($docName === '') {
            $flash = ['type' => 'error', 'text' => 'The selected request was not found.'];
        } elseif (firebase_enabled() && firebase_firestore_enabled()) {
            $now = new DateTimeImmutable('now', firebase_app_timezone());
            $requestLabel = trim((string) (getManageRequestServiceName(is_array($selectedRequest) ? $selectedRequest : []) ?: ($selectedRequest['title'] ?? 'request')));

            if ($action === 'start_processing') {
                $payload = [
                    'status' => 'Processing',
                    'processing_at' => $now,
                    'processing_by' => 'Admin',
                ];
                $successText = 'The user request is now marked as processing.';
                $errorText = 'The request could not be moved to processing. Please try again.';
                $notificationTitle = 'Request Processing';
                $notificationMessage = 'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' is now being processed.';
            } elseif ($action === 'complete_request') {
                $payload = [
                    'status' => 'Completed',
                    'completed_at' => $now,
                    'completed_by' => 'Admin',
                    'approved_at' => $now,
                    'approved_by' => 'Admin',
                ];
                $successText = 'The user request was completed successfully.';
                $errorText = 'The request could not be completed. Please try again.';
                $notificationTitle = 'Request Completed';
                $notificationMessage = 'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' has been completed.';
            } elseif ($action === 'cancel_request') {
                $payload = [
                    'status' => 'Cancelled',
                    'cancelled_at' => $now,
                ];
                $successText = 'The user request was cancelled successfully.';
                $errorText = 'The request could not be cancelled. Please try again.';
                $notificationTitle = 'Request Cancelled';
                $notificationMessage = 'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' has been cancelled.';
            } else {
                $payload = [
                    'status' => 'Rejected',
                    'rejected_at' => $now,
                    'declined_at' => $now,
                ];
                $successText = 'The user request was rejected successfully.';
                $errorText = 'The request could not be rejected. Please try again.';
                $notificationTitle = 'Request Rejected';
                $notificationMessage = 'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' has been rejected.';
            }

            $success = firebase_firestore_patch_document($docName, $payload);
            if ($success && is_array($selectedRequest) && ($selectedRequest['user_id'] ?? '') !== '') {
                firebase_create_notification(
                    (string) $selectedRequest['user_id'],
                    $notificationTitle,
                    $notificationMessage
                );
            }

            $flash = $success
                ? ['type' => 'success', 'text' => $successText]
                : ['type' => 'error', 'text' => $errorText];
        } else {
            $selectedBucket = null;
            $selectedIndex = null;

            foreach (getManageRequestBuckets() as $bucket) {
                foreach ($data[$bucket] as $index => $item) {
                    if ((int) ($item['id'] ?? 0) === $requestId) {
                        $selectedBucket = $bucket;
                        $selectedIndex = $index;
                        break 2;
                    }
                }
            }

            if ($selectedBucket === null || $selectedIndex === null) {
                $flash = ['type' => 'error', 'text' => 'The selected request was not found.'];
            } else {
                $selectedRequest = $data[$selectedBucket][$selectedIndex];
                unset($data[$selectedBucket][$selectedIndex]);
                $data[$selectedBucket] = array_values($data[$selectedBucket]);
                $requestLabel = trim((string) (getManageRequestServiceName($selectedRequest) ?: ($selectedRequest['title'] ?? 'request')));

                if ($action === 'start_processing') {
                    $selectedRequest['status'] = 'Processing';
                    $selectedRequest['processing_at'] = firebase_now_string();
                    $selectedRequest['processing_by'] = 'Admin';
                    array_unshift($data['processing'], $selectedRequest);

                    $flash = saveManageRequestData($dataFile, $data)
                        ? ['type' => 'success', 'text' => 'The user request is now marked as processing.']
                        : ['type' => 'error', 'text' => 'The request could not be moved to processing. Please try again.'];
                    if ($flash['type'] === 'success' && ($selectedRequest['user_id'] ?? '') !== '') {
                        firebase_create_notification(
                            (string) $selectedRequest['user_id'],
                            'Request Processing',
                            'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' is now being processed.'
                        );
                    }
                } elseif ($action === 'complete_request') {
                    $selectedRequest['status'] = 'Completed';
                    $selectedRequest['completed_at'] = firebase_now_string();
                    $selectedRequest['completed_by'] = 'Admin';
                    $selectedRequest['approved_at'] = $selectedRequest['completed_at'];
                    $selectedRequest['approved_by'] = 'Admin';
                    array_unshift($data['completed'], $selectedRequest);

                    $flash = saveManageRequestData($dataFile, $data)
                        ? ['type' => 'success', 'text' => 'The user request was completed successfully.']
                        : ['type' => 'error', 'text' => 'The request could not be completed. Please try again.'];
                    if ($flash['type'] === 'success' && ($selectedRequest['user_id'] ?? '') !== '') {
                        firebase_create_notification(
                            (string) $selectedRequest['user_id'],
                            'Request Completed',
                            'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' has been completed.'
                        );
                    }
                } elseif ($action === 'cancel_request') {
                    $selectedRequest['status'] = 'Cancelled';
                    $selectedRequest['cancelled_at'] = firebase_now_string();
                    array_unshift($data['cancelled'], $selectedRequest);

                    $flash = saveManageRequestData($dataFile, $data)
                        ? ['type' => 'success', 'text' => 'The user request was cancelled successfully.']
                        : ['type' => 'error', 'text' => 'The request could not be cancelled. Please try again.'];
                    if ($flash['type'] === 'success' && ($selectedRequest['user_id'] ?? '') !== '') {
                        firebase_create_notification(
                            (string) $selectedRequest['user_id'],
                            'Request Cancelled',
                            'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' has been cancelled.'
                        );
                    }
                } else {
                    $selectedRequest['status'] = 'Rejected';
                    $selectedRequest['rejected_at'] = firebase_now_string();
                    $selectedRequest['declined_at'] = $selectedRequest['rejected_at'];
                    array_unshift($data['rejected'], $selectedRequest);

                    $flash = saveManageRequestData($dataFile, $data)
                        ? ['type' => 'success', 'text' => 'The user request was rejected successfully.']
                        : ['type' => 'error', 'text' => 'The request could not be rejected. Please try again.'];
                    if ($flash['type'] === 'success' && ($selectedRequest['user_id'] ?? '') !== '') {
                        firebase_create_notification(
                            (string) $selectedRequest['user_id'],
                            'Request Rejected',
                            'Your ' . ($requestLabel !== '' ? $requestLabel : 'request') . ' has been rejected.'
                        );
                    }
                }
            }
        }
    } elseif ($isAdmin && $action === 'delete_request_history') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $docName = trim((string) ($_POST['doc_name'] ?? ''));
        $historyBucket = trim((string) ($_POST['history_bucket'] ?? ''));
        $allowedBuckets = ['completed', 'rejected', 'cancelled'];

        if (!in_array($historyBucket, $allowedBuckets, true)) {
            $flash = ['type' => 'error', 'text' => 'The selected history item was not found.'];
        } elseif (firebase_enabled() && firebase_firestore_enabled()) {
            if ($docName === '') {
                $selectedRequest = findManageRequestDocument($data, $requestId);
                $docName = $selectedRequest['__name'] ?? '';
            }

            if ($docName === '') {
                $flash = ['type' => 'error', 'text' => 'The selected history item was not found.'];
            } else {
                $success = firebase_firestore_delete_document($docName);
                $flash = $success
                    ? ['type' => 'success', 'text' => 'The request history was deleted successfully.']
                    : ['type' => 'error', 'text' => firebase_get_last_error() ?? 'The request history could not be deleted.'];
            }
        } else {
            $deleted = deleteManageRequestHistoryItem($data, $historyBucket, $requestId);
            if (!$deleted) {
                $flash = ['type' => 'error', 'text' => 'The selected history item was not found.'];
            } else {
                $flash = saveManageRequestData($dataFile, $data)
                    ? ['type' => 'success', 'text' => 'The request history was deleted successfully.']
                    : ['type' => 'error', 'text' => 'The request history could not be deleted.'];
            }
        }
    }

    $_SESSION['manage_request_flash'] = $flash;
    $redirectCategory = trim((string) ($_POST['redirect_category'] ?? ''));
    $redirectApprovedSort = trim((string) ($_POST['redirect_approved_sort'] ?? 'recent'));
    header('Location: ' . buildManageRequestRedirectUrl($redirectCategory, $redirectApprovedSort));
    exit;
}

$selectedCategory = trim($_GET['category'] ?? '');
$approvedSortOptions = getManageRequestApprovedSortOptions();
$selectedApprovedSort = trim((string) ($_GET['approved_sort'] ?? 'recent'));
if (!isset($approvedSortOptions[$selectedApprovedSort])) {
    $selectedApprovedSort = 'recent';
}

$data = loadManageRequestData($dataFile);
$allCategories = getManageRequestCategories($data);
if ($selectedCategory !== '') {
    $data = filterManageRequestDataByCategory($data, $selectedCategory);
}
if ($isAdmin) {
    $data['completed'] = sortManageRequestApprovedItems($data['completed'], $selectedApprovedSort);
}

$flash = $_SESSION['manage_request_flash'] ?? null;
unset($_SESSION['manage_request_flash']);

if ($isAdmin) {
    ob_start();
    ?>
    <?php if ($flash): ?>
        <div class="alert-box <?php echo $flash['type'] === 'error' ? 'alert-error' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($firestoreError)): ?>
        <div class="alert-box alert-error">
            Firebase error: <?php echo htmlspecialchars($firestoreError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Pending</h3>
            <div class="stat-value"><?php echo count($data['pending']); ?></div>
            <p>New requests that still block repeat submissions</p>
        </div>
        <div class="stat-card">
            <h3>Processing</h3>
            <div class="stat-value"><?php echo count($data['processing']); ?></div>
            <p>Requests currently being handled by the admin</p>
        </div>
        <div class="stat-card">
            <h3>Completed</h3>
            <div class="stat-value"><?php echo count($data['completed']); ?></div>
            <p>Requests finalized and no longer blocking the user</p>
        </div>
        <div class="stat-card">
            <h3>Rejected</h3>
            <div class="stat-value"><?php echo count($data['rejected']); ?></div>
            <p>Requests closed by rejection</p>
        </div>
        <div class="stat-card">
            <h3>Cancelled</h3>
            <div class="stat-value"><?php echo count($data['cancelled']); ?></div>
            <p>Requests closed by cancellation</p>
        </div>
    </div>

    <div class="filter-row">
        <form method="get" class="filter-form">
            <label for="category-filter">Filter by service type:</label>
            <select id="category-filter" name="category" onchange="this.form.submit()">
                <option value="">All Services</option>
                <?php foreach ($allCategories as $categoryOption): ?>
                    <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedCategory === $categoryOption ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="approved-sort">Sort completed by:</label>
            <select id="approved-sort" name="approved_sort" onchange="this.form.submit()">
                <?php foreach ($approvedSortOptions as $sortValue => $sortLabel): ?>
                    <option value="<?php echo htmlspecialchars($sortValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedApprovedSort === $sortValue ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sortLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <h3 style="margin: 26px 0 10px;">Pending User Requests</h3>

    <?php if (empty($data['pending'])): ?>
        <div class="empty-state">No pending requests right now.</div>
    <?php else: ?>
        <div class="request-list">
            <?php foreach ($data['pending'] as $request): ?>
                <?php $statusMeta = getManageRequestStatusMeta((string) ($request['status'] ?? 'Pending')); ?>
                <div class="request-card">
                    <div class="request-top">
                        <div>
                            <h3><?php echo htmlspecialchars(getManageRequestServiceName($request), ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="meta-text">
                                Submitted by <strong><?php echo htmlspecialchars($request['requested_by'] ?? 'Unknown User', ENT_QUOTES, 'UTF-8'); ?></strong>
                                • <?php echo htmlspecialchars(formatManageRequestDate($request['submitted_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                        <span class="status-pill <?php echo htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <p><?php echo nl2br(htmlspecialchars($request['content'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>

                    <div class="request-meta-row">
                        <span><strong>Service:</strong> <?php echo htmlspecialchars(getManageRequestServiceName($request), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><strong>Contact:</strong> <?php echo htmlspecialchars($request['contact'] ?? 'Not provided', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <div class="button-row">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="start_processing">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn primary">Start Processing</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="reject_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn secondary">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 style="margin: 26px 0 10px;">Processing Requests</h3>
    <?php if (empty($data['processing'])): ?>
        <div class="empty-state">No requests are currently being processed.</div>
    <?php else: ?>
        <div class="request-list">
            <?php foreach ($data['processing'] as $request): ?>
                <?php $statusMeta = getManageRequestStatusMeta((string) ($request['status'] ?? 'Processing')); ?>
                <div class="request-card">
                    <div class="request-top">
                        <div>
                            <h3><?php echo htmlspecialchars(getManageRequestServiceName($request), ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="meta-text">
                                Submitted by <strong><?php echo htmlspecialchars($request['requested_by'] ?? 'Unknown User', ENT_QUOTES, 'UTF-8'); ?></strong>
                                • <?php echo htmlspecialchars(formatManageRequestDate($request['processing_at'] ?? ($request['submitted_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                        <span class="status-pill <?php echo htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <p><?php echo nl2br(htmlspecialchars($request['content'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>

                    <div class="request-meta-row">
                        <span><strong>Service:</strong> <?php echo htmlspecialchars(getManageRequestServiceName($request), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><strong>Contact:</strong> <?php echo htmlspecialchars($request['contact'] ?? 'Not provided', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <div class="button-row">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="complete_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn primary">Complete</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="reject_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn secondary">Reject</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="cancel_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn secondary">Cancel</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 style="margin: 26px 0 10px;">Completed Requests</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Submitted By</th>
                    <th>Contact</th>
                    <th>Completed On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['completed'])): ?>
                    <tr>
                        <td colspan="5">No completed requests yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['completed'] as $post): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(getManageRequestServiceName($post), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($post['requested_by'] ?? $post['userName'] ?? $post['fullName'] ?? 'Unknown User', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($post['contact'] ?? $post['mobileNumber'] ?? $post['userEmail'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(formatManageRequestDate($post['completed_at'] ?? ($post['approved_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this request history?');">
                                    <input type="hidden" name="action" value="delete_request_history">
                                    <input type="hidden" name="history_bucket" value="completed">
                                    <input type="hidden" name="request_id" value="<?php echo (int) ($post['id'] ?? 0); ?>">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($post['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="action-btn secondary">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h3 style="margin: 26px 0 10px;">Rejected Requests</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Submitted By</th>
                    <th>Contact</th>
                    <th>Rejected On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['rejected'])): ?>
                    <tr>
                        <td colspan="5">No rejected requests yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['rejected'] as $post): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(getManageRequestServiceName($post), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($post['requested_by'] ?? $post['userName'] ?? $post['fullName'] ?? 'Unknown User', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($post['contact'] ?? $post['mobileNumber'] ?? $post['userEmail'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(formatManageRequestDate($post['rejected_at'] ?? ($post['declined_at'] ?? ($post['submitted_at'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this request history?');">
                                    <input type="hidden" name="action" value="delete_request_history">
                                    <input type="hidden" name="history_bucket" value="rejected">
                                    <input type="hidden" name="request_id" value="<?php echo (int) ($post['id'] ?? 0); ?>">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($post['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="action-btn secondary">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h3 style="margin: 26px 0 10px;">Cancelled Requests</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Submitted By</th>
                    <th>Contact</th>
                    <th>Cancelled On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['cancelled'])): ?>
                    <tr>
                        <td colspan="5">No cancelled requests yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['cancelled'] as $post): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(getManageRequestServiceName($post), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($post['requested_by'] ?? $post['userName'] ?? $post['fullName'] ?? 'Unknown User', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($post['contact'] ?? $post['mobileNumber'] ?? $post['userEmail'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(formatManageRequestDate($post['cancelled_at'] ?? ($post['submitted_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this request history?');">
                                    <input type="hidden" name="action" value="delete_request_history">
                                    <input type="hidden" name="history_bucket" value="cancelled">
                                    <input type="hidden" name="request_id" value="<?php echo (int) ($post['id'] ?? 0); ?>">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($post['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="redirect_category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="action-btn secondary">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    $contentHtml = ob_get_clean();

    renderAdminPage('manage_request', 'Manage Requests', 'Track service requests from pending to processing to completion so repeat submissions stay blocked only while a request is active.', $contentHtml);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Request | Smart Iba</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: #1f2937;
        }

        .public-shell {
            max-width: 1080px;
            margin: 0 auto;
            padding: 28px 18px 40px;
        }

        .public-hero {
            background: linear-gradient(135deg, #2563eb, #38bdf8);
            color: #ffffff;
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 14px 30px rgba(37, 99, 235, 0.18);
        }

        .public-hero h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }

        .public-hero p {
            margin: 0;
            max-width: 700px;
            line-height: 1.6;
        }

        .hero-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .hero-link {
            display: inline-block;
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 700;
        }

        .hero-link.primary {
            background: #ffffff;
            color: #1d4ed8;
        }

        .hero-link.secondary {
            background: rgba(15, 23, 42, 0.18);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .public-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 18px;
            margin-top: 20px;
        }

        .public-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        .public-card h2 {
            margin-top: 0;
        }

        .stack-form {
            display: grid;
            gap: 12px;
        }

        .field-label {
            font-weight: 700;
            font-size: 14px;
            color: #1e3a8a;
        }

        .input-field,
        .select-field,
        .text-area {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font: inherit;
        }

        .text-area {
            min-height: 120px;
            resize: vertical;
        }

        .submit-btn {
            border: none;
            border-radius: 10px;
            background: #2563eb;
            color: #ffffff;
            padding: 12px 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .alert-box {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-weight: 700;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
        }

        .alert-error {
            background: #fee2e2;
            color: #b91c1c;
        }

        .public-post {
            border: 1px solid #dbeafe;
            background: #f8fbff;
            border-radius: 14px;
            padding: 14px;
            margin-top: 12px;
        }

        .public-post h3 {
            margin: 0 0 6px;
        }

        .meta-text {
            margin: 0 0 8px;
            font-size: 13px;
            color: #475569;
        }

        .empty-state {
            background: #eff6ff;
            border: 1px dashed #93c5fd;
            border-radius: 12px;
            padding: 14px;
            color: #1d4ed8;
        }
    </style>
</head>
<body>
    <div class="public-shell">
        <div class="public-hero">
            <h1>Submit a Post Request</h1>
            <p>Send your announcement, event, or public update to the admin. It will stay blocked for duplicates until the admin finishes the request.</p>
            <div class="hero-actions">
                <a href="login.php" class="hero-link primary">Admin Login</a>
                <a href="index.php" class="hero-link secondary">Dashboard</a>
            </div>
        </div>

        <div class="public-grid">
            <div class="public-card">
                <h2>User Request Form</h2>
                <p>Complete the form below to send your post request to the admin.</p>

                <?php if ($flash): ?>
                    <div class="alert-box <?php echo $flash['type'] === 'error' ? 'alert-error' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="stack-form">
                    <input type="hidden" name="action" value="submit_request">

                    <label class="field-label" for="requester_name">Your name</label>
                    <input class="input-field" type="text" id="requester_name" name="requester_name" placeholder="Enter your full name" required>

                    <label class="field-label" for="requester_contact">Contact details</label>
                    <input class="input-field" type="text" id="requester_contact" name="requester_contact" placeholder="Email or phone number">

                    <label class="field-label" for="public_title">Post title</label>
                    <input class="input-field" type="text" id="public_title" name="title" placeholder="Enter post title" required>

                    <label class="field-label" for="public_category">Category</label>
                    <select class="select-field" id="public_category" name="category">
                        <option value="Announcement">Announcement</option>
                        <option value="News">News</option>
                        <option value="Event">Event</option>
                        <option value="Notice">Notice</option>
                        <option value="General">General</option>
                    </select>

                    <label class="field-label" for="public_content">Message</label>
                    <textarea class="text-area" id="public_content" name="content" placeholder="Write the full details of your request" required></textarea>

                    <button type="submit" class="submit-btn">Send Request</button>
                </form>
            </div>

            <div class="public-card">
                <h2>Recently Completed Requests</h2>
                <p>These are the latest user requests that have already been finalized.</p>

                <?php if (empty($data['completed'])): ?>
                    <div class="empty-state">No completed requests yet. Newly finalized requests will appear here.</div>
                <?php else: ?>
                    <?php foreach (array_slice($data['completed'], 0, 5) as $post): ?>
                        <div class="public-post">
                            <h3><?php echo htmlspecialchars($post['title'] ?? 'Untitled Post', ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="meta-text">
                                <?php echo htmlspecialchars(getManageRequestServiceName($post) ?: 'General', ENT_QUOTES, 'UTF-8'); ?>
                                • <?php echo htmlspecialchars(formatManageRequestDate($post['completed_at'] ?? ($post['approved_at'] ?? ($post['submitted_at'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>
                                • <?php echo htmlspecialchars($post['source'] ?? 'Completed', ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p><?php echo nl2br(htmlspecialchars($post['content'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
