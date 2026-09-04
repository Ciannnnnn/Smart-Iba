<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

$dataFile = __DIR__ . '/includes/office_directory_data.json';
$officeCollections = ['office', 'office_directory'];

function getOfficeDirectoryJsonDefault(): array
{
    return ['offices' => []];
}

function loadOfficeDirectoryJsonData(string $dataFile): array
{
    $default = getOfficeDirectoryJsonDefault();

    if (!file_exists($dataFile)) {
        return $default;
    }

    $raw = file_get_contents($dataFile);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }

    return [
        'offices' => array_values($decoded['offices'] ?? []),
    ];
}

function saveOfficeDirectoryJsonData(string $dataFile, array $data): bool
{
    return file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function officeDirectoryFirstNonEmptyString(...$values): string
{
    foreach ($values as $value) {
        if (!is_scalar($value)) {
            continue;
        }

        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

function normalizeOfficeDirectoryDoc(array $doc): array
{
    return [
        'name' => officeDirectoryFirstNonEmptyString($doc['name'] ?? '', $doc['office_name'] ?? ''),
        'mobile' => officeDirectoryFirstNonEmptyString($doc['mobile'] ?? '', $doc['phone'] ?? '', $doc['phoneNumber'] ?? ''),
        'email' => officeDirectoryFirstNonEmptyString($doc['email'] ?? '', $doc['office_email'] ?? ''),
        'location' => officeDirectoryFirstNonEmptyString($doc['location'] ?? '', $doc['address'] ?? ''),
        'availability' => officeDirectoryFirstNonEmptyString($doc['availability'] ?? '', $doc['status'] ?? ''),
        'created_at' => officeDirectoryFirstNonEmptyString($doc['created_at'] ?? '', $doc['createdAt'] ?? ''),
        '__name' => $doc['__name'] ?? '',
    ];
}

function normalizeOfficeDirectoryRow(array $office, string $docName): array
{
    $office['__name'] = $docName;
    return normalizeOfficeDirectoryDoc($office);
}

function sortOfficeDirectoryEntries(array $offices): array
{
    usort($offices, static function (array $a, array $b): int {
        $nameComparison = strcmp(
            strtolower(trim((string) ($a['name'] ?? ''))),
            strtolower(trim((string) ($b['name'] ?? '')))
        );

        if ($nameComparison !== 0) {
            return $nameComparison;
        }

        return strcmp(
            strtolower(trim((string) ($a['location'] ?? ''))),
            strtolower(trim((string) ($b['location'] ?? '')))
        );
    });

    return $offices;
}

function loadOfficeDirectoryEntries(string $dataFile, array $officeCollections): array
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $entries = [];
        $loaded = false;

        foreach ($officeCollections as $collection) {
            $documents = firebase_firestore_list_documents($collection);
            if (!is_array($documents)) {
                continue;
            }

            $loaded = true;
            foreach ($documents as $document) {
                $entries[] = normalizeOfficeDirectoryDoc($document);
            }
        }

        if ($loaded) {
            return sortOfficeDirectoryEntries($entries);
        }
    }

    $jsonData = loadOfficeDirectoryJsonData($dataFile);
    $entries = [];
    foreach (array_values($jsonData['offices'] ?? []) as $index => $office) {
        $entries[] = normalizeOfficeDirectoryRow((array) $office, 'local:' . $index);
    }

    return sortOfficeDirectoryEntries($entries);
}

function findOfficeDirectoryByDocName(array $offices, string $docName): ?array
{
    foreach ($offices as $office) {
        if (($office['__name'] ?? '') === $docName) {
            return $office;
        }
    }

    return null;
}

function getLocalOfficeDirectoryIndex(string $docName): ?int
{
    if (strpos($docName, 'local:') !== 0) {
        return null;
    }

    $index = substr($docName, 6);
    return ctype_digit($index) ? (int) $index : null;
}

function officeDirectoryAvailabilityClass(string $availability): string
{
    return trim($availability) === '' ? 'status-review' : 'status-active';
}

function buildOfficeDirectoryRedirectUrl(string $editingDocName = ''): string
{
    if ($editingDocName === '') {
        return 'office_directory.php';
    }

    return 'office_directory.php?edit_office=' . urlencode($editingDocName);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $docName = trim((string) ($_POST['office_doc_name'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $availability = trim((string) ($_POST['availability'] ?? ''));

    $flash = ['type' => 'error', 'text' => 'Unable to process the office directory request.'];
    $redirectDocName = '';
    $offices = loadOfficeDirectoryEntries($dataFile, $officeCollections);

    if ($action === 'create_office' || $action === 'update_office') {
        if ($name === '' || $mobile === '' || $email === '' || $location === '') {
            $flash = ['type' => 'error', 'text' => 'Please complete the office name, mobile, email, and location fields.'];
            $redirectDocName = $action === 'update_office' ? $docName : '';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type' => 'error', 'text' => 'Please enter a valid email address for the office.'];
            $redirectDocName = $action === 'update_office' ? $docName : '';
        } else {
            $payload = [
                'name' => $name,
                'mobile' => $mobile,
                'email' => $email,
                'location' => $location,
                'availability' => $availability,
            ];

            if ($action === 'create_office') {
                $payload['created_at'] = firebase_now_string();

                if (firebase_enabled() && firebase_firestore_enabled()) {
                    $created = firebase_firestore_create_document($officeCollections[0], $payload);
                    $flash = $created !== null
                        ? ['type' => 'success', 'text' => 'Office directory entry created successfully.']
                        : ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The office directory entry could not be created.'];
                } else {
                    $jsonData = loadOfficeDirectoryJsonData($dataFile);
                    $jsonData['offices'][] = $payload;
                    $flash = saveOfficeDirectoryJsonData($dataFile, $jsonData)
                        ? ['type' => 'success', 'text' => 'Office directory entry created successfully.']
                        : ['type' => 'error', 'text' => 'The office directory entry could not be saved.'];
                }
            } else {
                $existingOffice = findOfficeDirectoryByDocName($offices, $docName);
                if ($existingOffice === null) {
                    $flash = ['type' => 'error', 'text' => 'The selected office entry was not found.'];
                } elseif (firebase_enabled() && firebase_firestore_enabled() && getLocalOfficeDirectoryIndex($docName) === null) {
                    $success = firebase_firestore_patch_document($docName, $payload);
                    $flash = $success
                        ? ['type' => 'success', 'text' => 'Office directory entry updated successfully.']
                        : ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The office directory entry could not be updated.'];
                    $redirectDocName = !$success ? $docName : '';
                } else {
                    $jsonData = loadOfficeDirectoryJsonData($dataFile);
                    $localIndex = getLocalOfficeDirectoryIndex($docName);

                    if ($localIndex === null || !isset($jsonData['offices'][$localIndex])) {
                        $flash = ['type' => 'error', 'text' => 'The selected office entry was not found.'];
                    } else {
                        $jsonData['offices'][$localIndex] = array_merge(
                            (array) $jsonData['offices'][$localIndex],
                            $payload
                        );

                        $flash = saveOfficeDirectoryJsonData($dataFile, $jsonData)
                            ? ['type' => 'success', 'text' => 'Office directory entry updated successfully.']
                            : ['type' => 'error', 'text' => 'The office directory entry could not be updated.'];

                        $redirectDocName = $flash['type'] === 'error' ? $docName : '';
                    }
                }
            }
        }
    } elseif ($action === 'delete_office') {
        $existingOffice = findOfficeDirectoryByDocName($offices, $docName);

        if ($existingOffice === null) {
            $flash = ['type' => 'error', 'text' => 'The selected office entry was not found.'];
        } elseif (firebase_enabled() && firebase_firestore_enabled() && getLocalOfficeDirectoryIndex($docName) === null) {
            $success = firebase_firestore_delete_document($docName);
            $flash = $success
                ? ['type' => 'success', 'text' => 'Office directory entry deleted successfully.']
                : ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The office directory entry could not be deleted.'];
        } else {
            $jsonData = loadOfficeDirectoryJsonData($dataFile);
            $localIndex = getLocalOfficeDirectoryIndex($docName);

            if ($localIndex === null || !isset($jsonData['offices'][$localIndex])) {
                $flash = ['type' => 'error', 'text' => 'The selected office entry was not found.'];
            } else {
                array_splice($jsonData['offices'], $localIndex, 1);
                $flash = saveOfficeDirectoryJsonData($dataFile, $jsonData)
                    ? ['type' => 'success', 'text' => 'Office directory entry deleted successfully.']
                    : ['type' => 'error', 'text' => 'The office directory entry could not be deleted.'];
            }
        }
    }

    $_SESSION['office_directory_flash'] = $flash;
    header('Location: ' . buildOfficeDirectoryRedirectUrl($redirectDocName));
    exit;
}

$offices = loadOfficeDirectoryEntries($dataFile, $officeCollections);
$flash = $_SESSION['office_directory_flash'] ?? null;
unset($_SESSION['office_directory_flash']);

$editingDocName = trim((string) ($_GET['edit_office'] ?? ''));
$editingOffice = $editingDocName !== '' ? findOfficeDirectoryByDocName($offices, $editingDocName) : null;
$isEditingOffice = is_array($editingOffice);

$withScheduleCount = 0;
$missingAvailabilityCount = 0;

foreach ($offices as $office) {
    $availability = (string) ($office['availability'] ?? '');

    if (trim($availability) !== '') {
        $withScheduleCount++;
    }

    if (trim($availability) === '') {
        $missingAvailabilityCount++;
    }
}

ob_start();
?>
<?php if ($flash): ?>
    <div class="alert-box <?php echo $flash['type'] === 'error' ? 'alert-error' : 'alert-success'; ?>">
        <?php echo htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Offices</h3>
        <div class="stat-value"><?php echo count($offices); ?></div>
        <p>Office directory entries managed by the admin</p>
    </div>
    <div class="stat-card">
        <h3>With Schedule</h3>
        <div class="stat-value"><?php echo $withScheduleCount; ?></div>
        <p>Entries with office hours like 7:00 AM - 7:00 PM</p>
    </div>
    <div class="stat-card">
        <h3>Needs Schedule</h3>
        <div class="stat-value"><?php echo $missingAvailabilityCount; ?></div>
        <p>Entries that still have no office hours value</p>
    </div>
</div>

<h3 style="margin: 26px 0 10px;"><?php echo $isEditingOffice ? 'Update Office Entry' : 'Create Office Entry'; ?></h3>
<div class="table-wrapper" style="padding: 18px;">
    <form method="post" style="display: grid; gap: 12px; max-width: 760px;">
        <input type="hidden" name="action" value="<?php echo $isEditingOffice ? 'update_office' : 'create_office'; ?>">
        <input type="hidden" name="office_doc_name" value="<?php echo htmlspecialchars($editingOffice['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label for="office-name"><strong>Office Name</strong></label>
        <input id="office-name" name="name" type="text" placeholder="Enter office name" required value="<?php echo htmlspecialchars($editingOffice['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="office-mobile"><strong>Mobile</strong></label>
        <input id="office-mobile" name="mobile" type="text" placeholder="Enter office mobile number" required value="<?php echo htmlspecialchars($editingOffice['mobile'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="office-email"><strong>Email</strong></label>
        <input id="office-email" name="email" type="email" placeholder="Enter office email" required value="<?php echo htmlspecialchars($editingOffice['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="office-location"><strong>Location</strong></label>
        <input id="office-location" name="location" type="text" placeholder="Enter office location" required value="<?php echo htmlspecialchars($editingOffice['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="office-availability"><strong>Availability / Office Hours</strong></label>
        <input id="office-availability" name="availability" type="text" placeholder="Example: 7:00 AM - 7:00 PM" value="<?php echo htmlspecialchars($editingOffice['availability'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <div>
            <button type="submit" class="action-btn primary"><?php echo $isEditingOffice ? 'Update Office' : 'Create Office'; ?></button>
            <?php if ($isEditingOffice): ?>
                <a href="office_directory.php" class="action-btn secondary" style="display: inline-block; text-decoration: none; margin-left: 8px;">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<h3 style="margin: 26px 0 10px;">Office Directory List</h3>

<?php if (empty($offices)): ?>
    <div class="empty-state">No office directory entries are available yet.</div>
<?php else: ?>
    <div class="directory-grid">
        <?php foreach ($offices as $office): ?>
            <?php
            $availabilityText = trim((string) ($office['availability'] ?? ''));
            $availabilityLabel = $availabilityText !== '' ? $availabilityText : 'Schedule not set';
            $availabilityClass = officeDirectoryAvailabilityClass($availabilityText);
            ?>
            <div class="directory-card">
                <div class="request-top" style="margin-bottom: 10px;">
                    <div>
                        <h3><?php echo htmlspecialchars($office['name'] ?? 'Unnamed Office', ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="meta-text"><?php echo htmlspecialchars($office['location'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <span class="status-pill <?php echo $availabilityClass; ?>">
                        <?php echo htmlspecialchars($availabilityLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <p class="meta-text"><strong>Mobile:</strong> <?php echo htmlspecialchars($office['mobile'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="meta-text"><strong>Email:</strong> <?php echo htmlspecialchars($office['email'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="meta-text"><strong>Office Hours:</strong> <?php echo htmlspecialchars($availabilityLabel, ENT_QUOTES, 'UTF-8'); ?></p>

                <div class="button-row">
                    <a href="office_directory.php?edit_office=<?php echo urlencode((string) ($office['__name'] ?? '')); ?>" class="action-btn primary" style="display: inline-block; text-decoration: none;">Edit</a>
                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this office entry?');">
                        <input type="hidden" name="action" value="delete_office">
                        <input type="hidden" name="office_doc_name" value="<?php echo htmlspecialchars($office['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="action-btn secondary">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$contentHtml = ob_get_clean();

renderAdminPage('office_directory', 'Office Directory', 'Create, update, and manage office contact details and availability.', $contentHtml);
