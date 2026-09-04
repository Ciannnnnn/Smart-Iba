<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/firebase_config.php';

function normalizeReportStatus($status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        '', 'pending' => 'Pending',
        'investigating', 'investigate', 'investigation' => 'Investigating',
        'resolved', 'resolve', 'done', 'completed' => 'Resolved',
        'rejected', 'reject' => 'Rejected',
        default => trim((string) $status) !== '' ? trim((string) $status) : 'Pending',
    };
}

function getReportStatusMeta(string $status): array
{
    return match (normalizeReportStatus($status)) {
        'Pending' => ['label' => 'Pending', 'class' => 'status-review'],
        'Investigating' => ['label' => 'Investigating', 'class' => 'status-updated'],
        'Rejected' => ['label' => 'Rejected', 'class' => 'status-danger'],
        default => ['label' => 'Resolved', 'class' => 'status-active'],
    };
}

function normalizeReportDoc(array $doc): array
{
    return [
        'reporter_name' => trim((string) ($doc['userName'] ?? 'Unknown Reporter')),
        'subject' => trim((string) ($doc['subject'] ?? 'No Subject')),
        'location' => trim((string) ($doc['location'] ?? 'No Location')),
        'description' => trim((string) ($doc['description'] ?? '')),
        'status' => normalizeReportStatus($doc['status'] ?? 'Pending'),
        'timestamp' => (string) ($doc['timestamp'] ?? ''),
        'user_email' => trim((string) ($doc['userEmail'] ?? '')),
        'user_id' => trim((string) ($doc['userId'] ?? '')),
        '__name' => (string) ($doc['__name'] ?? ''),
    ];
}

function formatReportDate(?string $value): string
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

function sortReportsNewestFirst(array $reports): array
{
    usort($reports, static function (array $a, array $b): int {
        $aTime = strtotime((string) ($a['timestamp'] ?? ''));
        $bTime = strtotime((string) ($b['timestamp'] ?? ''));
        $aTime = $aTime === false ? 0 : $aTime;
        $bTime = $bTime === false ? 0 : $bTime;

        return $bTime <=> $aTime;
    });

    return $reports;
}

function splitReportRowsByState(array $reports): array
{
    $active = [];
    $history = [];

    foreach ($reports as $report) {
        $status = normalizeReportStatus($report['status'] ?? 'Pending');

        if ($status === 'Resolved' || $status === 'Rejected') {
            $history[] = $report;
            continue;
        }

        $active[] = $report;
    }

    return [
        'active' => $active,
        'history' => $history,
    ];
}

$flash = $_SESSION['reports_flash'] ?? null;
unset($_SESSION['reports_flash']);
$reportsError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $flash = ['type' => 'error', 'text' => 'Unable to process the reports action.'];

    if ($action === 'update_report_status' || $action === 'delete_report') {
        $docName = trim((string) ($_POST['doc_name'] ?? ''));
        $allowedStatuses = ['Pending', 'Investigating', 'Resolved', 'Rejected'];
        $status = normalizeReportStatus($_POST['status'] ?? 'Pending');

        if ($docName === '') {
            $flash = ['type' => 'error', 'text' => 'The selected report could not be updated.'];
        } elseif (!firebase_enabled() || !firebase_firestore_enabled()) {
            $flash = ['type' => 'error', 'text' => 'Firestore is required to manage reports.'];
        } elseif ($action === 'delete_report') {
            $success = firebase_firestore_delete_document($docName);
            $flash = $success
                ? ['type' => 'success', 'text' => 'The report history entry was deleted successfully.']
                : ['type' => 'error', 'text' => firebase_get_last_error() ?? 'The report could not be deleted.'];
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $flash = ['type' => 'error', 'text' => 'The selected report could not be updated.'];
        } elseif ($status === 'Rejected') {
            $success = firebase_firestore_delete_document($docName);
            $flash = $success
                ? ['type' => 'success', 'text' => 'The report was rejected and deleted successfully.']
                : ['type' => 'error', 'text' => firebase_get_last_error() ?? 'The report could not be rejected.'];
        } else {
            $success = firebase_firestore_patch_document($docName, ['status' => $status]);
            $flash = $success
                ? ['type' => 'success', 'text' => 'The report status was updated successfully.']
                : ['type' => 'error', 'text' => firebase_get_last_error() ?? 'The report status could not be updated.'];
        }
    }

    $_SESSION['reports_flash'] = $flash;
    header('Location: reports.php');
    exit;
}

$reportRows = [];
if (firebase_enabled() && firebase_firestore_enabled()) {
    $documents = firebase_firestore_list_documents('reports');
    if (is_array($documents)) {
        $reportRows = sortReportsNewestFirst(array_map('normalizeReportDoc', $documents));
    } else {
        $reportsError = firebase_get_last_error() ?? 'Unable to load the Firestore reports collection.';
    }
} else {
    $reportsError = 'Firestore is not enabled, so reports cannot be loaded.';
}

$summary = [
    'total' => count($reportRows),
    'pending' => 0,
    'investigating' => 0,
    'resolved' => 0,
    'rejected' => 0,
];

foreach ($reportRows as $report) {
    $status = normalizeReportStatus($report['status'] ?? 'Pending');
    if ($status === 'Pending') {
        $summary['pending']++;
    } elseif ($status === 'Investigating') {
        $summary['investigating']++;
    } elseif ($status === 'Rejected') {
        $summary['rejected']++;
    } else {
        $summary['resolved']++;
    }
}

$reportBuckets = splitReportRowsByState($reportRows);
$activeReportRows = $reportBuckets['active'];
$historyReportRows = $reportBuckets['history'];

ob_start();
?>
<?php if ($flash): ?>
    <div class="alert-box <?php echo $flash['type'] === 'error' ? 'alert-error' : 'alert-success'; ?>">
        <?php echo htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($reportsError !== null): ?>
    <div class="alert-box alert-error">
        <?php echo htmlspecialchars($reportsError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<div class="summary-grid">
    <div class="summary-card">
        <h3>Total Reports</h3>
        <div class="stat-value"><?php echo $summary['total']; ?></div>
        <p>All documents in the Firestore <span class="mono-text">reports</span> collection</p>
    </div>
    <div class="summary-card">
        <h3>Pending</h3>
        <div class="stat-value"><?php echo $summary['pending']; ?></div>
        <p>Reports received but not yet reviewed</p>
    </div>
    <div class="summary-card">
        <h3>Investigating</h3>
        <div class="stat-value"><?php echo $summary['investigating']; ?></div>
        <p>Reports currently being checked on site</p>
    </div>
    <div class="summary-card">
        <h3>Resolved</h3>
        <div class="stat-value"><?php echo $summary['resolved']; ?></div>
        <p>Reports already fixed by the LGU</p>
    </div>
    <div class="summary-card">
        <h3>Rejected</h3>
        <div class="stat-value"><?php echo $summary['rejected']; ?></div>
        <p>Older rejected records still remaining in history</p>
    </div>
</div>

<style>
    .reports-select {
        min-width: 150px;
        padding: 9px 10px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        background: #ffffff;
        font: inherit;
    }

    .report-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .report-form-inline {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin: 0;
    }

    .report-delete-btn {
        background: #fee2e2;
        color: #b91c1c;
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.52);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        z-index: 1000;
    }

    .modal-overlay.open {
        display: flex;
    }

    .modal-card {
        width: min(680px, 100%);
        background: #ffffff;
        border-radius: 18px;
        padding: 22px;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.22);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
    }

    .modal-header h3 {
        margin: 0 0 6px;
    }

    .modal-close {
        border: none;
        background: #e2e8f0;
        color: #0f172a;
        border-radius: 10px;
        padding: 8px 12px;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }

    .modal-body {
        display: grid;
        gap: 12px;
    }

    .modal-body p {
        margin: 0;
        color: #334155;
        line-height: 1.6;
    }

    .modal-description {
        padding: 14px;
        border-radius: 14px;
        background: #f8fbff;
        border: 1px solid #dbeafe;
        white-space: pre-wrap;
    }
</style>

<h3 style="margin: 26px 0 10px;">Active Reports</h3>
<p class="muted-text" style="margin: 0 0 10px;">Move each active report from <strong>Pending</strong> to <strong>Investigating</strong> and then to <strong>Resolved</strong>. Choosing <strong>Rejected</strong> removes the report from the list.</p>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Reporter Name</th>
                <th>Subject</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($activeReportRows === []): ?>
                <tr>
                    <td colspan="6">No active reports are waiting in the Firestore <span class="mono-text">reports</span> collection.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($activeReportRows as $report): ?>
                    <?php $statusMeta = getReportStatusMeta((string) ($report['status'] ?? 'Pending')); ?>
                    <tr>
                        <td><?php echo htmlspecialchars(formatReportDate($report['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($report['reporter_name'] ?: 'Unknown Reporter', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($report['subject'] ?: 'No Subject', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($report['location'] ?: 'No Location', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <span class="status-pill <?php echo htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td>
                            <div class="report-actions">
                                <button
                                    type="button"
                                    class="action-btn secondary js-open-report-modal"
                                    data-reporter="<?php echo htmlspecialchars($report['reporter_name'] ?: 'Unknown Reporter', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-subject="<?php echo htmlspecialchars($report['subject'] ?: 'No Subject', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-location="<?php echo htmlspecialchars($report['location'] ?: 'No Location', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-email="<?php echo htmlspecialchars($report['user_email'] ?: 'No email provided', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-description="<?php echo htmlspecialchars($report['description'] ?: 'No description provided.', ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    View Details
                                </button>

                                <form method="post" class="report-form-inline">
                                    <input type="hidden" name="action" value="update_report_status">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($report['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <select name="status" class="reports-select" aria-label="Update report status">
                                        <?php foreach (['Pending', 'Investigating', 'Resolved', 'Rejected'] as $statusOption): ?>
                                            <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo normalizeReportStatus($report['status'] ?? 'Pending') === $statusOption ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="action-btn primary">Update</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<h3 style="margin: 26px 0 10px;">Report History</h3>
<p class="muted-text" style="margin: 0 0 10px;">Resolved and older rejected records stay here until an admin deletes them.</p>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Reporter Name</th>
                <th>Subject</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($historyReportRows === []): ?>
                <tr>
                    <td colspan="6">No report history is available right now.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($historyReportRows as $report): ?>
                    <?php $statusMeta = getReportStatusMeta((string) ($report['status'] ?? 'Resolved')); ?>
                    <tr>
                        <td><?php echo htmlspecialchars(formatReportDate($report['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($report['reporter_name'] ?: 'Unknown Reporter', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($report['subject'] ?: 'No Subject', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($report['location'] ?: 'No Location', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <span class="status-pill <?php echo htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td>
                            <div class="report-actions">
                                <button
                                    type="button"
                                    class="action-btn secondary js-open-report-modal"
                                    data-reporter="<?php echo htmlspecialchars($report['reporter_name'] ?: 'Unknown Reporter', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-subject="<?php echo htmlspecialchars($report['subject'] ?: 'No Subject', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-location="<?php echo htmlspecialchars($report['location'] ?: 'No Location', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-email="<?php echo htmlspecialchars($report['user_email'] ?: 'No email provided', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-description="<?php echo htmlspecialchars($report['description'] ?: 'No description provided.', ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    View Details
                                </button>

                                <form method="post" class="inline-form" onsubmit="return confirm('Delete this report history entry?');">
                                    <input type="hidden" name="action" value="delete_report">
                                    <input type="hidden" name="doc_name" value="<?php echo htmlspecialchars($report['__name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="action-btn report-delete-btn">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div id="report-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="report-modal-title">
        <div class="modal-header">
            <div>
                <h3 id="report-modal-title">Report Details</h3>
                <p class="meta-text" id="report-modal-meta">Reporter information</p>
            </div>
            <button type="button" class="modal-close" id="report-modal-close">Close</button>
        </div>
        <div class="modal-body">
            <p><strong>Reporter:</strong> <span id="report-modal-reporter">-</span></p>
            <p><strong>Subject:</strong> <span id="report-modal-subject">-</span></p>
            <p><strong>Location:</strong> <span id="report-modal-location">-</span></p>
            <p><strong>User Email:</strong> <span id="report-modal-email">-</span></p>
            <div>
                <p><strong>Description</strong></p>
                <div class="modal-description" id="report-modal-description">-</div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('report-modal');
        const closeButton = document.getElementById('report-modal-close');
        const reporterEl = document.getElementById('report-modal-reporter');
        const subjectEl = document.getElementById('report-modal-subject');
        const locationEl = document.getElementById('report-modal-location');
        const emailEl = document.getElementById('report-modal-email');
        const descriptionEl = document.getElementById('report-modal-description');
        const metaEl = document.getElementById('report-modal-meta');

        function closeModal() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }

        document.querySelectorAll('.js-open-report-modal').forEach((button) => {
            button.addEventListener('click', () => {
                reporterEl.textContent = button.dataset.reporter || '-';
                subjectEl.textContent = button.dataset.subject || '-';
                locationEl.textContent = button.dataset.location || '-';
                emailEl.textContent = button.dataset.email || '-';
                descriptionEl.textContent = button.dataset.description || '-';
                metaEl.textContent = (button.dataset.subject || 'Report') + ' from ' + (button.dataset.reporter || 'Unknown Reporter');
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
            });
        });

        closeButton.addEventListener('click', closeModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('open')) {
                closeModal();
            }
        });
    })();
</script>
<?php
$contentHtml = ob_get_clean();

renderAdminPage('reports', 'Reports', 'Review citizen reports, update active cases, and delete report history from the Firestore reports collection.', $contentHtml);
