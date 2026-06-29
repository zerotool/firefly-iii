<?php

declare(strict_types=1);

Route::group(
    ['namespace' => 'FireflyIII\Api\V1\Controllers\Hermes', 'as' => 'api.v1.hermes.finance.'],
    function () {
        Route::get('ping', ['uses' => 'FinanceHealthController@ping', 'as' => 'ping']);
        Route::post('resolve', ['uses' => 'FinanceResolverController@resolve', 'as' => 'resolve']);
    }
);
