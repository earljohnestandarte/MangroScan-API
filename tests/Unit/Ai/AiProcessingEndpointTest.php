<?php

namespace Tests\Unit\Ai;

use App\Exceptions\DownstreamServiceException;
use App\Services\Ai\AiProcessingService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AiProcessingEndpointTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function supportedCapabilities(): iterable
    {
        yield 'image detection' => ['detection.image', '/api/v1/detect/image'];
        yield 'video detection' => ['detection.video', '/api/v1/detect/video'];
        yield 'image classification' => ['classification.image', '/api/v1/classify/image'];
        yield 'video classification' => ['classification.video', '/api/v1/classify/video'];
        yield 'image full analysis' => ['analysis.image', '/api/v1/analyze/image'];
        yield 'video full analysis' => ['analysis.video', '/api/v1/analyze/video'];
    }

    #[DataProvider('supportedCapabilities')]
    public function test_it_maps_capabilities_to_the_fastapi_contract(string $capability, string $expected): void
    {
        $method = new ReflectionMethod(AiProcessingService::class, 'endpoint');
        $service = (new ReflectionClass(AiProcessingService::class))->newInstanceWithoutConstructor();

        $this->assertSame($expected, $method->invoke($service, $capability));
    }

    public function test_it_rejects_an_unsupported_capability(): void
    {
        $method = new ReflectionMethod(AiProcessingService::class, 'endpoint');
        $service = (new ReflectionClass(AiProcessingService::class))->newInstanceWithoutConstructor();

        $this->expectException(DownstreamServiceException::class);
        $this->expectExceptionMessage('Unsupported AI capability.');

        $method->invoke($service, 'segmentation.image');
    }
}
