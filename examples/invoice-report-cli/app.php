<?php
// Totals per customer from order lines, the slow way.
//     composer dump-autoload && php app.php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Invoice\Generator;
use Invoice\Report;

$lines = Generator::lines(200_000, 42);
$report = new Report(taxRate: 0.21, currency: 'EUR', decimals: 2);
echo $report->summary($lines);
