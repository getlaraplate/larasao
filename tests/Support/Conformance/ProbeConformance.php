<?php

declare(strict_types=1);

namespace Modules\SAO\Tests\Support\Conformance;

use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Contracts\VersionProbeCapability;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ProbedVersion;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Models\Environment;

/**
 * The battery every `probe` driver must pass: it declares the capability and, on a
 * healthy environment, returns a well-formed {@see ProbedVersion} with a non-empty
 * version and only string-or-null commit and branch. The driver's own test fakes
 * the transport and supplies the context and the environment.
 */
final class ProbeConformance
{
    public static function assert(VersionProbeCapability&DriverInterface $driver, BindingContext $context, Environment $environment): void
    {
        expect($driver->capabilities())->toContain(Capability::Probe);

        $probed = $driver->probe($context, $environment);

        expect($probed)->toBeInstanceOf(ProbedVersion::class)
            ->and($probed->version)->toBeString()->not->toBe('');

        if ($probed->commit !== null) {
            expect($probed->commit)->toBeString()->not->toBe('');
        }

        if ($probed->branch !== null) {
            expect($probed->branch)->toBeString()->not->toBe('');
        }
    }
}
