<div class="space-y-2">
    <h3 class="font-semibold">Состав изделия на момент оформления</h3>
    <dl class="space-y-2">
        <div>
            <dt class="font-medium">Металл</dt>
            <dd>{{ $composition['section_name'] }}</dd>
        </div>

        @foreach ([\App\Enums\CatalogItemType::RightFigure, \App\Enums\CatalogItemType::BottomFigure, \App\Enums\CatalogItemType::Charm, \App\Enums\CatalogItemType::Chain] as $type)
            @php($item = $composition['items'][$type->value] ?? null)
            <div>
                <dt class="font-medium">{{ $type->getLabel() }}</dt>
                <dd>
                    @if ($item === null)
                        Не выбрано
                    @else
                        <span>{{ $item['name'] }} (#{{ $item['id'] }})</span>
                        <span>· {{ \Illuminate\Support\Number::currency($item['price_kopecks'] / 100, 'RUB', locale: 'ru') }}</span>
                        @if ($type === \App\Enums\CatalogItemType::RightFigure)
                            <span>· {{ $item['top'] ? 'Расположение сверху' : 'Расположение по умолчанию' }}</span>
                        @endif
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</div>
