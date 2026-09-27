<?php
declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function env_required(string $key): string
{
    $value = env_value($key);
    if ($value === null) {
        throw new RuntimeException("Variável obrigatória ausente: {$key}");
    }
    return $value;
}

function app_config(): array
{
    static $config;

    if ($config !== null) {
        return $config;
    }

    $config = [
        'env' => env_value('APP_ENV', 'production'),
        'name' => env_value('APP_NAME', 'Afiliabot SaaS'),
        'url' => rtrim((string)env_value('APP_URL', ''), '/'),
        'timezone' => env_value('APP_TIMEZONE', 'America/Sao_Paulo'),
        'secret' => env_required('APP_SECRET'),
        'db_path' => env_value('DB_PATH', __DIR__ . '/../data/app.sqlite'),
        'telegram_token' => env_value('TELEGRAM_BOT_TOKEN', ''),
        'telegram_webhook_secret' => env_value('TELEGRAM_WEBHOOK_SECRET', ''),
        'telegram_source_chat_id' => env_value('TELEGRAM_SOURCE_CHAT_ID', ''),
        'telegram_admin_id' => (int)env_value('TELEGRAM_ADMIN_ID', '0'),
        'mp_access_token' => env_value('MP_ACCESS_TOKEN', ''),
        'mp_webhook_secret' => env_value('MP_WEBHOOK_SECRET', ''),
        'default_plan' => env_value('DEFAULT_PLAN', 'pro'),
        'default_plan_price' => (float)env_value('DEFAULT_PLAN_PRICE', '29.90'),
        'default_plan_days' => (int)env_value('DEFAULT_PLAN_DAYS', '30'),
        'queue_batch_size' => (int)env_value('QUEUE_BATCH_SIZE', '50'),
        'queue_max_attempts' => (int)env_value('QUEUE_MAX_ATTEMPTS', '5'),
        'worker_sleep_seconds' => (int)env_value('WORKER_SLEEP_SECONDS', '2'),
        'http_timeout' => (int)env_value('HTTP_TIMEOUT', '15'),
    ];

    date_default_timezone_set($config['timezone']);

    return $config;
}
