<?php

declare(strict_types=1);

namespace Pko\AdminNav\Filament\Widgets;

use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Pko\StorefrontCms\Filament\Resources\HomeSlideResource;
use Pko\StorefrontCms\Models\HomeSlide;

class HomeSlidesTable extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return HomeSlideResource::table(
            $table
                ->query(HomeSlide::query())
                ->heading(null)
                ->headerActions([
                    Action::make('create')
                        ->label(__('admin-nav::admin.hubs.homepage.actions.create_slide'))
                        ->icon('heroicon-m-plus')
                        ->url(HomeSlideResource::getUrl('index')),
                ])
        );
    }
}
