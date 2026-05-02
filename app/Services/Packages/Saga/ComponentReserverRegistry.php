<?php

namespace App\Services\Packages\Saga;

use App\Models\SagaComponentState;
use App\Services\Packages\Saga\Contracts\ComponentReserverInterface;
use App\Services\Packages\Saga\Reservers\StubComponentReserver;
use InvalidArgumentException;

/**
 * Registry mapping service_type → ComponentReserverInterface implementation.
 *
 * Default: every supported service type is bound to a StubComponentReserver
 * (always succeeds). Real supplier integrations replace stubs by calling
 * register() before the saga runs (e.g., from a service provider or tests).
 */
class ComponentReserverRegistry
{
    /** @var array<string, ComponentReserverInterface> */
    private array $reservers = [];

    public function __construct()
    {
        foreach (SagaComponentState::SERVICE_TYPES as $type) {
            $this->reservers[$type] = new StubComponentReserver($type);
        }
    }

    public function register(string $serviceType, ComponentReserverInterface $reserver): void
    {
        if (! in_array($serviceType, SagaComponentState::SERVICE_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported service type: {$serviceType}");
        }

        $this->reservers[$serviceType] = $reserver;
    }

    public function for(string $serviceType): ComponentReserverInterface
    {
        if (! isset($this->reservers[$serviceType])) {
            throw new InvalidArgumentException("No reserver registered for service type: {$serviceType}");
        }

        return $this->reservers[$serviceType];
    }

    /**
     * @return array<string, ComponentReserverInterface>
     */
    public function all(): array
    {
        return $this->reservers;
    }
}
