<?php
session_start();
include "config/db.php";

if(isset($_POST['login'])){

$email = $_POST['email'];
$password = $_POST['password'];

$sql = "SELECT * FROM users WHERE email='$email' AND password='$password'";
$result = $conn->query($sql);

if($result->num_rows > 0){

$user = $result->fetch_assoc();

$_SESSION['user'] = $user['name'];
$_SESSION['role'] = $user['role'];

if($user['role'] == "admin"){
header("Location: admin/dashboard.php");
}else{
header("Location: student/dashboard.php");
}

}else{
echo "Invalid Login";
}

}
?>

<link rel="stylesheet" href="css/style.css">

<div class="login-container">

<h2>Guidance System</h2>

<form method="POST">
<h2>Guidance System Login</h2>

<input type="email" name="email" placeholder="Email" required>

<input type="password" name="password" placeholder="Password" required>

<button name="login">Login</button>

</form>