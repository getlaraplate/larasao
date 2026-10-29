<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Contracts;

use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ProbedVersion;
use Modules\SAO\Exceptions\ProbeException;
use Modules\SAO\Models\Environment;

/**
 * Ask a running environment which version (and optionally commit and branch) it
 * is serving: the active counterpart to the deploy webhooks that feed the census.
 *
 * The probe is fed only by the environment's operator-set address, never by an
 * address found in an event payload. A driver that cannot tell returns null; one
 * that tried and failed throws a {@see ProbeException} whose message is a short,
 * secret-free reason the caller can print.
 */
interface VersionProbeCapability
{
    /**
     * @throws ProbeException
     */
    public function probe(BindingContext $context, Environment $environment): ?ProbedVersion;
}
