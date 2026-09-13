<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\SoftPosProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\DeviceResource;
use App\Models\Bank;
use App\Models\BankSoftPosProfile;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BankSoftPosProfilesController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', BankSoftPosProfile::class);
        $profiles = BankSoftPosProfile::with('updatedBy:id,name')->get()->keyBy('bank_id');
        $devices = Device::query()->whereNotNull('assigned_at')
            ->get(['id', 'uuid', 'bank_id', 'name', 'serial_number'])->groupBy('bank_id');

        return response()->json(['data' => Bank::query()->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn (Bank $bank): array => [
                'id' => $bank->id,
                'name' => $bank->name,
                'short_name' => $bank->short_name,
                'is_active' => $bank->is_active,
                'profile' => $profiles->get($bank->id),
                'devices' => $devices->get($bank->id, collect())->values(),
            ]), 'providers' => array_map(fn (SoftPosProvider $provider): array => [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'package' => $provider->package(),
                'refund_needs_transaction_id' => $provider->refundNeedsTransactionId(),
                'void_needs_session_id' => $provider->voidNeedsSessionId(),
            ], SoftPosProvider::cases())]);
    }

    public function update(Request $request, Bank $bank, WriteAuditLogAction $audit): JsonResponse
    {
        $this->authorize('manage', BankSoftPosProfile::class);
        $data = $request->validate([
            'softpos_provider' => ['required', Rule::enum(SoftPosProvider::class)],
            'softpos_package' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*(\\.[a-zA-Z][a-zA-Z0-9_]*)+$/'],
            'currency_code' => ['sometimes', 'string', 'regex:/^[0-9]{4}$/'],
            'refund_needs_transaction_id' => ['sometimes', 'boolean'],
            'void_needs_session_id' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'confirm_provider_change' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $bank, $data, $audit): JsonResponse {
            // Serialize creation and updates on the stable bank identity, including
            // the first profile save. This never writes to the charity bank row.
            Bank::query()->whereKey($bank->id)->lockForUpdate()->firstOrFail();
            $profile = BankSoftPosProfile::query()->where('bank_id', $bank->id)->lockForUpdate()->first();
            $provider = SoftPosProvider::from($data['softpos_provider']);
            $created = $profile === null;
            $changed = ! $created && $profile->softpos_provider !== $provider;
            $devices = Device::query()->where('bank_id', $bank->id)->whereNotNull('assigned_at')
                ->get(['id', 'uuid', 'name', 'serial_number']);

            if ($changed && $devices->isNotEmpty() && ! ($data['confirm_provider_change'] ?? false)) {
                return response()->json([
                    'code' => 'softpos_provider_change_needs_confirmation',
                    'message' => 'Confirm the card terminal app change for the assigned devices.',
                    'device_count' => $devices->count(),
                    'devices' => $devices,
                ], 409);
            }

            $profile ??= new BankSoftPosProfile(['bank_id' => $bank->id, 'created_by_user_id' => $request->user()->id]);
            $before = $profile->getAttributes();
            $values = array_diff_key($data, ['confirm_provider_change' => true]);
            $values['softpos_package'] = $provider === SoftPosProvider::None ? null
                : ($data['softpos_package'] ?? (($created || $changed) ? $provider->package() : $profile->softpos_package) ?? $provider->package());
            if ($created || $changed) {
                $values['refund_needs_transaction_id'] = $data['refund_needs_transaction_id'] ?? $provider->refundNeedsTransactionId();
                $values['void_needs_session_id'] = $data['void_needs_session_id'] ?? $provider->voidNeedsSessionId();
            }
            if ($created) {
                $values['currency_code'] = $data['currency_code'] ?? '0512';
                $values['is_active'] = $data['is_active'] ?? true;
            }
            $profile->fill($values);
            if ($profile->isDirty()) {
                $profile->updated_by_user_id = $request->user()->id;
                if ($changed) {
                    $profile->provider_changed_at = now();
                }
                $profile->save();
                // Any profile edit affects config, not just the provider code.
                Device::query()->where('bank_id', $bank->id)->update(['updated_at' => now()]);
                $audit->handle(new AuditLogData(
                    event: $created ? 'bank_softpos_profile.created' : 'bank_softpos_profile.updated',
                    actorUserId: $request->user()->id,
                    auditableType: BankSoftPosProfile::class,
                    auditableId: $profile->id,
                    oldValues: $before,
                    newValues: $profile->getAttributes(),
                ));
                if ($changed) {
                    $audit->handle(new AuditLogData(
                        event: 'bank_softpos_profile.provider_changed',
                        actorUserId: $request->user()->id,
                        auditableType: BankSoftPosProfile::class,
                        auditableId: $profile->id,
                        oldValues: $before,
                        newValues: $profile->getAttributes(),
                        metadata: ['device_ids' => $devices->pluck('id')->all()],
                    ));
                }
            }

            return response()->json(['data' => $profile->refresh()]);
        });
    }

    public function unblock(Request $request, Device $device, WriteAuditLogAction $audit): DeviceResource
    {
        $this->authorize('manage', BankSoftPosProfile::class);

        return DB::transaction(function () use ($request, $device, $audit): DeviceResource {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $before = $device->only(['card_tenders_blocked_reason', 'card_tenders_blocked_at']);
            if ($device->card_tenders_blocked_reason !== null) {
                $device->forceFill([
                    'card_tenders_blocked_reason' => null,
                    'card_tenders_blocked_at' => null,
                    'card_tenders_unblocked_at' => now(),
                ])->save();
                $audit->handle(new AuditLogData(
                    event: 'device.card_tenders.unblocked',
                    actorUserId: $request->user()->id,
                    companyId: $device->company_id,
                    branchId: $device->branch_id,
                    auditableType: Device::class,
                    auditableId: $device->id,
                    oldValues: $before,
                    newValues: $device->only(array_keys($before)),
                ));
            }

            return DeviceResource::make($device->load('bank'));
        });
    }
}
