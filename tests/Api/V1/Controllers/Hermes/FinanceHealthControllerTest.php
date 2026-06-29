<?php

declare(strict_types=1);

namespace Tests\Api\V1\Controllers\Hermes;

use Log;
use Tests\TestCase;

class FinanceHealthControllerTest extends TestCase
{
    private const TOKEN = 'test-hermes-token';

    public function setUp(): void
    {
        parent::setUp();
        Log::info(sprintf('Now in %s.', \get_class($this)));

        config()->set('hermes_finance.enabled', true);
        config()->set('hermes_finance.token_hash', hash('sha256', self::TOKEN));
        config()->set('hermes_finance.ledger_user_id', $this->user()->id);
        config()->set('hermes_finance.allowed_cidrs', []);
    }

    /**
     * @covers \FireflyIII\Api\V1\Controllers\Hermes\FinanceHealthController
     * @covers \FireflyIII\Http\Middleware\HermesFinance
     */
    public function testPing(): void
    {
        $response = $this->get(
            route('api.v1.hermes.finance.ping'),
            ['Authorization' => 'Bearer ' . self::TOKEN]
        );

        $response->assertStatus(200);
        $response->assertJson(
            [
                'data' => [
                    'ok'             => true,
                    'ledger_user_id' => $this->user()->id,
                ],
            ]
        );
    }

    /**
     * @covers \FireflyIII\Http\Middleware\HermesFinance
     */
    public function testPingRejectsMissingToken(): void
    {
        $response = $this->get(route('api.v1.hermes.finance.ping'));

        $response->assertStatus(401);
    }

    /**
     * @covers \FireflyIII\Http\Middleware\HermesFinance
     */
    public function testPingRejectsWrongToken(): void
    {
        $response = $this->get(
            route('api.v1.hermes.finance.ping'),
            ['Authorization' => 'Bearer wrong-token']
        );

        $response->assertStatus(401);
    }

    /**
     * @covers \FireflyIII\Http\Middleware\HermesFinance
     */
    public function testPingRejectsDisabledApi(): void
    {
        config()->set('hermes_finance.enabled', false);

        $response = $this->get(
            route('api.v1.hermes.finance.ping'),
            ['Authorization' => 'Bearer ' . self::TOKEN]
        );

        $response->assertStatus(503);
    }

    /**
     * @covers \FireflyIII\Http\Middleware\HermesFinance
     */
    public function testPingRejectsDisallowedSource(): void
    {
        config()->set('hermes_finance.allowed_cidrs', ['203.0.113.1/32']);

        $response = $this->get(
            route('api.v1.hermes.finance.ping'),
            ['Authorization' => 'Bearer ' . self::TOKEN]
        );

        $response->assertStatus(403);
    }
}
