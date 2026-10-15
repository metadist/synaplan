<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Support;

use App\Controller\ConfigController;
use App\Service\Capability\CapabilityService;
use App\Service\Config\FeatureStatusReporter;
use App\Service\Config\Runtime\RuntimeConfigAssembler;
use App\Service\LocalAi\LocalAiDownloadStatusService;
use App\Service\Memory\MemoryServiceStatus;
use App\Service\Model\ModelAvailabilityCheck;
use App\Service\Model\ModelDefaultsService;
use App\Service\Model\ModelListingService;
use App\Service\Model\RoutingModelsService;

/**
 * A ConfigController whose collaborators are passive stubs, except the
 * services a test hands in. The controller only maps HTTP, so tests pass
 * real services built around mocked repositories and assert status codes
 * and payloads end to end.
 */
trait BuildsConfigController
{
    private function buildConfigController(
        ?ModelDefaultsService $modelDefaults = null,
        ?RoutingModelsService $routingModels = null,
    ): ConfigController {
        return new ConfigController(
            $this->createStub(RuntimeConfigAssembler::class),
            new CapabilityService(),
            $this->createStub(FeatureStatusReporter::class),
            $this->createStub(MemoryServiceStatus::class),
            $this->createStub(LocalAiDownloadStatusService::class),
            $this->createStub(ModelListingService::class),
            $modelDefaults ?? $this->createStub(ModelDefaultsService::class),
            $this->createStub(ModelAvailabilityCheck::class),
            $routingModels ?? $this->createStub(RoutingModelsService::class),
        );
    }
}
