<?php

declare(strict_types=1);

namespace Tests\Api\V1\Controllers\Hermes;

use Log;
use Tests\TestCase;

class FinanceReportControllerTest extends TestCase
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
     * @covers \FireflyIII\Api\V1\Controllers\Hermes\FinanceReportController
     */
    public function testReportEndpointRequiresHermesToken(): void
    {
        $response = $this->post(route('api.v1.hermes.finance.reports'), ['report' => 'period_summary']);

        $response->assertStatus(401);
    }

    /**
     * @covers \FireflyIII\Api\V1\Controllers\Hermes\FinanceReportController
     */
    public function testPeriodSummaryEndpoint(): void
    {
        $response = $this->post(
            route('api.v1.hermes.finance.reports'),
            [
                'report' => 'period_summary',
                'start'  => '2030-01-01',
                'end'    => '2030-01-31',
            ],
            ['Authorization' => 'Bearer ' . self::TOKEN]
        );

        $response->assertStatus(200);
        $response->assertJson(['data' => ['report' => 'period_summary', 'errors' => []]]);
    }
}
