<?php
class Database {
    private static ?PDO $connection = null;

    public static function connect(): PDO {
        if (self::$connection === null) {
            $host = getenv('DB_HOST');
            $name = getenv('DB_NAME');
            $user = getenv('DB_USER');
            $pass = getenv('DB_PASS');

            $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
            self::$connection = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements, not string-substituted
            ]);

            // Force UTC for this session, explicitly, rather than trust
            // whatever timezone the server happens to default to — every
            // created_at timestamp needs to mean the same thing regardless
            // of how the host is configured, since the frontend converts
            // it to each visitor's own local time for display.
            self::$connection->exec("SET time_zone = '+00:00'");
        }
        return self::$connection;
    }
}
