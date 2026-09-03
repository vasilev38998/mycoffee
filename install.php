<?php
declare(strict_types=1);
session_start();

$configPath = __DIR__ . '/config.php';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($dbName === '' || $dbUser === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $error = 'Заполните все поля. Пароль должен быть не короче 8 символов.';
    } else {
        try {
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schema === false) throw new RuntimeException('Не найден database/schema.sql');
            $pdo->exec($schema);

            foreach (glob(__DIR__ . '/database/migrations/*.sql') ?: [] as $migrationFile) {
                $migration = file_get_contents($migrationFile);
                if ($migration !== false) $pdo->exec($migration);
            }

            $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count === 0) {
                $stmt = $pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'owner')");
                $stmt->execute([$name, mb_strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
            }

            $config = "<?php\nreturn " . var_export([
                'app' => ['name' => 'MyCoffee', 'timezone' => 'Europe/Moscow', 'currency' => '₽'],
                'db' => ['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass, 'charset' => 'utf8mb4'],
                'security' => ['encryption_key' => bin2hex(random_bytes(32))],
            ], true) . ";\n";

            if (file_put_contents($configPath, $config) === false) {
                throw new RuntimeException('Не удалось создать config.php. Проверьте права на запись.');
            }
            header('Location: login.php?installed=1');
            exit;
        } catch (Throwable $e) {
            $error = 'Ошибка установки: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка MyCoffee</title><link rel="stylesheet" href="assets/style.css"></head><body class="auth-body">
<div class="auth-card"><div class="brand">☕ MyCoffee</div><h1>Установка</h1><p class="muted">Укажите базу MySQL, созданную в панели Beget, и данные владельца.</p>
<?php if ($error): ?><div class="alert danger"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif; ?>
<form method="post" class="stack">
<label>Хост БД<input name="db_host" value="<?=htmlspecialchars($_POST['db_host'] ?? 'localhost',ENT_QUOTES,'UTF-8')?>" required></label>
<label>Имя БД<input name="db_name" required></label><label>Пользователь БД<input name="db_user" required></label><label>Пароль БД<input type="password" name="db_pass"></label>
<hr><label>Ваше имя<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Пароль владельца<input type="password" name="password" minlength="8" required></label>
<button class="btn primary" type="submit">Установить MyCoffee</button></form></div></body></html>
