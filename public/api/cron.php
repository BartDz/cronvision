<?php declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../src/CronSchedule.php';

$expression = trim($_GET['expr'] ?? '');
$timeZone = trim($_GET['tz'] ?? 'Europe/Warsaw');
$locale = in_array($_GET['locale'] ?? 'en', ['en', 'pl'], true) ? ($_GET['locale'] ?? 'en') : 'en';

if ($expression === '') {
    http_response_code(400);
    echo json_encode([
        'valid' => false,
        'explanation' => null,
        'next_runs' => [],
        'error' => 'Missing expr parameter',
    ]);
    exit;
}

try {
    $schedule = new CronSchedule($expression);
    $runs = $schedule->nextRuns(10, $timeZone);

    echo json_encode([
        'valid' => true,
        'explanation' => $schedule->explain($locale),
        'next_runs' => array_map(
            fn(\DateTimeImmutable $dt) => $dt->format('D, d M Y  H:i'),
            $runs
        ),
        'error' => null,
    ]);
} catch (\InvalidArgumentException $e) {
    echo json_encode([
        'valid' => false,
        'explanation' => null,
        'next_runs' => [],
        'error' => $e->getMessage(),
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'valid' => false,
        'explanation' => null,
        'next_runs' => [],
        'error' => 'Server error',
    ]);
}
