<?php

namespace App\Services\ProductCatalog;

use App\Models\Product;

/** Task 6 “before”: always query the database (no cache layer). */
class DirectProductLookup
{
    public function __construct(
        private ProductCatalogMetrics $metrics,
    ) {}

    /**
     * @return array{found: bool, product: array<string, mixed>|null, lookup_mode: string, db_queries: int, cache_result: null}
     */
    public function find(int $productId): array
    {
        $this->metrics->incrementDbQueries();

        $product = Product::query()->find($productId);

        if (! $product) {
            $this->metrics->recordLookup($productId, 'direct', null, 1, 'direct_db');

            return [
                'found' => false,
                'product' => null,
                'lookup_mode' => 'direct_db',
                'db_queries' => 1,
                'cache_result' => null,
            ];
        }

        $this->metrics->recordLookup($productId, 'direct', null, 1, 'direct_db');

        return [
            'found' => true,
            'product' => $this->serializeProduct($product),
            'lookup_mode' => 'direct_db',
            'db_queries' => 1,
            'cache_result' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'stock' => $product->stock,
        ];
    }
}
