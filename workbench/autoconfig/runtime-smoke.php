<?php

use App\Events\Clients\ClientCreated;
use App\Events\Clients\ClientRenamed;
use App\Models\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;

// Runs only in a disposable runtime copy with scrubbed environment.
$root = realpath($argv[1]);
if (! $root || ! str_contains($root, '/rnd-autoconfig-apps/') || ! str_ends_with($root, '-runtime')) {
    throw new RuntimeException('Runtime copies only.');
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Fail on any accidental query. These are dispatch and fake-publication checks,
// not persistence, transaction, retry, snapshot or rendering coverage.
DB::listen(function (): void {
    throw new RuntimeException('Unexpected database access');
});
Storyfeed::fake();
$client = new Client;
$client->setRawAttributes(['id' => 4242, 'name' => 'Example Company']);
event(new ClientCreated($client));
Storyfeed::assertPublishedCount(1);
Storyfeed::assertPublished('client.created', $client);
event(new ClientRenamed($client, 'Earlier Company', 'Example Company'));
Storyfeed::assertPublishedCount(2);
Storyfeed::assertPublished('client.renamed', $client);
foreach (Storyfeed::published() as $activity) {
    if ($activity->actor_id !== null) {
        throw new RuntimeException('Invented actor');
    }
}
echo "PASS: two real app events discovered generated listeners and published exactly once each; explicit null actors. No database queries.\n";
