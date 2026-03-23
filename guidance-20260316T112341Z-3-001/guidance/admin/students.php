<?php
session_start();
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

if (isset($_GET['lookup_student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $lookupStudentId = trim((string) ($_GET['lookup_student_id'] ?? ''));
    if ($lookupStudentId === '') {
        echo json_encode(['ok' => false, 'message' => 'Student ID is required.']);
        exit;
    }

    $student = guidance_find_student_by_student_id($conn, $lookupStudentId);
    if (!$student) {
        echo json_encode(['ok' => false, 'message' => 'Student not found in Registrar.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'student_id' => (string) ($student['student_id'] ?? ''),
            'name' => (string) ($student['student_name'] ?? ''),
            'course' => (string) ($student['course'] ?? ''),
            'year_level' => (string) ($student['year_level'] ?? ''),
            'section_name' => (string) ($student['section_name'] ?? ''),
            'enrollment_status' => (string) ($student['enrollment_status'] ?? 'Enrolled'),
            'subject_load' => (string) ($student['subject_load'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

$result = $conn->query("SELECT * FROM students ORDER BY name ASC, student_id ASC LIMIT 80");
$studentTotalResult = $conn->query("SELECT COUNT(*) AS total FROM students");
$studentTotalRow = (is_object($studentTotalResult) && method_exists($studentTotalResult, 'fetch_assoc')) ? $studentTotalResult->fetch_assoc() : ['total' => 0];
$studentTotal = (int) ($studentTotalRow['total'] ?? 0);
$courseTotalResult = $conn->query("SELECT COUNT(DISTINCT course) AS total FROM students WHERE COALESCE(course, '') <> ''");
$courseTotalRow = (is_object($courseTotalResult) && method_exists($courseTotalResult, 'fetch_assoc')) ? $courseTotalResult->fetch_assoc() : ['total' => 0];
$courseTotal = (int) ($courseTotalRow['total'] ?? 0);
$yearTotalResult = $conn->query("SELECT COUNT(DISTINCT year_level) AS total FROM students WHERE COALESCE(year_level, '') <> ''");
$yearTotalRow = (is_object($yearTotalResult) && method_exists($yearTotalResult, 'fetch_assoc')) ? $yearTotalResult->fetch_assoc() : ['total' => 0];
$yearTotal = (int) ($yearTotalRow['total'] ?? 0);
$registrarSyncedResult = $conn->query("SELECT COUNT(*) AS total FROM students WHERE registrar_status='Synced'");
$registrarSyncedRow = (is_object($registrarSyncedResult) && method_exists($registrarSyncedResult, 'fetch_assoc')) ? $registrarSyncedResult->fetch_assoc() : ['total' => 0];
$registrarSynced = (int) ($registrarSyncedRow['total'] ?? 0);

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
        ['label' => 'Request to HR', 'href' => '#hr-request-form', 'class' => 'btn-secondary'],
        ['label' => 'Open Integration Hub', 'href' => 'integration.php', 'class' => 'btn-secondary'],
    ],
    ['Registrar Sends Student Identity', 'Guidance Creates Cases', 'Registrar / Prefect / PMED Coordination', 'Status Tracking']
);
?>

<?php if (isset($_POST['sync_from_registrar']) && $message !== ''): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>


<div class="split-layout">
    <section class="form-box" id="student-form">
        <div class="panel-heading">
            <div>
                <h2>Quick Manual Registrar Intake</h2>
                <p>Use this form only when a live shared Registrar record is not available yet. For best performance, this page loads the local Guidance registry first; use the sync button only when you need fresh Registrar data.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <button type="submit" name="sync_live_registrar" formnovalidate>Sync Latest Registrar Students</button>
            <input name="student_id" id="student-id-input" placeholder="Student ID" required value="<?php echo guidance_escape($_GET['lookup_student_id'] ?? ''); ?>">
            <input name="name" id="student-name-input" placeholder="Student Name" required>
            <div class="form-grid">
                <input name="course" id="course-input" placeholder="Course" required>
                <input name="year_level" id="year-level-input" placeholder="Year Level" required>
            </div>
            <div class="form-grid">
                <input name="section_name" id="section-input" placeholder="Section" required>
                <select name="enrollment_status" id="enrollment-status-input" required>
                    <option value="Enrolled">Enrolled</option>
                    <option value="Advised">Advised</option>
                    <option value="Cleared">Cleared</option>
                    <option value="On Hold">On Hold</option>
                </select>
            </div>
            <textarea name="subject_load" id="subject-load-input" placeholder="Subject Load, separated by commas"></textarea>
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

<script>
(() => {
    const studentIdInput = document.getElementById('student-id-input');
    const studentNameInput = document.getElementById('student-name-input');
    const courseInput = document.getElementById('course-input');
    const yearLevelInput = document.getElementById('year-level-input');
    const sectionInput = document.getElementById('section-input');
    const enrollmentStatusInput = document.getElementById('enrollment-status-input');
    const subjectLoadInput = document.getElementById('subject-load-input');
    if (!studentIdInput || !studentNameInput || !courseInput || !yearLevelInput || !sectionInput || !enrollmentStatusInput || !subjectLoadInput) return;

    let lookupTimer = null;
    let lastLookup = '';

    const applyData = (data) => {
        if (!data || typeof data !== 'object') return;
        studentNameInput.value = String(data.name || '');
        courseInput.value = String(data.course || '');
        yearLevelInput.value = String(data.year_level || '');
        sectionInput.value = String(data.section_name || '');
        subjectLoadInput.value = String(data.subject_load || '');
        const status = String(data.enrollment_status || '');
        if (status) enrollmentStatusInput.value = status;
    };

    const lookupStudent = async () => {
        const value = String(studentIdInput.value || '').trim();
        if (!value || value.length < 3 || value === lastLookup) return;
        lastLookup = value;
        try {
            const response = await fetch(`students.php?lookup_student_id=${encodeURIComponent(value)}`, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (payload && payload.ok && payload.data) {
                applyData(payload.data);
            }
        } catch (_) {
            // Keep form usable even when lookup fails.
        }
    };

    const queueLookup = () => {
        if (lookupTimer) window.clearTimeout(lookupTimer);
        lookupTimer = window.setTimeout(lookupStudent, 250);
    };

    studentIdInput.addEventListener('input', queueLookup);
    studentIdInput.addEventListener('change', lookupStudent);
    studentIdInput.addEventListener('blur', lookupStudent);
})();
</script>

<?php guidance_render_shell_end(); ?>
