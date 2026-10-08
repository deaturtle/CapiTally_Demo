<?php
declare(strict_types=1);

/**
 * Database backup (command line only):  php backend/bin/backup.php
 * Schedule it with Windows Task Scheduler or cron. Writes a timestamped .sql dump to
 * storage/backups (blocked from HTTP) and keeps the newest N copies.
 * The password is passed through a temporary 0600 options file, never on the command line.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use CapiTally\Support\Config;
use CapiTally\Support\Logger;

$dir  = (string) Config::get('backup.directory');
$keep = max(1, (int) Config::get('backup.keep', 14));
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create backup directory.\n");
    exit(1);
}

$name   = (string) Config::get('db.name');
$target = $dir . '/capitally_' . date('Ymd_His') . '.sql';
$cnf    = tempnam(sys_get_temp_dir(), 'capi');
chmod($cnf, 0600);
file_put_contents($cnf, sprintf(
    "[client]\nuser=%s\npassword=\"%s\"\nhost=%s\nport=%d\n",
    Config::get('db.user'),
    addcslashes((string) Config::get('db.password'), "\"\\"),
    Config::get('db.host'),
    (int) Config::get('db.port')
));

$process = proc_open(
    [(string) Config::get('backup.mysqldump_path'), '--defaults-extra-file=' . $cnf, '--single-transaction',
     '--triggers', '--default-character-set=utf8mb4', '--result-file=' . $target, $name],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$stderr = '';
$code = 1;
if (is_resource($process)) {
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $code = proc_close($process);
}
@unlink($cnf);

$ok = $code === 0 && is_file($target) && filesize($target) > 0
    && str_contains((string) file_get_contents($target, false, null, max(0, filesize($target) - 200)), 'Dump completed');

if (!$ok) {
    @unlink($target);
    Logger::error('Backup failed', ['exit' => $code, 'detail' => trim($stderr)]);
    fwrite(STDERR, "Backup failed. See storage/logs/app.log\n");
    exit(1);
}

$files = glob($dir . '/capitally_*.sql') ?: [];
rsort($files);
foreach (array_slice($files, $keep) as $old) {
    @unlink($old);
}
echo "Backup written: {$target}\n";
