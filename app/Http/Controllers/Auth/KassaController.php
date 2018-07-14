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
    public function index(Request $request)
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
        //$collector->setRange($start, $end);
        $transactions = $collector->getPaginatedJournals();
        //$transactions->setPath(route('accounts.show', [$account->id, $start->format('Y-m-d'), $end->format('Y-m-d')]));
        $categories = array_merge([null => '(none)'],
            $this->categories->getAllCategories('Гостевые Комнаты /')->pluck('name', 'name')->toArray());
        $balance = app('steam')->balance($account, new Carbon());
        return view('kassa.index', compact('transactions','categories', 'balance', 'kassaid', 'account'));
    }

    public function add(Request $request, JournalRepositoryInterface $repository)
    {
        $kassaId = $_GET['UserId'];
        $account = Account::where('kassa_id', '=', $kassaId)->first();
        $description = $request->get("description");
        $amount = $request->get("amount");
        $type = $amount < 0 ? 'withdrawal' : 'deposit';
        if ($type == 'withdrawal') {
            $sourceName = '';
            $destinationName = trim(str_replace(['Касса / ', ' (РУБ)'], '', $account->name));
            $sourceId = $account->id;
            $destinationId = null;
        } else {
            $sourceId = null;
            $destinationId = $account->id;
            $sourceName = trim(str_replace(['Касса / ', ' (РУБ)'], '', $account->name));
            $destinationName = '';
        }
        $data = [
            'type' => $type,
            'date' => new Carbon(),
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
                    'category_name' => $request->get("category"),
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

        return redirect('Api/Kassa?UserId=' . $kassaId);
    }
}
