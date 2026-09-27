<?php
declare(strict_types=1);

final class Queue
{
    public function __construct(
        private readonly Database $db,
        private readonly TelegramClient $telegram,
        private readonly AffiliateEngine $affiliate
    ) {}

    public function process(int $limit = 50): int
    {
        $count = 0;

        $this->db->execute(
            "UPDATE deliveries
             SET status='pending', updated_at=?
             WHERE status='sending' AND updated_at < datetime(?, '-10 minutes')",
            [now(), now()]
        );

        for ($i = 0; $i < $limit; $i++) {
            $delivery = $this->claimOne();
            if (!$delivery) {
                break;
            }

            try {
                $offer = $this->db->fetch(
                    "SELECT * FROM source_offers WHERE id = ?",
                    [(int)$delivery['offer_id']]
                );

                if (!$offer) {
                    throw new RuntimeException('Oferta não encontrada.');
                }

                $text = $this->affiliate->transformForUser(
                    (int)$delivery['user_id'],
                    (string)$offer['text']
                );

                $result = match ($offer['media_type']) {
                    'photo' => $this->telegram->sendPhoto((string)$delivery['chat_id'], (string)$offer['media_file_id'], $text),
                    'video' => $this->telegram->sendVideo((string)$delivery['chat_id'], (string)$offer['media_file_id'], $text),
                    'document' => $this->telegram->sendDocument((string)$delivery['chat_id'], (string)$offer['media_file_id'], $text),
                    default => $this->telegram->sendMessage((string)$delivery['chat_id'], $text, null, false),
                };

                $messageId = (int)($result['message_id'] ?? 0);

                $this->db->execute(
                    "UPDATE deliveries
                     SET status='sent', telegram_message_id=?, sent_at=?, updated_at=?
                     WHERE id=?",
                    [$messageId, now(), now(), (int)$delivery['id']]
                );

                $count++;
            } catch (Throwable $e) {
                $attempts = (int)$delivery['attempts'] + 1;
                $max = app_config()['queue_max_attempts'];

                if ($attempts >= $max) {
                    $status = 'failed';
                    $next = now();
                } else {
                    $status = 'pending';
                    $delay = min(3600, 10 * (2 ** min($attempts, 8)));
                    $next = date('Y-m-d H:i:s', time() + $delay);
                }

                $this->db->execute(
                    "UPDATE deliveries
                     SET status=?, attempts=?, last_error=?, next_attempt_at=?, updated_at=?
                     WHERE id=?",
                    [$status, $attempts, mb_substr($e->getMessage(), 0, 1000), $next, now(), (int)$delivery['id']]
                );

                log_app('error', 'delivery_failed', [
                    'delivery_id' => (int)$delivery['id'],
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function claimOne(): ?array
    {
        return $this->db->transaction(function(Database $db) {
            $row = $db->fetch(
                "SELECT d.*, de.chat_id
                 FROM deliveries d
                 JOIN destinations de ON de.id = d.destination_id
                 WHERE d.status='pending'
                   AND d.next_attempt_at <= ?
                   AND d.attempts < ?
                 ORDER BY d.id
                 LIMIT 1",
                [now(), app_config()['queue_max_attempts']]
            );

            if (!$row) {
                return null;
            }

            $changed = $db->execute(
                "UPDATE deliveries
                 SET status='sending', attempts=attempts+1, updated_at=?
                 WHERE id=? AND status='pending'",
                [now(), (int)$row['id']]
            );

            return $changed === 1 ? $row : null;
        });
    }
}
