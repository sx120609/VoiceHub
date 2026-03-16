<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$projectRoot = dirname(__DIR__);
$envFile = $projectRoot.'/.env';
$envExampleFile = $projectRoot.'/.env.example';
$sqlFile = $projectRoot.'/database/mysql/voicehub_schema.sql';
$lockFile = $projectRoot.'/storage/app/install.lock';

if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0775, true);
}

/**
 * @return array<string, string>
 */
function parseEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");
        $vars[$key] = $value;
    }

    return $vars;
}

/**
 * @param array<string, string> $updates
 */
function writeEnvFile(string $path, array $updates): void
{
    $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $updatedKeys = [];

    foreach ($lines as $index => $line) {
        if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key] = explode('=', $line, 2);
        $key = trim($key);
        if (!array_key_exists($key, $updates)) {
            continue;
        }
        $lines[$index] = $key.'='.$updates[$key];
        $updatedKeys[$key] = true;
    }

    foreach ($updates as $key => $value) {
        if (isset($updatedKeys[$key])) {
            continue;
        }
        $lines[] = $key.'='.$value;
    }

    file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
}

/**
 * @throws Exception
 */
function importSql(PDO $pdo, string $sqlPath): void
{
    $sql = file_get_contents($sqlPath);
    if ($sql === false) {
        throw new RuntimeException('无法读取 SQL 文件: '.$sqlPath);
    }

    $statement = '';
    $lines = preg_split("/\r\n|\n|\r/", $sql) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $statement .= $line."\n";
        if (str_ends_with($trimmed, ';')) {
            $pdo->exec($statement);
            $statement = '';
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, string> $defaults
 * @param array<string, string> $errors
 * @param string[] $logs
 */
function renderPage(array $defaults, array $errors = [], array $logs = [], bool $locked = false): void
{
    $title = $locked ? 'VoiceHub 已安装' : 'VoiceHub 安装向导';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.h($title).'</title>';
    echo '<style>
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f6f8fa;margin:0;padding:24px;color:#111}
      .wrap{max-width:840px;margin:0 auto;background:#fff;border-radius:12px;padding:24px;box-shadow:0 8px 24px rgba(0,0,0,.08)}
      h1{margin:0 0 16px;font-size:24px}
      .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
      .full{grid-column:1/-1}
      label{display:block;font-size:13px;color:#333;margin-bottom:6px}
      input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccd2d8;border-radius:8px}
      button{background:#2f855a;color:#fff;border:none;padding:12px 16px;border-radius:8px;cursor:pointer;font-weight:600}
      .err{background:#fff5f5;border:1px solid #fed7d7;color:#c53030;padding:10px;border-radius:8px;margin-bottom:10px}
      .ok{background:#f0fff4;border:1px solid #9ae6b4;color:#22543d;padding:10px;border-radius:8px;margin-bottom:10px}
      .log{background:#0b1020;color:#c8d2ff;padding:12px;border-radius:8px;white-space:pre-wrap;font-size:12px}
      .muted{font-size:12px;color:#666}
    </style></head><body><div class="wrap">';
    echo '<h1>'.h($title).'</h1>';

    if ($locked) {
        echo '<div class="ok">检测到安装锁，系统已初始化。若需重装，请删除 <code>laravel/storage/app/install.lock</code> 后刷新。</div>';
        echo '</div></body></html>';

        return;
    }

    foreach ($errors as $error) {
        echo '<div class="err">'.h($error).'</div>';
    }
    if ($logs !== []) {
        echo '<div class="ok">安装步骤执行完毕。</div>';
        echo '<div class="log">'.h(implode("\n", $logs)).'</div>';
    }

    echo '<form method="post"><div class="grid">';
    $fields = [
        'db_host' => 'MySQL Host',
        'db_port' => 'MySQL Port',
        'db_name' => 'Database Name',
        'db_user' => 'Database User',
        'db_pass' => 'Database Password',
        'app_url' => 'APP_URL',
        'app_base_path' => 'APP_BASE_PATH',
        'jwt_secret' => 'JWT_SECRET',
        'admin_username' => 'Admin Username',
        'admin_name' => 'Admin Display Name',
        'admin_password' => 'Admin Password',
        'admin_email' => 'Admin Email',
    ];
    foreach ($fields as $key => $label) {
        $type = str_contains($key, 'pass') || str_contains($key, 'password') || str_contains($key, 'secret') ? 'password' : 'text';
        $class = in_array($key, ['jwt_secret'], true) ? 'full' : '';
        echo '<div class="'.h($class).'"><label>'.h($label).'</label><input type="'.h($type).'" name="'.h($key).'" value="'.h($defaults[$key] ?? '').'" required></div>';
    }
    echo '<div class="full"><button type="submit">开始安装</button></div>';
    echo '</div><p class="muted">安装成功后会生成安装锁，并建议你删除或限制此安装页访问。</p></form>';
    echo '</div></body></html>';
}

$defaultsFromEnv = parseEnvFile(is_file($envFile) ? $envFile : $envExampleFile);
$defaults = [
    'db_host' => $defaultsFromEnv['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $defaultsFromEnv['DB_PORT'] ?? '3306',
    'db_name' => $defaultsFromEnv['DB_DATABASE'] ?? 'voicehub',
    'db_user' => $defaultsFromEnv['DB_USERNAME'] ?? 'voicehub',
    'db_pass' => $defaultsFromEnv['DB_PASSWORD'] ?? '',
    'app_url' => $defaultsFromEnv['APP_URL'] ?? 'http://localhost',
    'app_base_path' => $defaultsFromEnv['APP_BASE_PATH'] ?? '/rareapp',
    'jwt_secret' => $defaultsFromEnv['JWT_SECRET'] ?? bin2hex(random_bytes(24)),
    'admin_username' => 'admin',
    'admin_name' => '系统管理员',
    'admin_password' => '',
    'admin_email' => 'admin@example.com',
];

if (is_file($lockFile)) {
    renderPage($defaults, [], [], true);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    renderPage($defaults);
    exit;
}

$input = [];
foreach (array_keys($defaults) as $key) {
    $input[$key] = trim((string) ($_POST[$key] ?? ''));
}

$errors = [];
if ($input['admin_password'] === '' || strlen($input['admin_password']) < 6) {
    $errors[] = '管理员密码至少 6 位。';
}
if ($input['jwt_secret'] === '' || strlen($input['jwt_secret']) < 16) {
    $errors[] = 'JWT_SECRET 不能太短（至少 16 位）。';
}
if (!is_file($sqlFile)) {
    $errors[] = '未找到初始化 SQL 文件: '.$sqlFile;
}
if ($errors !== []) {
    renderPage(array_merge($defaults, $input), $errors);
    exit;
}

$logs = [];
try {
    $dsnNoDb = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $input['db_host'], (int) $input['db_port']);
    $pdo = new PDO($dsnNoDb, $input['db_user'], $input['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $logs[] = '数据库连接成功。';

    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $input['db_name']) ?: 'voicehub';
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci");
    $logs[] = "数据库 `{$dbName}` 已确认。";

    $dsnWithDb = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $input['db_host'], (int) $input['db_port'], $dbName);
    $pdoDb = new PDO($dsnWithDb, $input['db_user'], $input['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    importSql($pdoDb, $sqlFile);
    $logs[] = '数据表初始化完成。';

    $checkStmt = $pdoDb->prepare('SELECT id FROM `User` WHERE username = :username LIMIT 1');
    $checkStmt->execute(['username' => $input['admin_username']]);
    $admin = $checkStmt->fetch();

    $passwordHash = password_hash($input['admin_password'], PASSWORD_BCRYPT);
    if ($admin) {
        $updateStmt = $pdoDb->prepare('UPDATE `User` SET `name`=:name, `role`=\'SUPER_ADMIN\', `password`=:password, `email`=:email, `emailVerified`=1, `status`=\'active\', `forcePasswordChange`=0, `updatedAt`=NOW() WHERE `id`=:id');
        $updateStmt->execute([
            'id' => $admin['id'],
            'name' => $input['admin_name'],
            'password' => $passwordHash,
            'email' => $input['admin_email'],
        ]);
        $logs[] = '已更新管理员账号信息。';
    } else {
        $insertStmt = $pdoDb->prepare('INSERT INTO `User` (`createdAt`,`updatedAt`,`username`,`name`,`role`,`password`,`email`,`emailVerified`,`status`,`forcePasswordChange`) VALUES (NOW(),NOW(),:username,:name,\'SUPER_ADMIN\',:password,:email,1,\'active\',0)');
        $insertStmt->execute([
            'username' => $input['admin_username'],
            'name' => $input['admin_name'],
            'password' => $passwordHash,
            'email' => $input['admin_email'],
        ]);
        $logs[] = '管理员账号创建成功。';
    }

    if (!is_file($envFile) && is_file($envExampleFile)) {
        copy($envExampleFile, $envFile);
    }

    $dbUserEncoded = rawurlencode($input['db_user']);
    $dbPassEncoded = rawurlencode($input['db_pass']);
    $databaseUrl = sprintf(
        'mysql://%s:%s@%s:%d/%s?charset=utf8mb4',
        $dbUserEncoded,
        $dbPassEncoded,
        $input['db_host'],
        (int) $input['db_port'],
        $dbName
    );

    writeEnvFile($envFile, [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => $input['app_url'],
        'APP_BASE_PATH' => $input['app_base_path'],
        'JWT_SECRET' => $input['jwt_secret'],
        'DB_CONNECTION' => 'mysql',
        'DATABASE_URL' => $databaseUrl,
        'DB_URL' => '"${DATABASE_URL}"',
        'DB_HOST' => $input['db_host'],
        'DB_PORT' => (string) ((int) $input['db_port']),
        'DB_DATABASE' => $dbName,
        'DB_USERNAME' => $input['db_user'],
        'DB_PASSWORD' => $input['db_pass'],
        'DB_CHARSET' => 'utf8mb4',
        'DB_COLLATION' => 'utf8mb4_unicode_ci',
    ]);
    $logs[] = '.env 写入完成。';

    $clearOutput = @shell_exec('cd '.escapeshellarg($projectRoot).' && php artisan optimize:clear 2>&1');
    if (is_string($clearOutput) && trim($clearOutput) !== '') {
        $logs[] = 'artisan optimize:clear 已执行。';
    } else {
        $logs[] = '提示：未执行 artisan optimize:clear（可手动执行）。';
    }

    file_put_contents($lockFile, json_encode([
        'installedAt' => date(DATE_ATOM),
        'installedFrom' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'appUrl' => $input['app_url'],
        'dbName' => $dbName,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $logs[] = '安装锁已创建：storage/app/install.lock';

    renderPage(array_merge($defaults, $input), [], $logs);
} catch (Throwable $exception) {
    $errors[] = '安装失败：'.$exception->getMessage();
    renderPage(array_merge($defaults, $input), $errors, $logs);
}
