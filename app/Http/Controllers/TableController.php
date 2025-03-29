<?php

namespace App\Http\Controllers;

use App\Http\Requests\TableRequest;
use App\Models\StockDetail;
use App\Models\Table;
use Illuminate\Http\Request;
use App\Http\Requests\AddStockToOrderRequest;
use App\Models\Order;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\ChangeTableRequest;
use App\Http\Requests\CreateQrOrderRequest;
use App\Models\TableOrder;
// use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
// use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TableController extends Controller
{
    public function index(Request $request)
    {
        // Get tables for the authenticated user's restaurant
        $restaurant = $request->user()->restaurant;

        $tableGroupId = $request->query('table_group_id');

        // Query the tables based on the restaurant and optionally filter by table_group_id
        $query = Table::where('restaurant_id', $restaurant->id);

        if ($tableGroupId) {
            $query->where('table_group_id', $tableGroupId);
        }

        $query = $query
            ->select('tables.*')
            ->selectSub(function ($query) {
                $query->from('table_orders')
                    ->join('orders', 'table_orders.order_id', '=', 'orders.id')
                    ->where('orders.status', 'approved')
                    ->whereColumn('table_orders.table_id', 'tables.id')
                    ->selectRaw('CASE
            WHEN COUNT(*) = 0 THEN 1
            ELSE 0
        END');
            }, 'is_available') // If count is 0, table is available
            // Subquery to get the `book_time`
            ->selectSub(function ($query) {
                $query->from('table_orders')
                    ->join('orders', 'table_orders.order_id', '=', 'orders.id')
                    ->where('orders.status', 'approved')
                    ->whereColumn('table_orders.table_id', 'tables.id')
                    ->orderBy('table_orders.created_at', 'desc')
                    ->limit(1)
                    ->selectRaw("DATE_FORMAT(table_orders.created_at, '%H:%i')");
            }, 'book_time')
            // Subquery to get the `user_name`
            ->selectSub(function ($query) {
                $query->from('table_orders')
                    ->join('orders', 'table_orders.order_id', '=', 'orders.id')
                    ->join('users', 'orders.user_id', '=', 'users.id') // Left join with `users` table to handle null
                    ->where('orders.status', 'approved')
                    ->whereColumn('table_orders.table_id', 'tables.id')
                    ->orderBy('table_orders.created_at', 'desc')
                    ->limit(1)
                    ->selectRaw("COALESCE(users.name, 'No User')");
            }, 'user_name')
            // Subquery to calculate `total_price` including details
            ->selectSub(function ($query) {
                $query->from('table_orders')
                    ->join('orders', 'table_orders.order_id', '=', 'orders.id')
                    ->join('order_stock', 'orders.id', '=', 'order_stock.order_id')
                    ->leftJoin('stock_details', 'order_stock.detail_id', '=', 'stock_details.id') // Join for detail prices
                    ->join('stocks', 'order_stock.stock_id', '=', 'stocks.id')
                    ->where('orders.status', 'approved')
                    ->whereColumn('table_orders.table_id', 'tables.id')
                    ->selectRaw('
                    SUM(
                        CASE
                            WHEN stock_details.price IS NOT NULL THEN stock_details.price * order_stock.quantity
                            ELSE stocks.price * order_stock.quantity
                        END
                    )
                ');
            }, 'total_price');

        if ($request->has('is_available') && in_array($request->is_available, ['0', '1'])) {
            $query->having('is_available', $request->is_available);
        }

        $tables = $query->get();

        return response()->json([
            'tables' => $tables,
            'empty_table_color' => $restaurant->empty_table_color,
            'booked_table_color' => $restaurant->booked_table_color,
        ]);
    }


    public function store(TableRequest $request)
    {
        $data = $request->validated();
        $uniqueUrl = Str::uuid();

        // $qrCode = QrCode::format('png')->size(300)->generate($uniqueUrl);
        // $qrImagePath = 'qr_images/' . Str::random(10) . '.png';
        // Storage::disk('public')->put($qrImagePath, $qrCode);

        // $data['qr_image'] = $qrImagePath;
        $data['unique_url'] = $uniqueUrl;

        $data['restaurant_id'] = $request->user()->restaurant_id;

        $table = Table::create($data);

        return response()->json($table, 201);
    }

    public function update(TableRequest $request, $id)
    {
        $table = Table::findOrFail($id);

        if ($table->restaurant_id != $request->user()->restaurant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $table->update($request->validated());

        return response()->json($table);
    }

    public function show(Request $request, $id)
    {
        $table = Table::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($id);
        return response()->json($table);
    }

    public function destroy(Request $request, $id)
    {
        $table = Table::findOrFail($id);

        if ($table->restaurant_id != $request->user()->restaurant_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $table->delete();

        return response()->json(['message' => 'Table deleted successfully']);
    }

    public function addStockToOrder(AddStockToOrderRequest $request, $tableId)
    {
        DB::beginTransaction();

        try {
            // Ensure the table belongs to the user's restaurant
            $table = Table::where('restaurant_id', $request->user()->restaurant_id)->find($tableId);

            if (!$table) {
                return response()->json(['error' => 'Masa tapılmadı.'], 404);
            }

            $tableOrder = $table->tableOrders()
                ->whereHas('order', function ($query) {
                    $query->where('status', 'approved');
                })
                ->first();

            if (!$tableOrder) {
                // Yeni sifariş yaradılır
                $newOrder = Order::create([
                    'restaurant_id' => $table->restaurant_id,
                    'status' => 'approved',
                    'user_id' => $request->user()->id,
                ]);

                // Sifariş masaya bağlanır
                $tableOrder = $table->tableOrders()->create([
                    'order_id' => $newOrder->id,
                    'restaurant_id' => $table->restaurant_id,
                ]);
            }

            // Mövcud pivot qeydi tapılır
            $existingPivot = DB::table('order_stock')
                ->where('order_id', $tableOrder->order->id)
                ->where('stock_id', $request->stock_id)
                ->where('detail_id', $request->detail_id ?? null)
                ->first();

            // Stock modelindən məlumat əldə edilir
            $stock = Stock::where('id', $request->stock_id)
                ->where('restaurant_id', $table->restaurant_id)
                ->first();

            if (!$stock) {
                return response()->json(['error' => 'Stok tapılmadı.'], 404);
            }

            // Azaldılacaq miqdarın hesablanması
            $decrementAmount = 0;

            if ($request->detail_id) {
                // Detail mövcuddursa, unit ilə quantity-ni vururuq
                $detail = StockDetail::find($request->detail_id);

                if (!$detail) {
                    return response()->json(['error' => 'Detail tapılmadı.'], 404);
                }

                $decrementAmount = $request->quantity * $detail->count;
            } else {
                // Detail mövcud deyilsə, sadəcə quantity qədər azaldırıq
                $decrementAmount = $request->quantity;
            }



            $stock->decrement('amount', $decrementAmount);

            if ($existingPivot) {
                // Mövcud qeydin quantity-si artırılır
                DB::table('order_stock')
                    ->where('id', $existingPivot->id)
                    ->update([
                        'quantity' => $existingPivot->quantity + $request->quantity,
                    ]);
            } else {
                // Yeni pivot yaradılır
                $tableOrder->order->stocks()->attach($request->stock_id, [
                    'quantity' => $request->quantity,
                    'detail_id' => $request->detail_id,
                ]);
            }

            DB::commit();

            return response()->json($tableOrder->order->load('stocks'));
        } catch (\Exception $e) {
            DB::rollback();

            return response()->json(['error' => 'Stok sifarişə əlavə edilə bilmədi. ' . $e->getMessage()], 500);
        }
    }




    public function subtractStockFromOrder(AddStockToOrderRequest $request, $tableId)
    {
        DB::beginTransaction();

        try {
            $table = Table::where('restaurant_id', $request->user()->restaurant_id)->find($tableId);

            if (!$table) {
                return response()->json(['error' => 'Masa tapılmadı.'], 404);
            }


            $tableOrder = $table->tableOrders()
                ->whereHas('order', function ($query) {
                    $query->where('status', 'approved');
                })
                ->first();

            if (!$tableOrder) {
                return response()->json(['error' => 'Bu masada təsdiqlənmiş sifariş yoxdur.'], 404);
            }

            $existingPivot = DB::table('order_stock')
                ->where('id', $request->pivotId)
                ->first();

            $stock = Stock::where('id', $request->stock_id)
                ->where('restaurant_id', $table->restaurant_id)
                ->first();

            if (!$stock) {
                return response()->json(['error' => 'Stok tapılmadı.'], 404);
            }

            if (!$existingPivot) {
                return response()->json(['error' => 'Bu sifariş üçün uyğun stok tapılmadı.'], 404);
            }

            $incrementAmount = 0;

            if ($existingPivot->detail_id) {
                $detail = StockDetail::find($existingPivot->detail_id);

                if (!$detail) {
                    return response()->json(['error' => 'Detail tapılmadı.'], 404);
                }

                $incrementAmount = $detail->count;

            } else {
                $incrementAmount = 1;
            }

            if ($request->increase) {

                $stock->increment('amount', $incrementAmount);
            } else {
                $stock->increment('amount', $incrementAmount);
            }

            $newQuantity = $existingPivot->quantity - $request->quantity;

            if ($newQuantity <= 0) {
                if ($request->increase) {
                    if ($existingPivot->quantity === 1) {
                        DB::table('order_stock')
                            ->where('id', $existingPivot->id)
                            ->delete();
                    } else {
                        DB::table('order_stock')
                            ->where('id', $existingPivot->id)
                            ->update(['quantity' => $existingPivot->quantity - 1]);
                    }
                } else {
                    DB::table('order_stock')
                        ->where('id', $existingPivot->id)
                        ->delete();
                }
            } else {
                DB::table('order_stock')
                    ->where('id', $existingPivot->id)
                    ->update(['quantity' => $newQuantity]);
            }

            DB::commit();

            return response()->json($tableOrder->order->load('stocks'));
        } catch (\Exception $e) {
            DB::rollback();

            return response()->json(['error' => 'Stok sifarişdən çıxarıla bilmədi. ' . $e->getMessage()], 500);
        }
    }



    // public function getTableWithApprovedOrders(Request $request, $tableId)
    // {
    //     // Ensure the table belongs to the user's restaurant
    //     $table = Table::where('restaurant_id', $request->user()->restaurant_id)
    //         ->find($tableId);

    //     if (!$table) {
    //         return response()->json(['error' => 'Table not found.'], 404);
    //     }

    //     // Retrieve the approved orders associated with the table
    //     $tableWithApprovedOrders = $table->load([
    //         'tableOrders' => function ($query) {
    //             $query->whereHas('order', function ($query) {
    //                 $query->where('status', 'approved');
    //             })->with(['order.stocks.details', 'order.prepayments']); // Eager load stocks and prepayments
    //         }
    //     ]);

    //     $t = [
    //         'name' => $tableWithApprovedOrders->name,
    //         'id' => $tableWithApprovedOrders->id,
    //         'orders' => $tableWithApprovedOrders->tableOrders->map(function ($tableOrder) {
    //             return [
    //                 'order_id' => $tableOrder->order->id,
    //                 'status' => $tableOrder->order->status,
    //                 'stocks' => $tableOrder->order->stocks->map(function ($stock) {
    //                     $detail = $stock->pivot->detail_id
    //                         ? StockDetail::find($stock->pivot->detail_id)->only(['id','price', 'unit', 'count'])
    //                         : null;

    //                     $price = $detail
    //                         ? ($stock->pivot->quantity > 1 ? $detail['price'] * $stock->pivot->quantity : $detail['price'])
    //                         : $stock->price * $stock->pivot->quantity;

    //                     return [
    //                         'pivot_id' => $stock->pivot->id,
    //                         'id' => $stock->id,
    //                         'name' => $stock->name,
    //                         'quantity' => $stock->pivot->quantity,
    //                         'price' => $price,
    //                         'detail' => $detail,
    //                     ];
    //                 }),
    //                 'prepayments' => $tableOrder->order->prepayments,
    //                 'total_prepayment' => $tableOrder->order->totalPrepayments(),
    //                 'total_price' => $tableOrder->order->stocks->sum(function ($stock) {
    //                     $detail = $stock->pivot->detail_id
    //                         ? StockDetail::find($stock->pivot->detail_id)->only(['price', 'unit', 'count'])
    //                         : null;

    //                     // Quantity > 1 olduqda qiymətləri toplayırıq
    //                     return $detail
    //                         ? ($stock->pivot->quantity > 1 ? $detail['price'] * $stock->pivot->quantity : $detail['price'])
    //                         : $stock->price * $stock->pivot->quantity;
    //                 }),
    //             ];
    //         }),
    //     ];

    //     return response()->json([
    //         'table' => $t,
    //     ]);
    // }


    // public function getTableWithApprovedOrders(Request $request, $tableId)
    // {
    //     $table = Table::where('restaurant_id', $request->user()->restaurant_id)
    //         ->find($tableId);
    
    //     if (!$table) {
    //         return response()->json(['error' => 'Table not found.'], 404);
    //     }
    
    //     $tableWithApprovedOrders = $table->load([
    //         'tableOrders' => function ($query) {
    //             $query->whereHas('order', function ($query) {
    //                 $query->where('status', 'approved');
    //             })->with([
    //                 'order.stocks' => function ($query) {
    //                     $query->with('details'); // **Stock-un detalları yüklənir**
    //                 },
    //                 'order.prepayments'
    //             ]);
    //         }
    //     ]);
    
    //     $t = [
    //         'name' => $tableWithApprovedOrders->name,
    //         'id' => $tableWithApprovedOrders->id,
    //         'orders' => $tableWithApprovedOrders->tableOrders->map(function ($tableOrder) {
    //             return [
    //                 'order_id' => $tableOrder->order->id,
    //                 'status' => $tableOrder->order->status,
    //                 'stocks' => $tableOrder->order->stocks->map(function ($stock) {
    //                     $selectedDetail = $stock->pivot->detail_id
    //                         ? $stock->details->firstWhere('id', $stock->pivot->detail_id) // **Burada `firstWhere()` istifadə etdik**
    //                         : null;
    
    //                     return [
    //                         // 'pivot' => $stock->pivot,
    //                         'pivot_id' => $stock->pivot,
    //                         'id' => $stock->id,
    //                         'name' => $stock->name,
    //                         'quantity' => $stock->pivot->quantity,
    //                         'price' => $selectedDetail
    //                             ? ($selectedDetail->price * $stock->pivot->quantity)
    //                             : ($stock->price * $stock->pivot->quantity),
    //                         'detail_id' => $selectedDetail ? $selectedDetail->id : null,
    //                         'detail' => $selectedDetail ? [
    //                             'id' => $selectedDetail->id,
    //                             'price' => $selectedDetail->price,
    //                             'unit' => $selectedDetail->unit,
    //                             'count' => $selectedDetail->count,
    //                         ] : null,
    //                     ];
    //                 }),
    //                 'prepayments' => $tableOrder->order->prepayments,
    //                 'total_prepayment' => $tableOrder->order->totalPrepayments(),
    //                 'total_price' => $tableOrder->order->stocks->sum(function ($stock) {
    //                     $selectedDetail = $stock->pivot->detail_id
    //                         ? $stock->details->firstWhere('id', $stock->pivot->detail_id) // **Burada da `firstWhere()` istifadə etdik**
    //                         : null;
    
    //                     return $selectedDetail
    //                         ? ($selectedDetail->price * $stock->pivot->quantity)
    //                         : ($stock->price * $stock->pivot->quantity);
    //                 }),
    //             ];
    //         }),
    //     ];
    
    //     return response()->json([
    //         'table' => $t,
    //     ]);
    // }
    
    public function getTableWithApprovedOrders(Request $request, $tableId)
    {
        $table = Table::where('restaurant_id', $request->user()->restaurant_id)
            ->find($tableId);
    
        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }
    
        $tableWithApprovedOrders = $table->load([
            'tableOrders' => function ($query) {
                $query->whereHas('order', function ($query) {
                    $query->where('status', 'approved');
                })->with([
                    'order.stocks' => function ($query) {
                        $query->with('details')->withPivot(['id', 'detail_id', 'quantity']);
                    },
                    'order.prepayments'
                ]);
            }
        ]);
        
        $t = [
            'name' => $tableWithApprovedOrders->name,
            'id' => $tableWithApprovedOrders->id,
            'orders' => $tableWithApprovedOrders->tableOrders->map(function ($tableOrder) {
                return [
                    'order_id' => $tableOrder->order->id,
                    'status' => $tableOrder->order->status,
                    'stocks' => $tableOrder->order->stocks->map(function ($stock) {
                        $selectedDetail = $stock->pivot->detail_id
                            ? $stock->details->firstWhere('id', $stock->pivot->detail_id)
                            : null;
        
                        return [
                            'pivot_id' => $stock->pivot->id,
                            'pivot'=> $stock->pivot,
                            'id' => $stock->id,
                            'name' => $stock->name,
                            'quantity' => $stock->pivot->quantity,
                            'price' => $selectedDetail
                                ? ($selectedDetail->price * $stock->pivot->quantity)
                                : ($stock->price * $stock->pivot->quantity),
                            'detail_id' => $selectedDetail ? $selectedDetail->id : null,
                            'detail' => $selectedDetail ? [
                                'id' => $selectedDetail->id,
                                'price' => $selectedDetail->price,
                                'unit' => $selectedDetail->unit,
                                'count' => $selectedDetail->count,
                            ] : null,
                        ];
                    }),
                    'prepayments' => $tableOrder->order->prepayments,
                    'total_prepayment' => $tableOrder->order->totalPrepayments(),
                    'total_price' => $tableOrder->order->stocks->sum(function ($stock) {
                        $selectedDetail = $stock->pivot->detail_id
                            ? $stock->details->firstWhere('id', $stock->pivot->detail_id)
                            : null;
        
                        return $selectedDetail
                            ? ($selectedDetail->price * $stock->pivot->quantity)
                            : ($stock->price * $stock->pivot->quantity);
                    }),
                ];
            }),
        ];
        
        return response()->json(['table' => $t]);        
    }
    
    



    public function cancelOrder(Request $request, $tableId)
    {
        DB::beginTransaction();

        try {
            // Ensure the table belongs to the user's restaurant
            $table = Table::where('restaurant_id', $request->user()->restaurant_id)->find($tableId);

            // If the table is not found, return a 404 Not Found response
            if (!$table) {
                return response()->json(['error' => 'Table not found.'], 404);
            }

            // Check if there is an approved order for the table
            $tableOrder = $table->tableOrders()
                ->whereHas('order', function ($query) {
                    $query->where('status', 'approved');
                })
                ->first();

            // If no approved order exists, return a 404 Not Found response
            if (!$tableOrder) {
                return response()->json(['error' => 'No approved order found for this table.'], 404);
            }

            // Cancel the order by setting the status to 'canceled'
            $tableOrder->order->update(['status' => 'canceled']);

            // Commit the transaction
            DB::commit();

            return response()->json(['message' => 'Order cancelled successfully']);
        } catch (\Exception $e) {
            // Rollback the transaction in case of an error
            DB::rollback();
            return response()->json(['error' => 'Failed to cancel order. ' . $e->getMessage()], 500);
        }
    }

    public function changeTables(ChangeTableRequest $request, $tableId)
    {
        $table = Table::where('restaurant_id', $request->user()->restaurant_id)->find($tableId);

        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }

        if ($table->isAvailable()) {
            return response()->json(['error' => 'Table is available.'], 400);
        }

        $newTable = Table::where('restaurant_id', $request->user()->restaurant_id)->find($request->table_id);

        if (!$newTable) {
            return response()->json(['error' => 'New table not found.'], 404);
        }

        if (!$newTable->isAvailable()) {
            return response()->json(['error' => 'New table is not available.'], 400);
        }

        $table->tableOrders()->update(['table_id' => $newTable->id]);


        return response()->json($newTable);
    }

    public function mergeTables(Request $request, $sourceTableId)
    {
        // Start transaction to ensure atomicity
        DB::beginTransaction();

        try {
            // Fetch the source and destination tables
            $restaurantId = $request->user()->restaurant_id;
            $sourceTable = Table::where('restaurant_id', $restaurantId)->findOrFail($sourceTableId);
            $destinationTable = Table::where('restaurant_id', $restaurantId)->findOrFail($request->table_id);

            // Fetch approved orders for both tables
            $sourceOrder = $sourceTable->tableOrders()
                ->whereHas('order', function ($query) {
                    $query->where('status', 'approved');
                })->first();

            $destinationOrder = $destinationTable->tableOrders()
                ->whereHas('order', function ($query) {
                    $query->where('status', 'approved');
                })->first();

            if (!$sourceOrder || !$destinationOrder) {
                return response()->json(['message' => 'Both tables must have an approved order to merge.'], 400);
            }

            // Loop through stocks in the source order and add them to the destination order
            foreach ($sourceOrder->order->stocks as $stock) {
                $existingStock = $destinationOrder->order->stocks()->where('stock_id', $stock->id)->first();

                if ($existingStock) {
                    // If the stock exists, increase the quantity
                    $destinationOrder->order->stocks()->updateExistingPivot($stock->id, [
                        'quantity' => $existingStock->pivot->quantity + $stock->pivot->quantity,
                    ]);
                } else {
                    // Otherwise, attach the stock to the destination order
                    $destinationOrder->order->stocks()->attach($stock->id, [
                        'quantity' => $stock->pivot->quantity,
                    ]);
                }
            }

            // Remove all stocks from the source order after merging
            $sourceOrder->order->update(['status' => 'canceled']);

            DB::commit();

            return response()->json(['message' => 'Tables merged successfully.', 'order' => $destinationOrder->order->load('stocks')]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error merging tables.', 'error' => $e->getMessage()], 500);
        }
    }

    public function generateQrCode(Request $request, $id)
    {
        $table = Table::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($id);

        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }

        $uniqueUrl = Str::uuid();

        // $qrCode = QrCode::format('png')->size(300)->generate($uniqueUrl);
        // $qrImagePath = 'qr_images/' . Str::random(10) . '.png';
        // Storage::disk('public')->put($qrImagePath, $qrCode);

        $table->update([
            // 'qr_image' => $qrImagePath,
            'unique_url' => $uniqueUrl,
        ]);


        return response($uniqueUrl);
    }

    public function getQrCode(Request $request, $id)
    {
        $table = Table::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($id);

        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }



        return response($table->unique_url);
    }

    // public function getTableByQrCode(Request $request, $uniqueUrl)
    // {
    //     $table = Table::where('unique_url', $uniqueUrl)
    //         ->with([
    //             'tableOrders' => function ($query) {
    //                 // Load tableOrders with related orders that have status 'approved' or 'pending_approval'
    //                 $query->whereHas('order', function ($query) {
    //                     $query->whereIn('status', ['approved', 'pending_approval']);
    //                 })
    //                     ->with(['order.stocks', 'order.prepayments']); // Eager load stocks and prepayments for the orders
    //             }
    //         ])
    //         ->first();

    //     if (!$table) {
    //         return response()->json(['error' => 'Table not found.'], 404);
    //     }

    //     // Helper function to transform orders
    //     $transformOrder = function ($tableOrder) {
    //         $order = $tableOrder->order;
    //         $stocks = $order->stocks->map(function ($stock) {
    //             return [
    //                 'id' => $stock->id,
    //                 'name' => $stock->name,
    //                 'quantity' => $stock->pivot->quantity,
    //                 'price' => $stock->price * $stock->pivot->quantity,
    //             ];
    //         });

    //         return [
    //             'order_id' => $order->id,
    //             'status' => $order->status,
    //             'stocks' => $stocks,
    //             'prepayments' => $order->prepayments,
    //             'total_prepayment' => $order->totalPrepayments(),
    //             'total_price' => $stocks->sum(fn($stock) => $stock['price']),
    //         ];
    //     };

    //     // Separate approved and pending approval orders
    //     $approvedOrders = $table->tableOrders->where('order.status', 'approved')->map($transformOrder);
    //     $pendingApprovalOrders = $table->tableOrders->where('order.status', 'pending_approval')->map($transformOrder);

    //     return response()->json([
    //         'table' => [
    //             'name' => $table->name,
    //             'id' => $table->id,
    //             'orders' => [
    //                 'approved' => [
    //                     'orders' => $approvedOrders,
    //                     'total_price' => $approvedOrders->sum(fn($order) => $order['total_price']),
    //                 ],
    //                 'pending_approval' => [
    //                     'orders' => array_values($pendingApprovalOrders->toArray()),
    //                     'total_price' => $pendingApprovalOrders->sum(fn($order) => $order['total_price']),
    //                 ],
    //             ]
    //         ]
    //     ]);
    // }

    // public function getTableByQrCode(Request $request, $uniqueUrl)
    // {
    //     $table = Table::where('unique_url', $uniqueUrl)
    //         ->with([
    //             'tableOrders' => function ($query) {
    //                 $query->whereHas('order', function ($query) {
    //                     $query->whereIn('status', ['approved', 'pending_approval']);
    //                 })
    //                     ->with(['order.stocks.details', 'order.prepayments']); // Eager load stocks and prepayments for the orders
    //             }
    //         ])
    //         ->first();
    
    //     if (!$table) {
    //         return response()->json(['error' => 'Table not found.'], 404);
    //     }
    
    //     // Helper function to transform orders
    //     $transformOrder = function ($tableOrder) {
    //         $order = $tableOrder->order;
    //         $stocks = $order->stocks->map(function ($stock) {
    //             // Stok detalları varsa, əlaqəli qiymətləri gətiririk
    //             $detail = StockDetail::where('stock_id', $stock->id)->get(['id', 'price', 'unit', 'count']);
    
    //             $price = $detail->isNotEmpty()
    //                 ? $detail->sum('price') * $stock->pivot->quantity
    //                 : $stock->price * $stock->pivot->quantity;
    
    //             return [
    //                 'pivot_id' => $stock->pivot->id,
    //                 'id' => $stock->id,
    //                 'name' => $stock->name,
    //                 'quantity' => $stock->pivot->quantity,
    //                 'price' => $price,
    //                 'detail' => $detail->toArray(), // Bütün StockDetail məlumatlarını əlavə edirik
    //             ];
    //         });
    
    //         return [
    //             'order_id' => $order->id,
    //             'status' => $order->status,
    //             'stocks' => $stocks,
    //             'prepayments' => $order->prepayments,
    //             'total_prepayment' => $order->totalPrepayments(),
    //             'total_price' => $stocks->sum(fn($stock) => $stock['price']),
    //         ];
    //     };
    
    //     // Separate approved and pending approval orders
    //     $approvedOrders = $table->tableOrders->where('order.status', 'approved')->map($transformOrder);
    //     $pendingApprovalOrders = $table->tableOrders->where('order.status', 'pending_approval')->map($transformOrder);
    
    //     return response()->json([
    //         'table' => [
    //             'name' => $table->name,
    //             'id' => $table->id,
    //             'orders' => [
    //                 'approved' => [
    //                     'orders' => array_values($approvedOrders->toArray()),
    //                     'total_price' => $approvedOrders->sum(fn($order) => $order['total_price']),
    //                 ],
    //                 'pending_approval' => [
    //                     'orders' => array_values($pendingApprovalOrders->toArray()),
    //                     'total_price' => $pendingApprovalOrders->sum(fn($order) => $order['total_price']),
    //                 ],
    //             ]
    //         ]
    //     ]);
    // }
    
    public function getTableByQrCode(Request $request, $uniqueUrl)
    {
        $table = Table::where('unique_url', $uniqueUrl)
            ->with([
                'tableOrders' => function ($query) {
                    $query->whereHas('order', function ($query) {
                        $query->whereIn('status', ['approved', 'pending_approval']);
                    })
                    ->with([
                        'order.stocks' => function ($query) {
                            $query->with('details'); // **Stock-un detallarını yükləyirik**
                        },
                        'order.prepayments'
                    ]);
                }
            ])
            ->first();
    
        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }
    
        // Helper function to transform orders
        $transformOrder = function ($tableOrder) {
            $order = $tableOrder->order;
    
            $stocks = $order->stocks->map(function ($stock) {
                // **Əgər pivot->detail_id varsa, yalnız həmin detail-in qiymətini götürürük**
                $selectedDetail = $stock->pivot->detail_id
                    ? $stock->details->firstWhere('id', $stock->pivot->detail_id)
                    : null;
    
                $pricePerUnit = $selectedDetail ? $selectedDetail->price : $stock->price;
                $totalStockPrice = $pricePerUnit * $stock->pivot->quantity;
    
                return [
                    'id' => $stock->id,
                    'name' => $stock->name,
                    'quantity' => $stock->pivot->quantity,
                    'unit_price' => $pricePerUnit,
                    'total_price' => $totalStockPrice, // **Cəmi qiymət**
                    'detail' => $selectedDetail ? [
                        'id' => $selectedDetail->id,
                        'price' => $selectedDetail->price,
                        'unit' => $selectedDetail->unit,
                        'count' => $selectedDetail->count,
                    ] : null, // **Əgər `detail_id` yoxdursa, `null` qaytarılır**
                ];
            });
    
            return [
                'order_id' => $order->id,
                'status' => $order->status,
                'stocks' => $stocks,
                'prepayments' => $order->prepayments,
                'total_prepayment' => $order->totalPrepayments(),
                'total_price' => $stocks->sum(fn($stock) => $stock['total_price']), // **Düzgün cəmi qiymət**
            ];
        };
    
        // Separate approved and pending approval orders
        $approvedOrders = $table->tableOrders->where('order.status', 'approved')->map($transformOrder);
        $pendingApprovalOrders = $table->tableOrders->where('order.status', 'pending_approval')->map($transformOrder);
    
        return response()->json([
            'table' => [
                'name' => $table->name,
                'id' => $table->id,
                'orders' => [
                    'approved' => [
                        'orders' => $approvedOrders,
                        'total_price' => $approvedOrders->sum(fn($order) => $order['total_price']),
                    ],
                    'pending_approval' => [
                        'orders' => array_values($pendingApprovalOrders->toArray()),
                        'total_price' => $pendingApprovalOrders->sum(fn($order) => $order['total_price']),
                    ],
                ]
            ]
        ]);
    }
    


    


    public function getOrderByQrCode(Request $request, $uniqueUrl)
    {
        $table = Table::where('unique_url', $uniqueUrl)->first();

        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }

        $tableWithApprovedOrders = $table
            ->load([
                'tableOrders' => function ($query) {
                    // Only load tableOrders that have an associated approved order
                    $query->whereHas('order', function ($query) {
                        $query->where('status', 'approved');
                    })
                        ->with('order.stocks')
                        ->with('order.prepayments'); // Eager load stocks for the orders
                }
            ]);

        $t = [
            'name' => $tableWithApprovedOrders->name,
            'id' => $tableWithApprovedOrders->id,
            'orders' => $tableWithApprovedOrders->tableOrders->map(function ($tableOrder) {
                return [
                    'order_id' => $tableOrder->order->id,
                    'status' => $tableOrder->order->status,
                    'stocks' => $tableOrder->order->stocks->map(function ($stock) {
                        return [
                            'id' => $stock->id,
                            'name' => $stock->name,
                            'quantity' => $stock->pivot->quantity,
                            'price' => $stock->price * $stock->pivot->quantity,
                        ];
                    }),
                    'prepayments' => $tableOrder->order->prepayments,
                    'total_prepayment' => $tableOrder->order->totalPrepayments(),
                    'total_price' => $tableOrder->order->stocks->sum(function ($stock) {
                        return $stock->price * $stock->pivot->quantity;
                    }),
                ];
            }),
        ];

        return response()->json([
            'table' => $t,
        ]);
    }

    // Old
    // public function createQrOrder(CreateQrOrderRequest $request, $uniqueUrl)
    // {
    //     $table = Table::where('unique_url', $uniqueUrl)->first();

    //     if (!$table) {
    //         return response()->json(['error' => 'Table not found.'], 404);
    //     }

    //     if (!$table->restaurant->is_qr_active || !$table->restaurant->get_qr_order) {
    //         return response()->json(['error' => 'QR menu is not active.'], 400);
    //     }

    //     $data = $request->validated();

    //     DB::beginTransaction();
    //     try {
    //         $order = Order::create([
    //             'restaurant_id' => $table->restaurant_id,
    //             'status' => 'pending_approval',
    //             // 'user_id' => $request->user()->id,
    //         ]);

    //         $tableOrder = $table->tableOrders()->create([
    //             'order_id' => $order->id,
    //             'restaurant_id' => $table->restaurant_id,
    //         ]);

    //         foreach ($data['stocks'] as $stockData) {
    //             $stock = Stock::where('restaurant_id', $table->restaurant_id)->where('show_on_qr', true)->find($stockData['stock_id']);

    //             if (!$stock) {
    //                 return response()->json(['error' => 'Stock not found.'], 404);
    //             }

    //             $order->stocks()->attach($stockData['stock_id'], [
    //                 'quantity' => $stockData['quantity'],
    //             ]);
    //         }

    //         DB::commit();

    //         return response()->json($order->load('stocks'));
    //     } catch (\Exception $e) {
    //         DB::rollback();
    //         return response()->json(['error' => 'Failed to create order. ' . $e->getMessage()], 500);
    //     }
    // }


    public function createQrOrder(CreateQrOrderRequest $request, $uniqueUrl)
    {
        $table = Table::where('unique_url', $uniqueUrl)->first();
    
        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }
    
        if (!$table->restaurant->is_qr_active || !$table->restaurant->get_qr_order) {
            return response()->json(['error' => 'QR menu is not active.'], 400);
        }
    
        $data = $request->validated();
    
        DB::beginTransaction();
        try {
            $order = Order::create([
                'restaurant_id' => $table->restaurant_id,
                'status' => 'pending_approval',
            ]);
    
            $tableOrder = $table->tableOrders()->create([
                'order_id' => $order->id,
                'restaurant_id' => $table->restaurant_id,
            ]);
    
            foreach ($data['stocks'] as $stockData) {
                $stock = Stock::where('restaurant_id', $table->restaurant_id)
                    ->where('show_on_qr', true)
                    ->find($stockData['stock_id']);
    
                if (!$stock) {
                    return response()->json(['error' => 'Stock not found.'], 404);
                }
    
                // **Əgər `quantity` null gəlirsə, default olaraq `1` təyin edirik**
                $quantity = $stockData['quantity'] ?? 1;
    
                // **Əgər `detail_id` varsa, yalnız həmin `detail_id`-yə uyğun məlumatı götür**
                $detailId = $stockData['detail_id'] ?? null;
    
                if ($detailId) {
                    $selectedDetail = StockDetail::where('id', $detailId)
                        ->where('stock_id', $stock->id)
                        ->first();
    
                    if (!$selectedDetail) {
                        return response()->json(['error' => 'Invalid detail_id for stock.'], 400);
                    }
                }
    
                // **Sifarişə `stock_id`, `quantity`, `detail_id` ilə əlavə edirik**
                $order->stocks()->attach($stockData['stock_id'], [
                    'quantity' => $quantity,
                    'detail_id' => $detailId, // **Əgər `detail_id` varsa, əlavə olunur, yoxdursa `null`**
                ]);
            }
    
            DB::commit();
    
            return response()->json([
                'id' => $order->id,
                'status' => $order->status,
                'stocks' => $order->stocks->map(function ($stock) {
                    $selectedDetail = $stock->pivot->detail_id
                        ? StockDetail::where('id', $stock->pivot->detail_id)->first()
                        : null;
    
                    return [
                        'id' => $stock->id,
                        'name' => $stock->name,
                        'image' => $stock->image,
                        'price' => $selectedDetail ? $selectedDetail->price : $stock->price,
                        'quantity' => $stock->pivot->quantity,
                        'detail' => $selectedDetail ? [
                            'id' => $selectedDetail->id,
                            'price' => $selectedDetail->price,
                            'unit' => $selectedDetail->unit,
                            'count' => $selectedDetail->count,
                        ] : null, // **Əgər `detail_id` yoxdursa, null qaytarılır**
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['error' => 'Failed to create order. ' . $e->getMessage()], 500);
        }
    }
    



    // public function getQrMenu(Request $request, $uniqueUrl)
    // {
    //     $table = Table::where('unique_url', $uniqueUrl)->first();

    //     if (!$table) {
    //         return response()->json(['error' => 'Table not found.'], 404);
    //     }

    //     $restaurant = $table->restaurant;

    //     if (!$restaurant->is_qr_active) {
    //         return response()->json(['error' => 'QR menu is not active.', 'restaurant' => $restaurant], 400);
    //     }

    //     $stockGroups = $restaurant->stockGroups()->where('show_on_qr_menu', true)->with(
    //         ['stocks' => function ($query) {
    //             $query->where('show_on_qr', true);
    //         }]
    //     )->get();


    //     return response()->json([
    //         'stockGroups' => $stockGroups,
    //         'restaurant' => $restaurant,
    //         'table' => $table,
    //     ]);
    // }

    public function getQrMenu(Request $request, $uniqueUrl)
    {
        $table = Table::where('unique_url', $uniqueUrl)->first();
    
        if (!$table) {
            return response()->json(['error' => 'Table not found.'], 404);
        }
    
        $restaurant = $table->restaurant;
    
        if (!$restaurant->is_qr_active) {
            return response()->json(['error' => 'QR menu is not active.', 'restaurant' => $restaurant], 400);
        }
    
        $stockGroups = $restaurant->stockGroups()
            ->where('show_on_qr_menu', true)
            ->with(['stocks' => function ($query) {
                $query->where('show_on_qr', true)->with('details'); // Eager load stock details
            }])->get();
    
        // Format the response to include stock images
        $formattedStockGroups = $stockGroups->map(function ($group) {
            return [
                'id' => $group->id,
                'name' => $group->name,
                'stocks' => $group->stocks->map(function ($stock) {
                    return [
                        'id' => $stock->id,
                        'name' => $stock->name,
                        'image' => $stock->image, // Ensure image field is included
                        'unit' => $stock->unit,
                        'price' => $stock->price,
                        'details' => $stock->details->map(function ($detail) {
                            return [
                                'id' => $detail->id,
                                'stock_id' => $detail->stock_id,
                                'price' => $detail->price,
                                'unit' => $detail->unit,
                                'count' => $detail->count,
                                'created_at' => $detail->created_at,
                                'updated_at' => $detail->updated_at,
                            ];
                        }),
                    ];
                }),
            ];
        });
    
        return response()->json([
            'stockGroups' => $formattedStockGroups,
            'restaurant' => $restaurant,
            'table' => $table,
        ]);
    }
    
    
    // public function getPendingApprovalOrders(Request $request)
    // {
    //     $restaurant = $request->user()->restaurant;

    //     $tables = Table::where('restaurant_id', $restaurant->id)
    //         ->with([
    //             'tableOrders' => function ($query) {
    //                 $query->whereHas('order', function ($query) {
    //                     $query->where('status', 'pending_approval');
    //                 })
    //                     ->with('order.stocks')
    //                     // ->with('order.stocks.pivot')
    //                     ->with('order.prepayments');
    //             }
    //         ])
    //         ->whereHas('tableOrders', function ($query) {
    //             $query->whereHas('order', function ($query) {
    //                 $query->where('status', 'pending_approval');
    //             });
    //         })
    //         ->get();

    //     return response()->json([
    //         'tables' => $tables,
    //     ]);
    // }


    //  istenilen kimidir amma sifaris islemir 
    // public function getPendingApprovalOrders(Request $request)
    // {
    //     $restaurant = $request->user()->restaurant;
    
    //     $tables = Table::where('restaurant_id', $restaurant->id)
    //         ->with([
    //             'tableOrders' => function ($query) {
    //                 $query->whereHas('order', function ($query) {
    //                     $query->where('status', 'pending_approval');
    //                 })
    //                 ->with([
    //                     'order.stocks' => function ($query) {
    //                         $query->with('details'); // **Stock-a bağlı detalları yükləyirik**
    //                     },
    //                     'order.prepayments'
    //                 ]);
    //             }
    //         ])
    //         ->whereHas('tableOrders', function ($query) {
    //             $query->whereHas('order', function ($query) {
    //                 $query->where('status', 'pending_approval');
    //             });
    //         })
    //         ->get();
    
    //     // **JSON formatına uyğunlaşdırılmış cavab**
    //     $formattedTables = $tables->map(function ($table) {
    //         return [
    //             'id' => $table->id,
    //             'name' => $table->name,
    //             'orders' => $table->tableOrders->map(function ($tableOrder) {
    //                 $order = $tableOrder->order;
    //                 return [
    //                     'id' => $order->id,
    //                     'status' => $order->status,
    //                     'stocks' => $order->stocks->map(function ($stock) {
    //                         $selectedDetail = $stock->pivot->detail_id
    //                             ? StockDetail::where('id', $stock->pivot->detail_id)->first()
    //                             : null;
    
    //                         return [
    //                             'id' => $stock->id,
    //                             'name' => $stock->name,
    //                             'image' => $stock->image,
    //                             'price' => $selectedDetail ? $selectedDetail->price : $stock->price,
    //                             'quantity' => $stock->pivot->quantity,
    //                             'detail' => $selectedDetail ? [
    //                                 'id' => $selectedDetail->id,
    //                                 'price' => $selectedDetail->price,
    //                                 'unit' => $selectedDetail->unit,
    //                                 'count' => $selectedDetail->count,
    //                             ] : null, // **Əgər `detail_id` yoxdursa, `null` qaytarılır**
    //                         ];
    //                     }),
    //                     'prepayments' => $order->prepayments->map(function ($prepayment) {
    //                         return [
    //                             'id' => $prepayment->id,
    //                             'amount' => $prepayment->amount,
    //                             'created_at' => $prepayment->created_at,
    //                         ];
    //                     }),
    //                 ];
    //             }),
    //         ];
    //     });
    
    //     return response()->json([
    //         'tables' => $formattedTables,
    //     ]);
    // }
    

     // 2 ci alinan
     

     public function getPendingApprovalOrders(Request $request)
{
    $restaurant = $request->user()->restaurant;

    $tables = Table::where('restaurant_id', $restaurant->id)
        ->with([
            'tableOrders' => function ($query) {
                $query->whereHas('order', function ($query) {
                    $query->where('status', 'pending_approval');
                })
                ->with([
                    'order.stocks' => function ($query) {
                        $query->with('details'); 
                    },
                    'order.prepayments'
                ]);
            }
        ])
        ->whereHas('tableOrders', function ($query) {
            $query->whereHas('order', function ($query) {
                $query->where('status', 'pending_approval');
            });
        })
        ->get();

    $formattedTables = $tables->map(function ($table) {
        return [
            'id' => $table->id,
            'unique_url' => $table->unique_url,
            'qr_image' => $table->qr_image,
            'restaurant_id' => $table->restaurant_id,
            'table_group_id' => $table->table_group_id,
            'name' => $table->name,
            'created_at' => $table->created_at,
            'updated_at' => $table->updated_at,
            'table_orders' => $table->tableOrders->map(function ($tableOrder) {
                $order = $tableOrder->order; 

                if (!$order) {
                    return [
                        'id' => $tableOrder->id,
                        'table_id' => $tableOrder->table_id,
                        'restaurant_id' => $tableOrder->restaurant_id,
                        'order_id' => null,
                        'created_at' => $tableOrder->created_at,
                        'updated_at' => $tableOrder->updated_at,
                        'order' => null, 
                    ];
                }

                return [
                    'id' => $tableOrder->id,
                    'table_id' => $tableOrder->table_id,
                    'restaurant_id' => $tableOrder->restaurant_id,
                    'order_id' => $order->id,
                    'created_at' => $tableOrder->created_at,
                    'updated_at' => $tableOrder->updated_at,
                    'order' => [
                        'id' => $order->id,
                        'restaurant_id' => $order->restaurant_id,
                        'status' => $order->status,
                        'created_at' => $order->created_at,
                        'updated_at' => $order->updated_at,
                        'user_id' => $order->user_id,
                        'stocks' => $order->stocks->map(function ($stock) {
                            $selectedDetail = $stock->pivot->detail_id
                                ? $stock->details->where('id', $stock->pivot->detail_id)->first()
                                : null;

                            return [
                                'id' => $stock->id,
                                'restaurant_id' => $stock->restaurant_id,
                                'stock_group_id' => $stock->stock_group_id,
                                'name' => $stock->name,
                                'image' => $stock->image,
                                'show_on_qr' => $stock->show_on_qr,
                                'price' => $selectedDetail ? $selectedDetail->price : $stock->price,
                                'amount' => $stock->amount,
                                'critical_amount' => $stock->critical_amount,
                                'alert_critical' => $stock->alert_critical,
                                'order_start' => $stock->order_start,
                                'order_stop' => $stock->order_stop,
                                'created_at' => $stock->created_at,
                                'updated_at' => $stock->updated_at,
                                'description' => $stock->description,
                                'pivot' => [
                                    'stock_id' => $stock->id,
                                    'id' => $stock->pivot->id,
                                    'quantity' => $stock->pivot->quantity,
                                    'detail_id' => $stock->pivot->detail_id,
                                    'created_at' => $stock->pivot->created_at,
                                    'updated_at' => $stock->pivot->updated_at,
                                ],
                                'detail' => $selectedDetail ? [
                                    'id' => $selectedDetail->id,
                                    'price' => $selectedDetail->price,
                                    'unit' => $selectedDetail->unit,
                                    'count' => $selectedDetail->count,
                                ] : null,
                            ];
                        }),
                        'prepayments' => $order->prepayments->map(function ($prepayment) {
                            return [
                                'id' => $prepayment->id,
                                'amount' => $prepayment->amount,
                                'created_at' => $prepayment->created_at,
                            ];
                        }),
                    ],
                ];
            }),
        ];
    });

    return response()->json([
        'tables' => $formattedTables,
    ]);
}




    // public function approvePendingOrder(Request $request, $table_order_id)
    // {
    //     $tableOrder = TableOrder::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($table_order_id);

    //     if (!$tableOrder) {
    //         return response()->json(['error' => 'Table order not found.'], 404);
    //     }

    //     $order = $tableOrder->order;



    //     if ($order->status != 'pending_approval') {
    //         return response()->json(['error' => 'Order is not pending approval.'], 400);
    //     }

    //     $approvedTableOrder = TableOrder::where('restaurant_id', $request->user()->restaurant_id)
    //         ->where('table_id', $tableOrder->table_id)
    //         ->whereHas('order', function ($query) {
    //             $query->where('status', 'approved');
    //         })
    //         ->first();

    //     if ($approvedTableOrder) {
    //         // Approved order exists, add stocks to it
    //         // Your code to add stocks here
    //         // Add stocks to the approved order
    //         foreach ($tableOrder->order->stocks as $stock) {
    //             $existingStock = $approvedTableOrder->order->stocks()->where('stock_id', $stock->id)->first();

    //             if ($existingStock) {
    //                 // If the stock exists, increase the quantity
    //                 $approvedTableOrder->order->stocks()->updateExistingPivot($stock->id, [
    //                     'quantity' => $existingStock->pivot->quantity + $stock->pivot->quantity,
    //                 ]);
    //             } else {
    //                 // Otherwise, attach the stock to the approved order
    //                 $approvedTableOrder->order->stocks()->attach($stock->id, [
    //                     'quantity' => $stock->pivot->quantity,
    //                 ]);
    //             }
    //         }

    //         // Delete the pending order
    //         $tableOrder->order->delete();
    //     } else {
    //         // No approved order exists, change status
    //         $order->update(['status' => 'approved', 'user_id' => $request->user()->id]);
    //     }

    //     return response()->json($order->load('stocks'));
    // }

    public function approvePendingOrder(Request $request, $table_order_id)
    {
        $tableOrder = TableOrder::where('restaurant_id', $request->user()->restaurant_id)
            ->find($table_order_id);
    
        if (!$tableOrder) {
            return response()->json(['error' => 'Table order not found.'], 404);
        }
    
        $order = $tableOrder->order;
    
        if ($order->status !== 'pending_approval') {
            return response()->json(['error' => 'Order is not pending approval.'], 400);
        }
    
        $approvedTableOrder = TableOrder::where('restaurant_id', $request->user()->restaurant_id)
            ->where('table_id', $tableOrder->table_id)
            ->whereHas('order', fn($q) => $q->where('status', 'approved'))
            ->first();
    
        if ($approvedTableOrder) {
            foreach ($tableOrder->order->stocks as $stock) {
                $existingStock = $approvedTableOrder->order->stocks()
                    ->wherePivot('detail_id', $stock->pivot->detail_id)
                    ->where('stock_id', $stock->id)
                    ->first();
    
                if ($existingStock) {
                    $approvedTableOrder->order->stocks()->updateExistingPivot($stock->id, [
                        'quantity' => $existingStock->pivot->quantity + $stock->pivot->quantity,
                        'detail_id' => $stock->pivot->detail_id,
                    ]);
                } else {
                    $approvedTableOrder->order->stocks()->attach($stock->id, [
                        'quantity' => $stock->pivot->quantity,
                        'detail_id' => $stock->pivot->detail_id,
                    ]);
                }
            }
    
            $tableOrder->order->delete();
    
            return response()->json($approvedTableOrder->order->load('stocks'));
        }

    // Əgər təsdiqlənmiş order yoxdursa, sadəcə statusu dəyiş
    $order->update([
        'status' => 'approved',
        'user_id' => $request->user()->id
    ]);

    return response()->json($order->load('stocks'));
  }

    public function cancelPendingOrder(Request $request, $table_order_id)
    {
        $tableOrder = TableOrder::where('restaurant_id', $request->user()->restaurant_id)->findOrFail($table_order_id);

        if (!$tableOrder) {
            return response()->json(['error' => 'Table order not found.'], 404);
        }

        $order = $tableOrder->order;

        if ($order->status != 'pending_approval') {
            return response()->json(['error' => 'Order is not pending approval.'], 400);
        }

        $order->delete();

        return response()->json(['message' => 'Order canceled successfully']);
    }
}
