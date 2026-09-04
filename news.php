<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

$dataFile = __DIR__ . '/includes/news_data.json';
$newsCollection = 'news';

function getNewsJsonDefault(): array
{
    return ['news' => []];
}

function loadNewsJsonData(string $dataFile): array
{
    $default = getNewsJsonDefault();

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
        'news' => array_values($decoded['news'] ?? []),
    ];
}

function saveNewsJsonData(string $dataFile, array $data): bool
{
    return file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function newsDateFromInput(string $dateInput): ?DateTimeImmutable
{
    $dateInput = trim($dateInput);
    if ($dateInput === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput, firebase_app_timezone());
    return $date instanceof DateTimeImmutable ? $date : null;
}

function parseNewsDateValue($value): ?DateTimeImmutable
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

function formatNewsDateForStorage(?DateTimeImmutable $date): string
{
    return $date instanceof DateTimeImmutable ? $date->format(DateTimeInterface::ATOM) : '';
}

function formatNewsDateForInput($value): string
{
    $date = parseNewsDateValue($value);
    return $date instanceof DateTimeImmutable ? $date->setTimezone(firebase_app_timezone())->format('Y-m-d') : '';
}

function formatNewsDateForDisplay($value): string
{
    $date = parseNewsDateValue($value);
    return $date instanceof DateTimeImmutable ? $date->setTimezone(firebase_app_timezone())->format('F j, Y') : 'Date not set';
}

function normalizeNewsMonthFilter($value): string
{
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value)) {
        return '';
    }

    $month = (int) $value;
    return $month >= 1 && $month <= 12 ? str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '';
}

function normalizeNewsYearFilter($value): string
{
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value)) {
        return '';
    }

    $year = (int) $value;
    return $year >= 1900 && $year <= 9999 ? (string) $year : '';
}

function getNewsMonthOptions(): array
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

function getNewsYearOptions(array $items): array
{
    $years = [];
    foreach ($items as $item) {
        $date = parseNewsDateValue($item['date'] ?? '');
        if ($date instanceof DateTimeImmutable) {
            $years[$date->setTimezone(firebase_app_timezone())->format('Y')] = true;
        }
    }

    $yearOptions = array_keys($years);
    rsort($yearOptions, SORT_STRING);
    return $yearOptions;
}

function filterNewsEntries(array $items, string $monthFilter, string $yearFilter): array
{
    if ($monthFilter === '' && $yearFilter === '') {
        return $items;
    }

    return array_values(array_filter($items, static function (array $item) use ($monthFilter, $yearFilter): bool {
        $date = parseNewsDateValue($item['date'] ?? '');
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

function normalizeNewsDoc(array $doc): array
{
    return [
        'title' => trim((string) ($doc['title'] ?? '')),
        'description' => trim((string) ($doc['description'] ?? $doc['content'] ?? '')),
        'date' => $doc['date'] ?? '',
        '__name' => $doc['__name'] ?? '',
    ];
}

function normalizeNewsRow(array $item, string $docName): array
{
    $item['__name'] = $docName;
    return normalizeNewsDoc($item);
}

function sortNewsEntries(array $items): array
{
    usort($items, static function (array $a, array $b): int {
        $aDate = parseNewsDateValue($a['date'] ?? '');
        $bDate = parseNewsDateValue($b['date'] ?? '');

        if ($aDate instanceof DateTimeImmutable && $bDate instanceof DateTimeImmutable) {
            $comparison = $bDate->getTimestamp() <=> $aDate->getTimestamp();
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

    return $items;
}

function loadNewsEntries(string $dataFile, string $newsCollection): array
{
    if (firebase_enabled() && firebase_firestore_enabled()) {
        $documents = firebase_firestore_list_documents($newsCollection);
        if (is_array($documents)) {
            return sortNewsEntries(array_map('normalizeNewsDoc', $documents));
        }
    }

    $jsonData = loadNewsJsonData($dataFile);
    $items = [];
    foreach (array_values($jsonData['news'] ?? []) as $index => $item) {
        $items[] = normalizeNewsRow((array) $item, 'local:' . $index);
    }

    return sortNewsEntries($items);
}

function findNewsByDocName(array $items, string $docName): ?array
{
    foreach ($items as $item) {
        if (($item['__name'] ?? '') === $docName) {
            return $item;
        }
    }

    return null;
}

function getLocalNewsIndex(string $docName): ?int
{
    if (!str_starts_with($docName, 'local:')) {
        return null;
    }

    $index = substr($docName, 6);
    return ctype_digit($index) ? (int) $index : null;
}

function buildNewsRedirectUrl(string $editingDocName = '', string $monthFilter = '', string $yearFilter = ''): string
{
    $query = [];

    if ($editingDocName !== '') {
        $query['edit_news'] = $editingDocName;
    }

    if ($monthFilter !== '') {
        $query['filter_month'] = $monthFilter;
    }

    if ($yearFilter !== '') {
        $query['filter_year'] = $yearFilter;
    }

    return 'news.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function buildNewsNotificationTitle(string $title): string
{
    $title = trim($title);
    return $title === '' ? 'News Update' : 'News: ' . $title;
}

function buildNewsNotificationBody(string $description): string
{
    $description = trim(preg_replace('/\s+/', ' ', $description));
    if ($description === '') {
        return 'A new news article has been posted.';
    }

    if (function_exists('mb_substr')) {
        return mb_strlen($description) > 120 ? mb_substr($description, 0, 117) . '...' : $description;
    }

    return strlen($description) > 120 ? substr($description, 0, 117) . '...' : $description;
}

function buildNewsBroadcastFlashText(array $notificationResult, bool $topicPushSent): string
{
    $createdCount = (int) ($notificationResult['created_count'] ?? 0);
    $failedCount = (int) ($notificationResult['failed_count'] ?? 0);
    $userCount = count($notificationResult['user_ids'] ?? []);

    if ($createdCount > 0 && $failedCount === 0 && $topicPushSent) {
        return 'News created successfully. ' . $createdCount . ' user notification records were created and the topic push was sent.';
    }

    if ($createdCount > 0 && $failedCount === 0) {
        return 'News created successfully. ' . $createdCount . ' user notification records were created, but the topic push could not be sent.';
    }

    if ($userCount === 0 && $topicPushSent) {
        return 'News created successfully and the topic push was sent, but no user notification records were created because no users were found.';
    }

    if ($createdCount > 0) {
        return 'News created successfully. ' . $createdCount . ' user notification records were created, but ' . $failedCount . ' failed and the topic push ' . ($topicPushSent ? 'was sent.' : 'could not be sent.');
    }

    if ($topicPushSent) {
        return 'News created successfully and the topic push was sent, but the user notification records could not be created.';
    }

    $error = trim((string) (firebase_get_last_error() ?? ''));
    return $error !== ''
        ? 'News created successfully, but notification delivery failed. ' . $error
        : 'News created successfully, but notification delivery failed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $docName = trim((string) ($_POST['news_doc_name'] ?? ''));
    $monthFilter = normalizeNewsMonthFilter($_POST['filter_month'] ?? '');
    $yearFilter = normalizeNewsYearFilter($_POST['filter_year'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $dateInput = trim((string) ($_POST['date'] ?? ''));
    $dateValue = newsDateFromInput($dateInput);

    $flash = ['type' => 'error', 'text' => 'Unable to process the news request.'];
    $redirectDocName = '';
    $items = loadNewsEntries($dataFile, $newsCollection);

    if ($action === 'create_news' || $action === 'update_news') {
        if ($title === '' || $description === '' || $dateValue === null) {
            $flash = ['type' => 'error', 'text' => 'Please complete the title, description, and date fields.'];
            $redirectDocName = $action === 'update_news' ? $docName : '';
        } else {
            $payload = [
                'title' => $title,
                'description' => $description,
                'date' => $dateValue,
            ];

            if ($action === 'create_news') {
                if (firebase_enabled() && firebase_firestore_enabled()) {
                    $created = firebase_firestore_create_document($newsCollection, $payload);
                    if ($created !== null) {
                        $notificationTitle = buildNewsNotificationTitle($title);
                        $notificationBody = buildNewsNotificationBody($description);
                        $notificationResult = firebase_create_notifications_for_all_users(
                            $notificationTitle,
                            'A new news article was posted on ' . formatNewsDateForDisplay($dateValue) . '.',
                            'news',
                            [
                                'newsTitle' => $title,
                                'newsDate' => formatNewsDateForInput($dateValue),
                                'newsDescription' => $description,
                                'newsDocName' => (string) ($created['__name'] ?? ''),
                            ]
                        );
                        $notificationSent = sendTopicPushNotification(
                            FIREBASE_FCM_NEWS_PUSH_TOPIC,
                            $notificationTitle,
                            $notificationBody,
                            [
                                'type' => 'news',
                                'news_title' => $title,
                                'news_date' => formatNewsDateForInput($dateValue),
                                'news_description' => $description,
                            ]
                        );

                        $flash = ['type' => 'success', 'text' => buildNewsBroadcastFlashText($notificationResult, $notificationSent)];
                    } else {
                        $flash = ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The news article could not be created.'];
                    }
                } else {
                    $jsonData = loadNewsJsonData($dataFile);
                    $jsonData['news'][] = [
                        'title' => $title,
                        'description' => $description,
                        'date' => formatNewsDateForStorage($dateValue),
                    ];

                    if (saveNewsJsonData($dataFile, $jsonData)) {
                        $notificationTitle = buildNewsNotificationTitle($title);
                        $notificationBody = buildNewsNotificationBody($description);
                        $notificationResult = firebase_create_notifications_for_all_users(
                            $notificationTitle,
                            'A new news article was posted on ' . formatNewsDateForDisplay($dateValue) . '.',
                            'news',
                            [
                                'newsTitle' => $title,
                                'newsDate' => formatNewsDateForInput($dateValue),
                                'newsDescription' => $description,
                            ]
                        );
                        $notificationSent = sendTopicPushNotification(
                            FIREBASE_FCM_NEWS_PUSH_TOPIC,
                            $notificationTitle,
                            $notificationBody,
                            [
                                'type' => 'news',
                                'news_title' => $title,
                                'news_date' => formatNewsDateForInput($dateValue),
                                'news_description' => $description,
                            ]
                        );

                        $flash = ['type' => 'success', 'text' => buildNewsBroadcastFlashText($notificationResult, $notificationSent)];
                    } else {
                        $flash = ['type' => 'error', 'text' => 'The news article could not be saved.'];
                    }
                }
            } else {
                $existingItem = findNewsByDocName($items, $docName);
                if ($existingItem === null) {
                    $flash = ['type' => 'error', 'text' => 'The selected news article was not found.'];
                } elseif (firebase_enabled() && firebase_firestore_enabled() && getLocalNewsIndex($docName) === null) {
                    $success = firebase_firestore_patch_document($docName, $payload);
                    $flash = $success
                        ? ['type' => 'success', 'text' => 'News article updated successfully.']
                        : ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The news article could not be updated.'];
                    $redirectDocName = !$success ? $docName : '';
                } else {
                    $jsonData = loadNewsJsonData($dataFile);
                    $localIndex = getLocalNewsIndex($docName);

                    if ($localIndex === null || !isset($jsonData['news'][$localIndex])) {
                        $flash = ['type' => 'error', 'text' => 'The selected news article was not found.'];
                    } else {
                        $jsonData['news'][$localIndex] = array_merge(
                            (array) $jsonData['news'][$localIndex],
                            [
                                'title' => $title,
                                'description' => $description,
                                'date' => formatNewsDateForStorage($dateValue),
                            ]
                        );

                        $flash = saveNewsJsonData($dataFile, $jsonData)
                            ? ['type' => 'success', 'text' => 'News article updated successfully.']
                            : ['type' => 'error', 'text' => 'The news article could not be updated.'];

                        $redirectDocName = $flash['type'] === 'error' ? $docName : '';
                    }
                }
            }
        }
    } elseif ($action === 'delete_news') {
        $existingItem = findNewsByDocName($items, $docName);

        if ($existingItem === null) {
            $flash = ['type' => 'error', 'text' => 'The selected news article was not found.'];
        } elseif (firebase_enabled() && firebase_firestore_enabled() && getLocalNewsIndex($docName) === null) {
            $success = firebase_firestore_delete_document($docName);
            $flash = $success
                ? ['type' => 'success', 'text' => 'News article deleted successfully.']
                : ['type' => 'error', 'text' => firebase_get_last_error() ?: 'The news article could not be deleted.'];
        } else {
            $jsonData = loadNewsJsonData($dataFile);
            $localIndex = getLocalNewsIndex($docName);

            if ($localIndex === null || !isset($jsonData['news'][$localIndex])) {
                $flash = ['type' => 'error', 'text' => 'The selected news article was not found.'];
            } else {
                array_splice($jsonData['news'], $localIndex, 1);
                $flash = saveNewsJsonData($dataFile, $jsonData)
                    ? ['type' => 'success', 'text' => 'News article deleted successfully.']
                    : ['type' => 'error', 'text' => 'The news article could not be deleted.'];
            }
        }
    }

    $_SESSION['news_flash'] = $flash;
    header('Location: ' . buildNewsRedirectUrl($redirectDocName, $monthFilter, $yearFilter));
    exit;
}

$items = loadNewsEntries($dataFile, $newsCollection);
$flash = $_SESSION['news_flash'] ?? null;
unset($_SESSION['news_flash']);

$monthFilter = normalizeNewsMonthFilter($_GET['filter_month'] ?? '');
$yearFilter = normalizeNewsYearFilter($_GET['filter_year'] ?? '');
$editingDocName = trim((string) ($_GET['edit_news'] ?? ''));
$editingItem = $editingDocName !== '' ? findNewsByDocName($items, $editingDocName) : null;
$isEditingNews = is_array($editingItem);
$monthOptions = getNewsMonthOptions();
$yearOptions = getNewsYearOptions($items);
$filteredItems = filterNewsEntries($items, $monthFilter, $yearFilter);

$withDescriptionCount = 0;
foreach ($items as $item) {
    if (trim((string) ($item['description'] ?? '')) !== '') {
        $withDescriptionCount++;
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
        <h3>Total News</h3>
        <div class="stat-value"><?php echo count($items); ?></div>
        <p>News articles managed by the admin</p>
    </div>
    <div class="stat-card">
        <h3>With Description</h3>
        <div class="stat-value"><?php echo $withDescriptionCount; ?></div>
        <p>Articles that already have details</p>
    </div>
    <div class="stat-card">
        <h3>Filtered Results</h3>
        <div class="stat-value"><?php echo count($filteredItems); ?></div>
        <p>Articles matching the selected month and year</p>
    </div>
</div>

<h3 style="margin: 26px 0 10px;"><?php echo $isEditingNews ? 'Update News Article' : 'Create News Article'; ?></h3>
<div class="table-wrapper" style="padding: 18px;">
    <form method="post" style="display: grid; gap: 12px; max-width: 760px;">
        <input type="hidden" name="action" value="<?php echo $isEditingNews ? 'update_news' : 'create_news'; ?>">
        <input type="hidden" name="news_doc_name" value="<?php echo htmlspecialchars($editingItem['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_month" value="<?php echo htmlspecialchars($monthFilter, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_year" value="<?php echo htmlspecialchars($yearFilter, ENT_QUOTES, 'UTF-8'); ?>">

        <label for="news-title"><strong>Title</strong></label>
        <input id="news-title" name="title" type="text" placeholder="Enter news title" required value="<?php echo htmlspecialchars($editingItem['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <label for="news-description"><strong>Description</strong></label>
        <textarea id="news-description" name="description" required placeholder="Enter news description" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; min-height: 140px; resize: vertical;"><?php echo htmlspecialchars($editingItem['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

        <label for="news-date"><strong>Date</strong></label>
        <input id="news-date" name="date" type="date" required value="<?php echo htmlspecialchars(formatNewsDateForInput($editingItem['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;">

        <div>
            <button type="submit" class="action-btn primary"><?php echo $isEditingNews ? 'Update News' : 'Create News'; ?></button>
            <?php if ($isEditingNews): ?>
                <a href="news.php" class="action-btn secondary" style="display: inline-block; text-decoration: none; margin-left: 8px;">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<h3 style="margin: 26px 0 10px;">News List</h3>

<div class="filter-row" style="justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
    <p class="meta-text" style="margin: 0;">
        Showing <?php echo count($filteredItems); ?> of <?php echo count($items); ?> news articles
    </p>
    <form method="get" class="filter-form">
        <?php if ($isEditingNews): ?>
            <input type="hidden" name="edit_news" value="<?php echo htmlspecialchars($editingItem['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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
        <a href="<?php echo $isEditingNews ? htmlspecialchars(buildNewsRedirectUrl($editingItem['__name'] ?? ''), ENT_QUOTES, 'UTF-8') : 'news.php'; ?>" class="action-btn secondary" style="display: inline-block; text-decoration: none;">Clear</a>
    </form>
</div>

<?php if (empty($filteredItems)): ?>
    <div class="empty-state">
        <?php echo ($monthFilter !== '' || $yearFilter !== '') ? 'No news articles match the selected month and year filter.' : 'No news articles are available yet.'; ?>
    </div>
<?php else: ?>
    <div class="directory-grid">
        <?php foreach ($filteredItems as $item): ?>
            <div class="directory-card">
                <div class="request-top" style="margin-bottom: 10px;">
                    <div>
                        <h3><?php echo htmlspecialchars($item['title'] ?? 'Untitled News', ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="meta-text"><?php echo htmlspecialchars(formatNewsDateForDisplay($item['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <span class="status-pill status-updated">News</span>
                </div>

                <p class="meta-text"><strong>Date:</strong> <?php echo htmlspecialchars(formatNewsDateForDisplay($item['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="meta-text"><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($item['description'] ?? '-', ENT_QUOTES, 'UTF-8')); ?></p>

                <div class="button-row">
                    <a href="<?php echo htmlspecialchars(buildNewsRedirectUrl((string) ($item['__name'] ?? ''), $monthFilter, $yearFilter), ENT_QUOTES, 'UTF-8'); ?>" class="action-btn primary" style="display: inline-block; text-decoration: none;">Edit</a>
                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this news article?');">
                        <input type="hidden" name="action" value="delete_news">
                        <input type="hidden" name="news_doc_name" value="<?php echo htmlspecialchars($item['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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

renderAdminPage('news', 'News', 'Create, update, and delete user-visible news articles stored in the Firestore news collection.', $contentHtml);
