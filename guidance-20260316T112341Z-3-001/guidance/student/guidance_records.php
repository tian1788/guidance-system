<?php
session_start();
include "../config/db.php";

// Ensure student_name is set (from login)
if(isset($_SESSION['student_name'])){
    $student_name = $_SESSION['student_name'];
} else {
    $student_name = "Juan Dela Cruz"; // temporary for testing
}

// Fetch this student's guidance records
$result = $conn->query("SELECT * FROM guidance WHERE student_name='$student_name' ORDER BY date_recorded DESC");
?>

<link rel="stylesheet" href="../css/style.css">

<div class="sidebar">
<h2>Student</h2>
<a href="dashboard.php">Dashboard</a>
<a href="counseling_request.php">Request Counseling</a>
<a href="guidance_records.php">My Guidance Records</a>
<a href="feedback.php">Survey & Feedback</a>
<a href="../logout.php">Logout</a>
</div>

<div class="main">
<h1>My Guidance Records</h1>
<p>View all your guidance sessions and interventions below.</p>

<?php if($result->num_rows > 0): ?>

<div class="records-table">
<table>
<tr>
<th>Date</th>
<th>Concern</th>
<th>Action Taken</th>
</tr>

<?php while($row = $result->fetch_assoc()): ?>
<tr>
<td><?php echo date("F d, Y", strtotime($row['date_recorded'])); ?></td>
<td><?php echo $row['concern']; ?></td>
<td><?php echo $row['action_taken']; ?></td>
</tr>
<?php endwhile; ?>

</table>
</div>

<?php else: ?>
<div class="no-records">
<p>You have no guidance records yet.</p>
</div>
<?php endif; ?>

</div>