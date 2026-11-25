<?php
// Front controller. Serve it with: php -S localhost:8000 -t public
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new Shop\App(require __DIR__ . '/../data/catalog.php', require __DIR__ . '/../data/deals.php');
$app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', (string) file_get_contents('php://input'));
