<?php
// backend/database.php

require_once __DIR__ . '/config/Config.php';

$pdo = null;

function getDB(): PDO {
    global $pdo;

    if ($pdo === null) {
        $host    = Config::get('DB_HOST', 'localhost');
        $name    = Config::get('DB_NAME');
        $user    = Config::get('DB_USER', 'root');
        $pass    = Config::get('DB_PASS', '');

        try {
            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB Connection Error: ' . $e->getMessage());
            die('Database connection failed. Please check if MySQL is running and database exists.');
        }
    }

    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement {
    // echo "Executing SQL: $sql with params: " . json_encode($params) . "\n";
    // die(); // Remove this line after debugging
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        json_encode(["sql" => $sql, "params" => $params, "error" => $e->getMessage()]);
        error_log('Query Error: ' . $e->getMessage() . ' | SQL: ' . $sql);
        throw $e;
    }
}

function fetchOne(string $sql, array $params = []): array|false {
    return query($sql, $params)->fetch();
}

function fetchAll(string $sql, array $params = []): array {
    return query($sql, $params)->fetchAll();
}

function insert(string $sql, array $params = []): string {
    query($sql, $params);
    return getDB()->lastInsertId();
}

function execute(string $sql, array $params = []): int {
    return query($sql, $params)->rowCount();
}

function beginTransaction(): void {
    getDB()->beginTransaction();
}

function commit(): void {
    getDB()->commit();
}

function rollback(): void {
    getDB()->rollBack();
}