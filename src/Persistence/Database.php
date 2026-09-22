<?php

declare(strict_types=1);

namespace Atelier\Persistence;

use Atelier\Error\StorageUnavailableException;
use Atelier\Kernel\Config;
use Atelier\Support\Files;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Accès aux données via PDO : connexion paresseuse, requêtes préparées, transactions imbriquées
 * (par compteur) et helpers CRUD. Les écarts entre SQLite et MariaDB sont isolés ici et dans
 * les quelques méthodes de dialecte (primaryKey(), etc.).
 *
 * Les contrôleurs et vues n'utilisent jamais cette classe directement : seuls les dépôts
 * (repositories) et services le font.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;
    private int $queryCount = 0;

    /** @param array<string, mixed> $options */
    public function __construct(
        private readonly string $driver,
        private readonly string $dsn,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
        private readonly array $options = [],
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $driver = $config->string('database.driver', 'sqlite');
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $c = $config->array('database.mysql');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'] ?? '127.0.0.1',
                (int) ($c['port'] ?? 3306),
                $c['name'] ?? 'atelier',
                $c['charset'] ?? 'utf8mb4'
            );
            return new self('mysql', $dsn, (string) ($c['user'] ?? ''), (string) ($c['password'] ?? ''));
        }

        $path = $config->string('database.sqlite.path');
        return new self('sqlite', 'sqlite:' . $path, null, null, ['wal' => $config->bool('database.sqlite.wal', true), 'path' => $path]);
    }

    public static function sqliteMemory(): self
    {
        return new self('sqlite', 'sqlite::memory:');
    }

    public static function sqliteFile(string $path, bool $wal = true): self
    {
        return new self('sqlite', 'sqlite:' . $path, null, null, ['wal' => $wal, 'path' => $path]);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    public function sqlitePath(): ?string
    {
        return $this->options['path'] ?? null;
    }

    /**
     * Connexion paresseuse. Lève StorageUnavailableException si la base est inaccessible.
     */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        try {
            if ($this->isSqlite() && isset($this->options['path'])) {
                Files::ensureDirectory(dirname((string) $this->options['path']));
            }
            $pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            if ($this->isSqlite()) {
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                if (($this->options['wal'] ?? false) && ($this->options['path'] ?? ':memory:') !== ':memory:') {
                    $pdo->exec('PRAGMA journal_mode = WAL');
                    $pdo->exec('PRAGMA synchronous = NORMAL');
                }
            } else {
                $pdo->exec("SET NAMES utf8mb4");
                $pdo->exec("SET time_zone = '+00:00'");
                $pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            }
            $this->pdo = $pdo;
            return $pdo;
        } catch (Throwable $e) {
            throw new StorageUnavailableException('', [], $e);
        }
    }

    public function isAvailable(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $this->queryCount++;
        try {
            $statement = $this->pdo()->prepare($sql);
            foreach ($params as $key => $value) {
                $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
                $type = match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_INT,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                };
                $statement->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
            }
            $statement->execute();
            return $statement;
        } catch (PDOException $e) {
            throw new QueryException($sql, $params, $e);
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();
        return $rows;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<int|string, mixed> $params */
    public function count(string $sql, array $params = []): int
    {
        return (int) $this->scalar($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return int nombre de lignes affectées
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        $this->run($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[] = $this->quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), implode(', ', $sets), $where);
        return $this->execute($sql, $params + $whereParams);
    }

    /** @param array<string, mixed> $whereParams */
    public function delete(string $table, string $where, array $whereParams = []): int
    {
        return $this->execute(sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $where), $whereParams);
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Transaction avec imbrication par compteur : seule la transaction externe valide ou annule.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function begin(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth = 0;
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function quoteIdentifier(string $identifier): string
    {
        $identifier = str_replace(['`', '"'], '', $identifier);
        return $this->isSqlite() ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    public function tableExists(string $table): bool
    {
        if ($this->isSqlite()) {
            return $this->scalar("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t", ['t' => $table]) !== null;
        }
        return $this->scalar('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t', ['t' => $table]) !== null;
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->isSqlite()) {
            $rows = $this->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        } else {
            $rows = $this->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name');
        }
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        if ($this->isSqlite()) {
            $rows = $this->select('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
            return array_map(static fn (array $r): string => (string) $r['name'], $rows);
        }
        $rows = $this->select('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t ORDER BY ordinal_position', ['t' => $table]);
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->transactionDepth = 0;
    }

    // ----- Dialecte : fragments SQL différant entre SQLite et MariaDB -----

    /** Colonne identifiant auto-incrémentée. */
    public function primaryKey(): string
    {
        return $this->isSqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    /** Type texte long. */
    public function text(): string
    {
        return $this->isSqlite() ? 'TEXT' : 'LONGTEXT';
    }

    /** Type chaîne courte indexable. */
    public function varchar(int $length = 255): string
    {
        return $this->isSqlite() ? 'TEXT' : 'VARCHAR(' . $length . ')';
    }

    public function boolean(): string
    {
        return $this->isSqlite() ? 'INTEGER' : 'TINYINT(1)';
    }

    public function datetime(): string
    {
        return $this->isSqlite() ? 'TEXT' : 'DATETIME';
    }

    public function bigint(): string
    {
        return $this->isSqlite() ? 'INTEGER' : 'BIGINT';
    }

    public function integer(): string
    {
        return $this->isSqlite() ? 'INTEGER' : 'INT';
    }

    /** Nombre à virgule flottante double précision (coordonnées, mesures). */
    public function double(): string
    {
        return $this->isSqlite() ? 'REAL' : 'DOUBLE';
    }

    /** Suffixe de table (moteur et jeu de caractères) pour MariaDB, vide pour SQLite. */
    public function tableOptions(): string
    {
        return $this->isSqlite() ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /** Comparaison insensible à la casse. */
    public function lower(string $expression): string
    {
        return 'LOWER(' . $expression . ')';
    }

    /** Concaténation portable. */
    public function concat(string ...$parts): string
    {
        return $this->isSqlite() ? implode(' || ', $parts) : 'CONCAT(' . implode(', ', $parts) . ')';
    }
}
