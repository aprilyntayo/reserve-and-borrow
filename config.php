<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'fbcreserve';

// --- API Configurations ---
define('GITHUB_TOKEN', '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');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// NEW: Add this line to set the connection flag
$db_connected = false; 

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// NEW: Set to true if connection reaches here
$db_connected = true; 

$conn->set_charset("utf8mb4");
?>