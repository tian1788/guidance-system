<?php
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

$message = '';
$messageType = 'success';
$students = guidance_fetch_students($conn);
$selectedStudentId = $_GET['student_id'] ?? ($_POST['student_id'] ?? '');
$crisisRoutes = guidance_integration_default_routes()['crisis'];

if (isset($_POST['add'])) {
    $studentId = trim($_POST['student_id'] ?? '');
    $student = guidance_find_student_by_student_id($conn, $studentId);
    $incident = trim($_POST['incident'] ?? '');
    $action = trim($_POST['action_taken'] ?? '');
    $date = trim($_POST['date_reported'] ?? '');
    $severity = trim($_POST['severity_level'] ?? 'high');

    if ($student && $incident !== '' && $action !== '' && $date !== '') {
        $caseReference = 'CRI-' . date('Y') . '-' . strtoupper(substr(uniqid(), -4));
        $conn->query("INSERT INTO crisis(
            student_name, student_id, incident, action_taken, date_reported,
            case_reference, severity_level, referral_status, synced_at
        ) VALUES(
            " . guidance_integration_quote($student['name']) . ",
            " . guidance_integration_quote($student['student_id']) . ",
            " . guidance_integration_quote($incident) . ",
            " . guidance_integration_quote($action) . ",
            " . guidance_integration_quote($date) . ",
            " . guidance_integration_quote($caseReference) . ",
            " . guidance_integration_quote($severity) . ",
            'internal_review',
            NOW()
        )");
        $message = 'Incident record added and ready for department coordination.';
    } else {
        $message = 'Select a student and complete the incident, action, and date fields.';
        $messageType = 'error';
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM crisis WHERE id=$id");
    $message = 'Incident record deleted.';
}

if (isset($_POST['queue_route'])) {
    $recordId = (int) $_POST['record_id'];
    $routeKey = trim($_POST['route_key'] ?? '');
    $route = null;

    foreach ($crisisRoutes as $candidate) {
        if ($candidate['route_key'] === $routeKey) {
            $route = $candidate;
            break;
        }
    }

    $recordResult = $conn->query("SELECT * FROM crisis WHERE id=$recordId LIMIT 1");
    $record = $recordResult && method_exists($recordResult, 'fetch_assoc') ? $recordResult->fetch_assoc() : null;

    if ($route && $record) {
        $queued = guidance_integration_queue_outbound($conn, $route['target_department'], $route['event_code'], [
            'route_key' => $route['route_key'],
            'reference_table' => 'crisis',
            'reference_id' => $record['id'],
            'student_id' => $record['student_id'],
            'student_name' => $record['student_name'],
            'payload_summary' => $record['incident'],
            'payload_json' => [
                'student_id' => $record['student_id'],
                'student_name' => $record['student_name'],
                'incident' => $record['incident'],
                'action_taken' => $record['action_taken'],
                'severity_level' => $record['severity_level'] ?? 'high',
                'case_reference' => $record['case_reference'] ?? null,
            ],
        ]);

        if ($queued) {
            $conn->query("UPDATE crisis SET referral_status='shared', synced_at=NOW() WHERE id=$recordId");
            $message = 'Incident routed to ' . guidance_integration_department_label($route['target_department']) . '.';
        } else {
            $message = 'Unable to route the incident to the selected department.';
            $messageType = 'error';
        }
    } else {
        $message = 'The selected incident record could not be found.';
        $messageType = 'error';
    }
}

$result = $conn->query("SELECT * FROM crisis ORDER BY date_reported DESC, id DESC");
$caseTotal = $result ? $result->num_rows : 0;
$recentResult = $conn->query("SELECT * FROM crisis WHERE date_reported >= CURRENT_DATE - INTERVAL '7 day'");
$recentTotal = $recentResult ? $recentResult->num_rows : 0;
$sharedResult = $conn->query("SELECT * FROM crisis WHERE referral_status='shared'");
$sharedTotal = $sharedResult ? $sharedResult->num_rows : 0;

guidance_render_shell_start(
    'Incident Desk',
    'Incident And Escalation Desk',
    'Capture urgent incidents in Guidance and send them end to end to Prefect or PMED without re-entering the student identity or the case summary.',
    [
        ['label' => 'Incident Cases', 'value' => $caseTotal, 'note' => 'All incident records currently tracked by Guidance.'],
        ['label' => 'Recent Incidents', 'value' => $recentTotal, 'note' => 'Cases recorded within the last seven days.'],
        ['label' => 'Shared Incidents', 'value' => $sharedTotal, 'note' => 'Incident records already sent outside Guidance.'],
        ['label' => 'Department Scope', 'value' => 'Prefect / PMED', 'note' => 'This incident workflow is wired directly to the active partner offices.'],
    ],
    [
        ['label' => 'Add Incident Case', 'href' => '#crisis-form', 'class' => 'btn-primary'],
        ['label' => 'Open Integration Hub', 'href' => 'integration.php', 'class' => 'btn-secondary'],
    ],
    ['Registrar Student Identity', 'Guidance Incident Intake', 'Prefect / PMED Escalation', 'Follow-Up Monitoring']
);
?>

<?php if ($message !== ''): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>

<div class="split-layout">
    <section class="form-box" id="crisis-form">
        <div class="panel-heading">
            <div>
                <h2>Add Incident Case</h2>
                <p>Choose the student first, then document the incident and the Guidance action once for all connected offices.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <div>
                <label>Student</label>
                <select name="student_id" required>
                    <option value="">Select Student</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo guidance_escape($student['student_id']); ?>" <?php if ($selectedStudentId === $student['student_id']) echo 'selected'; ?>>
                            <?php echo guidance_escape($student['student_id'] . ' - ' . $student['name'] . ' (' . ($student['course'] ?? '-') . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <textarea name="incident" placeholder="Incident details" required></textarea>
            <textarea name="action_taken" placeholder="Action taken by Guidance" required></textarea>
            <div class="form-grid">
                <div>
                    <label>Date Reported</label>
                    <input type="date" name="date_reported" required>
                </div>
                <div>
                    <label>Severity</label>
                    <select name="severity_level" required>
                        <option value="medium">Medium</option>
                        <option value="high" selected>High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <button name="add">Save Incident Case</button>
        </form>
    </section>

    <aside class="insight-panel soft">
        <div class="panel-heading">
            <div>
                <h3>Incident Routing</h3>
                <p>The crisis desk is now focused on the two departments that need incident visibility most.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">Prefect Management</div>
                <div class="mini-list-note">Use this for conduct, sanctions, behavior, and discipline coordination.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">PMED</div>
                <div class="mini-list-note">Use this for central incident monitoring, cross-office reporting, and follow-up tracking.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">Guidance keeps the source record</div>
                <div class="mini-list-note">The student identity and incident summary stay in Guidance while the partner office receives the routed event.</div>
            </article>
        </div>
    </aside>
</div>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Incident Register</h2>
            <p>Each incident can be escalated to Prefect or PMED directly from the same row after Guidance review.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Student</th>
                <th>Incident</th>
                <th>Action Taken</th>
                <th>Date Reported</th>
                <th>Severity</th>
                <th>Referral Status</th>
                <th>Action</th>
            </tr>

            <?php while ($row = $result->fetch_assoc()) { ?>
                <tr>
                    <td>
                        <?php echo guidance_escape($row['student_name']); ?><br>
                        <span class="summary-label"><?php echo guidance_escape($row['student_id'] ?: '-'); ?></span>
                    </td>
                    <td><?php echo guidance_escape($row['incident']); ?></td>
                    <td><?php echo guidance_escape($row['action_taken']); ?></td>
                    <td><?php echo guidance_escape($row['date_reported']); ?></td>
                    <td><?php echo guidance_escape(ucfirst($row['severity_level'] ?: 'high')); ?></td>
                    <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row['referral_status'] ?: 'pending')); ?>"><?php echo guidance_escape($row['referral_status'] ?: 'internal_review'); ?></span></td>
                    <td>
                        <div class="table-actions">
                            <a class="table-link" href="crisis_edit.php?id=<?php echo $row['id']; ?>">Edit</a>
                            <?php foreach ($crisisRoutes as $route): ?>
                                <form method="POST">
                                    <input type="hidden" name="record_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="route_key" value="<?php echo guidance_escape($route['route_key']); ?>">
                                    <button name="queue_route" class="btn-secondary"><?php echo guidance_escape($route['label']); ?></button>
                                </form>
                            <?php endforeach; ?>
                            <a class="table-link danger" href="crisis.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Delete this case?')">Delete</a>
                        </div>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</section>

<?php guidance_render_shell_end(); ?>
