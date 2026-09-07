<?php

namespace App\Filament\Resources;

use Filament\Schemas\Components\Utilities\Set;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;

use App\Filament\Resources\HelpArticleResource\Pages;
use App\Models\HelpArticle;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class HelpArticleResource extends BaseResource
{
    protected static ?string $model = HelpArticle::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Help Center';

    protected static ?int $navigationSort = 13;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Article Content')
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (string $operation, $state, Set $set) {
                                        if ($operation === 'create') {
                                            $set('slug', Str::slug($state));
                                        }
                                    }),

                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),

                                Forms\Components\RichEditor::make('content')
                                    ->required()
                                    ->fileAttachmentsDisk('public')
                                    ->fileAttachmentsDirectory('help-articles')
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('excerpt')
                                    ->maxLength(65535)
                                    ->columnSpanFull(),
                            ]),

                        Section::make('SEO Information')
                            ->schema([
                                Forms\Components\TextInput::make('meta_title')
                                    ->maxLength(255),

                                Forms\Components\Textarea::make('meta_description')
                                    ->maxLength(65535),

                                Forms\Components\TagsInput::make('meta_keywords')
                                    ->separator(',')
                                    ->splitKeys(['Tab', ' ', ','])
                                    ->helperText('Press Tab, Space or Comma to add a keyword'),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make('Organization')
                            ->schema([
                                Forms\Components\Select::make('category_id')
                                    ->relationship('category', 'name')
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('tags')
                                    ->relationship('tags', 'name')
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn($state, Set $set) => $set('slug', Str::slug($state))),
                                        Forms\Components\TextInput::make('slug')
                                            ->required()
                                            ->maxLength(255),
                                    ]),

                                Forms\Components\TextInput::make('order')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0),
                            ]),

                        Section::make('Publishing')
                            ->schema([
                                Forms\Components\Toggle::make('is_published')
                                    ->label('Published')
                                    ->default(false),

                                Forms\Components\DateTimePicker::make('published_at')
                                    ->label('Publish Date')
                                    ->default(now()),

                                Forms\Components\Toggle::make('is_featured')
                                    ->label('Featured')
                                    ->default(false),
                            ]),

                        Section::make('Related Articles')
                            ->schema([
                                Forms\Components\Select::make('related_articles')
                                    ->multiple()
                                    ->options(fn(?HelpArticle $record) => HelpArticle::where('id', '!=', $record?->id ?? 0)
                                        ->get()
                                        ->mapWithKeys(function ($article) {
                                            return [$article->id => $article->title ?? 'Unknown'];
                                        }))
                                    ->searchable()
                                    ->preload(),
                            ]),

                        Section::make('Statistics')
                            ->schema([
                                Forms\Components\Placeholder::make('view_count')
                                    ->label('Views')
                                    ->content(fn(?HelpArticle $record): string => $record ? number_format($record->view_count) : '0'),

                                Forms\Components\Placeholder::make('helpful_percentage')
                                    ->label('Helpful Rating')
                                    ->content(fn(?HelpArticle $record): string => $record ? $record->getHelpfulPercentage() . '%' : '0%'),

                                Forms\Components\Placeholder::make('reading_time')
                                    ->label('Reading Time')
                                    ->content(fn(?HelpArticle $record): string => $record ? $record->getReadingTime() . ' min' : '0 min'),

                                Forms\Components\Placeholder::make('created_at')
                                    ->label('Created at')
                                    ->content(fn(?HelpArticle $record): string => $record ? $record->created_at->diffForHumans() : 'Not created yet'),

                                Forms\Components\Placeholder::make('updated_at')
                                    ->label('Last modified at')
                                    ->content(fn(?HelpArticle $record): string => $record ? $record->updated_at->diffForHumans() : 'Not modified yet'),
                            ])
                            ->hidden(fn(?HelpArticle $record) => $record === null),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('view_count')
                    ->label('Views')
                    ->sortable(),

                Tables\Columns\TextColumn::make('helpful_percentage')
                    ->label('Helpful')
                    ->formatStateUsing(fn(HelpArticle $record): string => $record->getHelpfulPercentage() . '%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown'),

                Tables\Filters\SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->name ?? 'Unknown')
                    ->multiple(),

                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('Published')
                    ->boolean()
                    ->trueLabel('Published Only')
                    ->falseLabel('Drafts Only')
                    ->native(false),

                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->trueLabel('Featured Only')
                    ->falseLabel('Non-Featured Only')
                    ->native(false),
            ])
            ->actions([
                 EditAction::make(),
                 Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn(HelpArticle $record) => route('help.articles.show', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                 BulkActionGroup::make([
                     DeleteBulkAction::make(),
                     BulkAction::make('publish')
                        ->label('Publish Selected')
                        ->icon('heroicon-o-check')
                        ->action(fn($records) => $records->each->update([
                            'is_published' => true,
                            'published_at' => now(),
                        ]))
                        ->requiresConfirmation(),
                     BulkAction::make('unpublish')
                        ->label('Unpublish Selected')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn($records) => $records->each->update([
                            'is_published' => false,
                            'published_at' => null,
                        ]))
                        ->requiresConfirmation(),
                ]),
            ])
            ->defaultSort('published_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHelpArticles::route('/'),
            'create' => Pages\CreateHelpArticle::route('/create'),
            'edit' => Pages\EditHelpArticle::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}
