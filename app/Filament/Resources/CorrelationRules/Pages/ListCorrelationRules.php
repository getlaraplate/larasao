<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\CorrelationRules\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Core\Filament\Utils\HasRecords;
use Modules\SAO\Filament\Resources\CorrelationRules\CorrelationRuleResource;
use Override;

final class ListCorrelationRules extends ListRecords
{
    use HasRecords;

    #[Override]
    protected static string $resource = CorrelationRuleResource::class;
}
