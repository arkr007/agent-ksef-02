<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private Config $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $host = (string) $this->config->get('database.host', '');
        $port = (int) $this->config->get('database.port', 3306);
        $name = (string) $this->config->get('database.name', '');
        $username = (string) $this->config->get('database.username', '');
        $password = (string) $this->config->get('database.password', '');
        $charset = (string) $this->config->get('database.charset', 'utf8mb4');

        if ($host === '' || $name === '' || $username === '') {
            throw new PDOException('Brakuje konfiguracji połączenia z bazą danych w config/config.php.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        $this->connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $this->connection;
    }
}
