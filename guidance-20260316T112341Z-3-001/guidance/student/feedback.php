<?php
session_start();
include "../config/db.php";

// Get student info from login session
$student_name = $_SESSION['student_name'] ?? 'Unknown Student';
$student_id = $_SESSION['student_id'] ?? 'STD001';

// Handle submission
if(isset($_POST['submit'])){
    $feedback = $_POST['feedback'];
    $rating = $_POST['rating'];

    // Insert into survey table including student info and status
    $conn->query("INSERT INTO survey(student_id, student_name, feedback, rating, date_submitted, status)
                  VALUES('$student_id','$student_name', '$feedback', '$rating', CURDATE(), 'Pending')");

    $success_msg = "Your feedback has been submitted!";
}

// Get all feedbacks submitted by this student
$feedbacks = $conn->query("SELECT * FROM survey WHERE student_id='$student_id' ORDER BY date_submitted DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Survey & Feedback</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <h2>Student</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="counseling_request.php">Request Counseling</a>
    <a href="guidance_records.php">My Guidance Records</a>
    <a href="feedback.php">Survey & Feedback</a>
    <a href="../logout.php">Logout</a>
</div>

<!-- Main Content -->
<div class="main">

    <div style="display:flex; justify-content: space-between; align-items:center;">
        <h1>Survey & Feedback</h1>
        <form action="dashboard.php">
            <button type="submit" style="background:#6c757d; padding:8px 15px;">Back to Dashboard</button>
        </form>
    </div>

    <!-- Success Message -->
    <?php if(isset($success_msg)): ?>
    <div class="form-box" style="background:#d4edda; color:#155724;">
        <?php echo $success_msg; ?>
    </div>
    <?php endif; ?>

    <!-- Feedback Form -->
    <div class="form-box">
        <h2>Submit New Feedback</h2>
        <form method="POST">
            <!-- Student Name / ID (disabled) -->
            <input type="text" value="<?php echo $student_name .' ('.$student_id.')'; ?>" disabled style="margin-bottom:10px; 
            font-weight:bold; background:#f1f1f1; border-radius:5px; padding:10px;">

            <label>Feedback:</label>
            <textarea name="feedback" placeholder="Enter your feedback..." required></textarea>

            <label>Rating (1-5):</label>
            <select name="rating" required>
                <option value="">Select</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
            </select>

            <button type="submit" name="submit">Submit Feedback</button>
        </form>
    </div>

    <!-- Feedback Table -->
    <div class="records-table">
        <h2>My Submitted Feedback</h2>
        <table>
            <tr>
                <th>Student Name / ID</th>
                <th>Feedback</th>
                <th>Rating</th>
                <th>Date Submitted</th>
                <th>Status</th>
            </tr>

            <?php if($feedbacks->num_rows > 0): ?>
                <?php while($row = $feedbacks->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row['student_name'].' ('.$row['student_id'].')'; ?></td>
                        <td><?php echo $row['feedback']; ?></td>
                        <td><?php echo $row['rating']; ?></td>
                        <td><?php echo $row['date_submitted']; ?></td>
                        <td>
                            <?php
                                $status = $row['status'];
                                $color = $status=="Pending" ? "#ffc107" : ($status=="Reviewed" ? "#28a745" : "#6c757d");
                                echo "<span style='color:white; background:$color; padding:5px 10px; border-radius:5px;'>$status</span>";
                            ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5" class="no-records">No feedback submitted yet.</td></tr>
            <?php endif; ?>
        </table>
    </div>

</div>
</body>
</html>