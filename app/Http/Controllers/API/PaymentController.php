<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Order;
use App\Models\Car;
use App\Models\CarSwap;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    // ===================================================
    // إنشاء دفعة — يقبل order_id أو swap_id
    // ===================================================
    public function store(Request $request)
    {
        $request->validate([
            'order_id'           => 'nullable|exists:orders,id',
            'swap_id'            => 'nullable|exists:car_swaps,id',
            'car_id'             => 'required|exists:cars,id',
            'amount'             => 'required|numeric|min:1',
            'payment_type'       => 'required|in:service_fees,order_payment',
            'payment_method'     => 'required|in:cash,card,installment',
            'payment_provider'   => 'nullable|in:visa,mastercard,valu,nbe,cib,banquemisr',
            'total_installments' => 'nullable|integer|min:1',
            'installment_number' => 'nullable|integer|min:1',
        ]);

        if (!$request->order_id && !$request->swap_id) {
            return response()->json(['message' => 'يجب تحديد order_id أو swap_id'], 422);
        }

        $user = $request->user();
        $car  = Car::findOrFail($request->car_id);

        if ($request->swap_id) {
            $swap = CarSwap::findOrFail($request->swap_id);

            if ($swap->requester_id !== $user->id && $swap->receiver_id !== $user->id) {
                return response()->json(['message' => 'غير مصرح'], 403);
            }

            if (!in_array($swap->status, ['admin_approved', 'accepted', 'completed'])) {
                return response()->json(['message' => 'الـ swap لازم يكون في مرحلة متقدمة'], 422);
            }

            $existing = Payment::where('swap_id', $request->swap_id)
                               ->where('user_id', $user->id)
                               ->where('payment_type', 'service_fees')
                               ->whereIn('status', ['pending', 'completed'])
                               ->first();
            if ($existing) {
                return response()->json(['message' => 'تم تسجيل رسوم الخدمة لهذا التبديل مسبقاً'], 422);
            }

            $payment = Payment::create([
                'user_id'        => $user->id,
                'swap_id'        => $request->swap_id,
                'car_id'         => $request->car_id,
                'amount'         => $request->amount,
                'payment_type'   => $request->payment_type,
                'payment_method' => $request->payment_method ?? 'cash',
                'status'         => 'completed',
            ]);

            $payment->load(['swap', 'car']);
            return response()->json(['message' => 'تم تسجيل رسوم التبديل بنجاح', 'payment' => $payment], 201);
        }

        $order = Order::findOrFail($request->order_id);

        if ($request->payment_type === 'service_fees') {
            if ($car->user_id !== $user->id) {
                return response()->json(['message' => 'رسوم الخدمة تُحسب على صاحب الإعلان فقط'], 403);
            }
        } else {
            if ($order->user_id !== $user->id) {
                return response()->json(['message' => 'غير مصرح'], 403);
            }
        }

        if (!in_array($order->order_status, ['pending', 'confirmed'])) {
            return response()->json(['message' => 'لا يمكن تسجيل الدفع في هذه الحالة'], 422);
        }

        $existing = Payment::where('order_id', $request->order_id)
                           ->where('payment_type', $request->payment_type)
                           ->whereIn('status', ['pending', 'completed'])
                           ->first();
        if ($existing) {
            return response()->json(['message' => 'تم تسجيل هذا النوع من الدفع لهذا الطلب مسبقاً'], 422);
        }

        $paymentStatus = $order->order_status === 'confirmed' ? 'completed' : 'pending';

        $payment = Payment::create([
            'user_id'            => $user->id,
            'order_id'           => $request->order_id,
            'car_id'             => $request->car_id,
            'amount'             => $request->amount,
            'payment_type'       => $request->payment_type,
            'payment_method'     => $request->payment_method,
            'payment_provider'   => $request->payment_provider,
            'total_installments' => $request->total_installments,
            'installment_number' => $request->installment_number,
            'status'             => $paymentStatus,
        ]);

        $payment->load(['order', 'car']);

        return response()->json([
            'message' => 'تم تسجيل الدفع بنجاح',
            'payment' => $payment,
        ], 201);
    }

    // ===================================================
    // العميل: مدفوعاتي
    // ===================================================
    public function myPayments(Request $request)
    {
        $payments = Payment::with(['order', 'swap', 'car.images'])
            ->where('user_id', $request->user()->id)
            ->when($request->service_type, function ($q) use ($request) {
                $serviceType = $request->service_type;
                if ($serviceType === 'swap') {
                    $q->whereNotNull('swap_id');
                } else {
                    $q->whereNull('swap_id')->whereHas('car.service', function ($sq) use ($serviceType) {
                        if ($serviceType === 'rent') {
                            $sq->where(function($w) {
                                $w->where('name', 'like', '%rent%')
                                  ->orWhere('name', 'like', '%تأجير%')
                                  ->orWhere('name', 'like', '%إيجار%');
                            });
                        } else {
                            $sq->where('name', 'not like', '%rent%')
                               ->where('name', 'not like', '%تأجير%')
                               ->where('name', 'not like', '%swap%')
                               ->where('name', 'not like', '%exchange%')
                               ->where('name', 'not like', '%تبديل%');
                        }
                    });
                }
            })
            ->latest()
            ->get();

        return response()->json($payments);
    }

    // ===================================================
    // تفاصيل دفعة
    // ===================================================
    public function show(Request $request, Payment $payment)
    {
        if ($payment->user_id !== $request->user()->id && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $payment->load(['order', 'swap', 'car.images', 'user']);
        return response()->json($payment);
    }

    // ===================================================
    // الأدمن: كل المدفوعات
    // ===================================================
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $perPage = $request->input('per_page', 15);

        $payments = Payment::with(['user', 'order', 'swap', 'car.images', 'car.service', 'car.user'])
            ->when($request->status,       fn($q) => $q->where('status',         $request->status))
            ->when($request->method,       fn($q) => $q->where('payment_method', $request->method))
            ->when($request->payment_type, fn($q) => $q->where('payment_type',   $request->payment_type))
            ->when($request->service_type, function ($q) use ($request) {
                $serviceType = $request->service_type;
                if ($serviceType === 'swap') {
                    $q->where(function($w) {
                        $w->whereNotNull('swap_id')
                          ->orWhereHas('car.service', fn($sq) =>
                              $sq->where(fn($x) => $x
                                  ->where('name', 'like', '%exchange%')
                                  ->orWhere('name', 'like', '%swap%')
                                  ->orWhere('name', 'like', '%تبديل%')
                                  ->orWhere('name', 'like', '%استبدال%'))
                          );
                    });
                } elseif ($serviceType === 'rent') {
                    $q->whereNull('swap_id')->whereHas('car.service', fn($sq) =>
                        $sq->where(fn($w) => $w->where('name', 'like', '%rent%')
                                               ->orWhere('name', 'like', '%تأجير%')
                                               ->orWhere('name', 'like', '%إيجار%'))
                    );
                } else {
                    $q->whereNull('swap_id')->whereHas('car.service', fn($sq) =>
                        $sq->where('name', 'not like', '%rent%')
                           ->where('name', 'not like', '%تأجير%')
                           ->where('name', 'not like', '%swap%')
                           ->where('name', 'not like', '%exchange%')
                           ->where('name', 'not like', '%تبديل%')
                           ->where('name', 'not like', '%استبدال%')
                    );
                }
            })
            ->latest()
            ->paginate($perPage);

        return response()->json($payments);
    }

    // ===================================================
    // ✅ الأدمن: إحصائيات رسوم الخدمات — مُصلَح
    // ===================================================
    public function serviceFeesStats(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        // جلب كل رسوم الخدمات مع اسم الـ service
        $orderPayments = Payment::where('payments.payment_type', 'service_fees')
            ->where('payments.status', 'completed')
            ->whereNull('payments.swap_id')
            ->whereNotNull('payments.car_id')
            ->leftJoin('cars',     'cars.id',     '=', 'payments.car_id')
            ->leftJoin('services', 'services.id', '=', 'cars.service_id')
            ->select('payments.amount', 'services.name as service_name')
            ->get();

        // رسوم التبديل اليدوية (اتسجلت عبر swap_id مباشرة)
        $swapPayments = Payment::where('payment_type', 'service_fees')
            ->where('status', 'completed')
            ->whereNotNull('swap_id')
            ->get();

        $totalFees = 0; $totalCount = 0;
        $saleFees  = 0; $saleCount  = 0;
        $rentFees  = 0; $rentCount  = 0;
        $swapFees  = 0; $swapCount  = 0;

        foreach ($orderPayments as $p) {
            $amount = (float) $p->amount;
            $name   = strtolower($p->service_name ?? '');
            $totalFees += $amount;
            $totalCount++;

            $isRent = str_contains($name, 'rent')     ||
                      str_contains($name, 'تأجير')    ||
                      str_contains($name, 'إيجار')    ||
                      str_contains($name, 'ايجار');

            // ✅ الإصلاح: exchange بيتعرف من service_name
            // لأن approveCar بيسجل رسوم الـ exchange بـ swap_id = NULL
            $isExchange = str_contains($name, 'exchange') ||
                          str_contains($name, 'swap')     ||
                          str_contains($name, 'تبديل')   ||
                          str_contains($name, 'استبدال');

            if ($isRent)         { $rentFees += $amount; $rentCount++; }
            elseif ($isExchange) { $swapFees += $amount; $swapCount++; }
            else                 { $saleFees += $amount; $saleCount++; }
        }

        // رسوم التبديل اليدوية
        foreach ($swapPayments as $p) {
            $totalFees += (float) $p->amount;
            $totalCount++;
            $swapFees  += (float) $p->amount;
            $swapCount++;
        }

        return response()->json([
            'success'            => true,
            'total_service_fees' => $totalFees,
            'total_count'        => $totalCount,
            'sale_fees'          => $saleFees,
            'sale_count'         => $saleCount,
            'rent_fees'          => $rentFees,
            'rent_count'         => $rentCount,
            'swap_fees'          => $swapFees,
            'swap_count'         => $swapCount,
        ]);
    }
}