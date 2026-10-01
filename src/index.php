<?php

declare(strict_types=1);
session_start();

/* ---------- Config ---------- */
// Non-secret values come from the env file (env_file in docker-compose),
// the password is read from the Docker secret file pointed to by DB_PASS_FILE.
function cfg(string $key): string
{
    $file = getenv($key . '_FILE');
    if ($file !== false && $file !== '') {
        $value = @file_get_contents($file);
        if ($value === false) {
            throw new RuntimeException("Cannot read secret file for $key: $file");
        }
        return rtrim($value, "\r\n"); // strip only the trailing newline
    }
    $value = getenv($key);
    if ($value === false || $value === '') {
        throw new RuntimeException("Config value $key is not set");
    }
    return $value;
}


define('DB_HOST', cfg('DB_HOST'));
define('DB_NAME', cfg('DB_NAME'));
define('DB_USER', cfg('DB_USER'));
define('DB_PASS', cfg('DB_PASS'));
const LOG_TABLE = 'action_log';

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- Connection (a few retries while MySQL is starting) ---------- */
$pdo = null;
$connError = '';
for ($attempt = 1; $attempt <= 5 && $pdo === null; $attempt++) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        $connError = $e->getMessage();
        sleep(1);
    }
}

if ($pdo === null) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Connection error</title>';
    echo '<body style="font-family:sans-serif;padding:2rem">';
    echo '<h1>Database connection failed</h1><pre>' . h($connError) . '</pre></body>';
    exit;
}

/* ---------- Helpers ---------- */
$pdo->exec('CREATE TABLE IF NOT EXISTS `' . LOG_TABLE . '` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    action VARCHAR(50) NOT NULL,
    sql_text TEXT NOT NULL,
    status VARCHAR(10) NOT NULL,
    message TEXT NOT NULL
)');

function listTables(PDO $pdo): array
{
    $all = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter($all, fn($t) => $t !== LOG_TABLE));
}

function requireExistingTable(PDO $pdo, string $table): void
{
    if (!in_array($table, listTables($pdo), true)) {
        throw new RuntimeException("Table '$table' does not exist");
    }
}

function logAction(PDO $pdo, string $action, string $sql, bool $ok, string $message): void
{
    $st = $pdo->prepare('INSERT INTO `' . LOG_TABLE . '` (action, sql_text, status, message) VALUES (?, ?, ?, ?)');
    $st->execute([$action, $sql, $ok ? 'OK' : 'ERROR', $message]);
}

function renderTable(array $rows, string $empty): void
{
    if (!$rows) {
        echo '<p class="muted">' . h($empty) . '</p>';
        return;
    }
    echo '<div class="scroll"><table><thead><tr>';
    foreach (array_keys($rows[0]) as $col) {
        echo '<th>' . h($col) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . ($cell === null ? '<em class="muted">NULL</em>' : h($cell)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/* ---------- Handle actions (POST -> redirect -> GET) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $table  = trim((string) ($_POST['table'] ?? ''));
    $name   = trim((string) ($_POST['name'] ?? ''));
    $value  = trim((string) ($_POST['value'] ?? ''));
    $id     = (int) ($_POST['id'] ?? 0);

    $sql   = '(not executed)';
    $flash = ['ok' => true, 'msg' => '', 'rows' => []];

    try {
        switch ($action) {
            case 'create_table':
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table) || $table === LOG_TABLE) {
                    throw new RuntimeException('Invalid table name (letters, digits and underscores only; must not start with a digit)');
                }
                $sql = "CREATE TABLE `$table` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    value VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
                $pdo->exec($sql);
                $flash['msg'] = "Table '$table' created";
                break;

            case 'insert':
                requireExistingTable($pdo, $table);
                if ($name === '') {
                    throw new RuntimeException('Name is required');
                }
                $sql = "INSERT INTO `$table` (name, value) VALUES (?, ?)";
                $st = $pdo->prepare($sql);
                $st->execute([$name, $value]);
                $sql .= ' -- params: ' . json_encode([$name, $value], JSON_UNESCAPED_UNICODE);
                $flash['msg'] = 'Row inserted, id = ' . $pdo->lastInsertId();
                break;

            case 'update':
                requireExistingTable($pdo, $table);
                if ($id <= 0 || $name === '') {
                    throw new RuntimeException('ID and name are required');
                }
                $sql = "UPDATE `$table` SET name = ?, value = ? WHERE id = ?";
                $st = $pdo->prepare($sql);
                $st->execute([$name, $value, $id]);
                $sql .= ' -- params: ' . json_encode([$name, $value, $id], JSON_UNESCAPED_UNICODE);
                $flash['msg'] = $st->rowCount() . ' row(s) changed';
                break;

            case 'delete_row':
                requireExistingTable($pdo, $table);
                if ($id <= 0) {
                    throw new RuntimeException('ID is required');
                }
                $sql = "DELETE FROM `$table` WHERE id = ?";
                $st = $pdo->prepare($sql);
                $st->execute([$id]);
                $sql .= " -- params: [$id]";
                $flash['msg'] = $st->rowCount() . ' row(s) deleted';
                break;

            case 'truncate_table':
                requireExistingTable($pdo, $table);
                $sql = "TRUNCATE TABLE `$table`";
                $pdo->exec($sql);
                $flash['msg'] = "Table '$table' truncated";
                break;

            case 'drop_table':
                requireExistingTable($pdo, $table);
                $sql = "DROP TABLE `$table`";
                $pdo->exec($sql);
                $flash['msg'] = "Table '$table' dropped";
                break;

            case 'custom_sql':
                $sql = trim((string) ($_POST['sql'] ?? ''));
                if ($sql === '') {
                    throw new RuntimeException('SQL query is empty');
                }
                $st = $pdo->query($sql);
                if ($st->columnCount() > 0) {
                    $flash['rows'] = $st->fetchAll();
                    $flash['msg']  = count($flash['rows']) . ' row(s) returned';
                } else {
                    $flash['msg'] = $st->rowCount() . ' row(s) affected';
                }
                break;

            case 'clear_log':
                $pdo->exec('TRUNCATE TABLE `' . LOG_TABLE . '`');
                $flash['msg'] = 'Action log cleared';
                break;

            default:
                throw new RuntimeException('Unknown action');
        }
    } catch (Throwable $e) {
        $flash['ok']  = false;
        $flash['msg'] = $e->getMessage();
    }

    if ($action !== 'clear_log') {
        logAction($pdo, $action ?: 'unknown', $sql, $flash['ok'], $flash['msg']);
    }

    $_SESSION['flash'] = $flash;
    header('Location: index.php?table=' . rawurlencode($table));
    exit;
}

/* ---------- Prepare data for the page ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$tables   = listTables($pdo);
$selected = (string) ($_GET['table'] ?? '');
if (!in_array($selected, $tables, true)) {
    $selected = $tables[0] ?? '';
}

$tableRows = [];
if ($selected !== '') {
    $tableRows = $pdo->query("SELECT * FROM `$selected` LIMIT 100")->fetchAll();
}

$logRows      = $pdo->query('SELECT * FROM `' . LOG_TABLE . '` ORDER BY id DESC LIMIT 20')->fetchAll();
$mysqlVersion = $pdo->query('SELECT VERSION()')->fetchColumn();

function tableOptions(array $tables, string $selected): void
{
    if (!$tables) {
        echo '<option value="">-- no tables --</option>';
        return;
    }
    foreach ($tables as $t) {
        echo '<option value="' . h($t) . '"' . ($t === $selected ? ' selected' : '') . '>' . h($t) . '</option>';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dev Environment Test</title>
    <style>
        :root {
            --bg: #f4f6f8;
            --card: #fff;
            --text: #1f2933;
            --muted: #7b8794;
            --line: #e4e7eb;
            --accent: #2563eb;
            --ok: #15803d;
            --err: #b91c1c;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            font: 15px/1.5 system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        h1 {
            margin: 0 0 4px;
            font-size: 24px;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 16px;
        }

        .muted {
            color: var(--muted);
        }

        .wrap {
            max-width: 1200px;
            margin: 0 auto;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .grid .card {
            margin-bottom: 0;
        }

        label {
            display: block;
            font-size: 13px;
            color: var(--muted);
            margin: 8px 0 2px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            font: inherit;
        }

        textarea {
            min-height: 90px;
            font-family: ui-monospace, monospace;
            font-size: 13px;
        }

        button {
            margin-top: 12px;
            padding: 8px 14px;
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            font: inherit;
            cursor: pointer;
        }

        button.danger {
            background: var(--err);
        }

        button.secondary {
            background: #52606d;
        }

        .row {
            display: flex;
            gap: 8px;
        }

        .scroll {
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
        }

        th,
        td {
            text-align: left;
            padding: 6px 10px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        th {
            background: #f0f3f6;
            white-space: nowrap;
        }

        td.sql {
            font-family: ui-monospace, monospace;
            white-space: pre-wrap;
            max-width: 480px;
        }

        .status-OK {
            color: var(--ok);
            font-weight: 600;
        }

        .status-ERROR {
            color: var(--err);
            font-weight: 600;
        }

        .alert {
            padding: 10px 14px;
            border-radius: 8px;
            margin: 16px 0;
            border: 1px solid;
        }

        .alert.ok {
            background: #f0fdf4;
            border-color: #86efac;
            color: var(--ok);
        }

        .alert.err {
            background: #fef2f2;
            border-color: #fca5a5;
            color: var(--err);
        }

        .head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .head form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .head button,
        .head select {
            margin: 0;
            width: auto;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <h1>Dev Environment Test</h1>
        <p class="muted">
            PHP <?= h(PHP_VERSION) ?> &middot; MySQL <?= h($mysqlVersion) ?> &middot;
            host: <?= h(DB_HOST) ?> &middot; database: <?= h(DB_NAME) ?> &middot;
            <strong style="color:var(--ok)">Connected</strong>
        </p>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['ok'] ? 'ok' : 'err' ?>">
                <?= $flash['ok'] ? 'Success: ' : 'Error: ' ?><?= h($flash['msg']) ?>
            </div>
            <?php if (!empty($flash['rows'])): ?>
                <div class="card">
                    <h2>Query result</h2>
                    <?php renderTable($flash['rows'], 'No rows'); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="grid">
            <form class="card" method="post">
                <h2>Create table</h2>
                <input type="hidden" name="action" value="create_table">
                <label>Table name</label>
                <input name="table" placeholder="e.g. users" required>
                <p class="muted" style="font-size:12px;margin:8px 0 0">Columns: id, name, value, created_at</p>
                <button>Create table</button>
            </form>

            <form class="card" method="post">
                <h2>Insert row</h2>
                <input type="hidden" name="action" value="insert">
                <label>Table</label>
                <select name="table" required><?php tableOptions($tables, $selected); ?></select>
                <label>Name</label>
                <input name="name" required>
                <label>Value</label>
                <input name="value">
                <button>Insert</button>
            </form>

            <form class="card" method="post">
                <h2>Update row</h2>
                <input type="hidden" name="action" value="update">
                <label>Table</label>
                <select name="table" required><?php tableOptions($tables, $selected); ?></select>
                <label>Row ID</label>
                <input name="id" type="number" min="1" required>
                <label>New name</label>
                <input name="name" required>
                <label>New value</label>
                <input name="value">
                <button>Update</button>
            </form>

            <form class="card" method="post">
                <h2>Delete row</h2>
                <input type="hidden" name="action" value="delete_row">
                <label>Table</label>
                <select name="table" required><?php tableOptions($tables, $selected); ?></select>
                <label>Row ID</label>
                <input name="id" type="number" min="1" required>
                <button class="danger">Delete row</button>
            </form>

            <form class="card" method="post">
                <h2>Truncate / drop table</h2>
                <label>Table</label>
                <select name="table" required><?php tableOptions($tables, $selected); ?></select>
                <div class="row">
                    <button class="secondary" name="action" value="truncate_table"
                        onclick="return confirm('Remove all rows from this table?')">Truncate</button>
                    <button class="danger" name="action" value="drop_table"
                        onclick="return confirm('Drop this table?')">Drop table</button>
                </div>
            </form>

            <form class="card" method="post">
                <h2>Custom SQL</h2>
                <input type="hidden" name="action" value="custom_sql">
                <label>Query (single statement)</label>
                <textarea name="sql" placeholder="SELECT NOW();" required></textarea>
                <button>Run</button>
            </form>
        </div>

        <div class="card">
            <div class="head">
                <h2>Table data<?= $selected !== '' ? ': ' . h($selected) : '' ?> <span class="muted">(up to 100 rows)</span></h2>
                <form method="get">
                    <select name="table" onchange="this.form.submit()"><?php tableOptions($tables, $selected); ?></select>
                    <button class="secondary">Show</button>
                </form>
            </div>
            <?php renderTable($tableRows, $selected === '' ? 'Create a table to get started' : 'Table is empty'); ?>
        </div>

        <div class="card">
            <div class="head">
                <h2>Action log <span class="muted">(last 20)</span></h2>
                <form method="post">
                    <input type="hidden" name="action" value="clear_log">
                    <button class="secondary">Clear log</button>
                </form>
            </div>
            <?php if (!$logRows): ?>
                <p class="muted">No actions yet</p>
            <?php else: ?>
                <div class="scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Time</th>
                                <th>Action</th>
                                <th>SQL</th>
                                <th>Status</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logRows as $r): ?>
                                <tr>
                                    <td><?= h($r['id']) ?></td>
                                    <td><?= h($r['created_at']) ?></td>
                                    <td><?= h($r['action']) ?></td>
                                    <td class="sql"><?= h($r['sql_text']) ?></td>
                                    <td class="status-<?= h($r['status']) ?>"><?= h($r['status']) ?></td>
                                    <td><?= h($r['message']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>