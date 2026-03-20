<?php
include "../config/db.php";

if(isset($_GET['id'])){
    $id = $_GET['id'];
    
    // Delete survey by ID
    $conn->query("DELETE FROM survey WHERE id='$id'");
}

// Redirect back to survey page
header("Location: survey.php");
exit();
?>