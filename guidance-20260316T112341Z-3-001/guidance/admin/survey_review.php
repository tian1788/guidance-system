<?php
session_start();
include "../config/db.php";

// Get admin name from session
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

if(isset($_GET['id'])){
    $id = $_GET['id'];

    // Get student name (optional)
    $result = $conn->query("SELECT student_name FROM survey WHERE id='$id'");
    if($row = $result->fetch_assoc()){
        $student_name = $row['student_name'];

        // Mark as Reviewed and record who reviewed it
        $conn->query("UPDATE survey 
                      SET status='Reviewed', reviewed_by='$admin_name' 
                      WHERE id='$id'");
    }
}

// Redirect back to survey list
header("Location: survey.php");
exit();
?>