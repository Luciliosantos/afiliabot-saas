<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$config = app_config();
$token = $_GET['token'] ?? '';
$adminToken = env_value('ADMIN_PANEL_TOKEN', '');

if ($adminToken === '' || !hash_equals($adminToken, (string)$token)) {
    http_response_code(403);
    echo 'Painel bloqueado. Configure ADMIN_PANEL_TOKEN no .env e acesse /admin/?token=...';
    exit;
}

$stats = [
    'users' => (int)$db->scalar("SELECT COUNT(*) FROM users"),
    'active_subscriptions' => (int)$db->scalar("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at > ?", [now()]),
    'offers' => (int)$db->scalar("SELECT COUNT(*) FROM source_offers"),
    'sent' => (int)$db->scalar("SELECT COUNT(*) FROM deliveries WHERE status='sent'"),
    'pending' => (int)$db->scalar("SELECT COUNT(*) FROM deliveries WHERE status='pending'"),
    'failed' => (int)$db->scalar("SELECT COUNT(*) FROM deliveries WHERE status='failed'"),
];

$recentUsers = $db->fetchAll(
    "SELECT id,telegram_id,username,first_name,role,status,created_at FROM users ORDER BY id DESC LIMIT 50"
);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($config['name'])?> — Admin</title>
<style>
body{font-family:Inter,Arial,sans-serif;background:#f4f6fa;color:#172033;margin:0}
main{max-width:1100px;margin:30px auto;padding:0 18px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}
.card{background:#fff;padding:20px;border-radius:16px;box-shadow:0 5px 20px #0000000a}
.num{font-size:30px;font-weight:700;margin-top:8px}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:16px;overflow:hidden}
th,td{padding:12px;border-bottom:1px solid #eee;text-align:left}
.small{color:#667085;font-size:13px}
</style>
</head>
<body>
<main>
<h1>🤖 <?=h($config['name'])?></h1>
<div class="grid">
<?php foreach ($stats as $label=>$value): ?>
<div class="card"><div class="small"><?=h($label)?></div><div class="num"><?=h((string)$value)?></div></div>
<?php endforeach; ?>
</div>

<h2>Usuários recentes</h2>
<table>
<thead><tr><th>ID</th><th>Telegram</th><th>Nome</th><th>Role</th><th>Status</th><th>Criado</th></tr></thead>
<tbody>
<?php foreach ($recentUsers as $u): ?>
<tr>
<td><?=h((string)$u['id'])?></td>
<td><?=h((string)$u['telegram_id'])?></td>
<td><?=h(trim(($u['first_name'] ?? '').' '.($u['username'] ? '@'.$u['username'] : '')))?></td>
<td><?=h((string)$u['role'])?></td>
<td><?=h((string)$u['status'])?></td>
<td><?=h((string)$u['created_at'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</main>
</body>
</html>
