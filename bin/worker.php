<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_cli();
Migrator::up($db);

$tg = new TelegramClient(app_config()['telegram_token'], app_config()['http_timeout']);
$affiliate = new AffiliateEngine($db);
$queue = new Queue($db, $tg, $affiliate);

echo "Worker iniciado em " . now() . PHP_EOL;

while (true) {
    try {
        $count = $queue->process(app_config()['queue_batch_size']);

        if ($count > 0) {
            echo now() . " — enviados: {$count}" . PHP_EOL;
        }
    } catch (Throwable $e) {
        log_app('error', 'worker_loop_error', ['error'=>$e->getMessage()]);
        echo now() . " — erro: " . $e->getMessage() . PHP_EOL;
    }

    sleep(max(1, app_config()['worker_sleep_seconds']));
}
