<?php
/** @noinspection PhpDynamicAsStaticMethodCallInspection */
declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Auth;

use Carbon\Carbon;
use DB;
use FireflyConfig;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Category\CategoryRepository;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Helpers\Collector\JournalCollectorInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class KassaController extends Controller
{

    /** @var CategoryRepository */
    private $categories;

    /**
     * Show the kassa
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request, JournalRepositoryInterface $repository)
    {
        $this->categories = app(CategoryRepository::class);
        $this->middleware('guest');
        $kassaid = $_GET['UserId'];
        if (!in_array($kassaid, ['7B7CDEDF-D4EC-4F98-9B3C-F08252D4CAA8', 'DA43FC53-66A6-419A-8125-63884E462575'])) {
            die("no such account allowed");
        }
        $account = Account::where('kassa_id', '=', $kassaid)->first();
        $collector = app(JournalCollectorInterface::class);
        $collector->setAccounts(new Collection([$account]))->setLimit(150)->setPage(1);
        $transactions = $collector->getPaginatedJournals();
        $categories = array_merge([null => '(none)'],
            $this->categories->getAllCategories('Гостевые Комнаты /')->pluck('name', 'name')->toArray());
        $balance = app('steam')->balance($account, new Carbon());
        return view('kassa.index', compact('transactions', 'categories', 'balance', 'kassaid', 'account'));
    }

    private function import(JournalRepositoryInterface $repository)
    {
        set_time_limit(0);
        $kassaCashAccountId = 3; // Все старые платежи без банковского айди НАЛИЧНЫЕ в account 3
        $catsMapping = [];
        foreach (json_decode(file_get_contents('../import/cats.json'), true)['data'] as $el) {
            $catsMapping[$el['kassa_id']] = $el['name'];
        }
        $usersMapping = [];
        foreach (json_decode(file_get_contents('../import/users.json'), true)['data'] as $el) {
            $usersMapping[$el['kassa_id']] = $el['name'];
        }
        $ops = explode(PHP_EOL, trim(file_get_contents('../import/ops_full.txt')));
        $opsDataIndexes = [];
        $opsData = [];
        foreach ($ops as $key => $op) {
            $op = explode("\t", trim($op));
            if ($key == 0) {
                foreach ($op as $opKey) {
                    $opsDataIndexes[] = trim(strtolower($opKey));
                }
            } else {
                foreach ($op as $opKey => $opValue) {
                    $opsData[$key - 1][$opsDataIndexes[$opKey]] = $opValue;
                }
            }
        }

        $lastprocessed = explode('\t', file_get_contents('../import/lastprocessed.txt'))[0];
        foreach ($opsData as $k => $op) {
            if ($k <= $lastprocessed) {
                continue;
            }
            if ($op['bankaccountid'] == 'NULL') {
                $op['bankaccountid'] = null;
            }
            if ($op['categoryid'] == 'NULL') {
                $op['categoryid'] = null;
            }
            if (empty($op['comment']) || $op['comment'] == 'NULL') {
                $op['comment'] = '-';
            }
            if (empty($op['categoryid'])) {
                $categoryName = null;
            } else {
                $categoryName = $catsMapping[$op['categoryid']];
            }
            if ($op['amount'] < 0) {
                $type = 'withdrawal';
            } else {
                $type = 'deposit';
            }
            $destinationSourceName = $usersMapping[$op['userid']];
            if ($destinationSourceName == 'Перевод') {
                if (empty($op['bankaccountid'])) {
                    // Перевод в нал - пропускаем
                    file_put_contents('../import/skipped.txt', print_r($op, true) . PHP_EOL, FILE_APPEND);
                    file_put_contents('../import/lastprocessed.txt', $k . '\t' . print_r($op, true));
                    continue;
                } else {
                    $type = 'transfer';
                    if ($op['amount'] < 0) { // Перевод из банка в нал
                        $sourceAccount = Account::where('kassa_id', '=', $op['bankaccountid'])->first();
                        $destinationAccount = Account::where('id', '=', $kassaCashAccountId)->first();
                    } else { // Перевод из нала в банк
                        $sourceAccount = Account::where('id', '=', $kassaCashAccountId)->first();
                        $destinationAccount = Account::where('kassa_id', '=', $op['bankaccountid'])->first();
                    }
                    $this->addTransaction(
                        $type,
                        $sourceAccount,
                        $categoryName,
                        abs($op['amount']),
                        $op['comment'],
                        $repository,
                        '',
                        $op['created'],
                        $destinationAccount
                    );
                }
            } else {
                if (empty($op['bankaccountid'])) {
                    $account = Account::where('id', '=', $kassaCashAccountId)->first();
                } else {
                    $account = Account::where('kassa_id', '=', $op['bankaccountid'])->first();
                }
                $this->addTransaction(
                    $type,
                    $account,
                    $categoryName,
                    abs($op['amount']),
                    $op['comment'],
                    $repository,
                    $destinationSourceName,
                    $op['created']
                );
            }
            file_put_contents('../import/lastprocessed.txt', $k . '\t' . print_r($op, true));
        }
    }

    public function add(Request $request, JournalRepositoryInterface $repository)
    {
        $kassaId = $_GET['UserId'];
        $amount = $request->get("amount");
        $type = $amount < 0 ? 'withdrawal' : 'deposit';
        $account = Account::where('kassa_id', '=', $kassaId)->first();
        $this->addTransaction(
            $type,
            $account,
            $request->get("category"),
            $amount,
            $request->get("description"),
            $repository,
            trim(str_replace(['Касса / ', ' (РУБ)'], '', $account->name))
        );
        return redirect('Api/Kassa?UserId=' . $kassaId);
    }

    private function addTransaction(
        $type,
        Account $sourceAccount,
        $categoryName,
        $amount,
        $description,
        JournalRepositoryInterface $repository,
        $destinationSourceName,
        $time = null,
        Account $destinationAccount = null
    ) {
        if (empty($time)) {
            $time = now();
        }
        if ($type == 'withdrawal') {
            $sourceName = '';
            $destinationName = $destinationSourceName;
            $sourceId = $sourceAccount->id;
            $destinationId = null;
        } elseif ($type == 'deposit') {
            $sourceId = null;
            $destinationId = $sourceAccount->id;
            $sourceName = $destinationSourceName;
            $destinationName = '';
        } else {
            $sourceId = $sourceAccount->id;
            $destinationId = $destinationAccount->id;
            $sourceName = '';
            $destinationName = '';
        }
        $data = [
            'type' => $type,
            'date' => new Carbon($time),
            'user' => 1,
            'tags' => null,
            'description' => $description,
            // all custom fields:
            'interest_date' => null,
            'book_date' => null,
            'process_date' => null,
            'due_date' => null,
            'payment_date' => null,
            'invoice_date' => null,
            'internal_reference' => null,
            'notes' => null,

            // journal data:
            'piggy_bank_id' => null,
            'piggy_bank_name' => null,
            'bill_id' => null,
            'bill_name' => null,
            'transactions' => [
                [
                    'currency_id' => 18,
                    'currency_code' => null,
                    'description' => null,
                    'budget_name' => null,
                    'budget_id' => null,
                    'category_id' => null,
                    'foreign_currency_id' => null,
                    'foreign_currency_code' => null,
                    'foreign_amount' => null,
                    'identifier' => 0,
                    'amount' => $amount,
                    'category_name' => $categoryName,
                    'source_id' => $sourceId,
                    'source_name' => $sourceName,
                    'destination_id' => $destinationId,
                    'destination_name' => $destinationName,
                    'reconciled' => false,
                ]
            ]
        ];
        if (empty($data['description'])) {
            $data['description'] = $data['transactions'][0]['category_name'];
        }
        $journal = $repository->store($data);
        session()->flash('success', (string)trans('firefly.stored_journal', ['description' => $journal->description]));
    }
}
