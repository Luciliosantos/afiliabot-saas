<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

require_cli();
Migrator::up($db);
echo "Migração concluída.\n";
