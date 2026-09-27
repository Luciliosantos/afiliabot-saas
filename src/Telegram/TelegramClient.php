<?php
declare(strict_types=1);

final class TelegramClient
{
    public function __construct(
        private readonly string $token,
        private readonly int $timeout = 15
    ) {}

    public function call(string $method, array $params = []): array
    {
        if ($this->token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN não configurado.');
        }

        $url = "https://api.telegram.org/bot{$this->token}/{$method}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Expect:'],
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("Telegram cURL: {$error}");
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new RuntimeException("Telegram respondeu HTTP {$http} com JSON inválido.");
        }

        if (($data['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram API: ' . ($data['description'] ?? 'erro desconhecido'));
        }

        return $data['result'] ?? [];
    }

    public function sendMessage(string $chatId, string $text, ?array $keyboard = null, bool $html = true): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => false,
        ];
        if ($html) {
            $params['parse_mode'] = 'HTML';
        }

        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        return $this->call('sendMessage', $params);
    }

    public function sendPhoto(string $chatId, string $fileId, string $caption = ''): array
    {
        return $this->call('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $fileId,
            'caption' => $caption,
        ]);
    }

    public function sendVideo(string $chatId, string $fileId, string $caption = ''): array
    {
        return $this->call('sendVideo', [
            'chat_id' => $chatId,
            'video' => $fileId,
            'caption' => $caption,
        ]);
    }

    public function sendDocument(string $chatId, string $fileId, string $caption = ''): array
    {
        return $this->call('sendDocument', [
            'chat_id' => $chatId,
            'document' => $fileId,
            'caption' => $caption,
        ]);
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode([
                'message',
                'edited_message',
                'channel_post',
                'edited_channel_post',
                'callback_query'
            ]),
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getChat(string $chatId): array
    {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }

    public function getChatMember(string $chatId, int $userId): array
    {
        return $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function answerCallback(string $callbackId, string $text = ''): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
        ]);
    }
}
