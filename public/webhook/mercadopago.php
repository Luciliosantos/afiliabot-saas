<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

require_post();

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$dataId = (string)($_GET['data.id'] ?? $payload['data']['id'] ?? '');
$xSignature = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');
$mp = new MercadoPago($db);

if (!empty($config['mp_webhook_secret'])) {
    if (!$mp->verifyWebhook($raw, $xSignature, $dataId)) {
        http_response_code(403);
        exit('Invalid signature');
    }
}

$eventKey = 'mp:' . (($payload['id'] ?? '') ?: ($payload['type'] ?? 'unknown') . ':' . $dataId);

try {
    $db->execute(
        "INSERT INTO webhook_events(provider,event_key,payload,processed,created_at)
         VALUES(?,?,?,0,?)",
        ['mercadopago', $eventKey, $raw, now()]
    );
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        http_response_code(200);
        exit('Already received');
    }
    throw $e;
}

try {
    $type = (string)($payload['type'] ?? '');
    if ($type === 'payment' && $dataId !== '') {
        $payment = $mp->syncPayment($dataId);
        if ($payment) {
            $mp->activateFromPayment($payment);
        }
    }

    $db->execute(
        "UPDATE webhook_events SET processed=1,processed_at=? WHERE event_key=?",
        [now(), $eventKey]
    );

    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    log_app('error', 'mercadopago_webhook_error', ['error'=>$e->getMessage(),'event'=>$payload]);
    http_response_code(500);
    echo 'ERROR';
}
