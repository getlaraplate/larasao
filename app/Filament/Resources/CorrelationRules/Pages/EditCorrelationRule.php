<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\CorrelationRules\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Utils\HasCloseOrCancelFormAction;
use Modules\SAO\Filament\Resources\CorrelationRules\CorrelationRuleResource;
use Override;

final class EditCorrelationRule extends EditRecord
{
    use HasCloseOrCancelFormAction;

    #[Override]
    protected static string $resource = CorrelationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
