<?php
use App\Models\Company;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Services\P0SyncHistoryRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
uses(RefreshDatabase::class);
function fix2HistoryDevice(bool $moved=false): Device {
 $a=Company::factory()->create();$b=Branch::factory()->for($a)->create();
 $c=$moved?Company::factory()->create():$a;
 $d=$moved?Branch::factory()->for($c)->create():$b;
 $device=Device::factory()->create(['company_id'=>$c->id,'branch_id'=>$d->id,
  'assigned_at'=>now()->subHour(),'status'=>'active','device_token'=>hash('sha256','release-token'),
  'token_company_id'=>$c->id,'token_branch_id'=>$d->id]);
 foreach ([[$a,$b,now()->subDays(3),now()->subHour()],[$c,$d,now()->subHour(),null]] as [$company,$branch,$start,$end]) {
  DB::table('pos_device_assignments_history')->insert(['device_id'=>$device->id,
   'company_id'=>$company->id,'branch_id'=>$branch->id,'assigned_at'=>$start,'unassigned_at'=>$end]);
 }
 DeviceActivationToken::factory()->create(['device_id'=>$device->id,'used_at'=>now()->subDays(2)]);
 return $device;
}
it('F4 retains a release token after same branch terminal history split and attributes old backlog',function() {
 $device=fix2HistoryDevice();
 $event=(object)['device_id'=>$device->id,'server_received_at'=>now()->subDay()];
 expect(app(P0SyncHistoryRepair::class)->assignment($event))->not->toBeNull();
 (require database_path('migrations/2026_09_30_000008_repair_pos_p0_history_and_bindings.php'))->up();
 expect($device->refresh()->device_token)->not->toBeNull();
 expect((string)$device->getAttribute('assignment_activated_at'))->toStartWith(now()->subDays(2)->format('Y-m-d'));
});
it('F4 revokes a genuine move while preserving historical attribution evidence',function() {
 $device=fix2HistoryDevice(true);
 expect(app(P0SyncHistoryRepair::class)->assignment((object)['device_id'=>$device->id,'server_received_at'=>now()->subDay()]))->toBeNull();
 (require database_path('migrations/2026_09_30_000008_repair_pos_p0_history_and_bindings.php'))->up();
 expect($device->refresh()->device_token)->toBeNull();
});
it('F3 preservation uses bounded queries and repair runs without an outer migration transaction',function() {
 $device=fix2HistoryDevice();
 $wire=json_decode(file_get_contents(base_path('tests/Fixtures/launch-p0-fix2/till-payloads.json')),true)[0];
 $rows=[];
 for($i=0;$i<500;$i++) $rows[]=['device_id'=>$device->id,'client_event_id'=>Str::uuid(),
  'event_type'=>$wire['event_type'],'payload_json'=>json_encode($wire['payload']),
  'client_timestamp'=>$wire['client_timestamp'],'server_received_at'=>now()->subDay(),'ack_status'=>'received'];
 DB::table('pos_sync_events')->insert($rows);
 DB::enableQueryLog();DB::flushQueryLog();
 app(P0SyncHistoryRepair::class)->preserve();
 $count=count(DB::getQueryLog());DB::disableQueryLog();
 expect($count)->toBeLessThan(15);
 $migration=require database_path('migrations/2026_09_30_000008_repair_pos_p0_history_and_bindings.php');
 expect($migration->withinTransaction)->toBeFalse();
 DB::enableQueryLog();DB::flushQueryLog();
 $result=app(P0SyncHistoryRepair::class)->repair();
 $count=count(DB::getQueryLog());DB::disableQueryLog();
 expect($result['attributed'])->toBe(500)->and($count)->toBeLessThan(25);
});
