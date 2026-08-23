<?php

namespace App\Services\Annotation;

use App\Exceptions\WorkflowConflictException;
use App\Models\AnnotationExport;
use App\Models\AnnotationProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AnnotationExportCreationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function create(AnnotationProject $project, User $actor, string $format, ?string $ipAddress, ?string $userAgent, ?string $requestId): AnnotationExport
    {
        $project->load(['items.objects']);
        $objects = $project->items->flatMap->objects->values();
        if ($objects->isEmpty()) {
            throw new WorkflowConflictException('The annotation project has no objects to export.');
        }

        $exportId = (string) str()->uuid();
        $extension = in_array($format, ['coco', 'geojson'], true) ? 'json' : ($format === 'yolo' ? 'txt' : 'csv');
        $fileName = (Str::slug($project->name) ?: 'annotation-project').'-'.$exportId.'.'.$extension;
        $storageKey = 'annotation-exports/'.$project->organization_id.'/'.$project->annotation_project_id.'/'.$fileName;
        $contents = $this->serialize($project, $format);
        $disk = Storage::disk(config('mangroscan.media.disk', 'local'));

        if (! $disk->put($storageKey, $contents)) {
            throw new WorkflowConflictException('The annotation export could not be written to private storage.');
        }

        try {
            return DB::transaction(function () use ($project, $actor, $format, $exportId, $fileName, $storageKey, $ipAddress, $userAgent, $requestId): AnnotationExport {
                $export = AnnotationExport::query()->create([
                    'annotation_export_id' => $exportId,
                    'annotation_project_id' => $project->annotation_project_id,
                    'format' => $format,
                    'file_name' => $fileName,
                    'storage_key' => $storageKey,
                    'created_by' => $actor->user_id,
                    'created_at' => now('UTC'),
                ]);

                $this->auditLogger->record(
                    'annotation_project.export', 'annotation_exports', $export->annotation_export_id,
                    $actor->user_id, null, [
                        'annotation_project_id' => $project->annotation_project_id,
                        'format' => $format,
                        'file_name' => $fileName,
                        'storage_key' => $storageKey,
                    ], $ipAddress, $userAgent, $requestId,
                );

                return $export;
            });
        } catch (Throwable $exception) {
            $disk->delete($storageKey);
            throw $exception;
        }
    }

    private function serialize(AnnotationProject $project, string $format): string
    {
        $objects = $project->items->flatMap->objects->values();
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, ['annotation_item_id', 'class_id', 'bbox', 'polygon', 'attributes']);
            foreach ($objects as $object) {
                fputcsv($stream, [
                    $object->annotation_item_id,
                    $object->class_id,
                    json_encode($object->bbox, JSON_THROW_ON_ERROR),
                    json_encode($object->polygon, JSON_THROW_ON_ERROR),
                    json_encode($object->attributes, JSON_THROW_ON_ERROR),
                ]);
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            fclose($stream);

            return $contents === false ? '' : $contents;
        }

        if ($format === 'yolo') {
            $classes = $objects->pluck('class_id')->unique()->sort()->values();
            $indexes = $classes->flip();
            $lines = $classes->map(fn (string $classId, int $index): string => '# class '.$index.' '.$classId)->all();
            foreach ($objects as $object) {
                if (is_array($object->bbox) && count($object->bbox) === 4) {
                    $lines[] = $indexes[$object->class_id].' '.implode(' ', $object->bbox);
                }
            }

            return implode("\n", $lines)."\n";
        }

        if ($format === 'geojson') {
            return json_encode([
                'type' => 'FeatureCollection',
                'features' => $objects->map(fn ($object): array => [
                    'type' => 'Feature',
                    'id' => $object->annotation_object_id,
                    'geometry' => is_array($object->polygon) && isset($object->polygon['type'], $object->polygon['coordinates'])
                        ? $object->polygon
                        : (is_array($object->polygon) ? ['type' => 'Polygon', 'coordinates' => $object->polygon] : null),
                    'properties' => [
                        'annotation_item_id' => $object->annotation_item_id,
                        'class_id' => $object->class_id,
                        'bbox' => $object->bbox,
                        'attributes' => $object->attributes,
                    ],
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }

        $classes = $objects->pluck('class_id')->unique()->sort()->values();
        $imageIds = $project->items->values()->mapWithKeys(
            fn ($item, int $index): array => [$item->annotation_item_id => $index + 1],
        );

        return json_encode([
            'info' => ['project_id' => $project->annotation_project_id, 'project_name' => $project->name],
            'images' => $project->items->values()->map(fn ($item, int $index): array => [
                'id' => $index + 1,
                'annotation_item_id' => $item->annotation_item_id,
                'media_asset_id' => $item->media_asset_id,
            ])->all(),
            'categories' => $classes->map(fn (string $classId, int $index): array => ['id' => $index + 1, 'name' => $classId])->all(),
            'annotations' => $objects->map(fn ($object, int $index): array => [
                'id' => $index + 1,
                'image_id' => $imageIds[$object->annotation_item_id],
                'category_id' => $classes->search($object->class_id) + 1,
                'bbox' => $object->bbox,
                'segmentation' => $object->polygon,
                'attributes' => $object->attributes,
            ])->all(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
