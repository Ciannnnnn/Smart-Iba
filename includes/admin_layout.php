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
                flex-wrap: wrap;
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

            @media (max-width: 768px) {
                .filter-row {
                    justify-content: stretch;
                }

                .filter-form {
                    width: 100%;
                    flex-direction: column;
                    align-items: stretch;
                }

                .filter-form label,
                .filter-form input,
                .filter-form select,
                .filter-form button,
                .filter-form a {
                    width: 100%;
                }

                .filter-form .input-field {
                    min-width: 0;
                }

                .report-actions,
                .report-form-inline {
                    width: 100%;
                    flex-direction: column;
                    align-items: stretch;
                }

                .reports-select,
                .report-form-inline .action-btn,
                .report-form-inline select,
                .report-actions .action-btn,
                .report-actions button {
                    width: 100%;
                }

                .table-wrapper {
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                }

                table {
                    min-width: 520px;
                }
            }

            @media (max-width: 480px) {
                .summary-grid {
                    grid-template-columns: 1fr;
                }

                .page-content {
                    padding: 12px 10px 18px;
                }

                table {
                    min-width: 480px;
                }
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

            /* Refined admin shell */
            :root {
                --navy-950: #071a36;
                --navy-900: #0b2447;
                --navy-800: #123a6b;
                --blue-700: #1d4ed8;
                --blue-600: #2563eb;
                --blue-500: #3b82f6;
                --sky-500: #0ea5e9;
                --surface: #ffffff;
                --surface-soft: #f8fafc;
                --border: #e2e8f0;
                --text: #172033;
                --text-soft: #64748b;
                --shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.04);
                --shadow-md: 0 14px 35px rgba(15, 35, 68, 0.08);
            }

            * {
                transition-property: color, background-color, border-color, box-shadow, transform, opacity;
            }

            html {
                background: #f4f7fb;
            }

            body {
                font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background:
                    radial-gradient(circle at 96% 0%, rgba(14, 165, 233, 0.09), transparent 28rem),
                    #f4f7fb;
                color: var(--text);
            }

            .dashboard {
                min-height: 100vh;
                min-height: 100dvh;
            }

            .sidebar {
                width: 274px;
                padding: 26px 18px 22px;
                background:
                    radial-gradient(circle at 14% 3%, rgba(56, 189, 248, 0.28), transparent 13rem),
                    linear-gradient(165deg, var(--navy-950) 0%, var(--navy-900) 46%, #0d4f81 100%);
                box-shadow: 12px 0 35px rgba(7, 26, 54, 0.12);
                position: relative;
                z-index: 2;
            }

            .sidebar > div {
                display: flex;
                min-height: 0;
                flex: 1;
                flex-direction: column;
            }

            .brand {
                gap: 13px;
                margin: 0 8px 28px;
            }

            #logo {
                width: 50px;
                height: 50px;
                border: 2px solid rgba(255, 255, 255, 0.65);
                box-shadow: 0 8px 20px rgba(2, 12, 27, 0.25);
            }

            .brand h2 {
                font-size: 20px;
                letter-spacing: -0.02em;
            }

            .brand p {
                color: #bfdbfe;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .nav-links {
                gap: 6px;
                overflow-y: auto;
                padding: 0 2px 8px;
                scrollbar-width: thin;
                scrollbar-color: rgba(255, 255, 255, 0.24) transparent;
            }

            .nav-link {
                position: relative;
                padding: 12px 14px 12px 42px;
                border: 1px solid transparent;
                border-radius: 11px;
                background: transparent;
                color: #dbeafe;
                font-size: 14px;
                font-weight: 600;
            }

            .nav-link::before {
                content: "";
                position: absolute;
                left: 17px;
                top: 50%;
                width: 8px;
                height: 8px;
                border: 2px solid rgba(186, 230, 253, 0.8);
                border-radius: 50%;
                transform: translateY(-50%);
            }

            .nav-link:hover {
                background: rgba(255, 255, 255, 0.08);
                color: #ffffff;
                transform: translateX(2px);
            }

            .nav-link.active {
                border-color: rgba(255, 255, 255, 0.12);
                background: rgba(255, 255, 255, 0.15);
                box-shadow: inset 3px 0 0 #7dd3fc;
                color: #ffffff;
                transform: none;
            }

            .nav-link.active::before {
                border-color: #ffffff;
                background: #7dd3fc;
                box-shadow: 0 0 0 4px rgba(125, 211, 252, 0.13);
            }

            .logout-btn {
                margin: 18px 2px 0;
                border: 1px solid rgba(255, 255, 255, 0.13);
                border-radius: 11px;
                background: rgba(3, 15, 33, 0.42);
                font-size: 14px;
            }

            .logout-btn:hover {
                background: rgba(3, 15, 33, 0.7);
                transform: translateY(-1px);
            }

            .main-content {
                min-width: 0;
                padding: 30px clamp(24px, 3vw, 48px) 44px;
            }

            .topbar,
            .main-panel {
                width: 100%;
                max-width: 1480px;
                margin-left: auto;
                margin-right: auto;
            }

            .topbar {
                margin-bottom: 24px;
            }

            .topbar h1 {
                margin-bottom: 7px;
                color: #0f1d34;
                font-size: clamp(27px, 2.4vw, 36px);
                font-weight: 750;
                letter-spacing: -0.035em;
            }

            .topbar p {
                color: var(--text-soft);
                font-size: 14px;
                line-height: 1.6;
            }

            .badge {
                border: 1px solid #bfdbfe;
                background: rgba(239, 246, 255, 0.86);
                box-shadow: var(--shadow-sm);
                color: var(--blue-700);
                letter-spacing: 0.02em;
            }

            .main-panel {
                border: 1px solid rgba(226, 232, 240, 0.88);
                border-radius: 24px;
                background: rgba(255, 255, 255, 0.94);
                box-shadow: var(--shadow-md);
                padding: clamp(20px, 2.2vw, 30px);
            }

            .page-dashboard .main-panel {
                border: 0;
                background: transparent;
                box-shadow: none;
                padding: 0;
            }

            .stats-grid,
            .quick-grid,
            .directory-grid {
                gap: 18px;
                margin-top: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            }

            .quick-grid {
                grid-template-columns: repeat(auto-fit, minmax(255px, 1fr));
                margin-top: 22px;
            }

            .stat-card,
            .quick-card,
            .directory-card,
            .summary-card,
            .request-card {
                border: 1px solid var(--border);
                background: rgba(255, 255, 255, 0.96);
                box-shadow: var(--shadow-sm);
            }

            .stat-card {
                --card-accent: #2563eb;
                position: relative;
                min-height: 148px;
                overflow: hidden;
                border-radius: 18px;
                padding: 21px;
            }

            .stat-card::before {
                content: "";
                position: absolute;
                inset: 0 auto 0 0;
                width: 4px;
                background: var(--card-accent);
            }

            .stat-card::after {
                content: "";
                position: absolute;
                top: -24px;
                right: -19px;
                width: 90px;
                height: 90px;
                border-radius: 50%;
                background: var(--card-accent);
                opacity: 0.07;
            }

            .stat-card:nth-child(2),
            .stat-card:nth-child(6) {
                --card-accent: #0891b2;
            }

            .stat-card:nth-child(3),
            .stat-card:nth-child(7) {
                --card-accent: #7c3aed;
            }

            .stat-card:nth-child(4) {
                --card-accent: #059669;
            }

            .stat-card:nth-child(5) {
                --card-accent: #ea580c;
            }

            .stat-card:hover,
            .quick-card:hover,
            .directory-card:hover {
                border-color: #bfdbfe;
                box-shadow: 0 18px 38px rgba(15, 35, 68, 0.1);
                transform: translateY(-3px);
            }

            .stat-card h3 {
                margin-bottom: 15px;
                color: var(--text-soft);
                font-size: 12px;
                font-weight: 750;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .stat-value {
                color: var(--card-accent);
                font-size: 35px;
                font-weight: 780;
                letter-spacing: -0.04em;
                line-height: 1;
            }

            .stat-card p {
                margin: 12px 0 0;
                color: var(--text-soft);
                font-size: 13px;
                line-height: 1.45;
            }

            .quick-card {
                display: flex;
                min-height: 168px;
                flex-direction: column;
                align-items: flex-start;
                border-radius: 18px;
                padding: 21px;
            }

            .quick-card h3 {
                margin-bottom: 9px;
                color: #172033;
                font-size: 16px;
                letter-spacing: -0.01em;
            }

            .quick-card p {
                flex: 1;
                margin: 0 0 20px;
                color: var(--text-soft);
                font-size: 13px;
                line-height: 1.6;
            }

            .action-link {
                border: 1px solid #bfdbfe;
                border-radius: 9px;
                background: #eff6ff;
                color: var(--blue-700);
                font-size: 13px;
                padding: 9px 12px;
            }

            .action-link::after {
                content: "\2192";
                display: inline-block;
                margin-left: 7px;
            }

            .action-link:hover {
                border-color: var(--blue-600);
                background: var(--blue-600);
                color: #ffffff;
                transform: translateX(2px);
            }

            .action-btn,
            .input-field,
            .select-field,
            .text-area,
            .filter-form select {
                min-height: 42px;
            }

            .action-btn.primary {
                box-shadow: 0 5px 14px rgba(37, 99, 235, 0.18);
            }

            .action-btn:hover {
                transform: translateY(-1px);
            }

            .table-wrapper {
                border: 1px solid var(--border);
                border-radius: 14px;
                background: #ffffff;
            }

            th {
                background: #f1f5f9;
                color: #274060;
                font-size: 12px;
                letter-spacing: 0.035em;
                text-transform: uppercase;
            }

            td {
                color: #334155;
                font-size: 14px;
            }

            tbody tr:hover {
                background: #f8fbff;
            }

            @media (max-width: 1100px) {
                .sidebar {
                    width: 244px;
                }

                .main-content {
                    padding-left: 24px;
                    padding-right: 24px;
                }
            }

            @media (max-width: 900px) {
                .sidebar {
                    width: 100%;
                    padding: 17px 18px 14px;
                    box-shadow: 0 8px 25px rgba(7, 26, 54, 0.13);
                }

                .sidebar > div {
                    width: 100%;
                }

                .brand {
                    margin: 0 0 14px;
                    padding-right: 100px;
                }

                #logo {
                    width: 44px;
                    height: 44px;
                }

                .brand h2 {
                    font-size: 18px;
                }

                .nav-links {
                    flex-direction: row;
                    gap: 8px;
                    overflow-x: auto;
                    overflow-y: hidden;
                    padding: 0 0 3px;
                    scrollbar-width: none;
                }

                .nav-links::-webkit-scrollbar {
                    display: none;
                }

                .nav-link {
                    flex: 0 0 auto;
                    padding: 9px 13px;
                    white-space: nowrap;
                }

                .nav-link::before {
                    display: none;
                }

                .nav-link:hover,
                .nav-link.active {
                    box-shadow: none;
                    transform: none;
                }

                .logout-btn {
                    position: absolute;
                    top: 19px;
                    right: 18px;
                    margin: 0;
                    padding: 9px 13px;
                }

                .main-content {
                    padding: 23px 20px 36px;
                }
            }

            @media (max-width: 640px) {
                .main-content {
                    padding: 20px 14px 30px;
                }

                .topbar {
                    align-items: flex-start;
                    margin-bottom: 18px;
                }

                .topbar h1 {
                    font-size: 27px;
                }

                .badge {
                    padding: 7px 11px;
                    font-size: 11px;
                }

                .main-panel {
                    border-radius: 18px;
                    padding: 18px 15px;
                }

                .page-dashboard .main-panel {
                    padding: 0;
                }

                .stats-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 12px;
                }

                .quick-grid,
                .directory-grid {
                    grid-template-columns: 1fr;
                    gap: 12px;
                    margin-top: 16px;
                }

                .stat-card {
                    min-height: 138px;
                    padding: 18px 16px;
                }

                .stat-value {
                    font-size: 31px;
                }

                .quick-card {
                    min-height: 0;
                    padding: 18px;
                }

                .quick-card p {
                    margin-bottom: 16px;
                }

                .table-wrapper {
                    border-radius: 11px;
                }
            }

            @media (max-width: 380px) {
                .brand {
                    padding-right: 82px;
                }

                .brand p {
                    font-size: 10px;
                }

                .logout-btn {
                    right: 14px;
                    padding: 8px 10px;
                }

                .stats-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    </head>
    <body class="admin-page page-<?php echo htmlspecialchars($currentPage, ENT_QUOTES, 'UTF-8'); ?>">
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
