<?php
declare(strict_types=1);

final class Bot
{
    private array $config;

    public function __construct(
        private readonly Database $db,
        private readonly TelegramClient $tg,
        private readonly MercadoPago $mp,
        private readonly AffiliateEngine $affiliate
    ) {
        $this->config = app_config();
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }

        $chat = $message['chat'] ?? [];
        $chatType = (string)($chat['type'] ?? '');

        if ($chatType !== 'private') {
            return;
        }

        $telegramId = (int)($chat['id'] ?? 0);
        if ($telegramId <= 0) {
            return;
        }

        $this->upsertUser($message);

        $text = trim((string)($message['text'] ?? ''));

        if ($text === '/start') {
            $this->home($telegramId);
            return;
        }

        if ($text === '📊 Meu painel') {
            $this->panel($telegramId);
            return;
        }

        if ($text === '🔗 Afiliados') {
            $this->affiliateMenu($telegramId);
            return;
        }

        if ($text === '📢 Meus grupos') {
            $this->destinationsMenu($telegramId);
            return;
        }

        if ($text === '💳 Assinatura') {
            $this->subscription($telegramId);
            return;
        }

        if ($text === '🤖 Inteligência Artificial') {
            $this->aiDevelopment($telegramId);
            return;
        }

        if ($text === '❓ Ajuda') {
            $this->help($telegramId);
            return;
        }

        $state = $this->db->fetch(
            "SELECT * FROM bot_states WHERE telegram_id=?",
            [$telegramId]
        );

        if ($state) {
            $this->handleState($telegramId, (string)$state['state'], (string)$state['payload'], $text);
            return;
        }

        $this->home($telegramId);
    }

    private function upsertUser(array $message): void
    {
        $from = $message['from'] ?? [];
        $id = (int)($from['id'] ?? $message['chat']['id'] ?? 0);

        $existing = $this->db->fetch("SELECT id FROM users WHERE telegram_id=?", [$id]);

        if ($existing) {
            $this->db->execute(
                "UPDATE users SET username=?,first_name=?,last_name=?,updated_at=? WHERE telegram_id=?",
                [
                    (string)($from['username'] ?? ''),
                    (string)($from['first_name'] ?? ''),
                    (string)($from['last_name'] ?? ''),
                    now(),
                    $id
                ]
            );
            return;
        }

        $role = $id === $this->config['telegram_admin_id'] ? 'admin' : 'customer';

        $this->db->execute(
            "INSERT INTO users(telegram_id,username,first_name,last_name,role,status,created_at,updated_at)
             VALUES(?,?,?,?,?,?,?,?)",
            [
                $id,
                (string)($from['username'] ?? ''),
                (string)($from['first_name'] ?? ''),
                (string)($from['last_name'] ?? ''),
                $role,
                'active',
                now(),
                now()
            ]
        );
    }

    private function home(int $telegramId): void
    {
        $this->tg->sendMessage(
            (string)$telegramId,
            "🤖 <b>{$this->config['name']}</b>\n\nAutomatize suas ofertas e publique nos seus grupos usando suas próprias configurações de afiliado.",
            [
                'keyboard' => [
                    [['text'=>'📊 Meu painel'],['text'=>'🔗 Afiliados']],
                    [['text'=>'📢 Meus grupos'],['text'=>'💳 Assinatura']],
                    [['text'=>'🤖 Inteligência Artificial']],
                    [['text'=>'❓ Ajuda']],
                ],
                'resize_keyboard' => true,
            ]
        );
    }

    private function panel(int $telegramId): void
    {
        $user = $this->db->fetch("SELECT * FROM users WHERE telegram_id=?", [$telegramId]);

        $sub = $this->db->fetch(
            "SELECT s.*, p.name plan_name
             FROM subscriptions s JOIN plans p ON p.id=s.plan_id
             WHERE s.user_id=? AND s.status='active'
             ORDER BY s.expires_at DESC LIMIT 1",
            [(int)$user['id']]
        );

        $aff = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM affiliate_accounts WHERE user_id=? AND active=1",
            [(int)$user['id']]
        );

        $dest = (int)$this->db->scalar(
            "SELECT COUNT(*) FROM destinations WHERE user_id=? AND active=1",
            [(int)$user['id']]
        );

        $text = "📊 <b>Meu painel</b>\n\n";
        $text .= "Plano: " . ($sub ? h((string)$sub['plan_name']) : 'Sem assinatura') . "\n";
        $text .= "Vencimento: " . ($sub ? h((string)$sub['expires_at']) : '-') . "\n";
        $text .= "Afiliados configurados: {$aff}\n";
        $text .= "Grupos ativos: {$dest}\n";

        $this->tg->sendMessage((string)$telegramId, $text);
    }

    private function affiliateMenu(int $telegramId): void
    {
        $this->tg->sendMessage(
            (string)$telegramId,
            "🔗 <b>Afiliados</b>\n\nEscolha uma plataforma:",
            [
                'inline_keyboard' => [
                    [['text'=>'🟠 Mercado Livre','callback_data'=>'aff:mercadolivre']],
                    [['text'=>'🟧 Shopee','callback_data'=>'aff:shopee']],
                    [['text'=>'🔵 Magalu','callback_data'=>'aff:magalu']],
                ]
            ]
        );
    }

    private function destinationsMenu(int $telegramId): void
    {
        $user = $this->db->fetch("SELECT id FROM users WHERE telegram_id=?", [$telegramId]);
        $rows = $this->db->fetchAll(
            "SELECT * FROM destinations WHERE user_id=? AND active=1 ORDER BY id DESC",
            [(int)$user['id']]
        );

        $text = "📢 <b>Meus grupos</b>\n\n";
        if (!$rows) {
            $text .= "Nenhum grupo cadastrado.\n";
        } else {
            foreach ($rows as $row) {
                $text .= "• " . h((string)($row['title'] ?: $row['chat_id'])) . "\n";
            }
        }

        $this->tg->sendMessage(
            (string)$telegramId,
            $text,
            ['inline_keyboard'=>[
                [['text'=>'➕ Adicionar grupo','callback_data'=>'dest:add']],
                [['text'=>'🔄 Atualizar','callback_data'=>'dest:list']],
            ]]
        );
    }

    private function subscription(int $telegramId): void
    {
        $user = $this->db->fetch("SELECT id FROM users WHERE telegram_id=?", [$telegramId]);

        $sub = $this->db->fetch(
            "SELECT s.*, p.name plan_name, p.price
             FROM subscriptions s JOIN plans p ON p.id=s.plan_id
             WHERE s.user_id=? AND s.status='active'
             ORDER BY s.expires_at DESC LIMIT 1",
            [(int)$user['id']]
        );

        if ($sub) {
            $this->tg->sendMessage(
                (string)$telegramId,
                "💳 <b>Assinatura ativa</b>\n\nPlano: " . h((string)$sub['plan_name']) .
                "\nVencimento: " . h((string)$sub['expires_at'])
            );
            return;
        }

        $plan = $this->db->fetch(
            "SELECT * FROM plans WHERE slug=? AND active=1",
            [$this->config['default_plan']]
        );

        if (!$plan) {
            $this->tg->sendMessage((string)$telegramId, "Nenhum plano disponível.");
            return;
        }

        $this->tg->sendMessage(
            (string)$telegramId,
            "⭐ <b>{$plan['name']}</b>\n\nValor: R$ " .
            number_format((float)$plan['price'], 2, ',', '.') .
            "\nValidade: {$plan['duration_days']} dias.",
            ['inline_keyboard'=>[
                [['text'=>'💠 Pagar com PIX','callback_data'=>'pay:' . $plan['id']]]
            ]]
        );
    }

    private function aiDevelopment(int $telegramId): void
    {
        $this->tg->sendMessage(
            (string)$telegramId,
            "🚧 <b>Inteligência Artificial</b>\n\n" .
            "Este recurso está em desenvolvimento.\n" .
            "Em breve você poderá gerar automaticamente textos e imagens para suas ofertas."
        );
    }

    private function help(int $telegramId): void
    {
        $this->tg->sendMessage(
            (string)$telegramId,
            "❓ <b>Como funciona</b>\n\n" .
            "1. Ative sua assinatura.\n" .
            "2. Cadastre seus dados de afiliado.\n" .
            "3. Adicione o grupo destino e dê administrador ao bot.\n" .
            "4. As ofertas recebidas da fonte serão processadas e colocadas na fila.\n" .
            "5. O worker publica cada oferta de forma personalizada."
        );
    }

    private function callback(array $callback): void
    {
        $from = $callback['from'] ?? [];
        $telegramId = (int)($from['id'] ?? 0);
        $data = (string)($callback['data'] ?? '');

        if ($telegramId <= 0) {
            return;
        }

        // Responde o clique IMEDIATAMENTE.
        // O Telegram exige que o callback_query seja respondido rapidamente.
        try {
            $this->tg->answerCallback((string)($callback['id'] ?? ''));
        } catch (Throwable $e) {
            log_app('error', 'callback_answer_error', [
                'error' => $e->getMessage()
            ]);
        }

        $this->upsertUser([
            'from' => $from,
            'chat' => ['id' => $telegramId]
        ]);

        try {
            if (str_starts_with($data, 'aff:')) {
                $platform = substr($data, 4);

                $this->setState(
                    $telegramId,
                    'affiliate_id',
                    json_encode(['platform' => $platform])
                );

                $this->tg->sendMessage(
                    (string)$telegramId,
                    "Envie seu <b>ID/identificador</b> de afiliado da plataforma <b>" .
                    h($platform) .
                    "</b>.\n\nOu envie <code>pular</code> para configurar apenas um template de link depois."
                );

                return;
            }

            if ($data === 'dest:add') {
                $this->setState($telegramId, 'destination', '');

                $this->tg->sendMessage(
                    (string)$telegramId,
                    "Envie o <b>ID do grupo/canal</b> destino.\nExemplo: <code>-1001234567890</code>\n\nO bot precisa estar no grupo e ter permissão para publicar."
                );

                return;
            }

            if ($data === 'dest:list') {
                $this->destinationsMenu($telegramId);
                return;
            }

            if (str_starts_with($data, 'pay:')) {
                $planId = (int)substr($data, 4);

                $user = $this->db->fetch(
                    "SELECT id FROM users WHERE telegram_id=?",
                    [$telegramId]
                );

                if (!$user) {
                    $this->tg->sendMessage(
                        (string)$telegramId,
                        "Usuário não encontrado. Envie /start novamente."
                    );
                    return;
                }

                $payment = $this->mp->createPix(
                    (int)$user['id'],
                    $planId
                );

                $text = "💎 <b>PIX gerado</b>\n\n";
                $text .= "Copie e cole o código abaixo:\n\n";
                $text .= "<code>" . h($payment['pix_code']) . "</code>\n\n";
                $text .= "Após o pagamento, a assinatura será ativada quando o Mercado Pago confirmar a transação.";

                $this->tg->sendMessage(
                    (string)$telegramId,
                    $text
                );

                return;
            }

        } catch (Throwable $e) {
            log_app('error', 'callback_error', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            // Não chama answerCallback novamente.
            $this->tg->sendMessage(
                (string)$telegramId,
                "❌ Não foi possível concluir esta ação. Tente novamente."
            );
        }
    }

    private function handleState(int $telegramId, string $state, string $payload, string $text): void
    {
        if ($state === 'affiliate_id') {
            $data = json_decode($payload, true) ?: [];
            $platform = (string)($data['platform'] ?? '');

            if ($text === '') {
                $this->tg->sendMessage((string)$telegramId, "Envie um valor.");
                return;
            }

            $user = $this->db->fetch("SELECT id FROM users WHERE telegram_id=?", [$telegramId]);

            $this->db->execute(
                "INSERT INTO affiliate_accounts(user_id,platform,affiliate_id,active,created_at,updated_at)
                 VALUES(?,?,?,1,?,?)
                 ON CONFLICT(user_id,platform) DO UPDATE SET affiliate_id=excluded.affiliate_id,active=1,updated_at=excluded.updated_at",
                [(int)$user['id'], $platform, $text, now(), now()]
            );

            $this->clearState($telegramId);
            $this->tg->sendMessage((string)$telegramId, "✅ Afiliado <b>" . h($platform) . "</b> salvo.");
            return;
        }

        if ($state === 'destination') {
            if (!preg_match('/^-100\d+$/', $text)) {
                $this->tg->sendMessage((string)$telegramId, "ID inválido. Use o formato -100XXXXXXXXXX.");
                return;
            }

            $user = $this->db->fetch("SELECT id FROM users WHERE telegram_id=?", [$telegramId]);
            $title = $text;

            try {
                $chat = $this->tg->getChat($text);
                $title = (string)($chat['title'] ?? $chat['username'] ?? $text);
            } catch (Throwable $e) {
                // O usuário pode corrigir depois; salvamos apenas se o bot conseguir acessar.
                $this->tg->sendMessage(
                    (string)$telegramId,
                    "⚠️ Não consegui acessar esse grupo. Confirme que o bot foi adicionado como administrador e envie o ID novamente."
                );
                return;
            }

            $this->db->execute(
                "INSERT INTO destinations(user_id,chat_id,title,active,created_at,updated_at)
                 VALUES(?,?,?,1,?,?)
                 ON CONFLICT(user_id,chat_id) DO UPDATE SET active=1,title=excluded.title,updated_at=excluded.updated_at",
                [(int)$user['id'], $text, $title, now(), now()]
            );

            $this->clearState($telegramId);
            $this->tg->sendMessage((string)$telegramId, "✅ Grupo <b>" . h($title) . "</b> cadastrado.");
            return;
        }

        $this->clearState($telegramId);
        $this->home($telegramId);
    }

    private function setState(int $telegramId, string $state, string $payload): void
    {
        $this->db->execute(
            "INSERT INTO bot_states(telegram_id,state,payload,updated_at)
             VALUES(?,?,?,?)
             ON CONFLICT(telegram_id) DO UPDATE SET state=excluded.state,payload=excluded.payload,updated_at=excluded.updated_at",
            [$telegramId, $state, $payload, now()]
        );
    }

    private function clearState(int $telegramId): void
    {
        $this->db->execute("DELETE FROM bot_states WHERE telegram_id=?", [$telegramId]);
    }
}
