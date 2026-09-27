<?php
declare(strict_types=1);

final class OfferProcessor
{
    public function __construct(
        private readonly Database $db,
        private readonly AffiliateEngine $affiliate
    ) {}

    public function process(array $update): ?int
    {
        $message = $update['message'] ?? $update['channel_post'] ?? null;
        if (!$message) {
            return null;
        }

        $chat = $message['chat'] ?? [];
        $sourceChatId = (string)($chat['id'] ?? '');
        $messageId = (int)($message['message_id'] ?? 0);

        if ($sourceChatId === '' || $messageId <= 0) {
            return null;
        }

        $config = app_config();
        if ($config['telegram_source_chat_id'] !== '' &&
            $sourceChatId !== (string)$config['telegram_source_chat_id']) {
            return null;
        }

        $text = (string)($message['text'] ?? $message['caption'] ?? '');
        $mediaType = '';
        $mediaFileId = '';

        if (!empty($message['photo'])) {
            $mediaType = 'photo';
            $last = end($message['photo']);
            $mediaFileId = (string)($last['file_id'] ?? '');
        } elseif (!empty($message['video'])) {
            $mediaType = 'video';
            $mediaFileId = (string)($message['video']['file_id'] ?? '');
        } elseif (!empty($message['document'])) {
            $mediaType = 'document';
            $mediaFileId = (string)($message['document']['file_id'] ?? '');
        }

        $fingerprint = hash('sha256', $sourceChatId . ':' . $messageId . ':' . $text . ':' . $mediaFileId);

        try {
            $this->db->execute(
                "INSERT INTO source_offers
                 (source_chat_id,source_message_id,fingerprint,text,media_type,media_file_id,raw_update,created_at)
                 VALUES(?,?,?,?,?,?,?,?)",
                [
                    $sourceChatId,
                    $messageId,
                    $fingerprint,
                    $text,
                    $mediaType,
                    $mediaFileId,
                    json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    now()
                ]
            );
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return null;
            }
            throw $e;
        }

        $offerId = (int)$this->db->pdo()->lastInsertId();

        $users = $this->db->fetchAll(
            "SELECT u.id
             FROM users u
             JOIN subscriptions s ON s.user_id = u.id
             JOIN plans p ON p.id = s.plan_id
             WHERE u.status = 'active'
               AND s.status = 'active'
               AND s.expires_at > ?
             GROUP BY u.id",
            [now()]
        );

        foreach ($users as $user) {
            $destinations = $this->db->fetchAll(
                "SELECT * FROM destinations WHERE user_id = ? AND active = 1",
                [(int)$user['id']]
            );

            $personalized = $this->affiliate->transformForUser((int)$user['id'], $text);

            foreach ($destinations as $destination) {
                try {
                    $this->db->execute(
                        "INSERT INTO deliveries
                         (offer_id,user_id,destination_id,status,attempts,next_attempt_at,created_at,updated_at)
                         VALUES(?,?,?,'pending',0,?,?,?)",
                        [$offerId, (int)$user['id'], (int)$destination['id'], now(), now(), now()]
                    );
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'UNIQUE')) {
                        throw $e;
                    }
                }

                $deliveryId = (int)$this->db->pdo()->lastInsertId();

                if ($personalized !== $text) {
                    $this->db->execute(
                        "INSERT INTO audit_logs(user_id,action,context,created_at)
                         VALUES(?,?,?,?)",
                        [
                            (int)$user['id'],
                            'offer_personalized',
                            json_encode(['offer_id'=>$offerId,'delivery_id'=>$deliveryId], JSON_UNESCAPED_UNICODE),
                            now()
                        ]
                    );
                }
            }
        }

        return $offerId;
    }
}
