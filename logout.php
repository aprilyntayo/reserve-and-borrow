<?php
session_start();

// Destroy the session and clear all session variables
session_unset();
session_destroy();

// Redirect to the login page or home page
header("Location: login.php");
exit();
?>