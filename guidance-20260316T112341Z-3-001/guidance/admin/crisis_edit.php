<?php
include "../config/db.php";

$id = $_GET['id'];

$result = $conn->query("SELECT * FROM crisis WHERE id=$id");
$row = $result->fetch_assoc();

if(isset($_POST['update'])){

$name = $_POST['student_name'];
$incident = $_POST['incident'];
$action = $_POST['action_taken'];
$date = $_POST['date_reported'];

$conn->query("UPDATE crisis SET
student_name='$name',
incident='$incident',
action_taken='$action',
date_reported='$date'
WHERE id=$id");

header("Location: crisis.php");

}
?>

<link rel="stylesheet" href="../css/style.css">

<div class="main">

<h2>Edit Crisis Case</h2>

<div class="form-box">

<form method="POST">

<input name="student_name" value="<?php echo $row['student_name']; ?>" required>

<textarea name="incident"><?php echo $row['incident']; ?></textarea>

<textarea name="action_taken"><?php echo $row['action_taken']; ?></textarea>

<input type="date" name="date_reported" value="<?php echo $row['date_reported']; ?>">

<button name="update">Update Case</button>

</form>

</div>

</div>