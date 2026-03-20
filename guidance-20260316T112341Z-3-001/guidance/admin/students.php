<?php
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

$message = '';
$messageType = 'success';

if (isset($_POST['sync_from_registrar'])) {
    $synced = guidance_receive_student_profile_from_registrar($conn, [
        'student_id' => $_POST['student_id'] ?? '',
        'name' => $_POST['name'] ?? '',
        'course' => $_POST['course'] ?? '',
        'year_level' => $_POST['year_level'] ?? '',
        'section_name' => $_POST['section_name'] ?? '',
        'enrollment_status' => $_POST['enrollment_status'] ?? 'Enrolled',
        'subject_load' => array_values(array_filter(array_map('trim', explode(',', $_POST['subject_load'] ?? '')))),
    ]);

    if ($synced) {
        $message = 'Student profile received from Registrar and added to the Guidance registry.';
    } else {
        $message = 'Unable to receive the Registrar profile. Check the required student fields.';
        $messageType = 'error';
    }
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $conn->query("DELETE FROM students WHERE id=$id");
    $message = 'Student profile removed from the Guidance registry.';
}

$result = $conn->query("SELECT * FROM students ORDER BY name ASC, student_id ASC");
$studentTotal = $result ? $result->num_rows : 0;
$courseTotalResult = $conn->query("SELECT DISTINCT course FROM students WHERE course IS NOT NULL");
$courseTotal = $courseTotalResult ? $courseTotalResult->num_rows : 0;
$yearTotalResult = $conn->query("SELECT DISTINCT year_level FROM students WHERE year_level IS NOT NULL");
$yearTotal = $yearTotalResult ? $yearTotalResult->num_rows : 0;
$registrarSyncedResult = $conn->query("SELECT * FROM students WHERE registrar_status='Synced'");
$registrarSynced = $registrarSyncedResult ? $registrarSyncedResult->num_rows : 0;

guidance_render_shell_start(
    'Student Info',
    'Student Intake From Registrar',
    'Keep Guidance user-friendly for staff by receiving student identity and academic details from Registrar first, then launching counseling, referral, incident, and monitoring work from one student registry. Use Connected Data for live shared Supabase records, then manage imported students here.',
    [
        ['label' => 'Student Profiles', 'value' => $studentTotal, 'note' => 'Students available for Guidance intake and case handling.'],
        ['label' => 'Registrar Synced', 'value' => $registrarSynced, 'note' => 'Profiles already received from Registrar into Guidance.'],
        ['label' => 'Course Groups', 'value' => $courseTotal, 'note' => 'Programs currently represented in Guidance.'],
        ['label' => 'Year Levels', 'value' => $yearTotal, 'note' => 'Academic levels currently monitored by the office.'],
    ],
    [
        ['label' => 'Fetch Shared Data', 'href' => 'connected_data.php', 'class' => 'btn-primary'],
        ['label' => 'Quick Manual Intake', 'href' => '#student-form', 'class' => 'btn-secondary'],
        ['label' => 'Open Integration Hub', 'href' => 'integration.php', 'class' => 'btn-secondary'],
    ],
    ['Registrar Sends Student Identity', 'Guidance Creates Cases', 'Registrar / Prefect / PMED Coordination', 'Status Tracking']
);
?>

<?php if ($message !== ''): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>

<div class="split-layout">
    <section class="form-box" id="student-form">
        <div class="panel-heading">
            <div>
                <h2>Quick Manual Registrar Intake</h2>
                <p>Use this form only when a live shared Registrar record is not available yet. The preferred path is the Connected Data page, which reads directly from shared Supabase data.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <input name="student_id" placeholder="Student ID" required>
            <input name="name" placeholder="Student Name" required>
            <div class="form-grid">
                <input name="course" placeholder="Course" required>
                <input name="year_level" placeholder="Year Level" required>
            </div>
            <div class="form-grid">
                <input name="section_name" placeholder="Section" required>
                <select name="enrollment_status" required>
                    <option value="Enrolled">Enrolled</option>
                    <option value="Advised">Advised</option>
                    <option value="Cleared">Cleared</option>
                    <option value="On Hold">On Hold</option>
                </select>
            </div>
            <textarea name="subject_load" placeholder="Subject Load, separated by commas"></textarea>
            <button name="sync_from_registrar">Receive From Registrar</button>
        </form>
    </section>

    <aside class="insight-panel soft">
        <div class="panel-heading">
            <div>
                <h3>How This Works</h3>
                <p>The student registry is now the starting point of the Guidance end-to-end flow.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">1. Registrar sends identity</div>
                <div class="mini-list-note">Student ID, course, year, section, and enrollment context are received into Guidance.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">2. Guidance opens a case</div>
                <div class="mini-list-note">Staff can jump from the student profile directly into counseling, referrals, incidents, or monitoring.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">3. Guidance shares outcomes</div>
                <div class="mini-list-note">Finished cases can be routed to Registrar, Prefect, or PMED without re-encoding the student information.</div>
            </article>
        </div>
    </aside>
</div>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Guidance Student Registry</h2>
            <p>Each profile is ready to start counseling, behavior monitoring, referrals, or incident handling for the student.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Course / Year / Section</th>
                <th>Enrollment</th>
                <th>Registrar Sync</th>
                <th>Action</th>
            </tr>

            <?php while ($row = $result->fetch_assoc()) { ?>
                <tr>
                    <td><?php echo guidance_escape($row['student_id']); ?></td>
                    <td><?php echo guidance_escape($row['name']); ?></td>
                    <td><?php echo guidance_escape(trim(($row['course'] ?? '-') . ' / ' . ($row['year_level'] ?? '-') . ' / ' . ($row['section_name'] ?? '-'))); ?></td>
                    <td><?php echo guidance_escape($row['enrollment_status'] ?: 'Unknown'); ?></td>
                    <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row['registrar_status'] === 'Synced' ? 'synced' : 'pending')); ?>"><?php echo guidance_escape($row['registrar_status'] ?: 'Pending'); ?></span></td>
                    <td>
                        <div class="table-actions">
                            <a class="table-link" href="edit_student.php?id=<?php echo $row['id']; ?>">Edit</a>
                            <a class="table-link success" href="counseling.php?student_id=<?php echo urlencode($row['student_id']); ?>">Open Counseling</a>
                            <a class="table-link success" href="guidance.php?student_id=<?php echo urlencode($row['student_id']); ?>">Open Monitoring</a>
                            <a class="table-link success" href="crisis.php?student_id=<?php echo urlencode($row['student_id']); ?>">Open Incident</a>
                            <a class="table-link danger" href="students.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Delete this student?')">Delete</a>
                        </div>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</section>

<?php guidance_render_shell_end(); ?>
