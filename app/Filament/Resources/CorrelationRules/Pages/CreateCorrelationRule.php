<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\CorrelationRules\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\SAO\Filament\Resources\CorrelationRules\CorrelationRuleResource;
use Override;

final class CreateCorrelationRule extends CreateRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = CorrelationRuleResource::class;
}
