<?php

namespace App\Console\Commands;

use App\Models\Income;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Stock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('api:fetch
                            {--dateFrom=2000-01-01 : Start date for sales/orders/incomes}
                            {--dateTo=now : End date (Y-m-d or "now")}
                            {--limit=500 : Page size (max 500)}
                            {--truncate : Truncate tables before import}')]
#[Description('Загрузить данные из тестового api и сохранить в дб')]
class FetchApiData extends Command
{
    private string $baseUrl;

    private string $apiKey;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ini_set('memory_limit', '-1');

        $this->baseUrl = rtrim(env('API_BASE_URL'), '/');
        $this->apiKey = env('API_SECRET_KEY');

        if (! $this->apiKey) {
            $this->error('API_SECRET_KEY not set in .env');

            return 1;
        }

        $dateFrom = $this->option('dateFrom');
        $dateTo = $this->option('dateTo') === 'now' ? now()->format('Y-m-d') : $this->option('dateTo');
        $limit = min((int) $this->option('limit'), 500);

        if ($this->option('truncate')) {
            $this->truncateTables();
        }

        $this->fetchAndStore(
            '/stocks',
            ['dateFrom' => now()->format('Y-m-d')],
            new Stock,
            $this->getStockMapping(),
            $limit,
            'stocks'
        );

        $this->fetchAndStore(
            '/incomes',
            ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
            new Income,
            $this->getIncomeMapping(),
            $limit,
            'incomes'
        );

        $this->fetchAndStore(
            '/sales',
            ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
            new Sale,
            $this->getSaleMapping(),
            $limit,
            'sales'
        );

        $this->fetchAndStore(
            '/orders',
            ['dateFrom' => $dateFrom, 'dateTo' => $dateTo],
            new Order,
            $this->getOrderMapping(),
            $limit,
            'orders'
        );

        $this->info('All data imported successfully.');

        return 0;
    }

    /**
     * Основной метод загрузки и сохранения данных.
     */
    private function fetchAndStore(string $endpoint, array $params, $model, array $mapping, int $limit, string $label): void
    {
        $this->info("Fetching {$label}...");
        $page = 1;
        $totalRecords = 0;
        $maxRetries = 10;        // максимум повторных попыток при 429
        $retryDelay = 5;         // начальная задержка (секунд) при 429

        do {
            $attempt = 0;
            $data = null;

            // Цикл повтора для страницы
            while ($attempt < $maxRetries) {
                $response = Http::retry(3, 1000)->get("{$this->baseUrl}{$endpoint}", array_merge($params, [
                    'key' => $this->apiKey,
                    'page' => $page,
                    'limit' => $limit,
                ]));

                if ($response->status() === 429) {
                    $this->warn("Rate limit hit for {$label} page {$page}. Retrying in {$retryDelay} seconds...");
                    sleep($retryDelay);
                    $retryDelay = min($retryDelay * 2, 60); // экспоненциальное увеличение до 60 сек
                    $attempt++;

                    continue;
                }

                if ($response->successful()) {
                    $data = $response->json();
                    break;
                }

                // Другая ошибка
                $this->error("Error fetching {$label} page {$page}: ".$response->status());
                break 2; // выход из внешнего цикла
            }

            if ($attempt >= $maxRetries) {
                $this->error("Max retries reached for {$label} page {$page}. Aborting.");
                break;
            }

            if (empty($data) || empty($data['data'])) {
                break;
            }

            $count = count($data['data']);
            $totalRecords += $count;

            $inserts = [];
            foreach ($data['data'] as $item) {
                $attributes = $this->mapFields($item, $mapping);
                $attributes['created_at'] = now();
                $attributes['updated_at'] = now();
                $inserts[] = $attributes;
            }

            DB::table($model->getTable())->insert($inserts);

            $pageData = $data;
            unset($inserts, $data);
            gc_collect_cycles();

            $this->line("Page {$page}: {$count} records (total so far: {$totalRecords})");

            $lastPage = $pageData['meta']['last_page'] ?? 1;
            $page++;

            if ($page <= $lastPage) {
                usleep(500000); // 0.5 секунды
            }
        } while ($page <= $lastPage);

        $this->info("{$label} done. Total records: {$totalRecords}");
    }

    /**
     * Преобразует поля согласно маппингу.
     * mapping: ['поле_модели' => 'поле_из_API' | callable]
     */
    private function mapFields(array $item, array $mapping): array
    {
        $result = [];
        foreach ($mapping as $modelField => $source) {
            if (is_callable($source) && ! is_string($source)) {
                $result[$modelField] = $source($item);
            } else {
                $result[$modelField] = $item[$source] ?? null;
            }
        }

        return $result;
    }

    // --- Маппинги для каждой сущности ---

    private function getStockMapping(): array
    {
        return [
            'date' => 'date',
            'last_change_date' => 'last_change_date',
            'supplier_article' => 'supplier_article',
            'tech_size' => 'tech_size',
            'barcode' => 'barcode',
            'quantity' => 'quantity',
            'is_supply' => function ($item) {
                return isset($item['is_supply']) ? (bool) $item['is_supply'] : null;
            },
            'is_realization' => function ($item) {
                return isset($item['is_realization']) ? (bool) $item['is_realization'] : null;
            },
            'quantity_full' => 'quantity_full',
            'warehouse_name' => 'warehouse_name',
            'in_way_to_client' => 'in_way_to_client',
            'in_way_from_client' => 'in_way_from_client',
            'nm_id' => 'nm_id',
            'subject' => 'subject',
            'category' => 'category',
            'brand' => 'brand',
            'sc_code' => 'sc_code',
            'price' => function ($item) {
                return isset($item['price']) ? round((float) $item['price'], 2) : null;
            },
            'discount' => function ($item) {
                return isset($item['discount']) ? (int) $item['discount'] : null;
            },
        ];
    }

    private function getIncomeMapping(): array
    {
        return [
            'income_id' => 'income_id',
            'number' => 'number',
            'date' => 'date',
            'last_change_date' => 'last_change_date',
            'supplier_article' => 'supplier_article',
            'tech_size' => 'tech_size',
            'barcode' => 'barcode',
            'quantity' => 'quantity',
            'total_price' => function ($item) {
                return isset($item['total_price']) ? round((float) $item['total_price'], 2) : 0;
            },
            'date_close' => function ($item) {
                return ($item['date_close'] ?? null) === '0001-01-01' ? null : $item['date_close'];
            },
            'warehouse_name' => 'warehouse_name',
            'nm_id' => 'nm_id',
        ];
    }

    private function getSaleMapping(): array
    {
        return [
            'g_number' => 'g_number',
            'date' => 'date',
            'last_change_date' => 'last_change_date',
            'supplier_article' => 'supplier_article',
            'tech_size' => 'tech_size',
            'barcode' => 'barcode',
            'total_price' => function ($item) {
                return isset($item['total_price']) ? round((float) $item['total_price'], 2) : null;
            },
            'discount_percent' => function ($item) {
                return isset($item['discount_percent']) ? (int) $item['discount_percent'] : null;
            },
            'is_supply' => function ($item) {
                return isset($item['is_supply']) ? (bool) $item['is_supply'] : null;
            },
            'is_realization' => function ($item) {
                return isset($item['is_realization']) ? (bool) $item['is_realization'] : null;
            },
            'promo_code_discount' => function ($item) {
                return isset($item['promo_code_discount']) ? round((float) $item['promo_code_discount'], 2) : null;
            },
            'warehouse_name' => 'warehouse_name',
            'country_name' => 'country_name',
            'oblast_okrug_name' => 'oblast_okrug_name',
            'region_name' => 'region_name',
            'income_id' => function ($item) {
                return (int) ($item['income_id'] ?? 0);
            },
            'sale_id' => 'sale_id',
            'odid' => function ($item) {
                return isset($item['odid']) ? (int) $item['odid'] : null;
            },
            'spp' => function ($item) {
                return isset($item['spp']) ? (int) $item['spp'] : null;
            },
            'for_pay' => function ($item) {
                return isset($item['for_pay']) ? round((float) $item['for_pay'], 2) : null;
            },
            'finished_price' => function ($item) {
                return isset($item['finished_price']) ? round((float) $item['finished_price'], 2) : null;
            },
            'price_with_disc' => function ($item) {
                return isset($item['price_with_disc']) ? round((float) $item['price_with_disc'], 2) : null;
            },
            'nm_id' => 'nm_id',
            'subject' => 'subject',
            'category' => 'category',
            'brand' => 'brand',
            'is_storno' => function ($item) {
                return isset($item['is_storno']) ? (bool) $item['is_storno'] : null;
            },
        ];
    }

    private function getOrderMapping(): array
    {
        return [
            'g_number' => 'g_number',
            'date' => function ($item) {
                return $item['date'] ?? null;
            }, // datetime
            'last_change_date' => 'last_change_date',
            'supplier_article' => 'supplier_article',
            'tech_size' => 'tech_size',
            'barcode' => 'barcode',
            'total_price' => function ($item) {
                return isset($item['total_price']) ? round((float) $item['total_price'], 2) : null;
            },
            'discount_percent' => 'discount_percent',
            'warehouse_name' => 'warehouse_name',
            'oblast' => 'oblast',
            'income_id' => function ($item) {
                return (int) ($item['income_id'] ?? 0);
            },
            'odid' => function ($item) {
                return (int) ($item['odid'] ?? 0);
            },
            'nm_id' => 'nm_id',
            'subject' => 'subject',
            'category' => 'category',
            'brand' => 'brand',
            'is_cancel' => function ($item) {
                return (bool) ($item['is_cancel'] ?? false);
            },
            'cancel_dt' => 'cancel_dt',
        ];
    }

    private function truncateTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Stock::truncate();
        Income::truncate();
        Sale::truncate();
        Order::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $this->warn('All tables truncated.');
    }
}
