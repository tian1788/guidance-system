<?php
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

$message = '';
$messageType = 'success';
$students = guidance_fetch_students($conn);
$selectedStudentId = $_GET['student_id'] ?? ($_POST['student_id'] ?? '');
$counselingRoutes = guidance_integration_default_routes()['counseling'];

if (isset($_POST['send_reply'])) {
    $id = (int) $_POST['id'];
    $reply = trim($_POST['reply'] ?? '');
    $conn->query("UPDATE counseling SET reply=" . guidance_integration_quote($reply !== '' ? $reply : null) . ", status='Replied' WHERE id=$id");
    $message = 'Reply saved for the counseling case.';
}

if (isset($_POST['add'])) {
    $studentId = trim($_POST['student_id'] ?? '');
    $student = guidance_find_student_by_student_id($conn, $studentId);
    $issue = trim($_POST['issue'] ?? '');
    $date = trim($_POST['schedule'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $riskLevel = trim($_POST['risk_level'] ?? 'medium');

    if ($student && $issue !== '' && $date !== '') {
        $caseReference = 'COU-' . date('Y') . '-' . strtoupper(substr(uniqid(), -4));
        $conn->query("INSERT INTO counseling(
            student_name, student_id, issue, schedule_date, status, reason, case_reference, risk_level, referral_status, source_department, synced_at
        ) VALUES(
            " . guidance_integration_quote($student['name']) . ",
            " . guidance_integration_quote($student['student_id']) . ",
            " . guidance_integration_quote($issue) . ",
            " . guidance_integration_quote($date) . ",
            'Pending',
            " . guidance_integration_quote($reason !== '' ? $reason : null) . ",
            " . guidance_integration_quote($caseReference) . ",
            " . guidance_integration_quote($riskLevel) . ",
            'internal_review',
            'guidance',
            NOW()
        )");
        $message = 'Counseling case added and linked to the selected student profile.';
    } else {
        $message = 'Select a student and complete the issue and schedule fields.';
        $messageType = 'error';
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM counseling WHERE id=$id");
    $message = 'Counseling case deleted.';
}

if (isset($_POST['update_status'])) {
    $id = (int) $_POST['id'];
    $status = trim($_POST['status'] ?? 'Pending');
    $conn->query("UPDATE counseling SET status=" . guidance_integration_quote($status) . " WHERE id=$id");
    $message = 'Counseling case status updated.';
}

if (isset($_POST['queue_route'])) {
    $recordId = (int) $_POST['record_id'];
    $routeKey = trim($_POST['route_key'] ?? '');
    $route = null;

    foreach ($counselingRoutes as $candidate) {
        if ($candidate['route_key'] === $routeKey) {
            $route = $candidate;
            break;
        }
    }

    $recordResult = $conn->query("SELECT * FROM counseling WHERE id=$recordId LIMIT 1");
    $record = $recordResult && method_exists($recordResult, 'fetch_assoc') ? $recordResult->fetch_assoc() : null;

    if ($route && $record) {
        $queued = guidance_integration_queue_outbound($conn, $route['target_department'], $route['event_code'], [
            'route_key' => $route['route_key'],
            'reference_table' => 'counseling',
            'reference_id' => $record['id'],
            'student_id' => $record['student_id'],
            'student_name' => $record['student_name'],
            'payload_summary' => $record['issue'],
            'payload_json' => [
                'student_id' => $record['student_id'],
                'student_name' => $record['student_name'],
                'issue' => $record['issue'],
                'reply' => $record['reply'],
                'risk_level' => $record['risk_level'] ?? 'medium',
                'case_reference' => $record['case_reference'] ?? null,
            ],
        ]);

        if ($queued) {
            $conn->query("UPDATE counseling SET referral_status='shared', synced_at=NOW() WHERE id=$recordId");
            $message = 'Counseling case routed to ' . guidance_integration_department_label($route['target_department']) . '.';
        } else {
            $message = 'Unable to route the counseling case to the selected department.';
            $messageType = 'error';
        }
    } else {
        $message = 'The selected counseling case could not be routed.';
        $messageType = 'error';
    }
}

$result = $conn->query("SELECT * FROM counseling ORDER BY schedule_date DESC, id DESC");
$allCounseling = $result ? $result->num_rows : 0;
$pendingResult = $conn->query("SELECT * FROM counseling WHERE status='Pending'");
$pendingCounseling = $pendingResult ? $pendingResult->num_rows : 0;
$repliedResult = $conn->query("SELECT * FROM counseling WHERE status='Replied'");
$repliedCounseling = $repliedResult ? $repliedResult->num_rows : 0;
$sharedResult = $conn->query("SELECT * FROM counseling WHERE referral_status='shared'");
$sharedCounseling = $sharedResult ? $sharedResult->num_rows : 0;

guidance_render_shell_start(
    'Academic Counseling',
    'Counseling Workspace',
    'Guidance receives students from Registrar, records counseling interventions here, and can send the outcome directly to Registrar or PMED when the case is ready.',
    [
        ['label' => 'Counseling Cases', 'value' => $allCounseling, 'note' => 'All counseling records currently handled by Guidance.'],
        ['label' => 'Pending Queue', 'value' => $pendingCounseling, 'note' => 'Cases still waiting for schedule, follow-up, or reply.'],
        ['label' => 'Replied Cases', 'value' => $repliedCounseling, 'note' => 'Cases already answered by the Guidance office.'],
        ['label' => 'Shared Cases', 'value' => $sharedCounseling, 'note' => 'Counseling outcomes already routed to other departments.'],
    ],
    [
        ['label' => 'Create Counseling Case', 'href' => '#counseling-form', 'class' => 'btn-primary'],
        ['label' => 'Open Integration Hub', 'href' => 'integration.php', 'class' => 'btn-secondary'],
    ],
    ['Registrar Sends Student Data', 'Guidance Counseling Session', 'Registrar / PMED Handoff', 'Status Tracking']
);
?>

<?php if ($message !== ''): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>

<div class="split-layout">
    <section class="form-box" id="counseling-form">
        <div class="panel-heading">
            <div>
                <h2>Create Counseling Case</h2>
                <p>Select a registered student first so Guidance staff do not need to retype identity details each time.</p>
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
            <textarea name="issue" placeholder="Counseling concern or presenting issue" required></textarea>
            <textarea name="reason" placeholder="Referral reason or background notes"></textarea>
            <div class="form-grid">
                <div>
                    <label>Schedule Date</label>
                    <input type="date" name="schedule" required>
                </div>
                <div>
                    <label>Risk Level</label>
                    <select name="risk_level" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
            </div>
            <button name="add">Save Counseling Case</button>
        </form>
    </section>

    <aside class="insight-panel soft">
        <div class="panel-heading">
            <div>
                <h3>End-to-End Flow</h3>
                <p>This workspace now follows a simpler staff flow from student intake to department sharing.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">1. Choose the student</div>
                <div class="mini-list-note">The record comes from the Registrar-fed student registry already stored inside Guidance.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">2. Record the session</div>
                <div class="mini-list-note">Guidance logs the concern, schedule, reply, and counseling risk context in one place.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">3. Share when ready</div>
                <div class="mini-list-note">Route the finished counseling case to Registrar or PMED directly from the case row below.</div>
            </article>
        </div>
    </aside>
</div>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Counseling Case Queue</h2>
            <p>Update the case, send the student reply, and route the final record to the connected departments from the same table.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Student</th>
                <th>Issue</th>
                <th>Schedule</th>
                <th>Status</th>
                <th>Reply</th>
                <th>Referral Status</th>
                <th>Action</th>
            </tr>

            <?php while ($row = $result->fetch_assoc()) { ?>
                <tr>
                    <td>
                        <?php echo guidance_escape($row['student_name']); ?><br>
                        <span class="summary-label"><?php echo guidance_escape($row['student_id'] ?: '-'); ?></span>
                    </td>
                    <td><?php echo guidance_escape($row['issue']); ?></td>
                    <td><?php echo guidance_escape($row['schedule_date']); ?></td>
                    <td>
                        <form method="POST" class="form-stack">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <select name="status">
                                <option <?php if ($row['status'] == "Pending") echo "selected"; ?>>Pending</option>
                                <option <?php if ($row['status'] == "Scheduled") echo "selected"; ?>>Scheduled</option>
                                <option <?php if ($row['status'] == "Completed") echo "selected"; ?>>Completed</option>
                                <option <?php if ($row['status'] == "Replied") echo "selected"; ?>>Replied</option>
                            </select>
                            <button name="update_status">Update</button>
                        </form>
                    </td>
                    <td><?php echo guidance_escape($row['reply'] ?? "No reply yet"); ?></td>
                    <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row['referral_status'] ?: 'pending')); ?>"><?php echo guidance_escape($row['referral_status'] ?: 'internal_review'); ?></span></td>
                    <td>
                        <div class="stack-layout">
                            <form method="POST" class="form-stack">
                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                <textarea name="reply" placeholder="Write the Guidance reply for the student"></textarea>
                                <button name="send_reply">Save Reply</button>
                            </form>
                            <div class="inline-links">
                                <?php foreach ($counselingRoutes as $route): ?>
                                    <form method="POST">
                                        <input type="hidden" name="record_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="route_key" value="<?php echo guidance_escape($route['route_key']); ?>">
                                        <button name="queue_route" class="btn-secondary"><?php echo guidance_escape($route['label']); ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <a class="table-link danger" href="counseling.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Delete this counseling case?')">Delete</a>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</section>

<?php guidance_render_shell_end(); ?>
