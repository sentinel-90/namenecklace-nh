@if ($imageUrl)
    <img
        src="{{ $imageUrl }}"
        alt="{{ $label }}"
        referrerpolicy="no-referrer"
        style="display: block; margin: auto; max-width: 100%; max-height: 65vh; object-fit: contain;{{ ($enlarged ?? false) ? ' width: 100%; height: 65vh;' : '' }}"
    >
@else
    <p>Изображение недоступно.</p>
@endif
