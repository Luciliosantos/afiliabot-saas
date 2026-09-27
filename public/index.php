<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/health') {
    json_response([
        'ok' => true,
        'service' => app_config()['name'],
        'time' => now(),
    ]);
}

if ($path === '/' || $path === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h(app_config()['name']) ?></title>
        <style>
            body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;color:#172033}
            .box{max-width:900px;margin:60px auto;background:white;padding:40px;border-radius:18px;box-shadow:0 10px 30px #0001}
            code{background:#eef1f6;padding:3px 6px;border-radius:5px}
        </style>
    </head>
    <body><div class="box">
        <h1>🤖 <?= h(app_config()['name']) ?></h1>
        <p>Serviço online.</p>
        <p><a href="/admin/">Painel administrativo</a></p>
    </div></body>
    </html>
    <?php
    exit;
}

if (str_starts_with($path, '/admin')) {
    require __DIR__ . '/admin.php';
    exit;
}

http_response_code(404);
echo 'Not Found';
