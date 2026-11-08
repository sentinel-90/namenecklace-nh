@if ($json === null)
    <p>Лог для этого заказа ещё не сохранён.</p>
@else
    <x-filament::input.wrapper class="fi-fo-textarea">
        <textarea readonly rows="16" wrap="off" aria-label="JSON лога заказа" spellcheck="false"
            style="width: 100%; font-family: monospace; resize: vertical;">{{ $json }}</textarea>
    </x-filament::input.wrapper>
@endif
