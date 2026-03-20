<?php
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

$message = '';
$messageType = 'success';
$students = guidance_fetch_students($conn);
$selectedStudentId = $_GET['student_id'] ?? ($_POST['student_id'] ?? '');
$guidanceRoutes = guidance_integration_default_routes()['guidance'];

if (isset($_POST['add'])) {
    $studentId = trim($_POST['student_id'] ?? '');
    $student = guidance_find_student_by_student_id($conn, $studentId);
    $concern = trim($_POST['concern'] ?? '');
    $action = trim($_POST['action_taken'] ?? '');
    $date = trim($_POST['date_recorded'] ?? '');
    $category = trim($_POST['category'] ?? 'behavior');
    $priority = trim($_POST['priority_level'] ?? 'medium');

    if ($student && $concern !== '' && $action !== '' && $date !== '') {
        $caseReference = 'GDN-' . date('Y') . '-' . strtoupper(substr(uniqid(), -4));
        $conn->query("INSERT INTO guidance(
            student_name, student_id, concern, action_taken, date_recorded, status,
            case_reference, category, priority_level, referral_status, synced_at
        ) VALUES(
            " . guidance_integration_quote($student['name']) . ",
            " . guidance_integration_quote($student['student_id']) . ",
            " . guidance_integration_quote($concern) . ",
            " . guidance_integration_quote($action) . ",
            " . guidance_integration_quote($date) . ",
            'Pending',
            " . guidance_integration_quote($caseReference) . ",
            " . guidance_integration_quote($category) . ",
            " . guidance_integration_quote($priority) . ",
            'internal_review',
            NOW()
        )");
        $message = 'Referral or behavior monitoring record added for the selected student.';
    } else {
        $message = 'Select a student and complete the concern, action, and date fields.';
        $messageType = 'error';
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM guidance WHERE id=$id");
    $message = 'Guidance record deleted.';
}

if (isset($_POST['queue_route'])) {
    $recordId = (int) $_POST['record_id'];
    $routeKey = trim($_POST['route_key'] ?? '');
    $route = null;

    foreach ($guidanceRoutes as $candidate) {
        if ($candidate['route_key'] === $routeKey) {
            $route = $candidate;
            break;
        }
    }

    $recordResult = $conn->query("SELECT * FROM guidance WHERE id=$recordId LIMIT 1");
    $record = $recordResult && method_exists($recordResult, 'fetch_assoc') ? $recordResult->fetch_assoc() : null;

    if ($route && $record) {
        $queued = guidance_integration_queue_outbound($conn, $route['target_department'], $route['event_code'], [
            'route_key' => $route['route_key'],
            'reference_table' => 'guidance',
            'reference_id' => $record['id'],
            'student_id' => $record['student_id'],
            'student_name' => $record['student_name'],
            'payload_summary' => $record['concern'],
            'payload_json' => [
                'student_id' => $record['student_id'],
                'student_name' => $record['student_name'],
                'concern' => $record['concern'],
                'action_taken' => $record['action_taken'],
                'category' => $record['category'] ?? 'general',
                'priority_level' => $record['priority_level'] ?? 'medium',
                'case_reference' => $record['case_reference'] ?? null,
            ],
        ]);

        if ($queued) {
            $conn->query("UPDATE guidance SET referral_status='shared', synced_at=NOW() WHERE id=$recordId");
            $message = 'Guidance record routed to ' . guidance_integration_department_label($route['target_department']) . '.';
        } else {
            $message = 'Unable to route the selected Guidance record.';
            $messageType = 'error';
        }
    } else {
        $message = 'The selected Guidance record could not be found.';
        $messageType = 'error';
    }
}

$result = $conn->query("SELECT * FROM guidance ORDER BY date_recorded DESC, id DESC");
$recordTotal = $result ? $result->num_rows : 0;
$pendingResult = $conn->query("SELECT * FROM guidance WHERE status='Pending'");
$pendingTotal = $pendingResult ? $pendingResult->num_rows : 0;
$recentResult = $conn->query("SELECT * FROM guidance WHERE date_recorded >= CURRENT_DATE - INTERVAL '7 day'");
$recentTotal = $recentResult ? $recentResult->num_rows : 0;
$sharedResult = $conn->query("SELECT * FROM guidance WHERE referral_status='shared'");
$sharedTotal = $sharedResult ? $sharedResult->num_rows : 0;

guidance_render_shell_start(
    'Referrals & Monitoring',
    'Referrals And Behavioral Monitoring',
    'Use this workspace for non-crisis interventions, referrals, and behavior monitoring that Guidance may later share with Registrar, Prefect, or PMED.',
    [
        ['label' => 'Monitoring Records', 'value' => $recordTotal, 'note' => 'Behavior and intervention records currently stored in Guidance.'],
        ['label' => 'Pending Cases', 'value' => $pendingTotal, 'note' => 'Cases still under office review or waiting for follow-through.'],
        ['label' => 'Recent Entries', 'value' => $recentTotal, 'note' => 'Records updated within the last seven days.'],
        ['label' => 'Shared Records', 'value' => $sharedTotal, 'note' => 'Records already handed off to partner departments.'],
    ],
    [
        ['label' => 'Add Monitoring Record', 'href' => '#guidance-form', 'class' => 'btn-primary'],
        ['label' => 'Open Integration Hub', 'href' => 'integration.php', 'class' => 'btn-secondary'],
    ],
    ['Registrar Student Data', 'Guidance Monitoring', 'Registrar / Prefect / PMED Referral', 'Follow-Up Tracking']
);
?>

<?php if ($message !== ''): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>

<div class="split-layout">
    <section class="form-box" id="guidance-form">
        <div class="panel-heading">
            <div>
                <h2>Add Referral Or Monitoring Record</h2>
                <p>Choose the student from the Registrar-fed registry, then document the case once for internal follow-up and external sharing.</p>
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
            <textarea name="concern" placeholder="Behavior concern, referral note, or monitoring summary" required></textarea>
            <textarea name="action_taken" placeholder="Action taken by Guidance" required></textarea>
            <div class="form-grid">
                <div>
                    <label>Case Category</label>
                    <select name="category" required>
                        <option value="behavior">Behavior Monitoring</option>
                        <option value="referral">Referral</option>
                        <option value="wellness">Wellness Monitoring</option>
                        <option value="academic">Academic Concern</option>
                    </select>
                </div>
                <div>
                    <label>Priority</label>
                    <select name="priority_level" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
            </div>
            <div>
                <label>Date Recorded</label>
                <input type="date" name="date_recorded" required>
            </div>
            <button name="add">Save Monitoring Record</button>
        </form>
    </section>

    <aside class="insight-panel soft">
        <div class="panel-heading">
            <div>
                <h3>When To Use This</h3>
                <p>This area is for student support records that are not crisis-only but still need visibility outside Guidance.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">Registrar</div>
                <div class="mini-list-note">Share case summaries that affect academic tracking, year/section handling, or support planning.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">Prefect</div>
                <div class="mini-list-note">Use for conduct or behavioral referrals that need discipline-side coordination.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">PMED</div>
                <div class="mini-list-note">Use for wellness or follow-up summaries that belong in central monitoring dashboards.</div>
            </article>
        </div>
    </aside>
</div>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Referral And Monitoring Register</h2>
            <p>Each record can be reviewed by Guidance and sent to the proper department from the same row.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Student</th>
                <th>Category</th>
                <th>Concern</th>
                <th>Action Taken</th>
                <th>Date</th>
                <th>Referral Status</th>
                <th>Action</th>
            </tr>

            <?php while ($row = $result->fetch_assoc()) { ?>
                <tr>
                    <td>
                        <?php echo guidance_escape($row['student_name']); ?><br>
                        <span class="summary-label"><?php echo guidance_escape($row['student_id'] ?: '-'); ?></span>
                    </td>
                    <td><?php echo guidance_escape(ucwords(str_replace('_', ' ', $row['category'] ?: 'general'))); ?></td>
                    <td><?php echo guidance_escape($row['concern']); ?></td>
                    <td><?php echo guidance_escape($row['action_taken']); ?></td>
                    <td><?php echo guidance_escape($row['date_recorded']); ?></td>
                    <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row['referral_status'] ?: 'pending')); ?>"><?php echo guidance_escape($row['referral_status'] ?: 'internal_review'); ?></span></td>
                    <td>
                        <div class="table-actions">
                            <a class="table-link" href="guidance_edit.php?id=<?php echo $row['id']; ?>">Edit</a>
                            <?php foreach ($guidanceRoutes as $route): ?>
                                <form method="POST">
                                    <input type="hidden" name="record_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="route_key" value="<?php echo guidance_escape($route['route_key']); ?>">
                                    <button name="queue_route" class="btn-secondary"><?php echo guidance_escape($route['label']); ?></button>
                                </form>
                            <?php endforeach; ?>
                            <a class="table-link danger" href="guidance.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Delete this record?')">Delete</a>
                        </div>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</section>

<?php guidance_render_shell_end(); ?>
