@php
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Js;

    $record = $getRecord();
    $status = $record->status;
    $action = (clone $getAction())->record($record);
    $context = Js::from(['table' => true, 'recordKey' => $getRecordKey()]);
@endphp

@if ($action->isVisible())
    <x-filament::dropdown placement="bottom-start" teleport shift>
        <x-slot name="trigger">
            <x-filament::button color="gray" size="sm" :icon="Heroicon::ChevronDown" icon-position="after">
                {{ $status->getLabel() }}
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($status->nextStatuses($record->delivery_method) as $nextStatus)
                @php
                    $arguments = Js::from(['status' => $nextStatus->value]);
                    $clickHandler = "mountAction('changeStatus', {$arguments}, {$context})";
                @endphp

                <x-filament::dropdown.list.item
                    :wire:click.stop="$clickHandler"
                    x-on:click="close()"
                >
                    {{ $nextStatus->getLabel() }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@else
    <x-filament::badge color="gray">{{ $status->getLabel() }}</x-filament::badge>
@endif
