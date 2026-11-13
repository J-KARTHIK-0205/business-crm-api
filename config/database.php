
<?php

$configFile = __DIR__ . "/database.local.php";

if (!file_exists($configFile)) {
    throw new RuntimeException(
        "Database configuration file not found."
    );
}

$dbConfig = require $configFile;

$host = $dbConfig['host'];
$port = $dbConfig['port'];
$username = $dbConfig['username'];
$password = $dbConfig['password'];
$database = $dbConfig['database'];

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database,
    $port
);

if ($conn->connect_error) {
    throw new RuntimeException(
        "Database connection failed."
    );
}

$conn->set_charset("utf8mb4");

?>
