<?php

use FireflyIII\Models\AccountType;

return [
    'actors' => [
        [
            'name' => 'Стас',
            'aliases' => ['я', 'мне', 'мой', 'моя', 'мою', 'стас', 'станислав'],
            'account_types' => [AccountType::EXPENSE, AccountType::REVENUE],
            'kassa_ids' => ['6775FCDE-9A51-4500-996E-17DC43721DAD'],
        ],
        [
            'name' => 'Таня',
            'aliases' => ['таня', 'тане', 'тани'],
            'account_types' => [AccountType::EXPENSE, AccountType::REVENUE],
            'kassa_ids' => ['D61CE6FA-A05A-4766-87D8-1F120950044A'],
        ],
        [
            'name' => 'Казанская 8-10',
            'aliases' => ['казанская 8-10', 'казанская 8', 'гостевые комнаты'],
            'account_types' => [AccountType::EXPENSE, AccountType::REVENUE],
            'kassa_ids' => ['7B7CDEDF-D4EC-4F98-9B3C-F08252D4CAA8'],
        ],
        [
            'name' => 'Казанская 23',
            'aliases' => ['казанская 23'],
            'account_types' => [AccountType::EXPENSE, AccountType::REVENUE],
            'kassa_ids' => ['DA43FC53-66A6-419A-8125-63884E462575'],
        ],
    ],

    'asset_accounts' => [
        [
            'name' => 'Стас / CGD (ЕВРО)',
            'aliases' => ['cgd', 'цгд', 'cgd евро', 'карта cgd'],
        ],
        [
            'name' => 'Наличные евро',
            'aliases' => ['наличные евро', 'кэш евро', 'кеш евро', 'cash eur'],
        ],
    ],

    'hotels' => [
        [
            'name' => 'Казанская 8-10',
            'aliases' => ['казанская 8-10', 'казанская 8', 'гостевые комнаты'],
            'kassa_ids' => ['7B7CDEDF-D4EC-4F98-9B3C-F08252D4CAA8'],
        ],
        [
            'name' => 'Казанская 23',
            'aliases' => ['казанская 23'],
            'kassa_ids' => ['DA43FC53-66A6-419A-8125-63884E462575'],
        ],
    ],

    'categories' => [
        ['name' => 'Еда', 'aliases' => ['еда', 'еду', 'продукты', 'кафе', 'ресторан'], 'kassa_ids' => ['1']],
        ['name' => 'Здоровье', 'aliases' => ['здоровье', 'аптека', 'врач'], 'kassa_ids' => ['3']],
        ['name' => 'Транспорт', 'aliases' => ['транспорт', 'такси', 'метро'], 'kassa_ids' => ['9']],
        ['name' => 'Развлечения', 'aliases' => ['развлечения', 'кино'], 'kassa_ids' => ['6']],
        ['name' => 'Квартплата', 'aliases' => ['квартплата', 'коммуналка'], 'kassa_ids' => ['10']],
        ['name' => 'Зарплата', 'aliases' => ['зарплата', 'зп'], 'kassa_ids' => ['17']],
    ],

    'budgets' => [
        [
            'name' => 'Стас и Таня в Португалии (EUR)',
            'aliases' => ['стас и таня в португалии', 'португалия', 'текущий бюджет стас и таня'],
        ],
    ],

    'currencies' => [
        ['name' => 'EUR', 'aliases' => ['eur', 'евро', '€']],
        ['name' => 'RUB', 'aliases' => ['rub', 'руб', 'рубль', 'рубли', '₽']],
        ['name' => 'USD', 'aliases' => ['usd', 'доллар', 'доллары', '$']],
    ],
];
