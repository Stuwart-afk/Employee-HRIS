<?php

$host = "127.0.0.1";
$db = "hris";
$user = "root";
$pass = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass
    );

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );
    echo "Connection Success";
} catch (PDOException $e) {
    echo $e;
    die("DB Connection failed");
}