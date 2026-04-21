<?php
// config.php
// Default XAMPP MySQL configuration
$host = 'localhost';
$db   = 'college_tally';
$user = 'root'; 
$pass = '';     


try {
    // Create a new PDO instance for secure database interaction
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    // Set PDO error mode to exception to catch errors easily
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Display error if connection fails
    die("<div style='font-family: sans-serif; padding: 20px; background: #ffebee; color: #c62828;'>
            <strong>Database connection failed!</strong><br><br> 
            Make sure XAMPP MySQL is running and you have imported the <b>database.sql</b> file.<br> 
            Error Details: " . $e->getMessage() . 
        "</div>");
}
?>