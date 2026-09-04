<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

function loadJsonCount(string $filePath, string $key): int
{
    if (!file_exists($filePath)) {
        return 0;
    }

    $raw = file_get_contents($filePath);
    if ($raw === false) {
        return 0;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded[$key]) || !is_array($decoded[$key])) {
        return 0;
    }

    return count($decoded[$key]);
}


function loadOfficeDirectoryCount(string $dataFile, array $collections): int
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $count = 0;
        $loaded = false;

        foreach ($collections as $collection) {
            $documents = firebase_firestore_list_documents($collection);
            if (!is_array($documents)) {
                continue;
            }

            $loaded = true;
            $count += count($documents);
        }

        if ($loaded) {
            return $count;
        }
    }

    return loadJsonCount($dataFile, 'offices');
}

function loadCollectionCount(string $collection, string $dataFile, string $key): int
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents($collection);
        if (is_array($documents)) {
            return count($documents);
        }
    }

    return loadJsonCount($dataFile, $key);
}

function normalizeDashboardManageRequestStatus($status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        '', 'pending' => 'pending',
        'processing', 'in_progress', 'in progress' => 'processing',
        default => $normalized,
    };
}

function loadDashboardManageRequestCount(string $dataFile, array $collections): int
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $count = 0;
        $loaded = false;

        foreach ($collections as $collection) {
            $documents = firebase_firestore_list_documents($collection);
            if (!is_array($documents)) {
                continue;
            }

            $loaded = true;
            foreach ($documents as $document) {
                $status = normalizeDashboardManageRequestStatus($document['status'] ?? '');
                if ($status === 'pending' || $status === 'processing') {
                    $count++;
                }
            }
        }

        if ($loaded) {
            return $count;
        }
    }

    return loadJsonCount($dataFile, 'pending') + loadJsonCount($dataFile, 'processing');
}

function loadDashboardEventCount(string $dataFile, array $collections): int
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $count = 0;
        $loaded = false;

        foreach ($collections as $collection) {
            $documents = firebase_firestore_list_documents($collection);
            if (!is_array($documents)) {
                continue;
            }

            $loaded = true;
            $count += count($documents);
        }

        if ($loaded) {
            return $count;
        }
    }

    return loadJsonCount($dataFile, 'events');
}

function normalizeDashboardScholarshipStatus($status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        '', 'pending' => 'pending',
        default => $normalized,
    };
}

function loadDashboardScholarshipPendingCount(string $dataFile, array $collections): int
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $count = 0;
        $loaded = false;

        foreach ($collections as $collection) {
            $documents = firebase_firestore_list_documents($collection);
            if (!is_array($documents)) {
                continue;
            }

            $loaded = true;
            foreach ($documents as $document) {
                $status = normalizeDashboardScholarshipStatus($document['status'] ?? '');
                if ($status === 'pending') {
                    $count++;
                }
            }
        }

        if ($loaded) {
            return $count;
        }
    }

    return loadJsonCount($dataFile, 'pending');
}

function loadUserCount(): int
{
    if (firebase_enabled()) {
        return count(firebase_list_user_ids());
    }

    return 0;
}

$manageRequestCount = loadDashboardManageRequestCount(
    __DIR__ . '/includes/manage_requests_data.json',
    ['service_requests', 'manage_requests']
);
$scholarshipPendingCount = loadDashboardScholarshipPendingCount(
    __DIR__ . '/includes/scholarship_requests_data.json',
    ['scholarship_submissions', 'scholarship_requests']
);
$officeDirectoryCount = loadOfficeDirectoryCount(__DIR__ . '/includes/office_directory_data.json', ['office', 'office_directory']);
$eventCount = loadDashboardEventCount(__DIR__ . '/includes/events_data.json', ['events', 'event']);
$newsCount = loadCollectionCount('news', __DIR__ . '/includes/news_data.json', 'news');
$userCount = loadUserCount();
$reportsCount = 0;
if (firebase_enabled() && firebase_firestore_enabled()) {
    $documents = firebase_firestore_list_documents('reports');
    if (is_array($documents)) {
        $reportsCount = count($documents);
    }
}

ob_start();
?>
<div class="stats-grid">
    <div class="stat-card">
        <h3>Users</h3>
        <div class="stat-value"><?php echo $userCount; ?></div>
        <p>Registered user accounts</p>
    </div>
    <div class="stat-card">
        <h3>Services</h3>
        <div class="stat-value"><?php echo $manageRequestCount; ?></div>
        <p>Active request entries</p>
    </div>
    <div class="stat-card">
        <h3>Scholarship</h3>
        <div class="stat-value"><?php echo $scholarshipPendingCount; ?></div>
        <p>Pending scholarship requests</p>
    </div>
    <div class="stat-card">
        <h3>Office Directory</h3>
        <div class="stat-value"><?php echo $officeDirectoryCount; ?></div>
        <p>Updated office contacts</p>
    </div>
    <div class="stat-card">
        <h3>Event</h3>
        <div class="stat-value"><?php echo $eventCount; ?></div>
        <p>Scheduled events for users to see</p>
    </div>
    <div class="stat-card">
        <h3>News</h3>
        <div class="stat-value"><?php echo $newsCount; ?></div>
        <p>Published news articles for users</p>
    </div>
    <div class="stat-card">
        <h3>Reports</h3>
        <div class="stat-value"><?php echo $reportsCount; ?></div>
        <p>Citizen reports received by the LGU</p>
    </div>
</div>

<div class="quick-grid">
    <div class="quick-card">
        <h3>Open Users</h3>
        <p>Review user profiles, activity status, and contact details.</p>
        <a href="users.php" class="action-link">Go to page</a>
    </div>
    <div class="quick-card">
        <h3>Open Manage Requests</h3>
        <p>Create or review announcement and user request updates.</p>
        <a href="manage_request.php" class="action-link">Go to page</a>
    </div>
    <div class="quick-card">
        <h3>Open Scholarship</h3>
        <p>Check scholarship listings, status, and schedules.</p>
        <a href="scholarship.php" class="action-link">Go to page</a>
    </div>
    <div class="quick-card">
        <h3>Open Office Directory</h3>
        <p>View office names, contact details, and locations.</p>
        <a href="office_directory.php" class="action-link">Go to page</a>
    </div>
    <div class="quick-card">
        <h3>Open Event</h3>
        <p>Create, update, or delete events shown to users.</p>
        <a href="event.php" class="action-link">Go to page</a>
    </div>
    <div class="quick-card">
        <h3>Open News</h3>
        <p>Create, update, or delete news articles shown to users.</p>
        <a href="news.php" class="action-link">Go to page</a>
    </div>
    <div class="quick-card">
        <h3>Open Reports</h3>
        <p>Create, update, or delete reports submitted by citizens.</p>
        <a href="reports.php" class="action-link">Go to page</a>
    </div>
</div>
<?php
$contentHtml = ob_get_clean();

renderAdminPage('dashboard', 'Dashboard', 'Overview of the admin sections and quick links.', $contentHtml);
