<?php
declare(strict_types=1);
require '/admin/vendor/autoload.php';
$app=require '/admin/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
if (getenv('APP_ENV')!=='testing' || getenv('DB_HOST')!=='qrfix2-pg'
 || getenv('DB_USERNAME')!=='p0_test' || !preg_match('/^launch_p0_fix2_[a-f0-9]+_(fresh|upgraded)$/',getenv('DB_DATABASE'))) {
 throw new RuntimeException('New isolated POS-only database required');
}
echo 'Database '.getenv('DB_DATABASE')."\n";
echo 'max_locks_per_transaction='.DB::selectOne('SHOW max_locks_per_transaction')->max_locks_per_transaction."\n";
$tables=DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename NOT LIKE 'pos\\_%'");
if ($tables) throw new RuntimeException('Non-POS table found');
echo 'pos_sync_events='.DB::table('pos_sync_events')->count()."\n";
$files=glob('/admin/database/migrations/2026_09_30_*.php');sort($files);
$run=function($file) {
 try {
 $t=microtime(true);
 $exit = Artisan::call('migrate', ['--path' => $file, '--realpath' => true, '--force' => true]);
 echo Artisan::output();
 if ($exit !== 0) throw new RuntimeException('Artisan migration failed');
 if (!DB::table('pos_admin_migrations')->where('migration', pathinfo($file, PATHINFO_FILENAME))->exists()) {
   throw new RuntimeException('Migration ledger entry missing');
 }
 printf("%s %.3fs OK\n",basename($file),microtime(true)-$t);
 } catch (Throwable $e) { fwrite(STDERR, basename($file)." FAILED ".$e::class." code=".$e->getCode()."\n"); exit(1); }
};
if(str_ends_with(getenv('DB_DATABASE'),'_upgraded')) {
 echo "Recreating pre-P0 + original 000001–000007, then late 000000\n";
 foreach($files as $file) if(basename($file)>='2026_09_30_000001'&&basename($file)<'2026_09_30_000008')$run($file);
 $run($files[0]);
 $files=array_values(array_filter($files,fn($f)=>basename($f)>='2026_09_30_000008'));
}
$start=microtime(true);
foreach($files as $file)$run($file);
printf("TIMED CHAIN %.3fs\n",microtime(true)-$start);
echo 'events_after='.DB::table('pos_sync_events')->count()."\n";
echo 'non_pos_tables_after='.count(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename NOT LIKE 'pos\\_%'"))."\n";

echo 'p0_ledger_rows='.DB::table('pos_admin_migrations')->where('migration','like','2026_09_30_%')->count()."\n";
