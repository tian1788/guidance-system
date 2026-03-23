<?php
session_start();
include "../config/db.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

function guidance_count(/** @var mysqli $conn */ $conn, string $sql): int
{
    if (!is_object($conn) || !method_exists($conn, 'query')) {
        return 0;
    }

    $result = $conn->query($sql);
    return is_object($result) && isset($result->num_rows) ? (int) $result->num_rows : 0;
}

function guidance_rows(/** @var mysqli $conn */ $conn, string $sql): array
{
    $rows = [];
    if (!is_object($conn) || !method_exists($conn, 'query')) {
        return $rows;
    }

    $result = $conn->query($sql);
    if (is_object($result) && method_exists($result, 'fetch_assoc')) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function guidance_date_label(?string $value): string
{
    if (!$value) {
        return 'No schedule';
    }

    $time = strtotime($value);
    return $time ? date('M d, Y', $time) : $value;
}

$studentRecords = guidance_count($conn, "SELECT * FROM students");
$studentAccounts = guidance_count($conn, "SELECT * FROM users WHERE role='student'");

$totalCounseling = guidance_count($conn, "SELECT * FROM counseling");
$pendingCounseling = guidance_count($conn, "SELECT * FROM counseling WHERE status='Pending'");
$repliedCounseling = guidance_count($conn, "SELECT * FROM counseling WHERE status='Replied'");

$totalGuidance = guidance_count($conn, "SELECT * FROM guidance");
$pendingGuidance = guidance_count($conn, "SELECT * FROM guidance WHERE status='Pending'");
$recentGuidance = guidance_count($conn, "SELECT * FROM guidance WHERE date_recorded >= CURDATE() - INTERVAL 7 DAY");

$totalSurvey = guidance_count($conn, "SELECT * FROM survey");
$pendingSurvey = guidance_count($conn, "SELECT * FROM survey WHERE status='Pending'");
$reviewedSurvey = guidance_count($conn, "SELECT * FROM survey WHERE status='Reviewed'");

$totalCrisis = guidance_count($conn, "SELECT * FROM crisis");
$newCrisis = guidance_count($conn, "SELECT * FROM crisis WHERE date_reported >= CURDATE() - INTERVAL 7 DAY");

$message = '';
$messageType = 'success';

if (isset($_POST['send_hr_request'])) {
    $employeeId = trim((string) ($_POST['employee_id'] ?? ''));
    $requestType = trim((string) ($_POST['request_type'] ?? ''));
    $requestDetails = trim((string) ($_POST['request_details'] ?? ''));

    if ($employeeId === '' || $requestType === '' || $requestDetails === '') {
        $message = 'Please fill in all required fields for the HR request.';
        $messageType = 'error';
    } else {
        $requested = guidance_request_employee_to_hr($conn, $employeeId, $requestType, $requestDetails);
        if ($requested) {
            $message = 'Employee information request sent to HR successfully.';
        } else {
            $message = 'Failed to send employee information request to HR. Please check the integration setup.';
            $messageType = 'error';
        }
    }
}

$outboundQueued = guidance_count($conn, "SELECT * FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance' AND status='Queued'");
$inboundReceived = guidance_count($conn, "SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' AND status='Received'");
$acknowledgedInbound = guidance_count($conn, "SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' AND status='Acknowledged'");
$sentOutbound = guidance_count($conn, "SELECT * FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance' AND status='Sent'");
$failedOutbound = guidance_count($conn, "SELECT * FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance' AND status='Failed'");

$counselingQueue = guidance_rows(
    $conn,
    "SELECT student_name, student_id, issue, status, schedule_date
     FROM counseling
     ORDER BY schedule_date DESC, id DESC
     LIMIT 4"
);

$guidanceQueue = guidance_rows(
    $conn,
    "SELECT student_name, concern, status, date_recorded
     FROM guidance
     ORDER BY date_recorded DESC, id DESC
     LIMIT 3"
);

$surveyQueue = guidance_rows(
    $conn,
    "SELECT student_name, feedback, rating, status, date_submitted
     FROM survey
     ORDER BY date_submitted DESC, id DESC
     LIMIT 3"
);

$integrationQueue = guidance_rows(
    $conn,
    "SELECT direction, source_department, target_department, flow_type, student_name, status, created_at
     FROM integration_flows
     WHERE source_department='guidance' OR target_department='guidance'
     ORDER BY created_at DESC, id DESC
     LIMIT 6"
);

$actionQueue = [];
foreach ($counselingQueue as $row) {
    $actionQueue[] = [
        'lane' => 'Counseling Request',
        'title' => ($row['student_name'] ?: 'Student') . ($row['student_id'] ? ' / ' . $row['student_id'] : ''),
        'detail' => $row['issue'] ?: 'Student counseling concern submitted to Guidance.',
        'status' => $row['status'] ?: 'Pending',
        'status_class' => strtolower($row['status'] ?? 'pending'),
        'date' => guidance_date_label($row['schedule_date'] ?? null),
        'link' => 'counseling.php',
        'cta' => 'Open Counseling',
    ];
}

foreach ($guidanceQueue as $row) {
    $actionQueue[] = [
        'lane' => 'Guidance Record',
        'title' => $row['student_name'] ?: 'Student record',
        'detail' => $row['concern'] ?: 'Pending guidance case logged for follow-up.',
        'status' => $row['status'] ?: 'Pending',
        'status_class' => strtolower($row['status'] ?? 'pending'),
        'date' => guidance_date_label($row['date_recorded'] ?? null),
        'link' => 'guidance.php',
        'cta' => 'Review Record',
    ];
}

foreach ($surveyQueue as $row) {
    $actionQueue[] = [
        'lane' => 'Survey Feedback',
        'title' => ($row['student_name'] ?: 'Student') . ' / Rating ' . (string) ($row['rating'] ?? 'N/A'),
        'detail' => $row['feedback'] ?: 'Student feedback is waiting for guidance review.',
        'status' => $row['status'] ?: 'Pending',
        'status_class' => strtolower($row['status'] ?? 'pending'),
        'date' => guidance_date_label($row['date_submitted'] ?? null),
        'link' => 'survey.php',
        'cta' => 'Review Feedback',
    ];
}

$actionQueue = array_slice($actionQueue, 0, 6);

$registrarInbound = guidance_count($conn, "SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' AND source_department='registrar'");
$prefectExchange = guidance_count($conn, "SELECT * FROM integration_flows WHERE (target_department='prefect' AND source_department='guidance') OR (target_department='guidance' AND source_department='prefect')");
$pmedExchange = guidance_count($conn, "SELECT * FROM integration_flows WHERE (target_department='pmed' AND source_department='guidance') OR (target_department='guidance' AND source_department='pmed')");

$routingBoard = [
    [
        'office' => 'Registrar',
        'status' => $registrarInbound > 0 ? 'Student intake flow active' : 'Waiting for student profile sync',
        'class' => $registrarInbound > 0 ? 'synced' : 'pending',
        'note' => 'Registrar supplies student identity, course, year, and section before Guidance opens a case.',
    ],
    [
        'office' => 'Prefect Management',
        'status' => $prefectExchange > 0 ? 'Behavior and incident routing active' : 'No active Prefect handoff yet',
        'class' => $prefectExchange > 0 ? 'monitoring' : 'pending',
        'note' => 'Guidance sends behavior referrals and discipline-related incidents here when case coordination is needed.',
    ],
    [
        'office' => 'PMED',
        'status' => $pmedExchange > 0 ? 'Monitoring updates active' : 'No active PMED routing yet',
        'class' => $pmedExchange > 0 ? 'monitoring' : 'pending',
        'note' => 'Counseling outcomes, wellness summaries, and incident monitoring updates are routed to PMED.',
    ],
];

$alerts = [];
if ($pendingCounseling > 0) {
    $alerts[] = $pendingCounseling . ' counseling request(s) still need scheduling or office response.';
}
if ($pendingGuidance > 0) {
    $alerts[] = $pendingGuidance . ' guidance record(s) are still marked pending for intervention closure.';
}
if ($pendingSurvey > 0) {
    $alerts[] = $pendingSurvey . ' survey feedback item(s) are waiting for review and possible PMED routing.';
}
if ($failedOutbound > 0) {
    $alerts[] = $failedOutbound . ' outbound integration message(s) failed and should be retried from the Integration Hub.';
}
if (!$alerts) {
    $alerts[] = 'Guidance queues are within expected levels and no urgent dashboard blockers are flagged.';
}

$activityFeed = [];
foreach ($integrationQueue as $row) {
    $activityFeed[] = [
        'module' => 'Integration',
        'title' => ($row['direction'] === 'INBOUND' ? $row['source_department'] . ' to Guidance' : 'Guidance to ' . $row['target_department']),
        'detail' => ($row['flow_type'] ?: 'Department flow') . ($row['student_name'] ? ' / ' . $row['student_name'] : ''),
        'date' => date('M d, Y g:i A', strtotime($row['created_at'])),
    ];
}

while (count($activityFeed) < 5) {
    $fallback = [
        ['module' => 'Counseling', 'title' => 'Daily counseling review', 'detail' => 'Pending student concerns and schedule slots are being validated.', 'date' => 'Today 8:30 AM'],
        ['module' => 'Guidance', 'title' => 'Case record follow-up', 'detail' => 'Behavior monitoring and referral notes are prepared for Registrar, Prefect, or PMED routing.', 'date' => 'Today 10:00 AM'],
        ['module' => 'Survey', 'title' => 'Feedback validation', 'detail' => 'Student sentiment records are queued for guidance assessment.', 'date' => 'Today 1:30 PM'],
    ];
    $activityFeed[] = $fallback[count($activityFeed) % count($fallback)];
}

$brandLogo = '../../../../Registrar/assets/img/logo.png';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">

<div class="sidebar guidance-sidebar">
    <div class="sidebar-brand">
        <img src="<?php echo $brandLogo; ?>" alt="Bestlink College logo">
        <div>
            <div class="sidebar-brand-title">Bestlink College</div>
            <div class="sidebar-brand-sub">Guidance Office</div>
            <div class="sidebar-brand-chip">Student Wellness Command Center</div>
        </div>
    </div>

    <div class="sidebar-panel">
        <div class="sidebar-panel-label">Office Status</div>
        <div class="sidebar-panel-title">Counseling, intervention, referral, and integration tracking in one workspace.</div>
    </div>

    <a class="active" href="dashboard.php">Dashboard</a>
    <a href="students.php">Student Info</a>
    <a href="connected_data.php">Connected Data</a>
    <a href="counseling.php">Academic Counseling</a>
    <a href="guidance.php">Referrals & Monitoring</a>
    <a href="crisis.php">Incident Desk</a>
    <a href="survey.php">Survey & Feedback</a>
    <a href="integration.php">Integration Hub</a>
    <a href="../logout.php">Logout</a>
</div>

<div class="main dashboard-shell">
    <section class="dashboard-hero">
        <div class="dashboard-hero-main">
            <div class="hero-badge">Guidance Management Dashboard</div>
            <h1>Guidance Department Operations Board</h1>
            <p>Track the full Guidance workflow from Registrar student intake to counseling, incidents, referrals, behavior monitoring, and direct sharing with Registrar, Prefect, and PMED.</p>
            <div class="hero-meta-row">
                <span><?php echo $studentRecords; ?> student records in Guidance</span>
                <span><?php echo $pendingCounseling; ?> counseling items waiting</span>
                <span><?php echo $outboundQueued + $inboundReceived; ?> active integration queue items</span>
            </div>
        </div>
        <aside class="hero-sidecard">
            <div class="hero-sidecard-label">Today&apos;s Office Pulse</div>
            <div class="hero-sidecard-value"><?php echo $pendingCounseling + $pendingGuidance + $pendingSurvey; ?> active guidance follow-ups</div>
            <div class="hero-sidecard-grid">
                <div>
                    <span class="mini-kicker">Student Accounts</span>
                    <strong><?php echo $studentAccounts; ?></strong>
                </div>
                <div>
                    <span class="mini-kicker">New Crisis Cases</span>
                    <strong><?php echo $newCrisis; ?></strong>
                </div>
                <div>
                    <span class="mini-kicker">Inbound Received</span>
                    <strong><?php echo $inboundReceived; ?></strong>
                </div>
                <div>
                    <span class="mini-kicker">Sent Outbound</span>
                    <strong><?php echo $sentOutbound; ?></strong>
                </div>
            </div>
        </aside>
    </section>

    <?php if (isset($_POST['send_hr_request']) && $message !== ''): ?>
        <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
    <?php endif; ?>

    <section class="form-box" id="hr-request-form">
        <div class="panel-heading">
            <div>
                <h2>Request Employee Information from HR</h2>
                <p>Use this form to send requests to the HR department for employee-related information.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <input name="employee_id" id="employee-id-input" placeholder="Employee ID" required>
            <select name="request_type" id="request-type-input" required>
                <option value="">Select Request Type</option>
                <option value="Verification">Verification</option>
                <option value="Leave Balance">Leave Balance</option>
                <option value="Salary Information">Salary Information</option>
                <option value="Other">Other</option>
            </select>
            <textarea name="request_details" id="request-details-input" placeholder="Details of your request"></textarea>
            <button name="send_hr_request">Send Request to HR</button>
        </form>
    </section>

    <?php if (isset($_POST['send_hr_request']) && $message !== ''): ?>
        <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
    <?php endif; ?>

    <section class="form-box" id="hr-request-form">
        <div class="panel-heading">
            <div>
                <h2>Request Employee Information from HR</h2>
                <p>Use this form to send requests to the HR department for employee-related information.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <input name="employee_id" id="employee-id-input" placeholder="Employee ID" required>
            <select name="request_type" id="request-type-input" required>
                <option value="">Select Request Type</option>
                <option value="Verification">Verification</option>
                <option value="Leave Balance">Leave Balance</option>
                <option value="Salary Information">Salary Information</option>
                <option value="Other">Other</option>
            </select>
            <textarea name="request_details" id="request-details-input" placeholder="Details of your request"></textarea>
            <button name="send_hr_request">Send Request to HR</button>
        </form>
    </section>

    <section class="dashboard-section compact">
        <div class="section-head">
            <div>
                <h2>Guidance Action Tray</h2>
                <p>Common office actions for end-to-end student intake, case handling, and department coordination.</p>
            </div>
        </div>
        <div class="action-toolbar">
            <a class="btn-primary" href="connected_data.php">Fetch Shared Data</a>
            <a class="btn-secondary" href="students.php">Student Registry</a>
            <a class="btn-secondary" href="counseling.php">Open Counseling</a>
            <a class="btn-secondary" href="guidance.php">Open Monitoring</a>
            <a class="btn-secondary" href="crisis.php">Open Incident Desk</a>
            <a class="btn-secondary" href="survey.php">Review Feedback</a>
            <a class="btn-secondary" href="integration.php">Integration Hub</a>
        </div>
    </section>

    <section class="summary-grid">
        <article class="summary-card accent">
            <div class="summary-label">Student Records</div>
            <div class="summary-value"><?php echo $studentRecords; ?></div>
            <div class="summary-note">Guidance student profiles registered for counseling and intervention monitoring.</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">Counseling Requests</div>
            <div class="summary-value"><?php echo $totalCounseling; ?></div>
            <div class="summary-note"><?php echo $pendingCounseling; ?> pending and <?php echo $repliedCounseling; ?> replied requests inside the office queue.</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">Referrals & Monitoring</div>
            <div class="summary-value"><?php echo $totalGuidance; ?></div>
            <div class="summary-note"><?php echo $recentGuidance; ?> record(s) were updated within the last seven days.</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">Survey & Feedback</div>
            <div class="summary-value"><?php echo $totalSurvey; ?></div>
            <div class="summary-note"><?php echo $pendingSurvey; ?> pending reviews and <?php echo $reviewedSurvey; ?> already processed.</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">Integration Queue</div>
            <div class="summary-value"><?php echo $outboundQueued + $inboundReceived; ?></div>
            <div class="summary-note"><?php echo $outboundQueued; ?> outbound queued and <?php echo $inboundReceived; ?> inbound waiting for acknowledgment.</div>
        </article>
    </section>

    <section class="dashboard-grid">
        <div class="dashboard-section">
            <div class="section-head">
                <div>
                    <h2>Guidance Approval Queue</h2>
                    <p>Operational items that need review, scheduling, intervention, or routing.</p>
                </div>
            </div>
            <div class="queue-list">
                <?php foreach ($actionQueue as $item): ?>
                    <article class="queue-item">
                        <div class="queue-body">
                            <div class="queue-lane"><?php echo htmlspecialchars($item['lane']); ?></div>
                            <div class="queue-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="queue-detail"><?php echo htmlspecialchars($item['detail']); ?></div>
                        </div>
                        <div class="queue-side">
                            <span class="status-pill modern <?php echo htmlspecialchars($item['status_class']); ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                            <div class="queue-date"><?php echo htmlspecialchars($item['date']); ?></div>
                            <a class="btn-secondary small" href="<?php echo htmlspecialchars($item['link']); ?>"><?php echo htmlspecialchars($item['cta']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="info-stack">
            <section class="dashboard-section mini-panel">
                <div class="section-kicker">Office Alerts</div>
                <h3>Immediate Attention</h3>
                <div class="alert-list">
                    <?php foreach ($alerts as $alert): ?>
                        <div class="alert-row">
                            <span class="alert-dot"></span>
                            <span><?php echo htmlspecialchars($alert); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-section mini-panel soft">
                <div class="section-kicker">Queue Status</div>
                <h3>Guidance Panel</h3>
                <div class="mini-stat-grid">
                    <div>
                        <span class="summary-label">Pending Guidance</span>
                        <strong><?php echo $pendingGuidance; ?></strong>
                    </div>
                    <div>
                        <span class="summary-label">Acknowledged</span>
                        <strong><?php echo $acknowledgedInbound; ?></strong>
                    </div>
                    <div>
                        <span class="summary-label">Crisis Cases</span>
                        <strong><?php echo $totalCrisis; ?></strong>
                    </div>
                    <div>
                        <span class="summary-label">Failed Sync</span>
                        <strong><?php echo $failedOutbound; ?></strong>
                    </div>
                </div>
            </section>
        </aside>
    </section>

    <section class="dashboard-section">
        <div class="section-head">
            <div>
                <h2>Department Integration Tracker</h2>
                <p>Live view of the three active partner departments in the Guidance end-to-end flow.</p>
            </div>
        </div>
        <div class="integration-grid">
            <?php foreach ($routingBoard as $route): ?>
                <article class="integration-card">
                    <div class="integration-top">
                        <div class="integration-title"><?php echo htmlspecialchars($route['office']); ?></div>
                        <span class="status-pill modern <?php echo htmlspecialchars($route['class']); ?>"><?php echo htmlspecialchars($route['status']); ?></span>
                    </div>
                    <div class="integration-note"><?php echo htmlspecialchars($route['note']); ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dashboard-grid">
        <div class="dashboard-section">
            <div class="section-head">
                <div>
                    <h2>Student Support Lifecycle</h2>
                    <p>How student concerns move through Guidance from intake to intervention and office coordination.</p>
                </div>
            </div>
            <div class="lifecycle-list">
                <article class="lifecycle-item">
                    <div class="lifecycle-index">1</div>
                    <div>
                        <div class="lifecycle-title">Student Record Intake</div>
                        <div class="lifecycle-note">Registrar sends student identity and academic details into the Guidance registry.</div>
                    </div>
                </article>
                <article class="lifecycle-item">
                    <div class="lifecycle-index">2</div>
                    <div>
                        <div class="lifecycle-title">Counseling Request Review</div>
                        <div class="lifecycle-note">Guidance records counseling concerns, schedules, replies, and PMED or Registrar handoff needs.</div>
                    </div>
                </article>
                <article class="lifecycle-item">
                    <div class="lifecycle-index">3</div>
                    <div>
                        <div class="lifecycle-title">Referral And Behavioral Monitoring</div>
                        <div class="lifecycle-note">Guidance logs interventions, behavior monitoring, and referrals for Registrar, Prefect, or PMED visibility.</div>
                    </div>
                </article>
                <article class="lifecycle-item">
                    <div class="lifecycle-index">4</div>
                    <div>
                        <div class="lifecycle-title">Incident Escalation</div>
                        <div class="lifecycle-note">Urgent incidents are sent end to end from Guidance to Prefect or PMED with the same student context.</div>
                    </div>
                </article>
                <article class="lifecycle-item">
                    <div class="lifecycle-index">5</div>
                    <div>
                        <div class="lifecycle-title">Follow-Up And Status Tracking</div>
                        <div class="lifecycle-note">The Integration Hub keeps the handoff history visible for Guidance staff and connected departments.</div>
                    </div>
                </article>
            </div>
        </div>

        <aside class="dashboard-section">
            <div class="section-head">
                <div>
                    <h2>Recent Activity</h2>
                    <p>Latest office and integration movements affecting the Guidance dashboard.</p>
                </div>
            </div>
            <div class="activity-list">
                <?php foreach ($activityFeed as $item): ?>
                    <article class="activity-item">
                        <div class="activity-top">
                            <span class="activity-module"><?php echo htmlspecialchars($item['module']); ?></span>
                            <span class="queue-date"><?php echo htmlspecialchars($item['date']); ?></span>
                        </div>
                        <div class="activity-title"><?php echo htmlspecialchars($item['title']); ?></div>
                        <div class="activity-detail"><?php echo htmlspecialchars($item['detail']); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </aside>
    </section>
</div>