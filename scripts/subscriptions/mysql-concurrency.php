<?php

use App\Exceptions\SubscriptionAccessException;
use App\Models\DailyUsage;
use App\Models\Item;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use App\Services\Subscriptions\DailyUsageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'Subscription concurrency acceptance failed: '.$exception::class.' (code '.$exception->getCode().') at '.basename($exception->getFile()).':'.$exception->getLine().PHP_EOL);
    exit(1);
});

function isolatedConnection(array $connection): void
{
    if (! preg_match('/^maikcat_subscriptions_qa_[a-f0-9]{16}$/D', $connection['database']) || ! in_array($connection['host'], ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Refusing a non-isolated database.');
    }
    config(['database.default' => 'mysql', 'database.connections.mysql' => [...$connection, 'url' => null], 'subscriptions.environment' => 'production']);
    DB::purge('mysql');
    if (DB::selectOne('select database() as database_name')->database_name !== $connection['database']) {
        throw new RuntimeException('Resolved database identity does not match the disposable schema.');
    }
}

if (($argv[1] ?? '') === '--worker') {
    $instructions = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    isolatedConnection($instructions['connection']);
    $connectionId = DB::selectOne('select connection_id() as connection_id')->connection_id;
    file_put_contents($instructions['ready'], 'ready');
    $deadline = microtime(true) + 30;
    while (! is_file($instructions['barrier'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker start barrier timed out.');
        }
        usleep(10000);
    }
    try {
        $claim = app(DailyUsageService::class)->claim(User::query()->findOrFail($instructions['user']), Item::query()->findOrFail($instructions['item']));
        $outcome = ['status' => 200, 'counted' => $claim['usage']['counted']];
    } catch (SubscriptionAccessException $exception) {
        $outcome = ['status' => $exception->httpStatus, 'code' => $exception->errorCode];
    }
    echo json_encode(['pid' => getmypid(), 'connection_id' => $connectionId, 'database' => $instructions['connection']['database'], ...$outcome], JSON_THROW_ON_ERROR);
    exit(0);
}

function concurrentClaims(array $connection, User $user, array $items, string $directory): array
{
    mkdir($directory, 0777, true);
    $workers = [];
    try {
        foreach ($items as $index => $item) {
            $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot launch a PHP concurrency worker.');
            }
            fwrite($pipes[0], json_encode(['connection' => $connection, 'user' => $user->id, 'item' => $item->id, 'ready' => $directory.'/ready-'.$index, 'barrier' => $directory.'/start'], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }
        $deadline = microtime(true) + 30;
        while (count(glob($directory.'/ready-*')) !== count($items)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Not all isolated database workers became ready.');
            }
            usleep(10000);
        }
        file_put_contents($directory.'/start', 'start');
        $deadline = microtime(true) + 60;
        do {
            $running = false;
            foreach ($workers as $worker) {
                $running = proc_get_status($worker['process'])['running'] || $running;
            }
            if ($running && microtime(true) > $deadline) {
                throw new RuntimeException('Concurrent claims exceeded the acceptance deadline.');
            }
            if ($running) {
                usleep(10000);
            }
        } while ($running);
        $results = [];
        foreach ($workers as $worker) {
            $output = stream_get_contents($worker['stdout']);
            $error = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            $exit = proc_close($worker['process']);
            if ($exit > 0 || $output === '') {
                throw new RuntimeException('A concurrency worker failed: '.substr($error, 0, 1000));
            }
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker['process']) && proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }
        }
    }
}

$connection = config('database.connections.mysql');
if (! in_array($connection['host'], ['localhost', '127.0.0.1', '::1'], true) || ! empty($connection['url'])) {
    throw new RuntimeException('Concurrency acceptance requires a verified local MySQL server without a DB_URL override.');
}
$schema = 'maikcat_subscriptions_qa_'.bin2hex(random_bytes(8));
$admin = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $admin->query('select @@hostname as hostname, @@port as port, version() as version')->fetch(PDO::FETCH_ASSOC);
$created = false;
try {
    $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $connection['database'] = $schema;
    isolatedConnection($connection);
    if (Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]) !== 0) {
        throw new RuntimeException('Disposable database migration failed: '.Artisan::output());
    }
    $evidence = ['recorded_at' => now('UTC')->toIso8601String(), 'server' => $server, 'database' => $schema, 'scenarios' => []];
    foreach (['last_slot', 'same_family', 'initial_bucket'] as $scenario) {
        $user = User::factory()->create();
        SubscriptionEntitlement::query()->create(['user_id' => $user->id, 'source' => 'grant', 'environment' => 'production', 'daily_allowance' => $scenario === 'last_slot' ? 2 : 1, 'features' => ['valuation' => true], 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'reason' => 'Disposable concurrency acceptance']);
        if ($scenario === 'last_slot') {
            app(DailyUsageService::class)->claim($user, Item::factory()->create(['serial_code' => 'PREVIOUS-FAMILY']));
        }
        $items = [];
        for ($index = 0; $index < 8; $index++) {
            $items[] = Item::factory()->create(['serial_code' => $scenario === 'same_family' ? 'SAME-FAMILY' : 'FAMILY-'.$index, 'weight_kg' => $index + 1, 'pt_ppm' => 100 + $index]);
        }
        $directory = storage_path('app/private/subscription-qa/'.$schema.'/'.$scenario);
        $results = concurrentClaims($connection, $user, $items, $directory);
        $counted = count(array_filter($results, fn (array $row): bool => ($row['counted'] ?? false) === true));
        $successful = count(array_filter($results, fn (array $row): bool => $row['status'] === 200));
        $expectedSuccesses = $scenario === 'same_family' ? 8 : 1;
        $used = DailyUsage::query()->where('user_id', $user->id)->sole()->used;
        $revealCount = DB::table('daily_usage_reveals')->where('daily_usage_id', DailyUsage::query()->where('user_id', $user->id)->value('id'))->count();
        $invalidRejections = array_filter($results, fn (array $row): bool => $row['status'] !== 200 && ($row['status'] !== 429 || ($row['code'] ?? '') !== 'DAILY_QUOTA_EXHAUSTED'));
        if ($counted !== 1 || $successful !== $expectedSuccesses || $used !== ($scenario === 'last_slot' ? 2 : 1)
            || $revealCount !== $used || $invalidRejections
            || count(array_unique(array_column($results, 'connection_id'))) !== 8 || count(array_unique(array_column($results, 'pid'))) !== 8) {
            throw new RuntimeException('Concurrency acceptance failed: '.json_encode(['scenario' => $scenario, 'used' => $used, 'results' => $results]));
        }
        $evidence['scenarios'][$scenario] = ['passed' => true, 'used' => $used, 'reveal_count' => $revealCount, 'results' => $results];
    }
} finally {
    DB::disconnect('mysql');
    if ($created && preg_match('/^maikcat_subscriptions_qa_[a-f0-9]{16}$/D', $schema)) {
        $admin->exec('DROP DATABASE `'.$schema.'`');
    }
}
$evidence['database_dropped'] = (int) $admin->query('SELECT COUNT(*) FROM information_schema.schemata WHERE SCHEMA_NAME = '.$admin->quote($schema))->fetchColumn() === 0;
if (! $evidence['database_dropped']) {
    throw new RuntimeException('Disposable schema cleanup was not confirmed.');
}
$report = json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
file_put_contents(storage_path('app/private/subscription-qa/last-run.json'), $report);
echo $report;
