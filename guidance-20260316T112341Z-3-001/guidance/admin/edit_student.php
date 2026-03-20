<?php
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

$id = (int) ($_GET['id'] ?? 0);
$result = $conn->query("SELECT * FROM students WHERE id=$id");
$row = $result && method_exists($result, 'fetch_assoc') ? $result->fetch_assoc() : null;

if (!$row) {
    die('Student not found.');
}

if (isset($_POST['update'])) {
    $studentId = trim($_POST['student_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $section = trim($_POST['section_name'] ?? '');
    $enrollmentStatus = trim($_POST['enrollment_status'] ?? 'Enrolled');

    $conn->query("UPDATE students SET
        student_id=" . guidance_integration_quote($studentId) . ",
        name=" . guidance_integration_quote($name) . ",
        course=" . guidance_integration_quote($course) . ",
        year_level=" . guidance_integration_quote($year) . ",
        section_name=" . guidance_integration_quote($section !== '' ? $section : null) . ",
        enrollment_status=" . guidance_integration_quote($enrollmentStatus) . ",
        synced_at=NOW()
    WHERE id=$id");

    header("Location: students.php");
    exit;
}

guidance_render_shell_start(
    'Student Info',
    'Edit Student Profile',
    'Update the student profile that Guidance received from Registrar so every connected case keeps the same identity details.',
    [],
    [
        ['label' => 'Back To Student Registry', 'href' => 'students.php', 'class' => 'btn-secondary'],
    ],
    ['Registrar Profile', 'Guidance Update', 'Case Reuse']
);
?>

<section class="form-box">
    <div class="panel-heading">
        <div>
            <h2>Edit Student</h2>
            <p>Adjust the academic details here if Guidance needs to correct or complete a student profile.</p>
        </div>
    </div>
    <form method="POST" class="form-stack">
        <input name="student_id" value="<?php echo guidance_escape($row['student_id']); ?>" required>
        <input name="name" value="<?php echo guidance_escape($row['name']); ?>" required>
        <div class="form-grid">
            <input name="course" value="<?php echo guidance_escape($row['course']); ?>" required>
            <input name="year" value="<?php echo guidance_escape($row['year_level']); ?>" required>
        </div>
        <div class="form-grid">
            <input name="section_name" value="<?php echo guidance_escape($row['section_name'] ?? ''); ?>" placeholder="Section">
            <select name="enrollment_status" required>
                <?php foreach (['Enrolled', 'Advised', 'Cleared', 'On Hold'] as $status): ?>
                    <option value="<?php echo guidance_escape($status); ?>" <?php if (($row['enrollment_status'] ?? 'Enrolled') === $status) echo 'selected'; ?>>
                        <?php echo guidance_escape($status); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button name="update">Update Student</button>
    </form>
</section>

<?php guidance_render_shell_end(); ?>
