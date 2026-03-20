<?php
session_start();
include "../config/db.php";

// Example: Student info from login session
$student_id = $_SESSION['student_id'] ?? 'STD001';
$student_name = $_SESSION['student_name'] ?? 'Student';

/* ADD NEW COUNSELING REQUEST */
if(isset($_POST['request_counseling'])){
    $student_input = $_POST['student_input']; // Name or ID
    $issue = $_POST['issue'];
    $schedule = $_POST['schedule'];
    $status = "Pending";

    $conn->query("INSERT INTO counseling(student_name, student_id, issue, schedule_date, status) 
    VALUES('$student_name','$student_id','$issue','$schedule','$status')");
    $success_msg = "Your counseling request has been submitted!";
}

/* GET ALL REQUESTS */
$requests = $conn->query("SELECT * FROM counseling WHERE student_id='$student_id' ORDER BY id DESC");
?>

<link rel="stylesheet" href="../css/style.css">

<div class="main">

<div style="display:flex; justify-content: space-between; align-items:center;">
    <h1>My Counseling Requests</h1>
    <form action="dashboard.php">
        <button type="submit" style="background:#6c757d; padding:8px 15px;">Back to Dashboard</button>
    </form>
</div>

<?php if(isset($success_msg)){ ?>
<div class="form-box" style="background:#d4edda;color:#155724;">
    <?php echo $success_msg; ?>
</div>
<?php } ?>

<!-- REQUEST NEW COUNSELING -->
<div class="form-box">
<h2>Request New Counseling</h2>
<form method="POST">
    <!-- VISIBLE NAME/ID -->
    <input type="text" value="<?php echo $student_name .' ('.$student_id.')'; ?>" disabled 
    style="margin-bottom:10px; font-weight:bold; background:#f1f1f1; border-radius:5px; padding:10px;">
    
    <!-- HIDDEN INPUT PARA SA POST -->
    <input type="hidden" name="student_input" value="<?php echo $student_name .' ('.$student_id.')'; ?>">

    <!-- Issue textarea -->
    <textarea name="issue" placeholder="Describe your issue..." required></textarea>

    <!-- Schedule date -->
    <label>Preferred Schedule Date</label>
    <input type="date" name="schedule" required>

    <!-- Submit button -->
    <button name="request_counseling">Submit Request</button>
</form>
</div>

<!-- COUNSELING TABLE -->
<div class="records-table">
<table>
<tr>
<th>Student Name / ID</th>
<th>Issue</th>
<th>Schedule</th>
<th>Status</th>
<th>Reply</th>
</tr>

<?php if($requests->num_rows > 0){ ?>
<?php while($row = $requests->fetch_assoc()){ ?>
<tr>
<td><?php echo $row['student_name'].' ('.$row['student_id'].')'; ?></td>
<td><?php echo $row['issue']; ?></td>
<td><?php echo $row['schedule_date']; ?></td>
<td>
<?php 
$status = $row['status'];
$color = $status=="Pending" ? "#ffc107" : ($status=="Replied" ? "#28a745" : ($status=="Completed" ? "#007bff" : "#6c757d"));
echo "<span style='color:white; background:$color; padding:5px 10px; border-radius:5px;'>$status</span>";
?>
</td>
<td><?php echo $row['reply'] ?? "Waiting for counselor reply"; ?></td>
</tr>
<?php } ?>
<?php } else { ?>
<tr><td colspan="5" class="no-records">No counseling requests found</td></tr>
<?php } ?>
</table>
</div>

</div>