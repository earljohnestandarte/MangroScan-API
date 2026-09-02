<?php

namespace Tests\Feature\Media;

use App\Contracts\Media\PrivateDownloadUrlIssuer;
use App\Exceptions\DownstreamServiceException;
use App\Services\Media\FilesystemPrivateDownloadUrlIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\BuildsApiIdentity;
use Tests\TestCase;

class MediaDownloadTest extends TestCase
{
    use BuildsApiIdentity;
    use RefreshDatabase;

    public function test_it_issues_an_exact_temporary_private_url(): void
    {
        Carbon::setTestNow('2026-09-02T00:00:00Z');
        config(['mangroscan.media.disk' => 'local', 'mangroscan.media.download_url_ttl_minutes' => 8]);
        $graph = $this->mediaGraph('download-', ['media.read']);
        $issuer = Mockery::mock(PrivateDownloadUrlIssuer::class);
        $issuer->shouldReceive('issue')->once()->with(
            'local',
            $graph['storage_key'],
            Mockery::on(fn ($expires): bool => Carbon::parse($expires)->equalTo('2026-09-02T00:08:00Z')),
        )->andReturn(['url' => 'https://storage.test/media?signature=safe']);
        $this->app->instance(PrivateDownloadUrlIssuer::class, $issuer);

        $this->withToken($graph['token'])
            ->withHeader('X-Request-ID', 'req_media_05')
            ->postJson('/api/v1/media/'.$graph['media_id'].'/download')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'url' => 'https://storage.test/media?signature=safe',
                    'expires_at' => '2026-09-02T00:08:00+00:00',
                ],
                'meta' => ['request_id' => 'req_media_05'],
            ]);
    }

    public function test_it_enforces_scope_access_and_maps_storage_failure(): void
    {
        $graph = $this->mediaGraph('download-access-', ['media.read']);
        $this->postJson('/api/v1/media/'.$graph['media_id'].'/download')->assertUnauthorized();

        $issuer = Mockery::mock(PrivateDownloadUrlIssuer::class);
        $issuer->shouldReceive('issue')->once()->andThrow(
            new DownstreamServiceException('Unavailable', 503, 'SERVICE_UNAVAILABLE'),
        );
        $this->app->instance(PrivateDownloadUrlIssuer::class, $issuer);
        $this->withToken($graph['token'])->postJson('/api/v1/media/'.$graph['media_id'].'/download')
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');

        $foreign = $this->mediaGraph('download-foreign-', []);
        $this->withToken($graph['token'])->postJson('/api/v1/media/'.$foreign['media_id'].'/download')
            ->assertNotFound();
    }

    public function test_filesystem_issuer_rejects_a_missing_object(): void
    {
        Storage::fake('local');
        $this->expectException(DownstreamServiceException::class);
        $this->expectExceptionMessage('The media object is unavailable.');
        (new FilesystemPrivateDownloadUrlIssuer)->issue('local', 'missing.jpg', now()->addMinutes(10));
    }

    /** @param list<string> $permissions @return array{media_id:string,storage_key:string,token:string} */
    private function mediaGraph(string $prefix, array $permissions): array
    {
        $identity = $this->apiIdentity($permissions, $prefix);
        $lineage = $this->missionLineage($identity['organization_id'], $identity['actor_id'], Str::upper($prefix));
        $mediaId = (string) Str::uuid();
        $storageKey = 'private/'.$mediaId.'/capture.jpg';
        DB::table('media_assets')->insert([
            'media_asset_id' => $mediaId,
            'flight_session_id' => $lineage['flight_id'],
            'uploaded_by_user_id' => $identity['actor_id'],
            'file_name' => 'capture.jpg',
            'file_type' => 'image',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 128,
            'storage_key' => $storageKey,
            'quality_status' => 'acceptable',
            'processing_status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['media_id' => $mediaId, 'storage_key' => $storageKey, 'token' => $identity['token']];
    }
}
