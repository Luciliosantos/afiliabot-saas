<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_cli();

$id = (int)($argv[1] ?? 0);
if ($id <= 0) {
    exit("Uso: php bin/create_admin.php TELEGRAM_ID\n");
}

Migrator::up($db);

$exists = $db->fetch("SELECT id FROM users WHERE telegram_id=?", [$id]);

if ($exists) {
    $db->execute("UPDATE users SET role='admin',updated_at=? WHERE telegram_id=?", [now(), $id]);
} else {
    $db->execute(
        "INSERT INTO users(telegram_id,username,first_name,last_name,role,status,created_at,updated_at)
         VALUES(?,?,?,?,?,?,?,?)",
        [$id, '', '', '', 'admin', 'active', now(), now()]
    );
}

echo "Administrador configurado: {$id}\n";
