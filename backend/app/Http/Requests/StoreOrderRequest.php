<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Support\RubleAmount;
use App\Support\StorefrontImage;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public const PAYMENT_METHODS = [
        'isCash' => PaymentMethod::Card,
        'isDolyame' => PaymentMethod::Dolyame,
    ];

    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public static function deliveryMethods(): array
    {
        $methods = [];

        foreach (DeliveryMethod::cases() as $method) {
            $methods[$method->getLabel()] = $method;
        }

        $methods['Доставка в Санкт Петербург'] = DeliveryMethod::SaintPetersburg;

        return $methods;
    }

    protected function prepareForValidation(): void
    {
        abort_unless($this->isJson(), 415, 'Отправьте заказ в формате JSON.');
    }

    public function validationData(): array
    {
        return $this->json()->all();
    }

    public function rules(): array
    {
        $imageUrl = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! StorefrontImage::isUrl($value)) {
                $fail('Укажите HTTPS-ссылку на изображение с namenecklace.ru.');
            }
        };

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32', 'regex:'.Order::PHONE_PATTERN],
            'delivery' => ['required', 'string', Rule::in(array_keys(self::deliveryMethods()))],
            'address' => [
                'nullable',
                'string',
                'max:500',
                Rule::requiredIf(fn (): bool => filled($this->json('delivery')) && $this->json('delivery') !== DeliveryMethod::Pickup->getLabel()),
            ],
            'payment' => ['required', 'string', Rule::in(array_keys(self::PAYMENT_METHODS))],
            'price' => ['required', 'numeric', 'regex:'.RubleAmount::INPUT_PATTERN],
            'promocode' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:10000'],
            'text' => ['nullable', 'string', 'max:255'],
            'textFont' => ['nullable', 'string', 'max:255'],
            'metaltype' => ['exclude_with:composition', 'nullable', 'string', 'max:255'],
            'coating' => ['nullable', 'string', 'max:255'],
            'sparking' => ['sometimes', 'boolean'],
            'composition' => ['sometimes', 'required', 'array:section,charm_id,right_figure_id,bottom_figure_id,chain_id'],
            'composition.section' => ['required_with:composition', 'string', 'max:32'],
            'composition.charm_id' => ['nullable', 'integer:strict', 'min:1'],
            'composition.right_figure_id' => ['nullable', 'integer:strict', 'min:1'],
            'composition.bottom_figure_id' => ['nullable', 'integer:strict', 'min:1'],
            'composition.chain_id' => ['nullable', 'integer:strict', 'min:1'],
            'rightimg' => ['exclude_with:composition', 'bail', 'nullable', 'string', 'max:2048', $imageUrl],
            'bottomimg' => ['exclude_with:composition', 'bail', 'nullable', 'string', 'max:2048', $imageUrl],
            'charmimg' => ['exclude_with:composition', 'bail', 'nullable', 'string', 'max:2048', $imageUrl],
            'circuit' => ['exclude_with:composition', 'nullable', 'string', 'max:255'],
            'circuitLength' => ['nullable', 'string', 'max:64'],
            'noCircuit' => ['exclude_with:composition', 'sometimes', 'boolean'],
            'stones' => ['nullable', 'array', 'list'],
            'stones.*' => ['array:type,size,image,x,y,width,height,color'],
            'stones.*.type' => ['required', 'string', Rule::in(['diamonds', 'swarovski'])],
            'stones.*.size' => ['required', 'string', 'max:64'],
            'stones.*.image' => [
                'bail', 'nullable', 'string', 'max:2048',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! StorefrontImage::isPathOrUrl($value)) {
                        $fail('Укажите путь или HTTPS-ссылку на изображение камня с namenecklace.ru.');
                    }
                },
            ],
            'stones.*.x' => ['nullable', 'numeric'],
            'stones.*.y' => ['nullable', 'numeric'],
            'stones.*.width' => ['nullable', 'numeric', 'min:0'],
            'stones.*.height' => ['nullable', 'numeric', 'min:0'],
            'stones.*.color' => ['exclude_unless:stones.*.type,swarovski', 'required', 'string', 'max:64'],
            'attachment' => ['required', 'string', 'max:'.(4 * intdiv(self::MAX_IMAGE_BYTES + 2, 3))],
        ];
    }

    public function messages(): array
    {
        return [
            'price.regex' => 'Укажите цену в рублях с точностью до копейки.',
            'attachment.required' => 'Приложите изображение изделия в Base64.',
            'attachment.max' => 'Размер изображения изделия должен быть не больше 5 МБ.',
        ];
    }
}
