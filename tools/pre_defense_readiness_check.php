<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$requiredFiles = [
    'artisan',
    'composer.json',
    'routes/web.php',
    'routes/console.php',
    'app/Services/PaymentWorkflowService.php',
    'app/Services/AmenityRequestWorkflowService.php',
    'app/Services/CheckOutInspectionRequestService.php',
    'app/Services/CheckOutWorkflowService.php',
    'app/Services/ReservationNoShowReleaseService.php',
    'app/Services/FinancialReportMetricsService.php',
    'app/Services/ActivityLogService.php',
    'tests/Feature/OperationalCheckoutEndToEndTest.php',
];

$recommendedFiles = [
    'app/Services/AuditObserverRegistry.php',
    'tests/Feature/FinancialReportMetricsAccuracyTest.php',
    'tests/Feature/ReportsFinancialMetricsIntegrationTest.php',
    'tests/Feature/ActivityLogCoverageHardeningTest.php',
    'docs/PROJECT_ARCHITECTURE_AND_WORKFLOW.md',
    'docs/DEFENSE_DEMO_GUIDE.md',
    'docs/USER_ACCEPTANCE_TEST_CHECKLIST.md',
    'docs/DEPLOYMENT_BACKUP_AND_RESTORE.md',
];

$missingRequired = [];
$missingRecommended = [];

foreach ($requiredFiles as $path) {
    if (! file_exists($root.DIRECTORY_SEPARATOR.$path)) {
        $missingRequired[] = $path;
    }
}

foreach ($recommendedFiles as $path) {
    if (! file_exists($root.DIRECTORY_SEPARATOR.$path)) {
        $missingRecommended[] = $path;
    }
}

echo 'Olaer Spring Resort — Pre-Defense Readiness Check'.PHP_EOL;
echo str_repeat('=', 51).PHP_EOL.PHP_EOL;

echo 'Required files'.PHP_EOL;
foreach ($requiredFiles as $path) {
    $exists = file_exists($root.DIRECTORY_SEPARATOR.$path);
    echo ($exists ? '[PASS] ' : '[FAIL] ').$path.PHP_EOL;
}

echo PHP_EOL.'Recommended files'.PHP_EOL;
foreach ($recommendedFiles as $path) {
    $exists = file_exists($root.DIRECTORY_SEPARATOR.$path);
    echo ($exists ? '[PASS] ' : '[WARN] ').$path.PHP_EOL;
}

echo PHP_EOL.'Commands to run from the project root'.PHP_EOL;
echo 'php artisan optimize:clear'.PHP_EOL;
echo 'php artisan migrate:status'.PHP_EOL;
echo 'php artisan route:list'.PHP_EOL;
echo 'php artisan schedule:list'.PHP_EOL;
echo 'php artisan test'.PHP_EOL;

echo PHP_EOL;

if ($missingRequired !== []) {
    echo 'RESULT: NOT READY'.PHP_EOL;
    echo 'Missing required files: '.count($missingRequired).PHP_EOL;
    exit(1);
}

if ($missingRecommended !== []) {
    echo 'RESULT: CORE FILES PRESENT; REVIEW WARNINGS'.PHP_EOL;
    echo 'Missing recommended files: '.count($missingRecommended).PHP_EOL;
    exit(0);
}

echo 'RESULT: DOCUMENTATION AND CORE FILES PRESENT'.PHP_EOL;
exit(0);
