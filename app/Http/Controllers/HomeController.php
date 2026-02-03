<?php
/**
 * HomeController.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Events\RequestedVersionCheckStatus;
use FireflyIII\Helpers\Collector\TransactionCollectorInterface;
use FireflyIII\Http\Middleware\Installer;
use FireflyIII\Models\AccountType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Log;

/**
 * Class HomeController.
 */
class HomeController extends Controller
{
    /**
     * HomeController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        app('view')->share('title', 'Firefly III');
        app('view')->share('mainTitleIcon', 'fa-fire');
        $this->middleware(Installer::class);
    }

    /**
     * Change index date range.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function dateRange(Request $request): JsonResponse
    {
        $start         = new Carbon($request->get('start'));
        $end           = new Carbon($request->get('end'));
        $label         = $request->get('label');
        $isCustomRange = false;

        Log::debug('Received dateRange', ['start' => $request->get('start'), 'end' => $request->get('end'), 'label' => $request->get('label')]);


        // check if the label is "everything" or "Custom range" which will betray
        // a possible problem with the budgets.
        if ($label === (string)trans('firefly.everything') || $label === (string)trans('firefly.customRange')) {
            $isCustomRange = true;
            Log::debug('Range is now marked as "custom".');
        }

        $diff = $start->diffInDays($end);

        if ($diff > 50) {
            $request->session()->flash('warning', (string)trans('firefly.warning_much_data', ['days' => $diff]));
        }

        $request->session()->put('is_custom_range', $isCustomRange);
        Log::debug(sprintf('Set is_custom_range to %s', var_export($isCustomRange, true)));
        $request->session()->put('start', $start);
        Log::debug(sprintf('Set start to %s', $start->format('Y-m-d H:i:s')));
        $request->session()->put('end', $end);
        Log::debug(sprintf('Set end to %s', $end->format('Y-m-d H:i:s')));

        return response()->json(['ok' => 'ok']);
    }


    /**
     * Show index.
     *
     * @param AccountRepositoryInterface $repository
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     */
    public function index(AccountRepositoryInterface $repository)
    {
        $types = config('firefly.accountTypesByIdentifier.asset');
        $count = $repository->count($types);

        Log::channel('audit')->info('User visits homepage.');

        if (0 === $count) {
            return redirect(route('new-user.index'));
        }
        $subTitle     = (string)trans('firefly.welcomeBack');
        $transactions = [];
        $frontPage    = app('preferences')->get(
            'frontPageAccounts', $repository->getAccountsByType([AccountType::DEFAULT, AccountType::ASSET])->pluck('id')->toArray()
        );
        /** @var Carbon $start */
        $start = session('start', Carbon::now()->startOfMonth());
        /** @var Carbon $end */
        $end = session('end', Carbon::now()->endOfMonth());
        /** @noinspection NullPointerExceptionInspection */
        $accounts = $repository->getAccountsById($frontPage->data);
        $today    = new Carbon;

        /** @var BillRepositoryInterface $billRepository */
        $billRepository = app(BillRepositoryInterface::class);
        $billCount      = $billRepository->getBills()->count();

        foreach ($accounts as $account) {
            $collector = app(TransactionCollectorInterface::class);
            $collector->setAccounts(new Collection([$account]))->setRange($start, $end)->setLimit(10)->setPage(1);
            $set            = $collector->getTransactions();
            $transactions[] = [$set, $account];
        }

        /** @var User $user */
        $user = auth()->user();
        event(new RequestedVersionCheckStatus($user));

        return view('index', compact('count', 'subTitle', 'transactions', 'billCount', 'start', 'end', 'today'));
    }

    /**
     * Show annual financial dashboard.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function dashboard(Request $request)
    {
        Log::channel('audit')->info('User visits financial dashboard.');
        
        $subTitle = 'Financial Analysis';
        
        return view('dashboard', compact('subTitle'));
    }

    /**
     * Get dashboard data via AJAX.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function dashboardData(Request $request): JsonResponse
    {
        $dateFrom = $request->get('date_from', null);
        $dateTo = $request->get('date_to', null);
        
        $params = [];
        $dateConditions = [];
        
        if ($dateFrom) {
            $dateConditions[] = 'tj.date >= ?';
            $params[] = $dateFrom;
        }
        
        if ($dateTo) {
            $dateConditions[] = 'tj.date <= ?';
            $params[] = $dateTo;
        }
        
        $dateFilter = !empty($dateConditions) ? 'AND ' . implode(' AND ', $dateConditions) : '';
        
        $data = \DB::select("
            SELECT 
                tt.type as transaction_type,
                COALESCE(c.name, 'Без категории') as category,
                a.name as account_name,
                SUM(ABS(t.amount)) as total_amount,
                tc.code as currency
            FROM transactions t
            JOIN transaction_journals tj ON t.transaction_journal_id = tj.id
            JOIN transaction_types tt ON tj.transaction_type_id = tt.id
            LEFT JOIN category_transaction ct ON ct.transaction_id = t.id
            LEFT JOIN categories c ON c.id = ct.category_id
            JOIN transaction_currencies tc ON t.transaction_currency_id = tc.id
            JOIN accounts a ON t.account_id = a.id
            JOIN account_types at ON a.account_type_id = at.id
            WHERE 1=1
                {$dateFilter}
                AND tt.type IN ('Deposit', 'Withdrawal')
                AND tj.deleted_at IS NULL
                AND t.deleted_at IS NULL
                AND ((tt.type = 'Withdrawal' AND at.type = 'Expense account') 
                     OR (tt.type = 'Deposit' AND at.type = 'Revenue account'))
            GROUP BY tt.type, c.name, a.name, tc.code
            ORDER BY tt.type, total_amount DESC
        ", $params);
        
        // Get individual transactions with details
        $transactions = \DB::select("
            SELECT 
                tt.type as transaction_type,
                COALESCE(c.name, 'Без категории') as category,
                a.name as account_name,
                tc.code as currency,
                ABS(t.amount) as amount,
                tj.date as date,
                tj.description as description,
                tj.id as journal_id
            FROM transactions t
            JOIN transaction_journals tj ON t.transaction_journal_id = tj.id
            JOIN transaction_types tt ON tj.transaction_type_id = tt.id
            LEFT JOIN category_transaction ct ON ct.transaction_id = t.id
            LEFT JOIN categories c ON c.id = ct.category_id
            JOIN transaction_currencies tc ON t.transaction_currency_id = tc.id
            JOIN accounts a ON t.account_id = a.id
            JOIN account_types at ON a.account_type_id = at.id
            WHERE 1=1
                {$dateFilter}
                AND tt.type IN ('Deposit', 'Withdrawal')
                AND tj.deleted_at IS NULL
                AND t.deleted_at IS NULL
                AND ((tt.type = 'Withdrawal' AND at.type = 'Expense account') 
                     OR (tt.type = 'Deposit' AND at.type = 'Revenue account'))
            ORDER BY tj.date DESC, tj.id DESC
        ", $params);
        
        return response()->json([
            'data' => $data, 
            'transactions' => $transactions,
            'date_from' => $dateFrom, 
            'date_to' => $dateTo
        ]);
    }

    /**
     * Get financial prediction for future period using AI.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function dashboardPrediction(Request $request): JsonResponse
    {
        try {
            $dateFrom = $request->get('date_from', null);
            $dateTo = $request->get('date_to', null);
            
            if (!$dateFrom || !$dateTo) {
                return response()->json(['error' => 'Date range required'], 400);
            }
            
            // Calculate the historical period duration in days
            $startDate = new Carbon($dateFrom);
            $endDate = new Carbon($dateTo);
            $periodDays = $startDate->diffInDays($endDate) + 1;
            $periodMonths = round($periodDays / 30, 1);
            
            // Get ALL historical transaction data (BEFORE the selected prediction period)
            $allHistoricalData = \DB::select("
                SELECT 
                    tt.type as transaction_type,
                    tc.code as currency,
                    COALESCE(c.name, 'Без категории') as category,
                    a.name as account_name,
                    DATE_FORMAT(tj.date, '%Y-%m') as month,
                    tj.date,
                    ABS(t.amount) as amount,
                    tj.description
                FROM transactions t
                JOIN transaction_journals tj ON t.transaction_journal_id = tj.id
                JOIN transaction_types tt ON tj.transaction_type_id = tt.id
                LEFT JOIN category_transaction ct ON ct.transaction_id = t.id
                LEFT JOIN categories c ON c.id = ct.category_id
                JOIN transaction_currencies tc ON t.transaction_currency_id = tc.id
                JOIN accounts a ON t.account_id = a.id
                JOIN account_types at ON a.account_type_id = at.id
                WHERE tt.type IN ('Deposit', 'Withdrawal')
                    AND tj.date < ?
                    AND tj.deleted_at IS NULL
                    AND t.deleted_at IS NULL
                    AND ((tt.type = 'Withdrawal' AND at.type = 'Expense account') 
                         OR (tt.type = 'Deposit' AND at.type = 'Revenue account'))
                ORDER BY tj.date DESC
                LIMIT 2000
            ", [$dateFrom]);
            
            // Prepare data summary for AI
            $summary = $this->prepareDataForAI($allHistoricalData, $dateFrom, $dateTo, $periodMonths);
            
            // Get AI prediction
            $aiPrediction = $this->getAIPrediction($summary, $periodMonths);
            
            if (isset($aiPrediction['error'])) {
                // Fallback to basic calculation if AI fails
                return $this->getFallbackPrediction($dateFrom, $dateTo, $periodDays);
            }
            
            return response()->json([
                'predictions' => $aiPrediction['predictions'],
                'period_days' => $periodDays,
                'period_months' => $periodMonths,
                'historical_from' => $dateFrom,
                'historical_to' => $dateTo,
                'ai_insights' => $aiPrediction['insights'] ?? null,
                'method' => 'ai'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Prediction error: ' . $e->getMessage());
            return response()->json(['error' => 'Prediction calculation failed: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Prepare transaction data for AI analysis with statistical pre-processing.
     */
    private function prepareDataForAI($transactions, $dateFrom, $dateTo, $periodMonths): array
    {
        $summary = [
            'prediction_target' => [
                'from' => $dateFrom, 
                'to' => $dateTo,
                'note' => 'This is the FUTURE period to predict, NOT historical data'
            ],
            'prediction_period_months' => $periodMonths,
            'currencies' => []
        ];
        
        // First pass: aggregate by currency and month
        $rawData = [];
        foreach ($transactions as $tx) {
            $currency = $tx->currency;
            $type = $tx->transaction_type === 'Deposit' ? 'income' : 'expense';
            
            if (!isset($rawData[$currency])) {
                $rawData[$currency] = [
                    'income_by_month' => [],
                    'expense_by_month' => [],
                    'income_by_category' => [],
                    'expense_by_category' => [],
                    'income_transactions' => [],
                    'expense_transactions' => []
                ];
            }
            
            // Monthly aggregation
            if (!isset($rawData[$currency][$type . '_by_month'][$tx->month])) {
                $rawData[$currency][$type . '_by_month'][$tx->month] = 0;
            }
            $rawData[$currency][$type . '_by_month'][$tx->month] += floatval($tx->amount);
            
            // Category aggregation
            if (!isset($rawData[$currency][$type . '_by_category'][$tx->category])) {
                $rawData[$currency][$type . '_by_category'][$tx->category] = 0;
            }
            $rawData[$currency][$type . '_by_category'][$tx->category] += floatval($tx->amount);
            
            // Store individual transactions for pattern detection
            $rawData[$currency][$type . '_transactions'][] = [
                'date' => $tx->date,
                'amount' => floatval($tx->amount),
                'category' => $tx->category,
                'description' => $tx->description
            ];
        }
        
        // Second pass: calculate statistical insights for each currency
        foreach ($rawData as $currency => $data) {
            $summary['currencies'][$currency] = [
                'income_by_month' => $data['income_by_month'],
                'expense_by_month' => $data['expense_by_month'],
                'income_by_category' => $data['income_by_category'],
                'expense_by_category' => $data['expense_by_category'],
                'statistics' => $this->calculateStatistics($data, $currency)
            ];
        }
        
        return $summary;
    }
    
    /**
     * Calculate statistical insights for predictions.
     */
    private function calculateStatistics($data, $currency): array
    {
        $stats = [];
        
        // Income statistics
        $incomeByMonth = $data['income_by_month'];
        if (!empty($incomeByMonth)) {
            ksort($incomeByMonth);
            $monthlyValues = array_values($incomeByMonth);
            
            $stats['income'] = [
                'average_monthly' => round(array_sum($monthlyValues) / count($monthlyValues), 2),
                'total_months' => count($monthlyValues),
                'min_month' => round(min($monthlyValues), 2),
                'max_month' => round(max($monthlyValues), 2),
                'trend' => $this->calculateTrend($monthlyValues),
                'recent_3mo_avg' => $this->getRecentAverage($monthlyValues, 3),
                'recent_6mo_avg' => $this->getRecentAverage($monthlyValues, 6),
                'recurring_detected' => $this->detectRecurringIncome($data['income_transactions'])
            ];
        }
        
        // Expense statistics
        $expenseByMonth = $data['expense_by_month'];
        if (!empty($expenseByMonth)) {
            ksort($expenseByMonth);
            $monthlyValues = array_values($expenseByMonth);
            
            $stats['expense'] = [
                'average_monthly' => round(array_sum($monthlyValues) / count($monthlyValues), 2),
                'total_months' => count($monthlyValues),
                'min_month' => round(min($monthlyValues), 2),
                'max_month' => round(max($monthlyValues), 2),
                'trend' => $this->calculateTrend($monthlyValues),
                'recent_3mo_avg' => $this->getRecentAverage($monthlyValues, 3),
                'recent_6mo_avg' => $this->getRecentAverage($monthlyValues, 6),
                'top_categories' => $this->getTopCategories($data['expense_by_category'], 5)
            ];
        }
        
        return $stats;
    }
    
    /**
     * Calculate trend (linear regression slope as percentage).
     */
    private function calculateTrend($values): array
    {
        $n = count($values);
        if ($n < 2) {
            return ['direction' => 'stable', 'percent' => 0];
        }
        
        // Take last 6 months for trend calculation
        $values = array_slice($values, -6);
        $n = count($values);
        
        $x = range(1, $n);
        $sumX = array_sum($x);
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumX2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $values[$i];
            $sumX2 += $x[$i] * $x[$i];
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $avgValue = $sumY / $n;
        
        // Convert slope to percentage change per month
        $percentChange = $avgValue > 0 ? ($slope / $avgValue) * 100 : 0;
        
        $direction = 'stable';
        if ($percentChange > 2) $direction = 'growing';
        else if ($percentChange < -2) $direction = 'declining';
        
        return [
            'direction' => $direction,
            'percent' => round($percentChange, 1)
        ];
    }
    
    /**
     * Get average of most recent N months.
     */
    private function getRecentAverage($values, $months): float
    {
        if (empty($values)) return 0;
        
        $recent = array_slice($values, -$months);
        return round(array_sum($recent) / count($recent), 2);
    }
    
    /**
     * Detect recurring income patterns (salary).
     */
    private function detectRecurringIncome($transactions): array
    {
        if (empty($transactions)) return [];
        
        // Group by similar amounts (±5%)
        $groups = [];
        foreach ($transactions as $tx) {
            $amount = $tx['amount'];
            $found = false;
            
            foreach ($groups as $key => $group) {
                $avgAmount = array_sum(array_column($group, 'amount')) / count($group);
                if (abs($amount - $avgAmount) / $avgAmount < 0.05) {
                    $groups[$key][] = $tx;
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $groups[] = [$tx];
            }
        }
        
        // Find groups with 3+ occurrences
        $recurring = [];
        foreach ($groups as $group) {
            if (count($group) >= 3) {
                $avgAmount = round(array_sum(array_column($group, 'amount')) / count($group), 2);
                $recurring[] = [
                    'amount' => $avgAmount,
                    'occurrences' => count($group),
                    'frequency' => 'monthly'
                ];
            }
        }
        
        return $recurring;
    }
    
    /**
     * Get top N categories by total amount.
     */
    private function getTopCategories($categories, $limit): array
    {
        arsort($categories);
        return array_slice($categories, 0, $limit, true);
    }
    
    /**
     * Get prediction from OpenAI API using GPT-5.2.
     */
    private function getAIPrediction($summary, $periodMonths): array
    {
        // API key should be stored in .env file as OPENAI_API_KEY
        $apiKey = env('OPENAI_API_KEY');
        
        if (!$apiKey) {
            \Log::warning('OpenAI API key not configured');
            return [];
        }
        
        $prompt = $this->buildAIPrompt($summary, $periodMonths);
        
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'gpt-5.2',  // Using GPT-5.2 for best reasoning capability
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert financial forecasting analyst with deep expertise in pattern recognition, trend analysis, and predictive modeling. Analyze transaction patterns with statistical rigor and provide accurate, justified predictions in JSON format only.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.2,  // Lower temperature for more consistent predictions
            'max_tokens' => 2500   // More tokens for detailed analysis
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            \Log::error('OpenAI API error: ' . $response);
            return ['error' => 'AI service unavailable'];
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            return ['error' => 'Invalid AI response'];
        }
        
        return $this->parseAIResponse($result['choices'][0]['message']['content']);
    }
    
    /**
     * Build enhanced prompt for AI analysis with statistical context.
     */
    private function buildAIPrompt($summary, $periodMonths): string
    {
        $predictionFrom = $summary['prediction_target']['from'];
        $predictionTo = $summary['prediction_target']['to'];
        
        // Build statistical summary for each currency
        $statsSummary = "";
        foreach ($summary['currencies'] as $currency => $data) {
            $stats = $data['statistics'] ?? [];
            
            $statsSummary .= "\n=== {$currency} ANALYSIS ===\n";
            
            if (isset($stats['income'])) {
                $inc = $stats['income'];
                $statsSummary .= "INCOME:\n";
                $statsSummary .= "  - Monthly Average: " . number_format($inc['average_monthly'], 2) . " {$currency}\n";
                $statsSummary .= "  - Recent 3-month Avg: " . number_format($inc['recent_3mo_avg'], 2) . " {$currency}\n";
                $statsSummary .= "  - Recent 6-month Avg: " . number_format($inc['recent_6mo_avg'], 2) . " {$currency}\n";
                $statsSummary .= "  - Trend: {$inc['trend']['direction']} ({$inc['trend']['percent']}% per month)\n";
                $statsSummary .= "  - Range: " . number_format($inc['min_month'], 2) . " to " . number_format($inc['max_month'], 2) . "\n";
                
                if (!empty($inc['recurring_detected'])) {
                    $statsSummary .= "  - RECURRING INCOME DETECTED:\n";
                    foreach ($inc['recurring_detected'] as $rec) {
                        $statsSummary .= "    * ~" . number_format($rec['amount'], 2) . " {$currency} ({$rec['occurrences']} times, {$rec['frequency']})\n";
                    }
                }
            }
            
            if (isset($stats['expense'])) {
                $exp = $stats['expense'];
                $statsSummary .= "\nEXPENSES:\n";
                $statsSummary .= "  - Monthly Average: " . number_format($exp['average_monthly'], 2) . " {$currency}\n";
                $statsSummary .= "  - Recent 3-month Avg: " . number_format($exp['recent_3mo_avg'], 2) . " {$currency}\n";
                $statsSummary .= "  - Recent 6-month Avg: " . number_format($exp['recent_6mo_avg'], 2) . " {$currency}\n";
                $statsSummary .= "  - Trend: {$exp['trend']['direction']} ({$exp['trend']['percent']}% per month)\n";
                $statsSummary .= "  - Range: " . number_format($exp['min_month'], 2) . " to " . number_format($exp['max_month'], 2) . "\n";
                
                if (!empty($exp['top_categories'])) {
                    $statsSummary .= "  - TOP EXPENSE CATEGORIES:\n";
                    $count = 0;
                    foreach ($exp['top_categories'] as $cat => $amount) {
                        if ($count++ >= 5) break;
                        $statsSummary .= "    * {$cat}: " . number_format($amount, 2) . " {$currency}\n";
                    }
                }
            }
            
            $statsSummary .= "\n";
        }
        
        $jsonData = json_encode($summary, JSON_PRETTY_PRINT);
        
        return <<<PROMPT
You are an expert financial forecasting analyst. Analyze the HISTORICAL transaction data and predict FUTURE financial outcomes.

╔════════════════════════════════════════════════════════════════╗
║ PREDICTION TARGET (FUTURE PERIOD TO FORECAST)                  ║
║ From: {$predictionFrom}                                        ║
║ To:   {$predictionTo}                                          ║
║ Duration: {$periodMonths} months                               ║
╚════════════════════════════════════════════════════════════════╝

CRITICAL INSTRUCTIONS:
1. The data below shows HISTORICAL transactions (BEFORE {$predictionFrom})
2. You must predict what WILL HAPPEN in the FUTURE period ({$predictionFrom} to {$predictionTo})
3. DO NOT simply report historical averages - analyze patterns and project forward
4. Weight RECENT data (last 3-6 months) MORE HEAVILY than older data
5. Detect and continue RECURRING patterns (salary, rent, subscriptions)
6. Account for TRENDS (growing/declining income or expenses)
7. Be REALISTIC - base predictions on actual patterns, not wishes

╔════════════════════════════════════════════════════════════════╗
║ STATISTICAL PRE-ANALYSIS                                        ║
╚════════════════════════════════════════════════════════════════╝
{$statsSummary}

╔════════════════════════════════════════════════════════════════╗
║ DETAILED HISTORICAL DATA                                        ║
╚════════════════════════════════════════════════════════════════╝
{$jsonData}

╔════════════════════════════════════════════════════════════════╗
║ YOUR FORECASTING METHODOLOGY                                    ║
╚════════════════════════════════════════════════════════════════╝
For EACH currency with data:

1. INCOME PREDICTION:
   - If recurring income detected: Use recurring amount × number of months
   - Apply trend adjustment: If growing at X% per month, compound it
   - Weight recent 3-month average at 60%, 6-month at 30%, overall at 10%
   - Account for any detected patterns (seasonal variations, bonuses)
   
2. EXPENSE PREDICTION:
   - Use recent 3-month average as baseline (weight: 60%)
   - Apply trend: If expenses growing at X% per month, compound it
   - Consider top categories: Recurring bills stay stable, discretionary may vary
   - Weight recent 6-month average at 30%, overall at 10%
   
3. CONFIDENCE SCORING:
   - High (80-95%): Clear recurring patterns, stable trends, consistent data
   - Medium (60-79%): Some patterns, moderate consistency
   - Low (40-59%): High variability, limited data, unclear patterns
   - Very Low (20-39%): Sparse or erratic data

╔════════════════════════════════════════════════════════════════╗
║ OUTPUT FORMAT (PURE JSON, NO MARKDOWN)                         ║
╚════════════════════════════════════════════════════════════════╝
{
    "predictions": {
        "EUR": {
            "predicted_income": <total for entire period>,
            "predicted_expense": <total for entire period>,
            "predicted_net": <income - expense>,
            "income_trend": <percent change per month: +5 or -3>,
            "expense_trend": <percent change per month>,
            "confidence": <1-100>
        },
        "USD": { same structure },
        "RUB": { same structure }
    },
    "insights": "2-4 sentences explaining: (1) What key patterns you found, (2) Why you made these specific predictions, (3) What assumptions or recurring items you factored in, (4) Any warnings or caveats"
}

IMPORTANT: 
- Only include currencies with actual historical data
- Total amounts should be for the ENTIRE prediction period ({$periodMonths} months)
- If monthly income is 100k, and period is 12 months, predicted_income should be ~1.2M (adjusted for trends)
- Be specific in insights - mention actual numbers and patterns
PROMPT;
    }
    
    /**
     * Parse AI response and extract predictions.
     */
    private function parseAIResponse($content): array
    {
        // Try to extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json && isset($json['predictions'])) {
                return $json;
            }
        }
        
        return ['error' => 'Could not parse AI response'];
    }
    
    /**
     * Fallback prediction without AI.
     */
    private function getFallbackPrediction($dateFrom, $dateTo, $periodDays): JsonResponse
    {
        $monthlyData = \DB::select("
            SELECT 
                tt.type as transaction_type,
                tc.code as currency,
                DATE_FORMAT(tj.date, '%Y-%m') as month,
                SUM(ABS(t.amount)) as total_amount
            FROM transactions t
            JOIN transaction_journals tj ON t.transaction_journal_id = tj.id
            JOIN transaction_types tt ON tj.transaction_type_id = tt.id
            JOIN transaction_currencies tc ON t.transaction_currency_id = tc.id
            JOIN accounts a ON t.account_id = a.id
            JOIN account_types at ON a.account_type_id = at.id
            WHERE tj.date >= ? 
                AND tj.date <= ?
                AND tt.type IN ('Deposit', 'Withdrawal')
                AND tj.deleted_at IS NULL
                AND t.deleted_at IS NULL
                AND ((tt.type = 'Withdrawal' AND at.type = 'Expense account') 
                     OR (tt.type = 'Deposit' AND at.type = 'Revenue account'))
            GROUP BY tt.type, tc.code, month
            ORDER BY month ASC
        ", [$dateFrom, $dateTo]);
        
        $predictions = [];
        $currencies = ['EUR', 'USD', 'RUB'];
        
        foreach ($currencies as $currency) {
            $incomeByMonth = [];
            $expenseByMonth = [];
            
            foreach ($monthlyData as $row) {
                if ($row->currency !== $currency) continue;
                
                if ($row->transaction_type === 'Deposit') {
                    $incomeByMonth[$row->month] = floatval($row->total_amount);
                } else {
                    $expenseByMonth[$row->month] = floatval($row->total_amount);
                }
            }
            
            $avgIncome = count($incomeByMonth) > 0 ? array_sum($incomeByMonth) / count($incomeByMonth) : 0;
            $avgExpense = count($expenseByMonth) > 0 ? array_sum($expenseByMonth) / count($expenseByMonth) : 0;
            
            $monthsInPeriod = max(1, round($periodDays / 30));
            
            $predictions[$currency] = [
                'predicted_income' => round($avgIncome * $monthsInPeriod, 2),
                'predicted_expense' => round($avgExpense * $monthsInPeriod, 2),
                'predicted_net' => round(($avgIncome - $avgExpense) * $monthsInPeriod, 2),
                'income_trend' => 0,
                'expense_trend' => 0,
                'confidence' => 50
            ];
        }
        
        return response()->json([
            'predictions' => $predictions,
            'period_days' => $periodDays,
            'period_months' => round($periodDays / 30, 1),
            'historical_from' => $dateFrom,
            'historical_to' => $dateTo,
            'method' => 'fallback'
        ]);
    }

    /**
     * Get current account balances (net worth).
     * Uses the same logic as the main dashboard's net worth box.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAccountBalances(Request $request): JsonResponse
    {
        try {
            $date = \Carbon\Carbon::now()->startOfDay();
            
            // Use Firefly's NetWorthInterface helper (same as main dashboard)
            /** @var \FireflyIII\Helpers\Report\NetWorthInterface $netWorthHelper */
            $netWorthHelper = app(\FireflyIII\Helpers\Report\NetWorthInterface::class);
            $netWorthHelper->setUser(auth()->user());
            
            // Get accounts to include (same types as BoxController)
            /** @var \FireflyIII\Repositories\Account\AccountRepositoryInterface $accountRepository */
            $accountRepository = app(\FireflyIII\Repositories\Account\AccountRepositoryInterface::class);
            $allAccounts = $accountRepository->getActiveAccountsByType([
                \FireflyIII\Models\AccountType::DEFAULT,
                \FireflyIII\Models\AccountType::ASSET,
                \FireflyIII\Models\AccountType::DEBT,
                \FireflyIII\Models\AccountType::LOAN,
                \FireflyIII\Models\AccountType::MORTGAGE,
                \FireflyIII\Models\AccountType::CREDITCARD
            ]);
            
            // Filter accounts based on include_net_worth preference
            $filtered = $allAccounts->filter(function ($account) use ($accountRepository) {
                $includeNetWorth = $accountRepository->getMetaValue($account, 'include_net_worth');
                return null === $includeNetWorth ? true : '1' === $includeNetWorth;
            });
            
            // Get net worth by currency
            $netWorthSet = $netWorthHelper->getNetWorthByCurrency($filtered, $date);
            
            // Format for our dashboard
            $balanceData = [
                'EUR' => 0,
                'USD' => 0,
                'RUB' => 0
            ];
            
            foreach ($netWorthSet as $data) {
                /** @var \FireflyIII\Models\TransactionCurrency $currency */
                $currency = $data['currency'];
                $balance = (float)$data['balance'];
                
                if (isset($balanceData[$currency->code])) {
                    $balanceData[$currency->code] = $balance;
                }
            }
            
            return response()->json([
                'balances' => $balanceData,
                'timestamp' => now()->toDateTimeString()
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Balance calculation error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to calculate balances'], 500);
        }
    }

    /**
     * Get current exchange rates.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getExchangeRates(Request $request): JsonResponse
    {
        try {
            // Use exchangerate-api.com (free tier: 1500 requests/month)
            $baseCurrency = $request->get('base', 'EUR');
            
            $ch = curl_init("https://api.exchangerate-api.com/v4/latest/{$baseCurrency}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                
                if (isset($data['rates'])) {
                    return response()->json([
                        'rates' => [
                            'EUR' => $data['rates']['EUR'] ?? 1,
                            'USD' => $data['rates']['USD'] ?? 1,
                            'RUB' => $data['rates']['RUB'] ?? 1
                        ],
                        'base' => $baseCurrency,
                        'date' => $data['date'] ?? date('Y-m-d'),
                        'source' => 'exchangerate-api.com'
                    ]);
                }
            }
            
            // Fallback to static rates if API fails
            return response()->json([
                'rates' => [
                    'EUR' => 1,
                    'USD' => 1.08,
                    'RUB' => 105
                ],
                'base' => 'EUR',
                'date' => date('Y-m-d'),
                'source' => 'fallback'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Exchange rate fetch error: ' . $e->getMessage());
            
            // Return fallback rates
            return response()->json([
                'rates' => [
                    'EUR' => 1,
                    'USD' => 1.08,
                    'RUB' => 105
                ],
                'base' => 'EUR',
                'date' => date('Y-m-d'),
                'source' => 'fallback'
            ]);
        }
    }

}
