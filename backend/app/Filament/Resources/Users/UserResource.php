<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\UserAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'пользователь';

    protected static ?string $pluralModelLabel = 'Пользователи';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Имя')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Адрес электронной почты')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(),
            self::passwordInput()->label('Пароль'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->description(fn (User $record): ?string => $record->is(Filament::auth()->user()) ? 'Ваша учётная запись' : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')->label('Адрес электронной почты')->searchable()->sortable(),
                TextColumn::make('is_blocked')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Заблокирован' : 'Активен')
                    ->color(fn (bool $state): string => $state ? 'danger' : 'success'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('changePassword')
                        ->label('Сменить пароль')
                        ->icon(Heroicon::Key)
                        ->authorize('changePassword')
                        ->authorizationTooltip()
                        ->modalHeading(fn (User $record): string => "Смена пароля для {$record->name}")
                        ->modalSubmitActionLabel('Сменить пароль')
                        ->schema([
                            self::passwordInput()
                                ->label('Новый пароль')
                                ->regex('/\A[^\x00]{1,72}\z/')
                                ->validationMessages(['regex' => 'Используйте пароль размером до 72 байт без нулевых символов.']),
                            TextInput::make('password_confirmation')
                                ->label('Подтвердите новый пароль')
                                ->password()
                                ->autocomplete('new-password')
                                ->required()
                                ->same('password')
                                ->validationMessages(['same' => 'Пароли не совпадают.'])
                                ->saved(false),
                        ])
                        ->successNotificationTitle('Пароль изменён')
                        ->action(function (User $record, array $data, UserAccess $access, Action $action): void {
                            $guard = Filament::auth();
                            $employee = $guard->user();
                            $user = $access->changePassword($employee, $record, $data['password']);

                            if ($employee->is($user)) {
                                $guard->login($user);
                                $action->successRedirectUrl(self::getUrl('index'));
                            }

                            $action->success();
                        }),
                    Action::make('block')
                        ->label('Заблокировать')
                        ->color('danger')
                        ->authorize('manageAccess')
                        ->authorizationTooltip()
                        ->visible(fn (User $record): bool => ! $record->is_blocked)
                        ->requiresConfirmation()
                        ->modalSubmitActionLabel('Заблокировать')
                        ->action(fn (User $record, UserAccess $access) => $access->setBlocked(Filament::auth()->user(), $record, true)),
                    Action::make('unblock')
                        ->label('Разблокировать')
                        ->authorize('manageAccess')
                        ->visible(fn (User $record): bool => $record->is_blocked)
                        ->action(fn (User $record, UserAccess $access) => $access->setBlocked(Filament::auth()->user(), $record, false)),
                    DeleteAction::make()
                        ->label('Удалить')
                        ->authorizationTooltip()
                        ->using(fn (User $record, UserAccess $access): bool => $access->delete(Filament::auth()->user(), $record)),
                ])
                    ->button()
                    ->label('Действие')
                    ->color('gray')
                    ->icon(Heroicon::ChevronDown)
                    ->iconPosition(IconPosition::After),
            ])
            ->recordActionsColumnLabel('Действия');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }

    private static function passwordInput(): TextInput
    {
        return TextInput::make('password')
            ->password()
            ->autocomplete('new-password')
            ->required()
            ->minLength(8)
            ->maxLength(72);
    }
}
