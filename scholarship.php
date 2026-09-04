<?php
session_start();

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

$dataFile = __DIR__ . '/includes/scholarship_requests_data.json';
$scholarshipSubmissionCollection = 'scholarship_submissions';

function getScholarshipRequestBuckets(): array
{
    return ['pending', 'processing', 'approved', 'rejected', 'completed'];
}

function getScholarshipRequestDefault(): array
{
    $data = [];

    foreach (getScholarshipRequestBuckets() as $bucket) {
        $data[$bucket] = [];
    }

    return $data;
}

function normalizeScholarshipRequestStatus($status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        '', 'pending' => 'Pending',
        'processing', 'in_progress', 'in progress' => 'Processing',
        'approved', 'completed', 'complete', 'done', 'finished' => 'Approved',
        'rejected', 'reject', 'declined', 'denied' => 'Rejected',
        default => trim((string) $status) !== '' ? trim((string) $status) : 'Pending',
    };
}

function getScholarshipBucketForStatus(string $status): string
{
    return match (normalizeScholarshipRequestStatus($status)) {
        'Pending' => 'pending',
        'Processing' => 'processing',
        'Rejected' => 'rejected',
        default => 'approved',
    };
}

function getScholarshipStatusMeta(string $status): array
{
    return match (normalizeScholarshipRequestStatus($status)) {
        'Pending' => ['label' => 'Pending', 'class' => 'status-review'],
        'Processing' => ['label' => 'Processing', 'class' => 'status-updated'],
        'Rejected' => ['label' => 'Rejected', 'class' => 'status-danger'],
        default => ['label' => 'Approved', 'class' => 'status-active'],
    };
}

function getScholarshipJsonDefault(): array
{
    return [
        'pending' => [],
        'processing' => [],
        'approved' => [],
        'rejected' => [],
        'completed' => [],
        'programs' => [],
    ];
}

function loadScholarshipJsonData(string $dataFile): array
{
    $default = getScholarshipJsonDefault();

    if (!file_exists($dataFile)) {
        return $default;
    }

    $raw = file_get_contents($dataFile);
    if ($raw === false || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }

    return [
        'pending' => array_values($decoded['pending'] ?? []),
        'processing' => array_values($decoded['processing'] ?? []),
        'approved' => array_values($decoded['approved'] ?? []),
        'rejected' => array_values($decoded['rejected'] ?? []),
        'completed' => array_values($decoded['completed'] ?? []),
        'programs' => array_values($decoded['programs'] ?? []),
    ];
}

function saveScholarshipJsonData(string $dataFile, array $data): bool
{
    return file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function normalizeScholarshipProgramDoc(array $doc): array
{
    $totalSlots = isset($doc['totalSlots']) && is_numeric($doc['totalSlots'])
        ? (int) $doc['totalSlots']
        : trim((string) ($doc['totalSlots'] ?? ''));
    $availableSlotsSource = $doc['availableSlots'] ?? $doc['available_slots'] ?? null;
    $availableSlots = '';

    if (is_numeric($availableSlotsSource)) {
        $availableSlots = (int) $availableSlotsSource;
    } elseif ($availableSlotsSource !== null && trim((string) $availableSlotsSource) !== '') {
        $availableSlots = trim((string) $availableSlotsSource);
    }

    return [
        'name' => trim((string) ($doc['name'] ?? '')),
        'requirements' => trim((string) ($doc['requirements'] ?? '')),
        'availableSlots' => $availableSlots,
        'totalSlots' => $totalSlots,
        'created_at' => $doc['created_at'] ?? '',
        '__name' => $doc['__name'] ?? '',
    ];
}

function normalizeScholarshipProgramRow(array $program, string $docName = ''): array
{
    $program['__name'] = $docName;
    return normalizeScholarshipProgramDoc($program);
}

function scholarshipFirstNonEmptyString(...$values): string
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

function scholarshipResolveApplicantName(array $doc): string
{
    $combinedName = trim(
        scholarshipFirstNonEmptyString($doc['firstName'] ?? '', $doc['first_name'] ?? '')
        . ' '
        . scholarshipFirstNonEmptyString($doc['lastName'] ?? '', $doc['last_name'] ?? '')
    );

    return scholarshipFirstNonEmptyString(
        $doc['applicant_name'] ?? '',
        $doc['applicantName'] ?? '',
        $doc['applicant_full_name'] ?? '',
        $doc['applicantFullName'] ?? '',
        $doc['full_name'] ?? '',
        $doc['fullName'] ?? '',
        $doc['userName'] ?? '',
        $doc['username'] ?? '',
        $doc['displayName'] ?? '',
        $doc['studentName'] ?? '',
        $doc['student_name'] ?? '',
        $doc['name'] ?? '',
        $combinedName
    );
}

function normalizeScholarshipRequestDoc(array $doc): array
{
    return [
        'id' => isset($doc['id']) ? (int) $doc['id'] : 0,
        'applicant_name' => scholarshipResolveApplicantName($doc),
        'user_id' => scholarshipFirstNonEmptyString($doc['user_id'] ?? '', $doc['userId'] ?? ''),
        'applicant_email' => scholarshipFirstNonEmptyString($doc['applicant_email'] ?? '', $doc['applicantEmail'] ?? '', $doc['email'] ?? '', $doc['userEmail'] ?? ''),
        'program' => scholarshipFirstNonEmptyString($doc['program'] ?? '', $doc['programName'] ?? '', $doc['scholarship'] ?? '', $doc['scholarshipName'] ?? ''),
        'school' => scholarshipFirstNonEmptyString($doc['school'] ?? '', $doc['schoolName'] ?? '', $doc['applicantSchool'] ?? ''),
        'course' => scholarshipFirstNonEmptyString($doc['course'] ?? '', $doc['courseName'] ?? ''),
        'reason' => scholarshipFirstNonEmptyString($doc['reason'] ?? '', $doc['message'] ?? '', $doc['applicationReason'] ?? ''),
        'proofLink' => scholarshipFirstNonEmptyString($doc['proofLink'] ?? '', $doc['proof_link'] ?? '', $doc['proofUrl'] ?? '', $doc['proofURL'] ?? ''),
        'status' => normalizeScholarshipRequestStatus($doc['status'] ?? 'Pending'),
        'submitted_at' => scholarshipFirstNonEmptyString($doc['submitted_at'] ?? '', $doc['submittedAt'] ?? ''),
        'processing_at' => $doc['processing_at'] ?? '',
        'processing_by' => $doc['processing_by'] ?? '',
        'approved_at' => $doc['approved_at'] ?? '',
        'approved_by' => $doc['approved_by'] ?? '',
        'rejected_at' => $doc['rejected_at'] ?? $doc['declined_at'] ?? '',
        'completed_at' => $doc['completed_at'] ?? '',
        '__name' => $doc['__name'] ?? '',
    ];
}

function normalizeScholarshipApprovedRequest(array $request): array
{
    $request['status'] = 'Approved';

    if (trim((string) ($request['approved_at'] ?? '')) === '' && trim((string) ($request['completed_at'] ?? '')) !== '') {
        $request['approved_at'] = (string) $request['completed_at'];
    }

    return $request;
}

function getLocalScholarshipProgramIndex(string $docName): ?int
{
    if (!str_starts_with($docName, 'local:')) {
        return null;
    }

    $index = substr($docName, 6);
    return ctype_digit($index) ? (int) $index : null;
}

function findScholarshipProgramByDocName(array $programs, string $docName): ?array
{
    foreach ($programs as $program) {
        if (($program['__name'] ?? '') === $docName) {
            return $program;
        }
    }

    return null;
}

function findScholarshipProgramByName(array $programs, string $programName): ?array
{
    $programName = trim($programName);
    if ($programName === '') {
        return null;
    }

    foreach ($programs as $program) {
        if (strcasecmp(trim((string) ($program['name'] ?? '')), $programName) === 0) {
            return $program;
        }
    }

    return null;
}

function deleteScholarshipRequestHistoryItem(array &$data, string $bucket, int $requestId): bool
{
    $searchBuckets = [$bucket];
    if ($bucket === 'approved') {
        $searchBuckets[] = 'completed';
    }

    foreach ($searchBuckets as $searchBucket) {
        if (!isset($data[$searchBucket]) || !is_array($data[$searchBucket])) {
            continue;
        }

        foreach ($data[$searchBucket] as $index => $request) {
            if ((int) ($request['id'] ?? 0) !== $requestId) {
                continue;
            }

            unset($data[$searchBucket][$index]);
            $data[$searchBucket] = array_values($data[$searchBucket]);
            return true;
        }
    }

        return false;
}

function getScholarshipProgramRemainingSlots(array $program): int
{
    if (isset($program['remainingSlots']) && is_numeric($program['remainingSlots'])) {
        return max(0, (int) $program['remainingSlots']);
    }

    if (isset($program['availableSlots']) && is_numeric($program['availableSlots'])) {
        return max(0, (int) $program['availableSlots']);
    }

    $totalSlots = $program['totalSlots'] ?? 0;
    return is_numeric($totalSlots) ? max(0, (int) $totalSlots) : 0;
}

function countScholarshipAcceptedRequests(array $requests, string $programName): int
{
    $programName = trim($programName);
    if ($programName === '') {
        return 0;
    }

    $count = 0;
    foreach (['approved', 'completed'] as $bucket) {
        foreach ($requests[$bucket] ?? [] as $request) {
            if (strcasecmp(trim((string) ($request['program'] ?? '')), $programName) === 0) {
                $count++;
            }
        }
    }

    return $count;
}

function calculateScholarshipAvailableSlots(int $totalSlots, int $occupiedSlots): int
{
    return max(0, $totalSlots - max(0, $occupiedSlots));
}

function attachScholarshipProgramAvailability(array $programs, array $requests): array
{
    foreach ($programs as $index => $program) {
        $totalSlots = is_numeric($program['totalSlots'] ?? null)
            ? max(0, (int) $program['totalSlots'])
            : 0;
        $occupiedSlots = countScholarshipAcceptedRequests($requests, (string) ($program['name'] ?? ''));
        $availableSlots = calculateScholarshipAvailableSlots($totalSlots, $occupiedSlots);

        $programs[$index]['totalSlots'] = $totalSlots;
        $programs[$index]['availableSlots'] = $availableSlots;
        $programs[$index]['occupiedSlots'] = $occupiedSlots;
        $programs[$index]['remainingSlots'] = $availableSlots;
    }

    return $programs;
}

function syncScholarshipProgramAvailability(string $dataFile, array $requests): void
{
    $programs = loadScholarshipPrograms($dataFile);

    if (firebase_enabled() && firebase_firestore_enabled()) {
        foreach ($programs as $program) {
            $docName = trim((string) ($program['__name'] ?? ''));
            $totalSlots = is_numeric($program['totalSlots'] ?? null) ? max(0, (int) $program['totalSlots']) : 0;
            $occupiedSlots = countScholarshipAcceptedRequests($requests, (string) ($program['name'] ?? ''));
            $availableSlots = calculateScholarshipAvailableSlots($totalSlots, $occupiedSlots);

            if ($docName !== '' && (int) ($program['availableSlots'] ?? -1) !== $availableSlots) {
                firebase_firestore_patch_document($docName, ['availableSlots' => $availableSlots]);
            }
        }

        return;
    }

    $jsonData = loadScholarshipJsonData($dataFile);
    $changed = false;

    foreach (array_values($jsonData['programs'] ?? []) as $index => $program) {
        $totalSlots = is_numeric($program['totalSlots'] ?? null) ? max(0, (int) $program['totalSlots']) : 0;
        $occupiedSlots = countScholarshipAcceptedRequests($requests, (string) ($program['name'] ?? ''));
        $availableSlots = calculateScholarshipAvailableSlots($totalSlots, $occupiedSlots);

        if ((int) ($jsonData['programs'][$index]['availableSlots'] ?? -1) !== $availableSlots) {
            $jsonData['programs'][$index]['availableSlots'] = $availableSlots;
            $changed = true;
        }
    }

    if ($changed) {
        saveScholarshipJsonData($dataFile, $jsonData);
    }
}

function loadScholarshipPrograms(string $dataFile): array
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents('scholarships');
        if (is_array($documents)) {
            $programs = array_map('normalizeScholarshipProgramDoc', $documents);
            usort($programs, static fn(array $a, array $b): int => strcmp(
                strtolower($a['name'] ?? ''),
                strtolower($b['name'] ?? '')
            ));
            return $programs;
        }
    }

    $data = loadScholarshipJsonData($dataFile);
    $programs = [];
    foreach (array_values($data['programs'] ?? []) as $index => $program) {
        $programs[] = normalizeScholarshipProgramRow((array) $program, 'local:' . $index);
    }

    usort($programs, static fn(array $a, array $b): int => strcmp(
        strtolower((string) ($a['name'] ?? '')),
        strtolower((string) ($b['name'] ?? ''))
    ));

    return $programs;
}

function loadScholarshipRequests(string $dataFile, string $submissionCollection): array
{
    $default = getScholarshipRequestDefault();

    if (firebase_enabled() && firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents($submissionCollection);
        if (is_array($documents)) {
            $requests = getScholarshipRequestDefault();

            foreach ($documents as $document) {
                $request = normalizeScholarshipRequestDoc($document);
                $bucket = getScholarshipBucketForStatus((string) ($request['status'] ?? 'Pending'));
                if ($bucket === 'approved') {
                    $request = normalizeScholarshipApprovedRequest($request);
                }

                $requests[$bucket][] = $request;
            }

            usort($requests['pending'], static fn(array $a, array $b): int =>
                strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? '')
            );
            usort($requests['processing'], static fn(array $a, array $b): int =>
                strcmp($b['processing_at'] ?? ($b['submitted_at'] ?? ''), $a['processing_at'] ?? ($a['submitted_at'] ?? ''))
            );
            usort($requests['approved'], static fn(array $a, array $b): int =>
                strcmp($b['approved_at'] ?? ($b['completed_at'] ?? ($b['submitted_at'] ?? '')), $a['approved_at'] ?? ($a['completed_at'] ?? ($a['submitted_at'] ?? '')))
            );
            usort($requests['rejected'], static fn(array $a, array $b): int =>
                strcmp($b['rejected_at'] ?? ($b['submitted_at'] ?? ''), $a['rejected_at'] ?? ($a['submitted_at'] ?? ''))
            );
            $requests['completed'] = [];

            return $requests;
        }
    }

    $data = loadScholarshipJsonData($dataFile);
    $approvedRequests = array_map(
        'normalizeScholarshipApprovedRequest',
        array_merge(
            array_values($data['approved'] ?? []),
            array_values($data['completed'] ?? [])
        )
    );
    usort($approvedRequests, static fn(array $a, array $b): int =>
        strcmp($b['approved_at'] ?? ($b['completed_at'] ?? ($b['submitted_at'] ?? '')), $a['approved_at'] ?? ($a['completed_at'] ?? ($a['submitted_at'] ?? '')))
    );

    return [
        'pending' => array_values($data['pending'] ?? []),
        'processing' => array_values($data['processing'] ?? []),
        'approved' => $approvedRequests,
        'rejected' => array_values($data['rejected'] ?? []),
        'completed' => [],
    ];
}

function loadScholarshipData(string $dataFile): array
{
    global $scholarshipSubmissionCollection;

    $requests = loadScholarshipRequests($dataFile, $scholarshipSubmissionCollection);
    $programs = attachScholarshipProgramAvailability(loadScholarshipPrograms($dataFile), $requests);

    return [
        'pending' => $requests['pending'],
        'processing' => $requests['processing'],
        'approved' => $requests['approved'],
        'rejected' => $requests['rejected'],
        'completed' => $requests['completed'],
        'programs' => $programs,
    ];
}

function getNextScholarshipRequestId(array $data): int
{
    $highestId = 0;

    foreach (getScholarshipRequestBuckets() as $bucket) {
        foreach ($data[$bucket] ?? [] as $item) {
            $highestId = max($highestId, (int) ($item['id'] ?? 0));
        }
    }

    return $highestId + 1;
}

function formatScholarshipDate(?string $value): string
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

function getScholarshipApprovedSortOptions(): array
{
    return [
        'recent' => 'Latest approved first',
        'oldest' => 'Oldest approved first',
        'program' => 'Program A-Z',
        'applicant' => 'Applicant name A-Z',
    ];
}

function getScholarshipSortableDate(array $request): int
{
    $value = (string) ($request['approved_at'] ?? $request['completed_at'] ?? $request['submitted_at'] ?? '');
    $timestamp = strtotime($value);
    return $timestamp !== false ? $timestamp : 0;
}

function sortScholarshipApprovedItems(array $requests, string $sort): array
{
    usort($requests, static function (array $a, array $b) use ($sort): int {
        $programA = strtolower(trim((string) ($a['program'] ?? '')));
        $programB = strtolower(trim((string) ($b['program'] ?? '')));
        $applicantA = strtolower(trim((string) ($a['applicant_name'] ?? '')));
        $applicantB = strtolower(trim((string) ($b['applicant_name'] ?? '')));
        $dateA = getScholarshipSortableDate($a);
        $dateB = getScholarshipSortableDate($b);

        if ($sort === 'oldest') {
            return ($dateA <=> $dateB) ?: strcmp($programA, $programB);
        }

        if ($sort === 'program') {
            return strcmp($programA, $programB) ?: ($dateB <=> $dateA);
        }

        if ($sort === 'applicant') {
            return strcmp($applicantA, $applicantB) ?: ($dateB <=> $dateA);
        }

        return ($dateB <=> $dateA) ?: strcmp($programA, $programB);
    });

    return $requests;
}

function buildScholarshipRedirectUrl(string $approvedSort = 'recent'): string
{
    $query = [];

    if ($approvedSort !== '' && $approvedSort !== 'recent') {
        $query['approved_sort'] = $approvedSort;
    }

    return 'scholarship.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function isScholarshipApiRequest(): bool
{
    $apiValue = strtolower((string) ($_GET['api'] ?? $_GET['format'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    return in_array($apiValue, ['1', 'true', 'json', 'programs', 'apply', 'approved'], true)
        || str_contains($accept, 'application/json')
        || str_contains($contentType, 'application/json');
}

function sendScholarshipJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function getScholarshipInput(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw !== false ? $raw : '', true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' && isScholarshipApiRequest()) {
    sendScholarshipJson(['success' => true, 'message' => 'Scholarship API is ready.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isScholarshipApiRequest()) {
    $data = loadScholarshipData($dataFile);
    $apiMode = strtolower((string) ($_GET['api'] ?? $_GET['format'] ?? 'programs'));

    if ($apiMode === 'approved') {
        sendScholarshipJson([
            'success' => true,
            'approved' => $data['approved'],
            'count' => count($data['approved']),
        ]);
    }

    sendScholarshipJson([
        'success' => true,
        'programs' => $data['programs'],
        'count' => count($data['programs']),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = loadScholarshipData($dataFile);
    $input = getScholarshipInput();
    $isApiRequest = isScholarshipApiRequest();
    $action = $input['action'] ?? '';
    $shouldSyncAvailability = false;

    if ($action === '' && !$isAdmin && (
        isset($input['applicant_name'])
        || isset($input['applicantName'])
        || isset($input['applicant_email'])
        || isset($input['applicantEmail'])
        || isset($input['program'])
        || isset($input['programName'])
        || isset($input['reason'])
        || isset($input['applicationReason'])
    )) {
        $action = 'apply_scholarship';
    }

    $flash = ['type' => 'error', 'text' => 'Unable to process the scholarship request.'];

    if ($isAdmin && ($action === 'create_scholarship' || $action === 'update_scholarship')) {
        $scholarshipDocName = trim((string) ($input['scholarship_doc_name'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $requirements = trim((string) ($input['requirements'] ?? ''));
        $totalSlotsRaw = trim((string) ($input['totalSlots'] ?? ''));
        $hasValidTotalSlots = $totalSlotsRaw !== '' && is_numeric($totalSlotsRaw);
        $totalSlots = $hasValidTotalSlots ? (int) $totalSlotsRaw : 0;
        $occupiedSlots = countScholarshipAcceptedRequests($data, $name);
        $availableSlots = calculateScholarshipAvailableSlots($totalSlots, $occupiedSlots);

        if (
            $name === ''
            || $requirements === ''
            || !$hasValidTotalSlots
            || $totalSlots <= 0
        ) {
            $flash = ['type' => 'error', 'text' => 'Please complete the scholarship name, requirements, and total slots.'];
        } elseif ($action === 'update_scholarship' && $scholarshipDocName === '') {
            $flash = ['type' => 'error', 'text' => 'The selected scholarship was not found.'];
        } else {
            if (firebase_enabled() && firebase_firestore_enabled()) {
                if ($action === 'update_scholarship') {
                    $success = firebase_firestore_patch_document($scholarshipDocName, [
                        'name' => $name,
                        'requirements' => $requirements,
                        'availableSlots' => $availableSlots,
                        'totalSlots' => $totalSlots,
                    ]);

                    $flash = $success
                        ? ['type' => 'success', 'text' => 'Scholarship updated successfully.']
                        : ['type' => 'error', 'text' => 'The scholarship could not be updated. Please try again.'];
                    $shouldSyncAvailability = $success;
                } else {
                    $created = firebase_firestore_create_document('scholarships', [
                        'name' => $name,
                        'requirements' => $requirements,
                        'availableSlots' => $availableSlots,
                        'totalSlots' => $totalSlots,
                        'created_at' => new DateTimeImmutable('now', firebase_app_timezone()),
                    ]);

                    $flash = $created !== null
                        ? ['type' => 'success', 'text' => 'Scholarship created successfully.']
                        : ['type' => 'error', 'text' => 'The scholarship could not be saved. Please try again.'];
                    $shouldSyncAvailability = $created !== null;
                }
            } else {
                $jsonData = loadScholarshipJsonData($dataFile);

                if ($action === 'update_scholarship') {
                    $localIndex = getLocalScholarshipProgramIndex($scholarshipDocName);
                    if ($localIndex === null || !isset($jsonData['programs'][$localIndex])) {
                        $flash = ['type' => 'error', 'text' => 'The selected scholarship was not found.'];
                    } else {
                        $jsonData['programs'][$localIndex]['name'] = $name;
                        $jsonData['programs'][$localIndex]['requirements'] = $requirements;
                        $jsonData['programs'][$localIndex]['availableSlots'] = $availableSlots;
                        $jsonData['programs'][$localIndex]['totalSlots'] = $totalSlots;

                        $flash = saveScholarshipJsonData($dataFile, $jsonData)
                            ? ['type' => 'success', 'text' => 'Scholarship updated successfully.']
                            : ['type' => 'error', 'text' => 'The scholarship could not be updated. Please try again.'];
                        $shouldSyncAvailability = $flash['type'] === 'success';
                    }
                } else {
                    $jsonData['programs'][] = [
                        'name' => $name,
                        'requirements' => $requirements,
                        'availableSlots' => $availableSlots,
                        'totalSlots' => $totalSlots,
                        'created_at' => firebase_now_string(),
                    ];

                    $flash = saveScholarshipJsonData($dataFile, $jsonData)
                        ? ['type' => 'success', 'text' => 'Scholarship created successfully.']
                        : ['type' => 'error', 'text' => 'The scholarship could not be saved. Please try again.'];
                    $shouldSyncAvailability = $flash['type'] === 'success';
                }
            }
        }
    } elseif ($isAdmin && $action === 'delete_scholarship') {
        $scholarshipDocName = trim((string) ($input['scholarship_doc_name'] ?? ''));

        if ($scholarshipDocName === '') {
            $flash = ['type' => 'error', 'text' => 'The selected scholarship was not found.'];
        } elseif (firebase_enabled() && firebase_firestore_enabled()) {
            $success = firebase_firestore_delete_document($scholarshipDocName);
            $flash = $success
                ? ['type' => 'success', 'text' => 'Scholarship deleted successfully.']
                : ['type' => 'error', 'text' => 'The scholarship could not be deleted. Please try again.'];
        } else {
            $jsonData = loadScholarshipJsonData($dataFile);
            $localIndex = getLocalScholarshipProgramIndex($scholarshipDocName);

            if ($localIndex === null || !isset($jsonData['programs'][$localIndex])) {
                $flash = ['type' => 'error', 'text' => 'The selected scholarship was not found.'];
            } else {
                unset($jsonData['programs'][$localIndex]);
                $jsonData['programs'] = array_values($jsonData['programs']);

                $flash = saveScholarshipJsonData($dataFile, $jsonData)
                    ? ['type' => 'success', 'text' => 'Scholarship deleted successfully.']
                    : ['type' => 'error', 'text' => 'The scholarship could not be deleted. Please try again.'];
            }
        }
    } elseif ($action === 'apply_scholarship') {
        $applicantName = scholarshipResolveApplicantName($input);
        $userId = scholarshipFirstNonEmptyString($input['user_id'] ?? '', $input['userId'] ?? '');
        $applicantEmail = scholarshipFirstNonEmptyString($input['applicant_email'] ?? '', $input['applicantEmail'] ?? '', $input['email'] ?? '', $input['userEmail'] ?? '');
        $program = scholarshipFirstNonEmptyString($input['program'] ?? '', $input['programName'] ?? '', $input['scholarship'] ?? '', $input['scholarshipName'] ?? '');
        $school = scholarshipFirstNonEmptyString($input['school'] ?? '', $input['schoolName'] ?? '', $input['applicantSchool'] ?? '');
        $course = scholarshipFirstNonEmptyString($input['course'] ?? '', $input['courseName'] ?? '');
        $reason = scholarshipFirstNonEmptyString($input['reason'] ?? '', $input['message'] ?? '', $input['applicationReason'] ?? '');
        $proofLink = scholarshipFirstNonEmptyString($input['proofLink'] ?? '', $input['proof_link'] ?? '', $input['proofUrl'] ?? '', $input['proofURL'] ?? '');

        if ($applicantName === '' || $applicantEmail === '' || $program === '' || $reason === '') {
            if ($isApiRequest) {
                sendScholarshipJson([
                    'success' => false,
                    'message' => 'Please complete the required scholarship application fields.',
                ], 422);
            }

            $flash = ['type' => 'error', 'text' => 'Please complete the required scholarship application fields.'];
        } else {
            $requestId = getNextScholarshipRequestId($data);
            $requestData = [
                'id' => $requestId,
                'applicant_name' => $applicantName,
                'user_id' => $userId,
                'applicant_email' => $applicantEmail,
                'program' => $program,
                'school' => $school,
                'course' => $course,
                'reason' => $reason,
                'proofLink' => $proofLink,
                'status' => 'Pending',
                'submitted_at' => firebase_now_string(),
            ];

            if (firebase_enabled() && firebase_firestore_enabled()) {
                $firestoreRequest = $requestData;
                $firestoreRequest['submitted_at'] = new DateTimeImmutable('now', firebase_app_timezone());
                $created = firebase_firestore_create_document($scholarshipSubmissionCollection, $firestoreRequest);
                $saved = $created !== null;
            } else {
                $jsonData = loadScholarshipJsonData($dataFile);
                $jsonData['pending'][] = $requestData;
                $saved = saveScholarshipJsonData($dataFile, $jsonData);
            }

            if ($isApiRequest) {
                sendScholarshipJson([
                    'success' => $saved,
                    'message' => $saved
                        ? 'Scholarship request submitted successfully and is pending admin approval.'
                        : 'The scholarship request could not be saved. Please try again.',
                    'request_id' => $saved ? $requestId : null,
                ], $saved ? 201 : 500);
            }

            $flash = $saved
                ? ['type' => 'success', 'text' => 'Your scholarship request was sent to the admin for review.']
                : ['type' => 'error', 'text' => 'The scholarship request could not be saved. Please try again.'];
        }
    } elseif ($isAdmin && in_array($action, ['start_processing', 'approve_request', 'reject_request'], true)) {
        $requestId = (int) ($input['request_id'] ?? 0);
        $docName = trim((string) ($input['doc_name'] ?? ''));
        $selectedRequest = null;

        foreach (getScholarshipRequestBuckets() as $bucket) {
            foreach ($data[$bucket] as $request) {
                if (($docName !== '' && ($request['__name'] ?? '') === $docName) || ($requestId > 0 && (int) ($request['id'] ?? 0) === $requestId)) {
                    $selectedRequest = $request;
                    break 2;
                }
            }
        }

        if (firebase_enabled() && firebase_firestore_enabled()) {
            if ($docName === '' || !is_array($selectedRequest)) {
                $flash = ['type' => 'error', 'text' => 'The selected scholarship request was not found.'];
            } elseif ($action === 'start_processing') {
                $success = firebase_firestore_patch_document($docName, [
                    'status' => 'Processing',
                    'processing_at' => new DateTimeImmutable('now', firebase_app_timezone()),
                    'processing_by' => 'Admin',
                ]);

                if ($success && ($selectedRequest['user_id'] ?? '') !== '') {
                    $programLabel = trim((string) ($selectedRequest['program'] ?? 'scholarship'));
                    firebase_create_notification(
                        (string) $selectedRequest['user_id'],
                        'Scholarship Application Processing',
                        'Your application for ' . ($programLabel !== '' ? $programLabel : 'the scholarship') . ' is now being reviewed.'
                    );
                }

                $flash = $success
                    ? ['type' => 'success', 'text' => 'The scholarship request is now marked as processing.']
                    : ['type' => 'error', 'text' => 'The scholarship request could not be moved to processing.'];
            } elseif ($action === 'approve_request') {
                $matchedProgram = findScholarshipProgramByName($data['programs'], (string) ($selectedRequest['program'] ?? ''));
                if (!is_array($matchedProgram) || ($matchedProgram['__name'] ?? '') === '') {
                    $flash = ['type' => 'error', 'text' => 'The matching scholarship was not found.'];
                } else {
                    $remainingSlots = getScholarshipProgramRemainingSlots($matchedProgram);
                    if ($remainingSlots <= 0) {
                        $flash = ['type' => 'error', 'text' => 'No slots are available for this scholarship.'];
                    } else {
                        $success = firebase_firestore_patch_document($docName, [
                            'status' => 'Approved',
                            'approved_at' => new DateTimeImmutable('now', firebase_app_timezone()),
                            'approved_by' => 'Admin',
                        ]);

                        if ($success && ($selectedRequest['user_id'] ?? '') !== '') {
                            $programLabel = trim((string) ($selectedRequest['program'] ?? 'scholarship'));
                            firebase_create_notification(
                                (string) $selectedRequest['user_id'],
                                'Scholarship Application Approved',
                                'Your application for ' . ($programLabel !== '' ? $programLabel : 'the scholarship') . ' has been approved.'
                            );
                        }

                        $flash = $success
                            ? ['type' => 'success', 'text' => 'The scholarship request was approved successfully.']
                            : ['type' => 'error', 'text' => 'The scholarship request could not be approved.'];
                        $shouldSyncAvailability = $success;
                    }
                }
            } else {
                $success = firebase_firestore_patch_document($docName, [
                    'status' => 'Rejected',
                    'rejected_at' => new DateTimeImmutable('now', firebase_app_timezone()),
                ]);
                if ($success && ($selectedRequest['user_id'] ?? '') !== '') {
                    $programLabel = trim((string) ($selectedRequest['program'] ?? 'scholarship'));
                    firebase_create_notification(
                        (string) $selectedRequest['user_id'],
                        'Scholarship Application Rejected',
                        'Your application for ' . ($programLabel !== '' ? $programLabel : 'the scholarship') . ' has been rejected.'
                    );
                }
                $flash = $success
                    ? ['type' => 'success', 'text' => 'The scholarship request was rejected successfully.']
                    : ['type' => 'error', 'text' => 'The scholarship request could not be rejected.'];
            }
        } else {
            $jsonData = loadScholarshipJsonData($dataFile);
            $selectedBucket = null;
            $selectedIndex = null;

            foreach (getScholarshipRequestBuckets() as $bucket) {
                foreach ($jsonData[$bucket] as $index => $item) {
                    if ((int) ($item['id'] ?? 0) === $requestId) {
                        $selectedBucket = $bucket;
                        $selectedIndex = $index;
                        break 2;
                    }
                }
            }

            if ($selectedBucket === null || $selectedIndex === null) {
                if ($isApiRequest) {
                    sendScholarshipJson([
                        'success' => false,
                        'message' => 'The selected scholarship request was not found.',
                    ], 404);
                }

                $flash = ['type' => 'error', 'text' => 'The selected scholarship request was not found.'];
            } else {
                $selectedRequest = $jsonData[$selectedBucket][$selectedIndex];

                if ($action === 'start_processing') {
                    unset($jsonData[$selectedBucket][$selectedIndex]);
                    $jsonData[$selectedBucket] = array_values($jsonData[$selectedBucket]);

                    $selectedRequest['status'] = 'Processing';
                    $selectedRequest['processing_at'] = firebase_now_string();
                    $selectedRequest['processing_by'] = 'Admin';
                    array_unshift($jsonData['processing'], $selectedRequest);

                    $saved = saveScholarshipJsonData($dataFile, $jsonData);
                    if ($saved && ($selectedRequest['user_id'] ?? '') !== '') {
                        $programLabel = trim((string) ($selectedRequest['program'] ?? 'scholarship'));
                        firebase_create_notification(
                            (string) $selectedRequest['user_id'],
                            'Scholarship Application Processing',
                            'Your application for ' . ($programLabel !== '' ? $programLabel : 'the scholarship') . ' is now being reviewed.'
                        );
                    }
                    $flash = $saved
                        ? ['type' => 'success', 'text' => 'The scholarship request is now marked as processing.']
                        : ['type' => 'error', 'text' => 'The scholarship request could not be moved to processing.'];
                } elseif ($action === 'approve_request') {
                    $matchedProgram = findScholarshipProgramByName($data['programs'], (string) ($selectedRequest['program'] ?? ''));
                    if (!is_array($matchedProgram)) {
                        $flash = ['type' => 'error', 'text' => 'The matching scholarship was not found.'];
                    } else {
                        $remainingSlots = getScholarshipProgramRemainingSlots($matchedProgram);
                        if ($remainingSlots <= 0) {
                            $flash = ['type' => 'error', 'text' => 'No slots are available for this scholarship.'];
                        } else {
                            unset($jsonData[$selectedBucket][$selectedIndex]);
                            $jsonData[$selectedBucket] = array_values($jsonData[$selectedBucket]);

                            $selectedRequest['status'] = 'Approved';
                            $selectedRequest['approved_at'] = firebase_now_string();
                            $selectedRequest['approved_by'] = 'Admin';
                            array_unshift($jsonData['approved'], $selectedRequest);

                            $saved = saveScholarshipJsonData($dataFile, $jsonData);
                            if ($saved && ($selectedRequest['user_id'] ?? '') !== '') {
                                $programLabel = trim((string) ($selectedRequest['program'] ?? 'scholarship'));
                                firebase_create_notification(
                                    (string) $selectedRequest['user_id'],
                                    'Scholarship Application Approved',
                                    'Your application for ' . ($programLabel !== '' ? $programLabel : 'the scholarship') . ' has been approved.'
                                );
                            }
                            $flash = $saved
                                ? ['type' => 'success', 'text' => 'The scholarship request was approved successfully.']
                                : ['type' => 'error', 'text' => 'The scholarship request could not be approved.'];
                            $shouldSyncAvailability = $saved;
                        }
                    }
                } else {
                    unset($jsonData[$selectedBucket][$selectedIndex]);
                    $jsonData[$selectedBucket] = array_values($jsonData[$selectedBucket]);

                    $selectedRequest['status'] = 'Rejected';
                    $selectedRequest['rejected_at'] = firebase_now_string();
                    array_unshift($jsonData['rejected'], $selectedRequest);

                    $saved = saveScholarshipJsonData($dataFile, $jsonData);
                    if ($saved && ($selectedRequest['user_id'] ?? '') !== '') {
                        $programLabel = trim((string) ($selectedRequest['program'] ?? 'scholarship'));
                        firebase_create_notification(
                            (string) $selectedRequest['user_id'],
                            'Scholarship Application Rejected',
                            'Your application for ' . ($programLabel !== '' ? $programLabel : 'the scholarship') . ' has been rejected.'
                        );
                    }
                    $flash = $saved
                        ? ['type' => 'success', 'text' => 'The scholarship request was rejected successfully.']
                        : ['type' => 'error', 'text' => 'The scholarship request could not be rejected.'];
                }
            }
        }
    } elseif ($isAdmin && $action === 'delete_request_history') {
        $requestId = (int) ($input['request_id'] ?? 0);
        $docName = trim((string) ($input['doc_name'] ?? ''));
        $historyBucket = trim((string) ($input['history_bucket'] ?? ''));
        $allowedBuckets = ['approved', 'rejected', 'completed'];

        if (!in_array($historyBucket, $allowedBuckets, true)) {
            $flash = ['type' => 'error', 'text' => 'The selected scholarship history item was not found.'];
        } elseif (firebase_enabled() && firebase_firestore_enabled()) {
            if ($docName === '') {
                foreach ($allowedBuckets as $bucket) {
                    foreach ($data[$bucket] as $request) {
                        if ((int) ($request['id'] ?? 0) === $requestId) {
                            $docName = trim((string) ($request['__name'] ?? ''));
                            break 2;
                        }
                    }
                }
            }

            if ($docName === '') {
                $flash = ['type' => 'error', 'text' => 'The selected scholarship history item was not found.'];
            } else {
                $success = firebase_firestore_delete_document($docName);
                $flash = $success
                    ? ['type' => 'success', 'text' => 'The scholarship history was deleted successfully.']
                    : ['type' => 'error', 'text' => firebase_get_last_error() ?? 'The scholarship history could not be deleted.'];
                $shouldSyncAvailability = $success && in_array($historyBucket, ['approved', 'completed'], true);
            }
        } else {
            $jsonData = loadScholarshipJsonData($dataFile);
            $deleted = deleteScholarshipRequestHistoryItem($jsonData, $historyBucket, $requestId);

            if (!$deleted) {
                $flash = ['type' => 'error', 'text' => 'The selected scholarship history item was not found.'];
            } else {
                $flash = saveScholarshipJsonData($dataFile, $jsonData)
                    ? ['type' => 'success', 'text' => 'The scholarship history was deleted successfully.']
                    : ['type' => 'error', 'text' => 'The scholarship history could not be deleted.'];
                $shouldSyncAvailability = $flash['type'] === 'success' && in_array($historyBucket, ['approved', 'completed'], true);
            }
        }
    } elseif ($isApiRequest) {
        sendScholarshipJson([
            'success' => false,
            'message' => 'Invalid scholarship API action.',
        ], 400);
    }

    if ($shouldSyncAvailability) {
        $requestsForSync = loadScholarshipRequests($dataFile, $scholarshipSubmissionCollection);
        syncScholarshipProgramAvailability($dataFile, $requestsForSync);
    }

    $_SESSION['scholarship_flash'] = $flash;
    $redirectApprovedSort = trim((string) ($input['redirect_approved_sort'] ?? 'recent'));
    header('Location: ' . buildScholarshipRedirectUrl($redirectApprovedSort));
    exit;
}

$data = loadScholarshipData($dataFile);
$approvedSortOptions = getScholarshipApprovedSortOptions();
$selectedApprovedSort = trim((string) ($_GET['approved_sort'] ?? 'recent'));
if (!isset($approvedSortOptions[$selectedApprovedSort])) {
    $selectedApprovedSort = 'recent';
}
$data['approved'] = sortScholarshipApprovedItems($data['approved'], $selectedApprovedSort);
$flash = $_SESSION['scholarship_flash'] ?? null;
unset($_SESSION['scholarship_flash']);

if ($isAdmin) {
    $editingScholarshipDocName = trim((string) ($_GET['edit_scholarship'] ?? ''));
    $editingScholarship = $editingScholarshipDocName !== ''
        ? findScholarshipProgramByDocName($data['programs'], $editingScholarshipDocName)
        : null;
    $isEditingScholarship = is_array($editingScholarship);

    ob_start();
    ?>
    <?php if ($flash): ?>
        <div class="alert-box <?php echo $flash['type'] === 'error' ? 'alert-error' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="filter-row">
        <form method="get" class="filter-form">
            <label for="approved-sort">Sort approved by:</label>
            <select id="approved-sort" name="approved_sort" onchange="this.form.submit()">
                <?php foreach ($approvedSortOptions as $sortValue => $sortLabel): ?>
                    <option value="<?php echo htmlspecialchars($sortValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedApprovedSort === $sortValue ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($sortLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Scholarships</h3>
            <div class="stat-value"><?php echo count($data['programs']); ?></div>
            <p>Scholarship entries created by the admin</p>
        </div>
        <div class="stat-card">
            <h3>Pending Requests</h3>
            <div class="stat-value"><?php echo count($data['pending']); ?></div>
            <p>Applications waiting for review</p>
        </div>
        <div class="stat-card">
            <h3>Processing</h3>
            <div class="stat-value"><?php echo count($data['processing']); ?></div>
            <p>Applications currently under review</p>
        </div>
        <div class="stat-card">
            <h3>Approved Scholars</h3>
            <div class="stat-value"><?php echo count($data['approved']); ?></div>
            <p>Accepted scholarship requests</p>
        </div>
        <div class="stat-card">
            <h3>Rejected</h3>
            <div class="stat-value"><?php echo count($data['rejected']); ?></div>
            <p>Applications finalized as rejected</p>
        </div>
    </div>

    <h3 style="margin: 26px 0 10px;"><?php echo $isEditingScholarship ? 'Update Scholarship' : 'Create Scholarship'; ?></h3>
    <div class="table-wrapper" style="padding: 18px;">
        <form method="post" style="display: grid; gap: 12px; max-width: 760px;">
            <input type="hidden" name="action" value="<?php echo $isEditingScholarship ? 'update_scholarship' : 'create_scholarship'; ?>">
            <input type="hidden" name="scholarship_doc_name" value="<?php echo htmlspecialchars($editingScholarship['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

            <label for="scholarship-name"><strong>Scholarship Name</strong></label>
            <input id="scholarship-name" name="name" type="text" placeholder="Enter scholarship name" required value="<?php echo htmlspecialchars($editingScholarship['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

            <label for="scholarship-requirements"><strong>Requirements</strong></label>
            <textarea id="scholarship-requirements" name="requirements" placeholder="List the scholarship requirements" required style="min-height: 120px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; resize: vertical;"><?php echo htmlspecialchars($editingScholarship['requirements'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

            <label for="scholarship-slots"><strong>Total Slots</strong></label>
            <input id="scholarship-slots" name="totalSlots" type="number" min="1" placeholder="Enter total number of slots" required value="<?php echo htmlspecialchars((string) ($editingScholarship['totalSlots'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">
            <p class="meta-text" style="margin: -4px 0 0;">
                Available slots are calculated automatically: total slots minus approved requests.
            </p>

            <div>
                <button type="submit" class="action-btn primary"><?php echo $isEditingScholarship ? 'Update Scholarship' : 'Create Scholarship'; ?></button>
                <?php if ($isEditingScholarship): ?>
                    <a href="scholarship.php" class="action-btn secondary" style="display: inline-block; text-decoration: none; margin-left: 8px;">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <h3 style="margin: 26px 0 10px;">Pending Scholarship Requests</h3>

    <?php if (empty($data['pending'])): ?>
        <div class="empty-state">No pending scholarship requests right now.</div>
    <?php else: ?>
        <div class="request-list">
            <?php foreach ($data['pending'] as $request): ?>
                <?php $statusMeta = getScholarshipStatusMeta((string) ($request['status'] ?? 'Pending')); ?>
                <div class="request-card">
                    <div class="request-top">
                        <div>
                            <h3><?php echo htmlspecialchars($request['applicant_name'] ?? 'Unknown Applicant', ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="meta-text">
                                <?php echo htmlspecialchars($request['program'] ?? 'No Program Selected', ENT_QUOTES, 'UTF-8'); ?>
                                - <?php echo htmlspecialchars(formatScholarshipDate($request['submitted_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                        <span class="status-pill <?php echo htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <p class="meta-text"><strong>Email:</strong> <?php echo htmlspecialchars($request['applicant_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="meta-text"><strong>School:</strong> <?php echo htmlspecialchars($request['school'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="meta-text"><strong>Course:</strong> <?php echo htmlspecialchars($request['course'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if (!empty($request['proofLink'])): ?>
                        <p class="meta-text"><strong>Proof:</strong> <a href="<?php echo htmlspecialchars($request['proofLink'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">View submitted proof</a></p>
                    <?php endif; ?>
                    <p><?php echo nl2br(htmlspecialchars($request['reason'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>

                    <div class="button-row">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="start_processing">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn primary">Start Processing</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="reject_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn secondary">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 style="margin: 26px 0 10px;">Processing Scholarship Requests</h3>

    <?php if (empty($data['processing'])): ?>
        <div class="empty-state">No scholarship applications are currently being processed.</div>
    <?php else: ?>
        <div class="request-list">
            <?php foreach ($data['processing'] as $request): ?>
                <?php $statusMeta = getScholarshipStatusMeta((string) ($request['status'] ?? 'Processing')); ?>
                <div class="request-card">
                    <div class="request-top">
                        <div>
                            <h3><?php echo htmlspecialchars($request['applicant_name'] ?? 'Unknown Applicant', ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="meta-text">
                                <?php echo htmlspecialchars($request['program'] ?? 'No Program Selected', ENT_QUOTES, 'UTF-8'); ?>
                                - <?php echo htmlspecialchars(formatScholarshipDate($request['processing_at'] ?? ($request['submitted_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>
                        <span class="status-pill <?php echo htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>

                    <p class="meta-text"><strong>Email:</strong> <?php echo htmlspecialchars($request['applicant_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="meta-text"><strong>School:</strong> <?php echo htmlspecialchars($request['school'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="meta-text"><strong>Course:</strong> <?php echo htmlspecialchars($request['course'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if (!empty($request['proofLink'])): ?>
                        <p class="meta-text"><strong>Proof:</strong> <a href="<?php echo htmlspecialchars($request['proofLink'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">View submitted proof</a></p>
                    <?php endif; ?>
                    <p><?php echo nl2br(htmlspecialchars($request['reason'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>

                    <div class="button-row">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="approve_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn primary">Approve</button>
                        </form>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="action" value="reject_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                            <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="redirect_approved_sort" value="<?php echo htmlspecialchars($selectedApprovedSort, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="action-btn secondary">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h3 style="margin: 26px 0 10px;">Approved Scholarship Requests</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Program</th>
                    <th>School</th>
                    <th>Course</th>
                    <th>Proof</th>
                    <th>Approved On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['approved'])): ?>
                    <tr>
                        <td colspan="7">No approved scholarship requests yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['approved'] as $request): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($request['applicant_name'] ?? 'Unknown Applicant', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($request['program'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($request['school'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($request['course'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if (!empty($request['proofLink'])): ?>
                                    <a href="<?php echo htmlspecialchars($request['proofLink'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">View proof</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(formatScholarshipDate($request['approved_at'] ?? ($request['completed_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this scholarship history?');">
                                    <input type="hidden" name="action" value="delete_request_history">
                                    <input type="hidden" name="history_bucket" value="approved">
                                    <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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

    <h3 style="margin: 26px 0 10px;">Rejected Scholarship Requests</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Program</th>
                    <th>School</th>
                    <th>Course</th>
                    <th>Proof</th>
                    <th>Rejected On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['rejected'])): ?>
                    <tr>
                        <td colspan="7">No rejected scholarship requests yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['rejected'] as $request): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($request['applicant_name'] ?? 'Unknown Applicant', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($request['program'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($request['school'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($request['course'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if (!empty($request['proofLink'])): ?>
                                    <a href="<?php echo htmlspecialchars($request['proofLink'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">View proof</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(formatScholarshipDate($request['rejected_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this scholarship history?');">
                                    <input type="hidden" name="action" value="delete_request_history">
                                    <input type="hidden" name="history_bucket" value="rejected">
                                    <input type="hidden" name="request_id" value="<?php echo (int) ($request['id'] ?? 0); ?>">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($request['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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

    <h3 style="margin: 26px 0 10px;">Scholarship List</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Requirements</th>
                    <th>Available Slots</th>
                    <th>Total Slots</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['programs'])): ?>
                    <tr>
                        <td colspan="5">No scholarship entries are available.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['programs'] as $program): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($program['name'] ?? 'Untitled Scholarship', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo nl2br(htmlspecialchars($program['requirements'] ?? '-', ENT_QUOTES, 'UTF-8')); ?></td>
                            <td><?php echo htmlspecialchars((string) getScholarshipProgramRemainingSlots($program), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($program['totalSlots'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <a href="scholarship.php?edit_scholarship=<?php echo urlencode((string) ($program['__name'] ?? '')); ?>" class="action-btn primary" style="display: inline-block; text-decoration: none;">Edit</a>
                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this scholarship?');" style="display: inline-block; margin-left: 8px;">
                                    <input type="hidden" name="action" value="delete_scholarship">
                                    <input type="hidden" name="scholarship_doc_name" value="<?php echo htmlspecialchars($program['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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

    renderAdminPage('scholarship', 'Scholarship', 'Create scholarships and review scholarship applications.', $contentHtml);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholarship Request | Smart Iba</title>
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

        .program-item {
            border: 1px solid #dbeafe;
            background: #f8fbff;
            border-radius: 14px;
            padding: 14px;
            margin-top: 12px;
        }

        .program-item h3 {
            margin: 0 0 6px;
        }

        .meta-text {
            margin: 0 0 8px;
            font-size: 13px;
            color: #475569;
        }

        .requirements-block {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #dbeafe;
            white-space: pre-line;
        }
    </style>
</head>
<body>
    <div class="public-shell">
        <div class="public-hero">
            <h1>Scholarship Request Form</h1>
            <p>Students can submit a scholarship application here. The admin creates the scholarship list, requirements, and available slots.</p>
            <div class="hero-actions">
                <a href="login.php" class="hero-link primary">Admin Login</a>
                <a href="index.php" class="hero-link secondary">Dashboard</a>
            </div>
        </div>

        <div class="public-grid">
            <div class="public-card">
                <h2>Apply for Scholarship</h2>
                <p>Fill in your details and send your application to the admin.</p>

                <?php if ($flash): ?>
                    <div class="alert-box <?php echo $flash['type'] === 'error' ? 'alert-error' : 'alert-success'; ?>">
                        <?php echo htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="stack-form">
                    <input type="hidden" name="action" value="apply_scholarship">

                    <label class="field-label" for="applicant_name">Full name</label>
                    <input class="input-field" type="text" id="applicant_name" name="applicant_name" placeholder="Enter your full name" required>

                    <label class="field-label" for="applicant_email">Email address</label>
                    <input class="input-field" type="email" id="applicant_email" name="applicant_email" placeholder="Enter your email" required>

                    <label class="field-label" for="program">Scholarship program</label>
                    <select class="select-field" id="program" name="program" required>
                        <option value="">Select a program</option>
                        <?php foreach ($data['programs'] as $program): ?>
                            <option value="<?php echo htmlspecialchars($program['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($program['name'] ?? 'Untitled Scholarship', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="field-label" for="school">School</label>
                    <input class="input-field" type="text" id="school" name="school" placeholder="Enter your school or university">

                    <label class="field-label" for="course">Course</label>
                    <input class="input-field" type="text" id="course" name="course" placeholder="Enter your course">

                    <label class="field-label" for="reason">Why are you applying?</label>
                    <textarea class="text-area" id="reason" name="reason" placeholder="Explain why you qualify for the scholarship" required></textarea>

                    <button type="submit" class="submit-btn">Send Scholarship Request</button>
                </form>
            </div>

            <div class="public-card">
                <h2>Available Scholarships</h2>
                <p>Review the available scholarships, required documents, and total slots.</p>

                <div class="program-item">
                    <h3>Android App API</h3>
                    <p class="meta-text"><strong>GET:</strong> <code>scholarship.php?api=programs</code></p>
                    <p class="meta-text"><strong>POST:</strong> <code>scholarship.php?api=apply</code></p>
                    <p class="meta-text">Send: <code>applicant_name</code>, <code>applicant_email</code>, <code>program</code>, <code>school</code>, <code>course</code>, <code>reason</code>, <code>proofLink</code>.</p>
                </div>

                <?php if (empty($data['programs'])): ?>
                    <div class="program-item">
                        <h3>No scholarships yet</h3>
                        <p class="meta-text">The admin has not added scholarship entries yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($data['programs'] as $program): ?>
                        <div class="program-item">
                            <h3><?php echo htmlspecialchars($program['name'] ?? 'Untitled Scholarship', ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="meta-text">
                                Slots: <?php echo htmlspecialchars((string) getScholarshipProgramRemainingSlots($program), ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string) ($program['totalSlots'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <div class="requirements-block">
                                <strong>Requirements:</strong><br>
                                <?php echo nl2br(htmlspecialchars($program['requirements'] ?? 'No requirements provided.', ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
