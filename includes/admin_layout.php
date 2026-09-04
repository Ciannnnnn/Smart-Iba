<?php
function renderAdminPage(string $currentPage, string $pageTitle, string $pageDescription, string $contentHtml): void
{
    $navItems = [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'index.php'],
        'users' => ['label' => 'Users', 'href' => 'users.php'],
        'manage_request' => ['label' => 'Services', 'href' => 'manage_request.php'],
        'reports' => ['label' => 'Reports', 'href' => 'reports.php'],
        'scholarship' => ['label' => 'Scholarship', 'href' => 'scholarship.php'],
        'office_directory' => ['label' => 'Office Directory', 'href' => 'office_directory.php'],
        'event' => ['label' => 'Event', 'href' => 'event.php'],
        'news' => ['label' => 'News', 'href' => 'news.php'],
    ];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | Smart Iba Admin</title>
        <style>
            * {
                box-sizing: border-box;
                transition: all 0.2s ease;
            }

            body {
                margin: 0;
                font-family: Arial, sans-serif;
                background: #eef4ff;
                color: #1f2937;
                height: 100vh;
                overflow: hidden;
            }

            .dashboard {
                display: flex;
                height: 100vh;
                overflow: hidden;
            }

            .sidebar {
                width: 260px;
                background: linear-gradient(180deg, #2563eb, #38bdf8);
                color: #ffffff;
                padding: 24px 18px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                flex-shrink: 0;
                height: 100vh;
            }

            .brand {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 24px;
            }

            #logo {
                width: 58px;
                height: 58px;
                object-fit: cover;
                border-radius: 50%;
                border: 3px solid rgba(255, 255, 255, 0.35);
            }

            .brand h2 {
                margin: 0;
                font-size: 22px;
            }

            .brand p {
                margin: 4px 0 0;
                font-size: 13px;
                opacity: 0.9;
            }

            .nav-links {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .nav-link {
                color: #ffffff;
                text-decoration: none;
                padding: 12px 14px;
                border-radius: 12px;
                font-weight: 600;
                background: rgba(255, 255, 255, 0.08);
            }

            .nav-link:hover,
            .nav-link.active {
                background: rgba(255, 255, 255, 0.22);
                transform: translateX(4px);
            }

            .logout-btn {
                display: block;
                text-align: center;
                text-decoration: none;
                color: #ffffff;
                padding: 12px 14px;
                border-radius: 12px;
                background: #0f172a;
                font-weight: 700;
            }

            .logout-btn:hover {
                background: #1e293b;
            }

            .main-content {
                flex: 1;
                padding: 28px;
                height: 100vh;
                overflow-y: auto;
                overflow-x: hidden;
            }

            .topbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 16px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .topbar h1 {
                margin: 0 0 6px;
                font-size: 30px;
            }

            .topbar p {
                margin: 0;
                color: #475569;
            }

            .badge {
                background: #dbeafe;
                color: #1d4ed8;
                padding: 8px 14px;
                border-radius: 999px;
                font-size: 13px;
                font-weight: 700;
            }

            .main-panel {
                background: #ffffff;
                border-radius: 20px;
                padding: 24px;
                box-shadow: 0 12px 30px rgba(37, 99, 235, 0.08);
            }

            .stats-grid,
            .quick-grid,
            .directory-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 16px;
                margin: 18px 0 0;
            }

            .stat-card,
            .quick-card,
            .directory-card {
                background: #f8fbff;
                border: 1px solid #dbeafe;
                border-radius: 16px;
                padding: 16px;
            }

            .stat-card h3,
            .quick-card h3,
            .directory-card h3 {
                margin: 0 0 8px;
                font-size: 16px;
            }

            .stat-value {
                font-size: 26px;
                font-weight: 700;
                color: #1d4ed8;
            }

            .page-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 18px;
            }

            .action-link {
                text-decoration: none;
                background: #2563eb;
                color: #ffffff;
                padding: 10px 14px;
                border-radius: 10px;
                font-weight: 700;
                display: inline-block;
            }

            .action-link.secondary {
                background: #e2e8f0;
                color: #0f172a;
            }

            .table-wrapper {
                overflow-x: auto;
                margin-top: 20px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                min-width: 620px;
            }

            th,
            td {
                padding: 12px 10px;
                text-align: left;
                border-bottom: 1px solid #e5e7eb;
            }

            th {
                background: #eff6ff;
                color: #1e3a8a;
            }

            .status-pill {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 700;
            }

            .status-active {
                background: #dcfce7;
                color: #166534;
            }

            .status-review {
                background: #fef3c7;
                color: #92400e;
            }

            .status-updated {
                background: #dbeafe;
                color: #1d4ed8;
            }

            .info-list {
                margin: 18px 0 0;
                padding-left: 18px;
                color: #475569;
            }

            .info-list li {
                margin-bottom: 8px;
            }

            .alert-box {
                border-radius: 12px;
                padding: 12px 14px;
                margin-bottom: 16px;
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

            .alert-info {
                background: #eff6ff;
                color: #1d4ed8;
            }

            .stack-form {
                display: grid;
                gap: 12px;
                margin-top: 14px;
            }

            .field-label {
                font-size: 14px;
                font-weight: 700;
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
                background: #ffffff;
            }

            .input-field:focus,
            .select-field:focus,
            .text-area:focus {
                outline: none;
                border-color: #2563eb;
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
            }

            .text-area {
                min-height: 120px;
                resize: vertical;
            }

            .button-row {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 6px;
            }

            .action-btn {
                border: none;
                cursor: pointer;
                padding: 10px 14px;
                border-radius: 10px;
                font-weight: 700;
                font: inherit;
            }

            .action-btn.primary {
                background: #2563eb;
                color: #ffffff;
            }

            .action-btn.secondary {
                background: #e2e8f0;
                color: #0f172a;
            }

            .request-list {
                display: grid;
                gap: 14px;
            }

            .request-card {
                background: #f8fbff;
                border: 1px solid #dbeafe;
                border-radius: 16px;
                padding: 16px;
            }

            .filter-row {
                display: flex;
                justify-content: flex-end;
                margin: 20px 0 10px;
            }

            .filter-form {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .filter-form label {
                font-weight: 700;
                color: #334155;
            }

            .filter-form select {
                padding: 10px 12px;
                border-radius: 10px;
                border: 1px solid #cbd5e1;
                background: #ffffff;
                font: inherit;
            }

            .filter-form .input-field {
                min-width: 220px;
            }

            .request-top {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 12px;
                flex-wrap: wrap;
            }

            .request-meta-row {
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
                margin: 12px 0 0;
                color: #334155;
                font-size: 13px;
            }

            .meta-text {
                margin: 4px 0 0;
                font-size: 13px;
                color: #475569;
            }

            .inline-form {
                margin: 0;
            }

            .empty-state {
                background: #eff6ff;
                border: 1px dashed #93c5fd;
                border-radius: 12px;
                padding: 14px;
                color: #1d4ed8;
            }

            .summary-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 16px;
                margin: 0 0 18px;
            }

            .summary-card {
                background: #f8fbff;
                border: 1px solid #dbeafe;
                border-radius: 16px;
                padding: 16px;
            }

            .summary-card h3 {
                margin: 0 0 8px;
                font-size: 15px;
            }

            .summary-card p {
                margin: 0;
                color: #475569;
            }

            .mono-text {
                font-family: Consolas, Monaco, monospace;
                font-size: 13px;
            }

            .muted-text {
                color: #64748b;
            }

            .status-inactive {
                background: #e2e8f0;
                color: #334155;
            }

            .status-danger {
                background: #fee2e2;
                color: #b91c1c;
            }

            @media (max-width: 900px) {
                body {
                    height: auto;
                    overflow: auto;
                }

                .dashboard {
                    flex-direction: column;
                    height: auto;
                    overflow: visible;
                }

                .sidebar {
                    width: 100%;
                    height: auto;
                }

                .main-content {
                    padding: 18px;
                    height: auto;
                    overflow: visible;
                }
            }
        </style>
    </head>
    <body>
        <div class="dashboard">
            <aside class="sidebar">
                <div>
                    <div class="brand">
                        <img src="includes/iba-logo.jpg" alt="Smart Iba Logo" id="logo">
                        <div>
                            <h2>Smart Iba</h2>
                            <p>Admin Panel</p>
                        </div>
                    </div>

                    <nav class="nav-links">
                        <?php foreach ($navItems as $key => $item): ?>
                            <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?php echo $currentPage === $key ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <a href="logout.php" class="logout-btn">Logout</a>
            </aside>

            <main class="main-content">
                <div class="topbar">
                    <div>
                        <h1><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                        <p><?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="badge">Admin Access</div>
                </div>

                <section class="main-panel">
                    <?php echo $contentHtml; ?>
                </section>
            </main>
        </div>
    </body>
    </html>
    <?php
}
