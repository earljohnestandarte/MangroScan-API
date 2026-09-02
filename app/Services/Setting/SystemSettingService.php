<?php

namespace App\Services\Setting;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class SystemSettingService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function update(User $actor, string $key, array $data, ?string $ip, ?string $agent, ?string $requestId): SystemSetting
    {
        return DB::transaction(function () use ($actor, $key, $data, $ip, $agent, $requestId): SystemSetting {
            $setting = SystemSetting::query()->lockForUpdate()->findOrFail($key);
            $old = $setting->only(['setting_group', 'setting_value', 'description']);
            $setting->fill($data)->save();
            $this->auditLogger->record('setting.update', 'system_settings', $key, $actor->user_id, $old, $setting->only(array_keys($old)), $ip, $agent, $requestId);
            return $setting->refresh();
        });
    }
}
