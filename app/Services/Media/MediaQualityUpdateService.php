<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class MediaQualityUpdateService
{
    public function __construct(
        private readonly ScopedMediaAssetService $scoped,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * [MEDIA-06] Persist a tenant-scoped quality review and its immutable audit evidence.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(
        User $actor,
        string $mediaId,
        array $data,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $requestId,
    ): MediaAsset {
        return DB::transaction(function () use (
            $actor,
            $mediaId,
            $data,
            $ipAddress,
            $userAgent,
            $requestId,
        ): MediaAsset {
            $asset = $this->scoped->findForUpdate($actor, $mediaId);
            $fields = ['quality_score', 'quality_status', 'notes', 'sync_version'];
            $old = $asset->only($fields);

            $asset->quality_status = $data['quality_status'];
            foreach (['quality_score', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $asset->{$field} = $data[$field];
                }
            }
            $asset->sync_version = ((int) $asset->sync_version) + 1;
            $asset->save();
            $asset->refresh();

            $new = $asset->only($fields);
            $new['storage_key'] = $asset->storage_key;
            $new['checksum_sha256'] = $asset->checksum_sha256;

            $this->auditLogger->record(
                action: 'media.quality',
                tableName: 'media_assets',
                recordId: $asset->media_asset_id,
                userId: $actor->user_id,
                oldValues: $old,
                newValues: $new,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                requestId: $requestId,
            );

            return MediaAsset::query()
                ->withCaptureLocationGeoJson()
                ->findOrFail($asset->media_asset_id);
        });
    }
}
