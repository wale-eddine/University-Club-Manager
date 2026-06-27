<?php

// One-command owner reset for local/dev environments.
// - Truncates all application data tables.
// - Prompts for admin email/password and creates a fresh admin account.

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$databaseConfigPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Database.php';

if (!file_exists($databaseConfigPath)) {
    fwrite(STDERR, "Could not find Database config at: {$databaseConfigPath}\n");
    exit(1);
}

require_once $databaseConfigPath;

function prompt(string $message): string
{
    fwrite(STDOUT, $message);
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function printUsage(): void
{
    $usage = <<<TXT
Usage:
    php scripts/reset_owner_db.php

What it does:
    1) Resets all DB data tables
    2) Prompts for admin email/password
    3) Creates a new verified admin account

Notes:
  - This is destructive and intended for local/dev reset only.
  - It truncates data tables and resets auto-increment ids.
TXT;

    fwrite(STDOUT, $usage . "\n");
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    printUsage();
    exit(0);
}

$adminEmail = prompt('Admin email: ');
if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(1);
}

$adminPassword = prompt('Admin password (min 8 chars): ');
if (strlen($adminPassword) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

try {
    $db = new Database();
    $pdo = $db->connect();

    $tables = [
        'EVENT_PARTICIPANTS',
        'MEMBERSHIP_REQUEST_COOLDOWNS',
        'EVENT_REVIEWS',
        'CLUB_YEARLY_BUDGETS',
        'MEMBERSHIP_REQUESTS',
        'CLUB_MEMBERS',
        'USER_NOTIFICATIONS',
        'PASSWORD_RESET_TOKENS',
        'EMAIL_VERIFICATION_TOKENS',
        'EVENTS',
        'CLUBS',
        'USERS',
    ];

    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tables as $table) {
        $pdo->exec("TRUNCATE TABLE {$table}");
    }

    $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $insertAdmin = $pdo->prepare(
        'INSERT INTO USERS (nom, prenom, email, mot_de_passe, role, email_verified_at)
         VALUES (:nom, :prenom, :email, :mot_de_passe, :role, NOW())'
    );
    $insertAdmin->execute([
        ':nom' => 'Owner',
        ':prenom' => 'Admin',
        ':email' => $adminEmail,
        ':mot_de_passe' => $passwordHash,
        ':role' => 'admin',
    ]);

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->commit();

    fwrite(STDOUT, "Database reset complete.\n");
    fwrite(STDOUT, "Admin account created for: {$adminEmail}\n");

    fwrite(STDOUT, "Done.\n");
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $restoreError) {
            // Ignore restoration errors during rollback path.
        }
    }

    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . "\n");
    exit(1);
}
