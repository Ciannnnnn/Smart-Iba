<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

$dataFile = __DIR__ . '/includes/events_data.json';
$eventCollection = 'events';

function getEventsJsonDefault(): array
{
    return ['events' => []];
}

function loadEventsJsonData(string $dataFile): array
{
    $default = getEventsJsonDefault();

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
        'events' => array_values($decoded['events'] ?? []),
    ];
}

function saveEventsJsonData(string $dataFile, array $data): bool
{
    return file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function eventFirstNonEmptyString(...$values): string
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

function eventDateFromInput(string $dateInput): ?DateTimeImmutable
{
    $dateInput = trim($dateInput);
    if ($dateInput === '') {
        return null;
    }

    $timezone = firebase_app_timezone();
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput, $timezone);
    return $date instanceof DateTimeImmutable ? $date : null;
}

function parseEventDateValue($value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }

    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($text, firebase_app_timezone());
    } catch (Throwable $exception) {
        return null;
    }
}

function formatEventDateForStorage(?DateTimeImmutable $date): string
{
    return $date instanceof DateTimeImmutable ? $date->format(DateTimeInterface::ATOM) : '';
}

function formatEventDateForInput($value): string
{
    $date = parseEventDateValue($value);
    return $date instanceof DateTimeImmutable ? $date->setTimezone(firebase_app_timezone())->format('Y-m-d') : '';
}

function formatEventDateForDisplay($value): string
{
    $date = parseEventDateValue($value);
    return $date instanceof DateTimeImmutable ? $date->setTimezone(firebase_app_timezone())->format('F j, Y') : 'Date not set';
}

function buildEventNotificationTitle(string $title): string
{
    $title = trim($title);
    return $title === '' ? 'New Event' : 'New Event: ' . $title;
}

function buildEventNotificationBody(string $title, $dateValue, string $time, string $location): string
{
    $dateText = formatEventDateForDisplay($dateValue);
    $time = trim($time);
    $location = trim($location);

    $parts = [];
    if ($dateText !== 'Date not set') {
        $parts[] = 'on ' . $dateText;
    }

    if ($time !== '') {
        $parts[] = 'at ' . $time;
    }

    if ($location !== '') {
        $parts[] = 'in ' . $location;
    }

    if ($parts === []) {
        return 'A new event has been posted.';
    }

    return 'Join us ' . implode(' ', $parts) . '!';
}

function buildEventBroadcastFlashText(array $notificationResult, bool $topicPushSent): string
{
    $createdCount = (int) ($notificationResult['created_count'] ?? 0);
    $failedCount = (int) ($notificationResult['failed_count'] ?? 0);
    $userCount = count($notificationResult['user_ids'] ?? []);

    if ($createdCount > 0 && $failedCount === 0 && $topicPushSent) {
        return 'Event created successfully. ' . $createdCount . ' user notification records were created and the topic push was sent.';
    }

    if ($createdCount > 0 && $failedCount === 0) {
        return 'Event created successfully. ' . $createdCount . ' user notification records were created, but the topic push could not be sent.';
    }

    if ($userCount === 0 && $topicPushSent) {
        return 'Event created successfully and the topic push was sent, but no user notification records were created because no users were found.';
    }

    if ($createdCount > 0) {
        return 'Event created successfully. ' . $createdCount . ' user notification records were created, but ' . $failedCount . ' failed and the topic push ' . ($topicPushSent ? 'was sent.' : 'could not be sent.');
    }

    if ($topicPushSent) {
        return 'Event created successfully and the topic push was sent, but the user notification records could not be created.';
    }

    $error = trim((string) (firebase_get_last_error() ?? ''));
    return $error !== ''
        ? 'Event created successfully, but notification delivery failed. ' . $error
        : 'Event created successfully, but notification delivery failed.';
}

function normalizeEventMonthFilter($value): string
{
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value)) {
        return '';
    }

    $month = (int) $value;
    return $month >= 1 && $month <= 12 ? str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '';
}

function normalizeEventYearFilter($value): string
{
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value)) {
        return '';
    }

    $year = (int) $value;
    return $year >= 1900 && $year <= 9999 ? (string) $year : '';
}

function getEventMonthOptions(): array
{
    return [
        '01' => 'January',
        '02' => 'February',
        '03' => 'March',
        '04' => 'April',
        '05' => 'May',
        '06' => 'June',
        '07' => 'July',
        '08' => 'August',
        '09' => 'September',
        '10' => 'October',
        '11' => 'November',
        '12' => 'December',
    ];
}

function getEventYearOptions(array $events): array
{
    $years = [];

    foreach ($events as $event) {
        $date = parseEventDateValue($event['date'] ?? '');
        if ($date instanceof DateTimeImmutable) {
            $years[$date->setTimezone(firebase_app_timezone())->format('Y')] = true;
        }
    }

    $yearOptions = array_keys($years);
    rsort($yearOptions, SORT_STRING);

    return $yearOptions;
}

function filterEventEntries(array $events, string $monthFilter, string $yearFilter): array
{
    if ($monthFilter === '' && $yearFilter === '') {
        return $events;
    }

    return array_values(array_filter($events, static function (array $event) use ($monthFilter, $yearFilter): bool {
        $date = parseEventDateValue($event['date'] ?? '');
        if (!$date instanceof DateTimeImmutable) {
            return false;
        }

        $localDate = $date->setTimezone(firebase_app_timezone());
        if ($monthFilter !== '' && $localDate->format('m') !== $monthFilter) {
            return false;
        }

        if ($yearFilter !== '' && $localDate->format('Y') !== $yearFilter) {
            return false;
        }

        return true;
    }));
}

function normalizeEventDoc(array $doc): array
{
    return [
        'title' => eventFirstNonEmptyString($doc['title'] ?? '', $doc['name'] ?? ''),
        'location' => eventFirstNonEmptyString($doc['location'] ?? '', $doc['venue'] ?? ''),
        'time' => eventFirstNonEmptyString($doc['time'] ?? '', $doc['schedule'] ?? ''),
        'date' => $doc['date'] ?? '',
        '__name' => $doc['__name'] ?? '',
    ];
}

function normalizeEventRow(array $event, string $docName): array
{
    $event['__name'] = $docName;
    return normalizeEventDoc($event);
}

function sortEventEntries(array $events): array
{
    usort($events, static function (array $a, array $b): int {
        $aDate = parseEventDateValue($a['date'] ?? '');
        $bDate = parseEventDateValue($b['date'] ?? '');

        if ($aDate instanceof DateTimeImmutable && $bDate instanceof DateTimeImmutable) {
            $comparison = $aDate->getTimestamp() <=> $bDate->getTimestamp();
            if ($comparison !== 0) {
                return $comparison;
            }
        } elseif ($aDate instanceof DateTimeImmutable) {
            return -1;
        } elseif ($bDate instanceof DateTimeImmutable) {
            return 1;
        }

        return strcmp(
            strtolower(trim((string) ($a['title'] ?? ''))),
            strtolower(trim((string) ($b['title'] ?? '')))
        );
    });

    return $events;
}

function loadEventEntries(string $dataFile, string $eventCollection): array
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents($eventCollection);
        if (is_array($documents)) {
            return sortEventEntries(array_map('normalizeEventDoc', $documents));
        }
    }

    $jsonData = loadEventsJsonData($dataFile);
    $events = [];
    foreach (array_values($jsonData['events'] ?? []) as $index => $event) {
        $events[] = normalizeEventRow((array) $event, 'local:' . $index);
    }

    return sortEventEntries($events);
}

function findEventByDocName(array $events, string $docName): ?array
{
    foreach ($events as $event) {
        if (($event['__name'] ?? '') === $docName) {
            return $event;
        }
    }

    return null;
}

function getLocalEventIndex(string $docName): ?int
{
    if (!str_starts_with($docName, 'local:')) {
        return null;
    }

    $index = substr($docName, 6);
    return ctype_digit($index) ? (int) $index : null;
}

function buildEventRedirectUrl(string $editingDocName = '', string $monthFilter = '', string $yearFilter = ''): string
{
    $query = [];

    if ($editingDocName !== '') {
        $query['edit_event'] = $editingDocName;
    }

    if ($monthFilter !== '') {
        $query['filter_month'] = $monthFilter;
    }

    if ($yearFilter !== '') {
        $query['filter_year'] = $yearFilter;
    }

    if ($query === []) {
        return 'event.php';
    }

    return 'event.php?' . http_build_query($query);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $docName = trim((string) ($_POST['event_doc_name'] ?? ''));
    $monthFilter = normalizeEventMonthFilter($_POST['filter_month'] ?? '');
    $yearFilter = normalizeEventYearFilter($_POST['filter_year'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $time = trim((string) ($_POST['time'] ?? ''));
    $dateInput = trim((string) ($_POST['date'] ?? ''));
    $dateValue = eventDateFromInput($dateInput);

    $flash = ['type' => 'error', 'text' => 'Unable to process the event request.'];
    $redirectDocName = '';
    $events = loadEventEntries($dataFile, $eventCollection);

    if ($action === 'create_event' || $action === 'update_event') {
        if ($title === '' || $location === '' || $time === '' || $dateValue === null) {
            $flash = ['type' => 'error', 'text' => 'Please complete the title, date, time, and location fields.'];
            $redirectDocName = $action === 'update_event' ? $docName : '';
        } else {
            $payload = [
                'title' => $title,
                'date' => $dateValue,
                'time' => $time,
                'location' => $location,
            ];

            if ($action === 'create_event') {
                if (firebase_enabled() && firebase_firestore_enabled()) {
                    $created = firebase_firestore_create_document($eventCollection, $payload);
                    if ($created !== null) {
                        $notificationTitle = buildEventNotificationTitle($title);
                        $notificationBody = buildEventNotificationBody($title, $dateValue, $time, $location);
                        $notificationResult = firebase_create_notifications_for_all_users(
                            $notificationTitle,
                            'A new event has been scheduled for ' . formatEventDateForDisplay($dateValue) . '.',
                            'event',
                            [
                                'eventTitle' => $title,
                                'eventDate' => formatEventDateForInput($dateValue),
                                'eventTime' => $time,
                                'eventLocation' => $location,
                                'eventDocName' => (string) ($created['__name'] ?? ''),
                            ]
                        );
                        $notificationSent = sendTopicPushNotification(
                            FIREBASE_FCM_EVENTS_TOPIC,
                            $notificationTitle,
                            $notificationBody,
                            [
                                'type' => 'event',
                                'event_title' => $title,
                                'event_date' => formatEventDateForInput($dateValue),
                                'event_time' => $time,
                                'event_location' => $location,
                            ]
                        );

                        $flash = ['type' => 'success', 'text' => buildEventBroadcastFlashText($notificationResult, $notificationSent)];
                    } else {
                        $flash = ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The event could not be created.'];
                    }
                } else {
                    $jsonData = loadEventsJsonData($dataFile);
                    $jsonData['events'][] = [
                        'title' => $title,
                        'date' => formatEventDateForStorage($dateValue),
                        'time' => $time,
                        'location' => $location,
                    ];
                    if (saveEventsJsonData($dataFile, $jsonData)) {
                        $notificationTitle = buildEventNotificationTitle($title);
                        $notificationBody = buildEventNotificationBody($title, $dateValue, $time, $location);
                        $notificationResult = firebase_create_notifications_for_all_users(
                            $notificationTitle,
                            'A new event has been scheduled for ' . formatEventDateForDisplay($dateValue) . '.',
                            'event',
                            [
                                'eventTitle' => $title,
                                'eventDate' => formatEventDateForInput($dateValue),
                                'eventTime' => $time,
                                'eventLocation' => $location,
                            ]
                        );
                        $notificationSent = sendTopicPushNotification(
                            FIREBASE_FCM_EVENTS_TOPIC,
                            $notificationTitle,
                            $notificationBody,
                            [
                                'type' => 'event',
                                'event_title' => $title,
                                'event_date' => formatEventDateForInput($dateValue),
                                'event_time' => $time,
                                'event_location' => $location,
                            ]
                        );

                        $flash = ['type' => 'success', 'text' => buildEventBroadcastFlashText($notificationResult, $notificationSent)];
                    } else {
                        $flash = ['type' => 'error', 'text' => 'The event could not be saved.'];
                    }
                }
            } else {
                $existingEvent = findEventByDocName($events, $docName);
                if ($existingEvent === null) {
                    $flash = ['type' => 'error', 'text' => 'The selected event was not found.'];
                } elseif (firebase_enabled() && firebase_firestore_enabled() && getLocalEventIndex($docName) === null) {
                    $success = firebase_firestore_patch_document($docName, $payload);
                    $flash = $success
                        ? ['type' => 'success', 'text' => 'Event updated successfully.']
                        : ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The event could not be updated.'];
                    $redirectDocName = !$success ? $docName : '';
                } else {
                    $jsonData = loadEventsJsonData($dataFile);
                    $localIndex = getLocalEventIndex($docName);

                    if ($localIndex === null || !isset($jsonData['events'][$localIndex])) {
                        $flash = ['type' => 'error', 'text' => 'The selected event was not found.'];
                    } else {
                        $jsonData['events'][$localIndex] = array_merge(
                            (array) $jsonData['events'][$localIndex],
                            [
                                'title' => $title,
                                'date' => formatEventDateForStorage($dateValue),
                                'time' => $time,
                                'location' => $location,
                            ]
                        );

                        $flash = saveEventsJsonData($dataFile, $jsonData)
                            ? ['type' => 'success', 'text' => 'Event updated successfully.']
                            : ['type' => 'error', 'text' => 'The event could not be updated.'];

                        $redirectDocName = $flash['type'] === 'error' ? $docName : '';
                    }
                }
            }
        }
    } elseif ($action === 'delete_event') {
        $existingEvent = findEventByDocName($events, $docName);

        if ($existingEvent === null) {
            $flash = ['type' => 'error', 'text' => 'The selected event was not found.'];
        } elseif (firebase_enabled() && firebase_firestore_enabled() && getLocalEventIndex($docName) === null) {
            $success = firebase_firestore_delete_document($docName);
            $flash = $success
                ? ['type' => 'success', 'text' => 'Event deleted successfully.']
                : ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The event could not be deleted.'];
        } else {
            $jsonData = loadEventsJsonData($dataFile);
            $localIndex = getLocalEventIndex($docName);

            if ($localIndex === null || !isset($jsonData['events'][$localIndex])) {
                $flash = ['type' => 'error', 'text' => 'The selected event was not found.'];
            } else {
                array_splice($jsonData['events'], $localIndex, 1);
                $flash = saveEventsJsonData($dataFile, $jsonData)
                    ? ['type' => 'success', 'text' => 'Event deleted successfully.']
                    : ['type' => 'error', 'text' => 'The event could not be deleted.'];
            }
        }
    }

    $_SESSION['event_flash'] = $flash;
    header('Location: ' . buildEventRedirectUrl($redirectDocName, $monthFilter, $yearFilter));
    exit;
}

$events = loadEventEntries($dataFile, $eventCollection);
$flash = $_SESSION['event_flash'] ?? null;
unset($_SESSION['event_flash']);

$monthFilter = normalizeEventMonthFilter($_GET['filter_month'] ?? '');
$yearFilter = normalizeEventYearFilter($_GET['filter_year'] ?? '');
$editingDocName = trim((string) ($_GET['edit_event'] ?? ''));
$editingEvent = $editingDocName !== '' ? findEventByDocName($events, $editingDocName) : null;
$isEditingEvent = is_array($editingEvent);
$monthOptions = getEventMonthOptions();
$yearOptions = getEventYearOptions($events);
$filteredEvents = filterEventEntries($events, $monthFilter, $yearFilter);

$withLocationCount = 0;
$withTimeCount = 0;

foreach ($events as $event) {
    if (trim((string) ($event['location'] ?? '')) !== '') {
        $withLocationCount++;
    }

    if (trim((string) ($event['time'] ?? '')) !== '') {
        $withTimeCount++;
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
        <h3>Total Events</h3>
        <div class="stat-value"><?php echo count($events); ?></div>
        <p>Event records managed by the admin</p>
    </div>
    <div class="stat-card">
        <h3>With Location</h3>
        <div class="stat-value"><?php echo $withLocationCount; ?></div>
        <p>Events that already have a location</p>
    </div>
    <div class="stat-card">
        <h3>With Time</h3>
        <div class="stat-value"><?php echo $withTimeCount; ?></div>
        <p>Events that already have a time</p>
    </div>
</div>

<h3 style="margin: 26px 0 10px;"><?php echo $isEditingEvent ? 'Update Event' : 'Create Event'; ?></h3>
<div class="table-wrapper" style="padding: 18px;">
    <form method="post" style="display: grid; gap: 12px; max-width: 760px;">
        <input type="hidden" name="action" value="<?php echo $isEditingEvent ? 'update_event' : 'create_event'; ?>">
        <input type="hidden" name="event_doc_name" value="<?php echo htmlspecialchars($editingEvent['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_month" value="<?php echo htmlspecialchars($monthFilter, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_year" value="<?php echo htmlspecialchars($yearFilter, ENT_QUOTES, 'UTF-8'); ?>">

        <label for="event-title"><strong>Title</strong></label>
        <input id="event-title" name="title" type="text" placeholder="Enter event title" required value="<?php echo htmlspecialchars($editingEvent['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="event-date"><strong>Date</strong></label>
        <input id="event-date" name="date" type="date" required value="<?php echo htmlspecialchars(formatEventDateForInput($editingEvent['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="event-time"><strong>Time</strong></label>
        <input id="event-time" name="time" type="text" placeholder="Example: 8:00 AM - 10:00 AM" required value="<?php echo htmlspecialchars($editingEvent['time'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="event-location"><strong>Location</strong></label>
        <input id="event-location" name="location" type="text" placeholder="Enter event location" required value="<?php echo htmlspecialchars($editingEvent['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <div>
            <button type="submit" class="action-btn primary"><?php echo $isEditingEvent ? 'Update Event' : 'Create Event'; ?></button>
            <?php if ($isEditingEvent): ?>
                <a href="event.php" class="action-btn secondary" style="display: inline-block; text-decoration: none; margin-left: 8px;">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<h3 style="margin: 26px 0 10px;">Event List</h3>

<div class="filter-row" style="justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
    <p class="meta-text" style="margin: 0;">
        Showing <?php echo count($filteredEvents); ?> of <?php echo count($events); ?> events
    </p>
    <form method="get" class="filter-form">
        <?php if ($isEditingEvent): ?>
            <input type="hidden" name="edit_event" value="<?php echo htmlspecialchars($editingEvent['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>

        <label for="filter-month">Month</label>
        <select id="filter-month" name="filter_month">
            <option value="">All months</option>
            <?php foreach ($monthOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $monthFilter === $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="filter-year">Year</label>
        <select id="filter-year" name="filter_year">
            <option value="">All years</option>
            <?php foreach ($yearOptions as $value): ?>
                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $yearFilter === $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="action-btn primary">Apply Filter</button>
        <a href="<?php echo $isEditingEvent ? htmlspecialchars(buildEventRedirectUrl($editingEvent['__name'] ?? ''), ENT_QUOTES, 'UTF-8') : 'event.php'; ?>" class="action-btn secondary" style="display: inline-block; text-decoration: none;">Clear</a>
    </form>
</div>

<?php if (empty($filteredEvents)): ?>
    <div class="empty-state">
        <?php echo ($monthFilter !== '' || $yearFilter !== '') ? 'No events match the selected month and year filter.' : 'No events are available yet.'; ?>
    </div>
<?php else: ?>
    <div class="directory-grid">
        <?php foreach ($filteredEvents as $event): ?>
            <div class="directory-card">
                <div class="request-top" style="margin-bottom: 10px;">
                    <div>
                        <h3><?php echo htmlspecialchars($event['title'] ?? 'Untitled Event', ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="meta-text"><?php echo htmlspecialchars(formatEventDateForDisplay($event['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <span class="status-pill status-updated">
                        <?php echo htmlspecialchars($event['time'] ?? 'Time not set', ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>

                <p class="meta-text"><strong>Date:</strong> <?php echo htmlspecialchars(formatEventDateForDisplay($event['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="meta-text"><strong>Time:</strong> <?php echo htmlspecialchars($event['time'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="meta-text"><strong>Location:</strong> <?php echo htmlspecialchars($event['location'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>

                <div class="button-row">
                    <a href="<?php echo htmlspecialchars(buildEventRedirectUrl((string) ($event['__name'] ?? ''), $monthFilter, $yearFilter), ENT_QUOTES, 'UTF-8'); ?>" class="action-btn primary" style="display: inline-block; text-decoration: none;">Edit</a>
                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this event?');">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_doc_name" value="<?php echo htmlspecialchars($event['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="filter_month" value="<?php echo htmlspecialchars($monthFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="filter_year" value="<?php echo htmlspecialchars($yearFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="action-btn secondary">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
$contentHtml = ob_get_clean();

renderAdminPage('event', 'Event', 'Create, update, and delete user-visible events stored in the Firestore events collection.', $contentHtml);
