<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\CorrelationRules;

use BackedEnum;
use Coolsam\Modules\Resource;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Modules\Core\Filament\Utils\HasTranslatedResourceLabels;
use Modules\SAO\Filament\Resources\CorrelationRules\Pages\CreateCorrelationRule;
use Modules\SAO\Filament\Resources\CorrelationRules\Pages\EditCorrelationRule;
use Modules\SAO\Filament\Resources\CorrelationRules\Pages\ListCorrelationRules;
use Modules\SAO\Filament\Resources\CorrelationRules\Schemas\CorrelationRuleForm;
use Modules\SAO\Filament\Resources\CorrelationRules\Tables\CorrelationRulesTable;
use Modules\SAO\Models\CorrelationRule;
use Override;
use UnitEnum;

/**
 * The authoring surface for correlation rules: an operator maps a canonical
 * field of an incoming event (the project key, the server host, the host of the
 * request url) to a project and, optionally, an environment name. Rules are
 * evaluated by priority, lower first, then by id, and may be scoped to one
 * connection and/or one source profile.
 */
final class CorrelationRuleResource extends Resource
{
    use HasTranslatedResourceLabels;

    protected static ?string $model = CorrelationRule::class;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'sao.navigation.signals';

    #[Override]
    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $recordTitleAttribute = 'pattern';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'sao/correlation-rules';
    }

    public static function form(Schema $schema): Schema
    {
        return CorrelationRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CorrelationRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCorrelationRules::route('/'),
            'create' => CreateCorrelationRule::route('/create'),
            'edit' => EditCorrelationRule::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
