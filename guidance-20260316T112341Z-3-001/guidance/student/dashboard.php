
<?php
include "../config/db.php";

/* Get counts for dashboard cards */
$myCounseling = $conn->query("SELECT * FROM counseling")->num_rows;
$myGuidance = $conn->query("SELECT * FROM guidance")->num_rows;
$mySurvey = $conn->query("SELECT * FROM survey")->num_rows;
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

<h1>Welcome, Student!</h1>
<p>Here is your guidance overview:</p>

<div class="cards">

<div class="card">
<h3>Counseling Requests</h3>
<p><?php echo $myCounseling; ?></p>
</div>

<div class="card">
<h3>Guidance Records</h3>
<p><?php echo $myGuidance; ?></p>
</div>

<div class="card">
<h3>Survey Feedback</h3>
<p><?php echo $mySurvey; ?></p>
</div>

</div>

</div>