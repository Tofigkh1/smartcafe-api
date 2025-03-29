<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    

    // public function index(Request $request)
    // {
    //     $restaurant = $request->user()->restaurant;

    //     // $openTime = $restaurant->open_time; 
    //     // $closeTime = $restaurant->close_time; 
    //     $openTime = '8:00'; 
    //     $closeTime = '18:00'; 

    //     $currentDate = now()->toDateString(); 
    //     $startDateTime = $currentDate . ' ' . $openTime; // Cari günün açılma vaxtı
    //     $endDateTime = $currentDate . ' ' . $closeTime; // Cari günün bağlanma vaxtı

    //     if (now()->toTimeString() > $closeTime) {
    //         $nextStartDate = now()->addDay()->toDateString();
    //         $startDateTime = $nextStartDate . ' ' . $openTime;
    //         $endDateTime = $nextStartDate . ' ' . $closeTime;
    //     }

    //     $cashQuery = Payment::where('payments.restaurant_id', $request->user()->restaurant_id)
    //         ->where('type', 'cash');
    //     $bankQuery = Payment::where('payments.restaurant_id', $request->user()->restaurant_id)
    //         ->where('type', 'bank');

    //     $query = Payment::where('payments.restaurant_id', $request->user()->restaurant_id)
    //         ->whereBetween('open_date', [$startDateTime, $endDateTime]) // Cari dövr üçün filtr
    //         ->whereBetween('close_date', [$startDateTime, $endDateTime]) // Cari dövr üçün filtr
    //         ->leftJoin('users', 'payments.user_id', '=', 'users.id') // Join with users table
    //         ->leftJoin('customers', 'payments.customer_id', '=', 'customers.id')  // Left join with customers table
    //         ->select(
    //             'order_id',
    //             'order_name',
    //             'open_date',   // Add open_date
    //             'close_date',
    //             'users.name as user_name',  // Select user name from users table
    //             'customers.name as customer_name',
    //             DB::raw('SUM(amount) as total_amount'),
    //             DB::raw("
    //             CASE
    //                 WHEN COUNT(DISTINCT type) > 1 THEN 'mixed'  -- If more than one type, return 'mixed'
    //                 ELSE MAX(type)  -- Otherwise, return the single type
    //             END as type
    //         "),
    //             DB::raw('ROUND((UNIX_TIMESTAMP(close_date) - UNIX_TIMESTAMP(open_date)) * 1000) as time_taken_ms'), // Calculate the time difference in milliseconds
    //             DB::raw('FLOOR((UNIX_TIMESTAMP(close_date) - UNIX_TIMESTAMP(open_date)) / 86400) as days_taken'), // Calculate the difference in days
    //             DB::raw('MOD(FLOOR((UNIX_TIMESTAMP(close_date) - UNIX_TIMESTAMP(open_date)) / 3600), 24) as hours_taken'), // Calculate the difference in hours
    //             DB::raw('MOD(FLOOR((UNIX_TIMESTAMP(close_date) - UNIX_TIMESTAMP(open_date)) / 60), 60) as minutes_taken'), // Calculate the difference in minutes
    //             DB::raw('MOD((UNIX_TIMESTAMP(close_date) - UNIX_TIMESTAMP(open_date)), 60) as seconds_taken') // Calculate the difference in seconds
    //         );

    //     // Apply filters
    //     if ($request->has('open_date')) {
    //         $query->whereDate('open_date', '>=', $request->input('open_date'));
    //         $cashQuery->whereDate('open_date', '>=', $request->input('open_date'));
    //         $bankQuery->whereDate('open_date', '>=', $request->input('open_date'));
    //     }

    //     if ($request->has('close_date')) {
    //         $query->whereDate('close_date', '<=', $request->input('close_date'));
    //         $cashQuery->whereDate('close_date', '<=', $request->input('close_date'));
    //         $bankQuery->whereDate('close_date', '<=', $request->input('close_date'));
    //     }

    //     if ($request->has('user_id')) {
    //         $query->where('payments.user_id', $request->input('user_id'));
    //         $cashQuery->where('payments.user_id', $request->input('user_id'));
    //         $bankQuery->where('payments.user_id', $request->input('user_id'));
    //     }

    //     if ($request->has('type')) {

    //         $type = $request->input('type');

    //         if ($type === 'mixed') {
    //             $query->havingRaw('COUNT(DISTINCT type) > 1');
    //             // $cashQuery->where('type', 'cash')->havingRaw('COUNT(DISTINCT type) > 1');
    //             // $bankQuery->where('type', 'bank')->havingRaw('COUNT(DISTINCT type) > 1');
    //         } else {
    //             $query->where('type', $type);
    //             $cashQuery->where('type', $request->input('type'));
    //             $bankQuery->where('type', $request->input('type'));
    //         }
    //         // $query->where('type', $request->input('type'));

    //     }

        

    //     $payments = $query->groupBy(
    //         'order_id',
    //         'order_name',
    //         'open_date',   // Add open_date
    //         'close_date',
    //         'users.name',  // Group by user_name
    //         'customers.name'
    //     )->get();

    //     // $totalAmount = $payments->sum('total_amount');
    //     $totalAmount = (float) Payment::where('restaurant_id', $restaurant->id)
    //     ->whereBetween('open_date', [$startDateTime, $endDateTime])
    //     ->whereBetween('close_date', [$startDateTime, $endDateTime])
    //     ->sum('amount');

    //     $totalCash = $cashQuery
    //         ->sum('amount');

    //     $totalBank = $bankQuery
    //         ->sum('amount');

    //     return response()->json([
    //         "payments" => $payments,
    //         "total_amount" => $totalAmount,
    //         "total_cash" => $totalCash,
    //         "total_bank" => $totalBank,
    //     ]);
    // }

    public function index(Request $request)
    {
        $restaurant = $request->user()->restaurant;
    
        $openTime = $restaurant->open_time ?? '6:00'; // Əgər null-dursa, '12:00' götür
        $closeTime = $restaurant->close_time ?? '18:00'; // Əgər null-dursa, '18:00' götür
    
        $currentDate = now()->toDateString(); 
        $startDateTime = $currentDate . ' ' . $openTime; // Cari günün açılma vaxtı
        $endDateTime = $currentDate . ' ' . $closeTime; // Cari günün bağlanma vaxtı
    
        // Əgər cari vaxt bağlanma vaxtını keçibsə, növbəti dövrə keç
        if (now()->toTimeString() > $closeTime) {
            $nextStartDate = now()->addDay()->toDateString();
            $startDateTime = $nextStartDate . ' ' . $openTime;
            $endDateTime = $nextStartDate . ' ' . $closeTime;
        }
    
        // Filtrlər
        if ($request->has('open_date')) {
            $startDateTime = $request->input('open_date');
        }
    
        if ($request->has('close_date')) {
            $endDateTime = $request->input('close_date');
        }
    
        // Sorğular
        $query = Payment::where('payments.restaurant_id', $request->user()->restaurant_id)
            ->whereBetween('open_date', [$startDateTime, $endDateTime])
            ->whereBetween('close_date', [$startDateTime, $endDateTime])
            ->leftJoin('users', 'payments.user_id', '=', 'users.id')
            ->leftJoin('customers', 'payments.customer_id', '=', 'customers.id')
            ->select(
                'order_id',
                'order_name',
                'open_date',
                'close_date',
                'users.name as user_name',
                'customers.name as customer_name',
                DB::raw('SUM(amount) as total_amount'),
                DB::raw("
                    CASE
                        WHEN COUNT(DISTINCT type) > 1 THEN 'mixed'
                        ELSE MAX(type)
                    END as type
                ")
            );
    
        $cashQuery = Payment::where('payments.restaurant_id', $request->user()->restaurant_id)
            ->where('type', 'cash')
            ->whereBetween('open_date', [$startDateTime, $endDateTime])
            ->whereBetween('close_date', [$startDateTime, $endDateTime]);
    
        $bankQuery = Payment::where('payments.restaurant_id', $request->user()->restaurant_id)
            ->where('type', 'bank')
            ->whereBetween('open_date', [$startDateTime, $endDateTime])
            ->whereBetween('close_date', [$startDateTime, $endDateTime]);
    
        // Əlavə filtrlər (user_id və ya type)
        if ($request->has('user_id')) {
            $query->where('payments.user_id', $request->input('user_id'));
            $cashQuery->where('payments.user_id', $request->input('user_id'));
            $bankQuery->where('payments.user_id', $request->input('user_id'));
        }
    
        if ($request->has('type')) {
            $type = $request->input('type');
            if ($type === 'mixed') {
                $query->havingRaw('COUNT(DISTINCT type) > 1');
            } else {
                $query->where('type', $type);
                $cashQuery->where('type', $type);
                $bankQuery->where('type', $type);
            }
        }
    
        $payments = $query->groupBy(
            'order_id',
            'order_name',
            'open_date',
            'close_date',
            'users.name',
            'customers.name'
        )->get();
    
        $totalAmount = (float) Payment::where('restaurant_id', $restaurant->id)
            ->whereBetween('open_date', [$startDateTime, $endDateTime])
            ->whereBetween('close_date', [$startDateTime, $endDateTime])
            ->sum('amount');
    
        $totalCash = $cashQuery->sum('amount');
        $totalBank = $bankQuery->sum('amount');
    
        return response()->json([
            "payments" => $payments,
            "total_amount" => $totalAmount,
            "total_cash" => $totalCash,
            "total_bank" => $totalBank,
        ]);
    }  


    public function store(StorePaymentRequest $request, $orderId)
    {
        DB::beginTransaction();

        try {

            $order = Order::where('restaurant_id', $request->user()->restaurant_id)->find($orderId);

            if (!$order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }

            if (($order->tableOrders && $request->user()->cannot('manage-tables')) || ($order->quickOrder && $request->user()->cannot('manage-quick-orders'))) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if ($order->status !== 'approved') {
                return response()->json(['message' => 'Order is not approved.'], 400);
            }

            if ($order->totalAmount() <= $order->totalPayments()) {
                return response()->json(['message' => 'Order is already fully paid.'], 400);
            }

            $sumOfShares = collect($request->shares)->sum('amount');

            $totalPayments = $order->totalPayments();
            $totalPrepayments = $order->totalPrepayments();
            $totalAmount = $order->totalAmount();

   /*         if (max($totalAmount - $totalPrepayments, 0) < $sumOfShares) {
                return response()->json(['message' => 'Amount exceeds the remaining balance.'], 400);
            }*/

//            if ($totalAmount - $totalPrepayments > $sumOfShares) {
//                return response()->json(['message' => 'Amount is not fully compensated'], 400);
//            }

            if (count($request->shares) > 1) {
                foreach ($request->shares as $share) {
                    if ($share['type'] === 'customer_balance') {
                        return response()->json(['message' => 'Only one customer_balance payment is allowed.'], 400);
                    }
                }
            }

            $data = $request->validated()['shares'];

            $data[0]['amount'] += $totalPrepayments;

            foreach ($data as $share) {
                if ($share['type'] === 'customer_balance' && !isset($share['customer_id'])) {
                    return response()->json(['message' => 'Customer ID is required for customer_balance payments.'], 400);
                }

                if ($share['type'] === 'customer_balance') {

                    $customer = Customer::where('restaurant_id', $request->user()->restaurant_id)->find($share['customer_id']);

                    if (!$customer) {
                        return response()->json(['message' => 'Customer not found.'], 404);
                    }

                    $customer->money -= $share['amount'];
                    $customer->save();


                    CustomerTransaction::create([
                        'customer_id' => $share['customer_id'],
                        'amount' => $share['amount'],
                        'type' => 'debit',
                        'note' => 'Sifariş ödənildi ' . $order->id,
                        'date' => now(),  // Assuming current date for simplicity
                        'restaurant_id' => $request->user()->restaurant_id,
                    ]);
                    // Optionally, you could validate if the customer has enough balance here
                }

                Payment::create([
                    'order_id' => $order->id,
                    'restaurant_id' => $request->user()->restaurant_id,
                    'amount' => $share['amount'],
                    'type' => $share['type'],
                    'date' => now(),  // Assuming current date for simplicity
                    'customer_id' => $share['customer_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'open_date' => $order->created_at,
                    'close_date' => now(),  // Assuming current date for simplicity
                    'order_name' => $order->tableOrders ? $order->tableOrders->table->name : ($order->quickOrder ? $order->quickOrder->name : null),
                ]);
            }

/*            foreach ($order->stocks as $stock) {
                $stock->amount -= $stock->pivot->quantity;
                $stock->save();
            }*/

            $order->update(['status' => 'completed']);

            DB::commit();

            return response()->json(['message' => 'Payments processed successfully.'], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to store payments.',
            ], 500);
        }
    }

    public function destroyByOrderId(Request $request, $orderId)
    {
        DB::beginTransaction();

        try {

            $order = Order::where('restaurant_id', $request->user()->restaurant_id)->find($orderId);

            if (!$order) {
                return response()->json(['message' => 'Order not found.'], 404);
            }

            $order->payments()->delete();

            DB::commit();

            return response()->json(['message' => 'Payments deleted successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to delete payments.',
            ], 500);
        }
    }
}
