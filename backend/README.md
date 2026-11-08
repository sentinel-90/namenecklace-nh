# ConstructNH — бэкенд

Laravel 13, PHP 8.4 и MySQL 8.4. Docker Compose запускает локальное окружение для разработки.

## Первый запуск

Откройте терминал в папке `backend/`. Для запуска нужен работающий Docker с плагином Compose. Если используется локальный профиль Colima, запустите его командой `colima start silin`.

```sh
umask 077
if [ ! -e .env ]; then
    {
        sed '/^APP_KEY=/d; /^DB_PASSWORD=/d; /^DB_ROOT_PASSWORD=/d' .env.example
        printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
        printf 'DB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'DB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    } > .env
fi

docker compose up -d --build --wait
```

Compose дождётся готовности MySQL, после чего Laravel применит миграции. Приложение доступно на [localhost:8000](http://localhost:8000), проверка доступности — на [localhost:8000/up](http://localhost:8000/up). Если порт занят, поменяйте `APP_PORT` и адрес в `APP_URL` файла `.env`.

## Вход в админку

Создайте учётную запись сотрудника из папки `backend/`. Команда запросит имя, email и пароль.

```sh
docker compose exec app php artisan make:filament-user
```

Войдите на [localhost:8000/admin](http://localhost:8000/admin) с указанными email и паролем. У всех сотрудников одинаковый доступ ко всей админке.

## Компоненты конструктора

В разделе [«Компоненты конструктора»](http://localhost:8000/admin/catalog-items) можно добавлять, редактировать и удалять шармы, фигурки справа и снизу и цепочки. Разделы «Серебро», «Позолота», «Золото», «Белое золото» и «Красное золото» создаются миграцией. Для основного изображения и превью можно загрузить файл или указать прямую публичную HTTP(S)-ссылку. Поддерживаются JPEG, PNG и WebP до 5 МБ на файл. При импорте по ссылке сервер сохраняет копию изображения. Размеры каждого файла определяются автоматически. Цена задаётся в рублях и может быть нулевой. Поле `top` есть у фигурок справа.

Каталог доступен без авторизации по [GET /api/v1/constructor/catalog](http://localhost:8000/api/v1/constructor/catalog). В `image.path` и `preview.path` возвращаются публичные URL. Файлы хранятся в `storage/app/public/constructor-catalog/`, который Docker подключает с диска. На хостинге укажите внешний HTTPS-адрес бэкенда в `APP_URL`. При запуске без Docker выполните `php artisan storage:link` и обеспечьте сохранность файлов каталога при обновлении приложения.

Ответ каталога — объект с ключами `silver`, `gold_plated`, `gold`, `white_gold` и `red_gold`. Каждый ключ содержит массив деталей раздела, у каждой детали есть поле `type`. Если подходящих деталей нет, массив пустой. Для фильтрации передайте `type` со значением `charm`, `right_figure`, `bottom_figure` или `chain`. Например, [запрос с `type=chain`](http://localhost:8000/api/v1/constructor/catalog?type=chain) вернёт массивы цепочек. Без фильтра массивы содержат детали всех типов. Неизвестное значение даёт HTTP 422.

Для импорта по ссылке требуется PHP cURL. Если в локальном окружении системный DNS возвращает только служебные адреса из `240.0.0.0/4`, сервер уточняет публичный адрес домена через Google Public DNS.

Для создания компонента через REST API отправьте `POST /api/v1/constructor/catalog` с заголовком `Authorization: Bearer <токен>` и полями `section`, `type`, `name`, `price`, `image_url` и `preview_url`. Для фигурки справа можно передать `top`. Задайте `CATALOG_WRITE_TOKEN` в окружении сервера, чтобы включить запись через API. Цена в запросе указывается в рублях.

## Приём заказов из конструктора

Откройте [Swagger UI](http://localhost:8000/swagger/) для просмотра схемы и пробного запроса. Спецификация доступна в [OpenAPI JSON](http://localhost:8000/swagger/openapi.json).

Эндпоинт `POST /api/v1/orders` принимает JSON. Для браузерного запроса разрешён источник `https://namenecklace.ru`. На хостинге укажите фактический адрес приложения в `APP_URL`, например `https://construct.namenecklace.ru`.

- `payment` принимает `isCash` для оплаты картой и `isDolyame` для оплаты Долями.
- `price` передаётся в рублях с точностью до копейки. На этом этапе сумма берётся из запроса.
- `attachment` обязателен. Передавайте JPEG, PNG или WebP до 5 МБ как строку Base64 без префикса.
- Успешный ответ имеет статус HTTP 201 и тело вида `{"id": 1, "status": "new"}`. Ошибки валидации возвращаются с HTTP 422 и полем `errors`.

Для выбора деталей из каталога передайте `composition` с `section` (slug раздела) и полями `charm_id`, `right_figure_id`, `bottom_figure_id` и `chain_id`. Для пропуска детали опустите поле или передайте `null`. При наличии `composition` выбор берётся из этих ID. Для запроса без `composition` поддерживаются поля `charmimg`, `rightimg`, `bottomimg`, `circuit` и `noCircuit`. Длину цепочки можно передать в отдельном поле `circuitLength` независимо от выбора цепочки.

Сервер проверяет тип и раздел каждой детали. Если выбрать деталь из другого раздела или указать удалённую деталь, ответ `422` содержит ключ соответствующей позиции, например `composition.chain_id`. В админке металл определяется по разделу из снимка заказа, а фигурки и шарм показываются по своим позициям с названиями и ценами на момент оформления.

## Логи заказов

`POST /api/v1/log/{order}` принимает JSON размером до 8 МБ. Передайте номер существующего заказа в URL и заголовок `Content-Type: application/json`. Запрос сохраняет JSON в `storage/app/private/order-logs/{order}.json`, записывает символы Unicode в UTF-8 и возвращает HTTP 204. Повторный запрос заменяет файл. Docker сохраняет эту папку при пересборке контейнера.

## Работа с окружением

- После изменения PHP-кода пересоберите приложение командой `docker compose up -d --build --wait`.
- Запустите тесты командой `docker compose exec app php artisan test`. Тесты используют SQLite в памяти.
- Изображения изделий хранятся в `backend/storage/app/private/order-images/`. Docker подключает эту папку с диска, поэтому изображения сохраняются при пересборке контейнера.
- Остановите контейнеры командой `docker compose down`. MySQL хранит данные в `backend/data/` и использует эту же папку при следующем запуске. Папка данных и файл `.env` исключены из Git. MySQL применяет начальные пароли из `.env` только при создании базы.

## Граф исходного кода

Установите [Graphify](https://github.com/Graphify-Labs/graphify) через `uv` и выполните команды из папки `backend/`.

```sh
uv tool install --python 3.12 graphifyy==0.9.104
GRAPHIFY_BACKEND=none graphify extract . --code-only
GRAPHIFY_BACKEND=none graphify cluster-only .
graphify query "StoreOrderController" --depth 1
```

Если CLI уже установлен в `.graphify-tools/`, используйте `.graphify-tools/bin/graphify` вместо `graphify`. Для пересборки повторите команды `extract` и `cluster-only`. Анализ исходников выполняется локально. Правила `.graphifyignore` исключают настройки окружения, данные заказов, зависимости и результаты сборки. Отчёт `GRAPH_REPORT.md`, граф `graph.json` и интерактивный `graph.html` сохраняются в `graphify-out/`.
