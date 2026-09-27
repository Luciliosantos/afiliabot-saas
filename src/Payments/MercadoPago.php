<?php
declare(strict_types=1);

final class MercadoPago
{
    private string $token;
    private int $timeout;

    public function __construct(private readonly Database $db)
    {
        $config = app_config();
        $this->token = (string)$config['mp_access_token'];
        $this->timeout = (int)$config['http_timeout'];
    }

    public function createPix(int $userId, int $planId): array
    {
        if ($this->token === '') {
            throw new RuntimeException('MP_ACCESS_TOKEN não configurado.');
        }

        $plan = $this->db->fetch("SELECT * FROM plans WHERE id=? AND active=1", [$planId]);
        if (!$plan) {
            throw new RuntimeException('Plano inválido.');
        }

        $external = uuid_v4();

        $payload = [
            'transaction_amount' => (float)$plan['price'],
            'description' => 'Assinatura ' . $plan['name'],
            'payment_method_id' => 'pix',
            'external_reference' => $external,
            'payer' => [
                'email' => "cliente-{$userId}@example.com",
            ],
        ];

        $response = $this->request('POST', '/v1/payments', $payload, [
            'X-Idempotency-Key: ' . $external,
        ]);

        $pix = $response['point_of_interaction']['transaction_data'] ?? [];

        $this->db->execute(
            "INSERT INTO payments
             (user_id,plan_id,external_id,amount,status,pix_code,qr_code_base64,mp_payment_id,raw_response,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)",
            [
                $userId,
                $planId,
                $external,
                (float)$plan['price'],
                (string)($response['status'] ?? 'pending'),
                (string)($pix['qr_code'] ?? ''),
                (string)($pix['qr_code_base64'] ?? ''),
                (string)($response['id'] ?? ''),
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                now(),
                now()
            ]
        );

        return [
            'external_id' => $external,
            'payment_id' => (string)($response['id'] ?? ''),
            'status' => (string)($response['status'] ?? 'pending'),
            'pix_code' => (string)($pix['qr_code'] ?? ''),
            'qr_code_base64' => (string)($pix['qr_code_base64'] ?? ''),
        ];
    }

    public function syncPayment(string $paymentId): ?array
    {
        if ($paymentId === '' || $this->token === '') {
            return null;
        }

        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    public function activateFromPayment(array $payment): void
    {
        $mpId = (string)($payment['id'] ?? '');
        $status = (string)($payment['status'] ?? '');

        if ($mpId === '' || $status !== 'approved') {
            return;
        }

        $row = $this->db->fetch(
            "SELECT * FROM payments WHERE mp_payment_id=? LIMIT 1",
            [$mpId]
        );

        if (!$row) {
            $external = (string)($payment['external_reference'] ?? '');
            if ($external !== '') {
                $row = $this->db->fetch(
                    "SELECT * FROM payments WHERE external_id=? LIMIT 1",
                    [$external]
                );
            }
        }

        if (!$row) {
            log_app('warning', 'approved_payment_not_mapped', ['payment_id'=>$mpId]);
            return;
        }

        $this->db->transaction(function(Database $db) use ($row, $payment) {
            $db->execute(
                "UPDATE payments SET status='approved', raw_response=?, updated_at=? WHERE id=?",
                [
                    json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    now(),
                    (int)$row['id']
                ]
            );

            $plan = $db->fetch("SELECT * FROM plans WHERE id=?", [(int)$row['plan_id']]);
            if (!$plan) {
                throw new RuntimeException('Plano do pagamento não encontrado.');
            }

            $userId = (int)$row['user_id'];
            $current = $db->fetch(
                "SELECT * FROM subscriptions
                 WHERE user_id=? AND status='active'
                 ORDER BY expires_at DESC LIMIT 1",
                [$userId]
            );

            $start = $current && strtotime((string)$current['expires_at']) > time()
                ? (string)$current['expires_at']
                : now();

            $expires = date(
                'Y-m-d H:i:s',
                strtotime($start) + ((int)$plan['duration_days'] * 86400)
            );

            $db->execute(
                "UPDATE subscriptions SET status='expired', updated_at=?
                 WHERE user_id=? AND status='active'",
                [now(), $userId]
            );

            $db->execute(
                "INSERT INTO subscriptions
                 (user_id,plan_id,status,starts_at,expires_at,mp_payment_id,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?)",
                [$userId,(int)$plan['id'],'active',$start,$expires,(string)($payment['id'] ?? ''),now(),now()]
            );

            $db->execute(
                "INSERT INTO audit_logs(user_id,action,context,created_at)
                 VALUES(?,?,?,?)",
                [
                    $userId,
                    'subscription_activated',
                    json_encode(['payment_id'=>$payment['id'] ?? null,'expires_at'=>$expires], JSON_UNESCAPED_UNICODE),
                    now()
                ]
            );
        });
    }

    public function verifyWebhook(string $rawBody, string $xSignature, string $dataId): bool
    {
        $secret = (string)app_config()['mp_webhook_secret'];
        if ($secret === '' || $xSignature === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$k] = $v;
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if ($ts === '' || $v1 === '' || $dataId === '') {
            return false;
        }

        $manifest = "id:{$dataId};request-id:" . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '') . ";ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }

    private function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $url = 'https://api.mercadopago.com' . $path;

        $ch = curl_init($url);
        $httpHeaders = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $httpHeaders = array_merge($httpHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("Mercado Pago cURL: {$error}");
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new RuntimeException("Mercado Pago HTTP {$http}: resposta inválida.");
        }

        if ($http < 200 || $http >= 300) {
            throw new RuntimeException('Mercado Pago HTTP ' . $http . ': ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return $data;
    }
}
