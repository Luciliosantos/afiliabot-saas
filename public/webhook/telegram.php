<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

require_post();

$config = app_config();

$provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$expected = (string)$config['telegram_webhook_secret'];

if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit('Forbidden');
}

$update = request_json();

try {
    $tg = new TelegramClient((string)$config['telegram_token'], (int)$config['http_timeout']);
    $affiliate = new AffiliateEngine($db);
    $processor = new OfferProcessor($db, $affiliate);
    $mp = new MercadoPago($db);
    $bot = new Bot($db, $tg, $mp, $affiliate);

    if (isset($update['message']) || isset($update['callback_query'])) {
        $bot->handleUpdate($update);
    }

    // O mesmo webhook atende ofertas vindas de grupo/supergrupo e canal.
    // O OfferProcessor valida TELEGRAM_SOURCE_CHAT_ID antes de criar a oferta.
    if (isset($update['message']) || isset($update['channel_post'])) {
        $processor->process($update);
    }

    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    log_app('error', 'telegram_webhook_error', ['error'=>$e->getMessage()]);
    http_response_code(500);
    echo 'ERROR';
}
