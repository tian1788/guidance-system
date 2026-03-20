<?php
include "../config/db.php";

$id=$_GET['id'];

$result=$conn->query("SELECT * FROM guidance WHERE id=$id");
$row=$result->fetch_assoc();

if(isset($_POST['update'])){

$name=$_POST['student_name'];
$concern=$_POST['concern'];
$action=$_POST['action_taken'];
$date=$_POST['date_recorded'];

$conn->query("UPDATE guidance SET
student_name='$name',
concern='$concern',
action_taken='$action',
date_recorded='$date'
WHERE id=$id");

header("Location: guidance.php");

}
?>

<link rel="stylesheet" href="../css/style.css">

<div class="main">

<h2>Edit Guidance Record</h2>

<div class="form-box">

<form method="POST">

<input name="student_name" value="<?php echo $row['student_name']; ?>">

<textarea name="concern"><?php echo $row['concern']; ?></textarea>

<textarea name="action_taken"><?php echo $row['action_taken']; ?></textarea>

<input type="date" name="date_recorded" value="<?php echo $row['date_recorded']; ?>">

<button name="update">Update</button>

</form>

</div>

</div>