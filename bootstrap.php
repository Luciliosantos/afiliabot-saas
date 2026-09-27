<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

function load_dotenv(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && $value[strlen($value)-1] === '"') ||
             ($value[0] === "'" && $value[strlen($value)-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

load_dotenv(__DIR__ . '/.env');

require_once __DIR__ . '/src/Support/helpers.php';
require_once __DIR__ . '/src/Database/Database.php';
require_once __DIR__ . '/src/Database/Migrator.php';
require_once __DIR__ . '/src/Telegram/TelegramClient.php';
require_once __DIR__ . '/src/Offers/UrlTools.php';
require_once __DIR__ . '/src/Offers/AffiliateEngine.php';
require_once __DIR__ . '/src/Offers/OfferProcessor.php';
require_once __DIR__ . '/src/Queue/Queue.php';
require_once __DIR__ . '/src/Payments/MercadoPago.php';
require_once __DIR__ . '/src/Bot/Bot.php';

$config = app_config();
$db = new Database($config['db_path']);
