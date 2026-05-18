<?php

declare(strict_types=1);

namespace Pko\AdminNav\Filament\Widgets;

use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Pko\StorefrontCms\Filament\Resources\HomeOfferResource;
use Pko\StorefrontCms\Models\HomeOffer;

class HomeOffersTable extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return HomeOfferResource::table(
            $table
                ->query(HomeOffer::query())
                ->heading(null)
                ->headerActions([
                    Action::make('create')
                        ->label(__('admin-nav::admin.hubs.homepage.actions.create_offer'))
                        ->icon('heroicon-m-plus')
                        ->url(HomeOfferResource::getUrl('index')),
                ])
        );
    }
}
