<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_cli();
Migrator::up($db);

$out = [
    'php' => PHP_VERSION,
    'time' => now(),
    'db' => 'ok',
    'telegram_token' => app_config()['telegram_token'] !== '' ? 'configured' : 'missing',
    'mp_token' => app_config()['mp_access_token'] !== '' ? 'configured' : 'missing',
];

try {
    if (app_config()['telegram_token'] !== '') {
        $tg = new TelegramClient(app_config()['telegram_token'], app_config()['http_timeout']);
        $out['telegram'] = $tg->getMe();
    }
} catch (Throwable $e) {
    $out['telegram_error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
