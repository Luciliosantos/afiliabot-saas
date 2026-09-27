<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_cli();

$config = app_config();
$url = rtrim($config['url'], '/') . '/webhook/telegram.php';

if ($config['url'] === '' || $config['telegram_token'] === '' || $config['telegram_webhook_secret'] === '') {
    exit("Configure APP_URL, TELEGRAM_BOT_TOKEN e TELEGRAM_WEBHOOK_SECRET no .env\n");
}

$tg = new TelegramClient($config['telegram_token'], $config['http_timeout']);
$result = $tg->setWebhook($url, $config['telegram_webhook_secret']);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
