<?php

namespace App\Services\Annotation;

use App\Models\AnnotationItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class AnnotationObjectReplacementService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param list<array<string, mixed>> $objects */
    public function replace(AnnotationItem $item, User $actor, array $objects, ?string $ipAddress, ?string $userAgent, ?string $requestId): int
    {
        return DB::transaction(function () use ($item, $actor, $objects, $ipAddress, $userAgent, $requestId): int {
            $locked = AnnotationItem::query()->lockForUpdate()->findOrFail($item->annotation_item_id);
            $oldCount = $locked->objects()->count();
            $locked->objects()->delete();

            $createdAt = now('UTC')->toIso8601String();
            $rows = collect($objects)->map(fn (array $object): array => [
                'annotation_object_id' => (string) str()->uuid(),
                'annotation_item_id' => $locked->annotation_item_id,
                'class_id' => $object['class_id'],
                'bbox' => array_key_exists('bbox', $object) && $object['bbox'] !== null ? json_encode($object['bbox'], JSON_THROW_ON_ERROR) : null,
                'polygon' => array_key_exists('polygon', $object) && $object['polygon'] !== null ? json_encode($object['polygon'], JSON_THROW_ON_ERROR) : null,
                'attributes' => array_key_exists('attributes', $object) && $object['attributes'] !== null ? json_encode($object['attributes'], JSON_THROW_ON_ERROR) : null,
                'created_by' => $actor->user_id,
                'created_at' => $createdAt,
            ])->all();
            if ($rows !== []) {
                DB::table('annotation_objects')->insert($rows);
            }

            if ($locked->status === 'planned') {
                $locked->forceFill(['status' => 'in_progress'])->save();
            }

            $this->auditLogger->record(
                'annotation_item.objects.replace', 'annotation_items', $locked->annotation_item_id,
                $actor->user_id, ['count' => $oldCount], [
                    'count' => count($rows),
                    'class_ids' => collect($objects)->pluck('class_id')->unique()->values()->all(),
                ], $ipAddress, $userAgent, $requestId,
            );

            return count($rows);
        });
    }
}
