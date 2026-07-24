<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

$targetPath = $projectRoot
    .DIRECTORY_SEPARATOR
    .'app'
    .DIRECTORY_SEPARATOR
    .'Providers'
    .DIRECTORY_SEPARATOR
    .'AppServiceProvider.php';

if (! is_file($targetPath)) {
    fail("Required project file not found: {$targetPath}");
}

$original = file_get_contents($targetPath);

if ($original === false) {
    fail("Unable to read: {$targetPath}");
}

$content = $original;

$newImport = <<<'PHP'
use App\Services\AuditObserverRegistry;
PHP;

if (! str_contains($content, $newImport)) {
    $oldImport = <<<'PHP'
use Carbon\CarbonImmutable;
PHP;

    assertExactOccurrence(
        $content,
        $oldImport,
        1,
        'Carbon import',
    );

    $content = str_replace(
        $oldImport,
        $newImport.PHP_EOL.$oldImport,
        $content,
        $count,
    );

    if ($count !== 1) {
        fail(
            'Unable to add the audit observer registry import.',
        );
    }
}

$newBoot = <<<'PHP'
    public function boot(): void
    {
        $this->configureDefaults();

        app(AuditObserverRegistry::class)
            ->register();
    }
PHP;

if (! str_contains($content, $newBoot)) {
    $oldBoot = <<<'PHP'
    public function boot(): void
    {
        $this->configureDefaults();
    }
PHP;

    assertExactOccurrence(
        $content,
        $oldBoot,
        1,
        'AppServiceProvider boot method',
    );

    $content = str_replace(
        $oldBoot,
        $newBoot,
        $content,
        $count,
    );

    if ($count !== 1) {
        fail(
            'Unable to activate the audit observer registry.',
        );
    }
}

if (file_put_contents($targetPath, $content) === false) {
    fail('Unable to write AppServiceProvider.php.');
}

$command = escapeshellarg(PHP_BINARY)
    .' -l '
    .escapeshellarg($targetPath)
    .' 2>&1';

exec($command, $output, $exitCode);

if ($exitCode !== 0) {
    file_put_contents($targetPath, $original);

    fail(
        'AppServiceProvider syntax validation failed. '
        .'The original file was restored.'
        .PHP_EOL
        .implode(PHP_EOL, $output),
    );
}

$final = file_get_contents($targetPath);

foreach ([
    'use App\Services\AuditObserverRegistry;',
    'app(AuditObserverRegistry::class)',
    '->register();',
] as $fragment) {
    if (
        $final === false
        || ! str_contains($final, $fragment)
    ) {
        file_put_contents($targetPath, $original);

        fail(
            'Post-install verification failed. '
            .'The original AppServiceProvider was restored.',
        );
    }
}

fwrite(
    STDOUT,
    'Audit observer registry activated successfully.'
    .PHP_EOL
    .'- app/Providers/AppServiceProvider.php'
    .PHP_EOL,
);

function assertExactOccurrence(
    string $content,
    string $fragment,
    int $expected,
    string $label,
): void {
    $count = substr_count($content, $fragment);

    if ($count !== $expected) {
        fail(
            "{$label} was expected {$expected} time(s), "
            ."found {$count}. No change was made.",
        );
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}
