<?php
declare(strict_types=1);
// Local-only HTTP rehearsal; never point this at the shared charity database.
if (getenv('APP_ENV') !== 'testing' || getenv('LAUNCH_P0_DISPOSABLE') !== '1'
    || getenv('DB_HOST') !== 'qrfix2-pg' || getenv('DB_USERNAME') !== 'p0_test') {
    throw new RuntimeException('Isolated launch-p0 test stack required.');
}
$old = realpath(getenv('P0_OLD_API_ROOT') ?: '');
$new = realpath(getenv('P0_NEW_API_ROOT') ?: '');
$admin = realpath(getenv('P0_ADMIN_ROOT') ?: '');
if (!$old || !$new || !$admin || $old === $new || $new === realpath('/api')) {
    throw new RuntimeException('Independent old/new API exports required; never use the live bind mount.');
}
$db = 'launch_p0_fix1_rehearsal_'.bin2hex(random_bytes(6));
$pdo = new PDO('pgsql:host=qrfix2-pg;dbname=postgres','p0_test',getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE "'.$db.'"');
$state = sys_get_temp_dir().'/'.$db;
mkdir($state,0700);
$env = array_merge(getenv(), [
    'DB_DATABASE'=>$db,'DB_CONNECTION'=>'pgsql','DB_URL'=>'','APP_ENV'=>'testing',
    'APP_KEY'=>'base64:'.base64_encode(str_repeat('R',32)),
    'APP_CONFIG_CACHE'=>$state.'/no-cache.php','APP_ROUTES_CACHE'=>$state.'/no-routes.php',
    'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync',
    'BROADCAST_CONNECTION'=>'null','MAIL_MAILER'=>'array','CHARITY_API_URL'=>'',
]);
$run = static function (string $root, array $args) use ($env): string {
    $p=proc_open([PHP_BINARY,...$args],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);
    $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    foreach($pipes as $pipe) fclose($pipe);
    if(proc_close($p)!==0) throw new RuntimeException($output);
    return $output;
};
mkdir($state.'/pre-migrations');
foreach(glob($admin.'/database/migrations/*.php') as $file) {
    if (basename($file) < '2026_09_30_000000') copy($file,$state.'/pre-migrations/'.basename($file));
}
echo "DATABASE $db (new isolated database)\n";
echo $run($admin,['artisan','migrate','--force','--no-interaction','--path='.$state.'/pre-migrations','--realpath']);
$seed = <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=App\Models\Company::factory()->active()->create();
$b=App\Models\Branch::factory()->for($c)->create();
$d=App\Models\Device::factory()->create(['company_id'=>$c->id,'branch_id'=>$b->id,'status'=>'active','assigned_at'=>now()->subDays(2)]);
Illuminate\Support\Facades\DB::table('pos_devices')->where('id',$d->id)->update(['device_token'=>'local-rehearsal-release-token']);
App\Models\DeviceActivationToken::factory()->create(['device_id'=>$d->id,'used_at'=>now()->subDay()]);
Illuminate\Support\Facades\DB::table('pos_device_assignments_history')->insert(['device_id'=>$d->id,'company_id'=>$c->id,'branch_id'=>$b->id,'assigned_at'=>now()->subDays(2)]);
foreach (['received','failed','processed'] as $status) {
 Illuminate\Support\Facades\DB::table('pos_sync_events')->insert(['device_id'=>$d->id,'client_event_id'=>Illuminate\Support\Str::uuid(),'event_type'=>'sync.noop','payload_json'=>'{}','client_timestamp'=>now()->subHour(),'server_received_at'=>now()->subHour(),'ack_status'=>$status,'result_json'=>'{"original":true}']);
}
echo "SEEDED device={$d->id}, company={$c->id}, branch={$b->id}\n";
PHP;
file_put_contents($state.'/seed.php',$seed);
echo $run($admin,[$state.'/seed.php']);
$database=new PDO('pgsql:host=qrfix2-pg;dbname='.$db,'p0_test',getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server=null;
$start=static function(string $root) use (&$server,$env,$state): void {
    if (is_resource($server)) {proc_terminate($server);proc_close($server);}
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:18994','-t','public','public/index.php'],
        [0=>['file','/dev/null','r'],1=>['file',$state.'/http.log','a'],2=>['file',$state.'/http.log','a']],$pipes,$root,$env);
    for($i=0;$i<100;$i++){ $s=@fsockopen('127.0.0.1',18994);if($s){fclose($s);return;}usleep(50000); }
    throw new RuntimeException('HTTP server did not start.');
};
$wipe=['01d17de'=>false,'8b3dc8d'=>false,'2491f73'=>false];
$probe=static function(string $stage,int $expected) use (&$wipe): void {
    $ctx=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>10,'header'=>"Accept: application/json\r\nAuthorization: Bearer local-rehearsal-release-token\r\n"]]);
    $body=file_get_contents('http://127.0.0.1:18994/api/v1/device/config',false,$ctx);
    preg_match('/\s(\d{3})\s/',$http_response_header[0],$m);
    $status=(int)$m[1];$payload=json_decode($body,true)?:[];
    foreach($wipe as $release=>&$cleared) {
        // Actual release _interpret: nonempty errors[] wins; otherwise bare 401 resets setup.
        $cleared=$cleared||($status===401&&empty($payload['errors']));
    } unset($cleared);
    echo "$stage HTTP=$status EXPECTED=$expected RELEASE_SETUP_CLEARED=".json_encode($wipe)."\n";
    if($status!==$expected||in_array(true,$wipe,true)) throw new RuntimeException('Old release lost its session: '.$body);
};
try {
    echo $run($old,['artisan','up']);
    $start($old);$probe('old API, plaintext token',200);
    if (getenv('P0_REHEARSAL_UNSAFE_ORDER') !== '1') {
        echo $run($old,['artisan','down','--retry=60']);
        $probe('old API maintenance BEFORE hashing',503);
    }
    echo $run($admin,['artisan','migrate','--force','--no-interaction']);
    $probe('old API maintenance AFTER hashing',503);
    // Maintenance remains present throughout code switch; separate exports simulate shared storage.
    copy($old.'/storage/framework/down',$new.'/storage/framework/down');
    $start($new);$probe('new API still in maintenance',503);
    echo $run($new,['artisan','up']);$probe('new API up with unchanged old APK token',200);
    $rows=$database->query("SELECT ack_status,company_id,branch_id,result_json FROM pos_sync_events ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if(array_column($rows,'ack_status')!==['received','failed','processed']) throw new RuntimeException('History status lost');
    foreach($rows as $row) if(!$row['company_id']||!$row['branch_id']||json_decode($row['result_json'],true)!==['original'=>true]) throw new RuntimeException('History/result lost');
    echo 'CONTINUOUS_HISTORY '.json_encode($rows)."\n";
    echo "PASS B4: three old release parsers retain the original token through every maintenance/migration/code-switch stage.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
}
