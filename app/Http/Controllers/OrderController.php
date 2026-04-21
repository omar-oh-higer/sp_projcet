<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendInvoiceJob;




class OrderController extends Controller
{
    // قبل التحسين — Race Condition
    public function buyWithoutLock()
    {
        $product = Product::first();

        if ($product->stock > 0) {
            // مشكلة التفرعية: طلبين ممكن يدخلوا بنفس الوقت
            $product->stock = $product->stock - 1;
            $product->save();

            return response()->json([
                'message' => 'Purchased WITHOUT lock',
                'stock' => $product->stock
            ]);
        }

        return response()->json(['message' => 'Out of stock']);
    }

    // بعد التحسين — Lock
    public function buyWithLock()
    {
        return DB::transaction(function () {
            $product = Product::lockForUpdate()->first();

            if ($product->stock > 0) {
                $product->stock = $product->stock - 1;
                $product->save();

                return response()->json([
                    'message' => 'Purchased WITH lock',
                    'stock' => $product->stock
                ]);
            }

            return response()->json(['message' => 'Out of stock']);
        });
    }
    public function testQueue()
{
    SendInvoiceJob::dispatch();

    return "Job dispatched!";}
}