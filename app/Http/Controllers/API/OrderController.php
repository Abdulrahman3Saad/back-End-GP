<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Car;
use App\Models\Payment;
use Illuminate\Http\Request;
use Carbon\Carbon;

class OrderController extends Controller
{
    // ===================================================
    // العميل: إنشاء طلب جديد
    // ===================================================
    public function store(Request $request)
    {
        $request->validate([
            'car_id'                 => 'required|exists:cars,id',
            'full_name'              => 'required|string|max:255',
            'phone'                  => 'required|string|max:20',
            'governorate'            => 'required|string|max:100',
            'address'                => 'required|string|max:500',
            'expected_delivery_date' => 'required|date|after:today',
            'return_date'            => 'nullable|date|after:expected_delivery_date',
            'with_driver'            => 'nullable|boolean',
            'discount'               => 'nullable|numeric|min:0',
        ]);

        $car = Car::with('service')->findOrFail($request->car_id);

        if ($car->status !== 'active' || !$car->is_available) {
            return response()->json(['message' => 'السيارة غير متاحة حالياً'], 422);
        }

        if ($car->user_id === $request->user()->id) {
            return response()->json(['message' => 'لا يمكنك طلب سيارتك الخاصة'], 422);
        }

        $serviceName = strtolower($car->service->name ?? '');
        $isRent      = str_contains($serviceName, 'rent') || str_contains($serviceName, 'تأجير');
        $discount    = floatval($request->discount ?? 0);

        // لو تأجير: السعر = عدد الأيام × سعر اليوم
        if ($isRent && $request->return_date) {
            $days     = max(1, Carbon::parse($request->expected_delivery_date)->diffInDays(Carbon::parse($request->return_date)));
            $carPrice = floatval($car->price) * $days;
        } else {
            $carPrice = floatval($car->price);
        }

        $totalAmount = $carPrice - $discount;

        $order = Order::create([
            'user_id'                => $request->user()->id,
            'car_id'                 => $car->id,
            'full_name'              => $request->full_name,
            'phone'                  => $request->phone,
            'governorate'            => $request->governorate,
            'address'                => $request->address,
            'expected_delivery_date' => $request->expected_delivery_date,
            'return_date'            => $request->return_date,
            'with_driver'            => $request->boolean('with_driver', false),
            'car_price'              => $carPrice,
            'discount'               => $discount,
            'total_amount'           => $totalAmount,
            'order_status'           => 'pending',
        ]);

        // ✅ احجز السيارة فوراً — منع حجز نفس السيارة من عميلين في نفس الوقت
        $car->update(['is_available' => false]);

        $order->load(['car.images', 'car.service']);
        return response()->json($order, 201);
    }

    // ===================================================
    // العميل: طلباتي
    // ===================================================
    public function myOrders(Request $request)
    {
        $orders = Order::with(['car.images', 'car.service', 'payments'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($orders);
    }

    // ===================================================
    // العميل: إلغاء طلب (pending فقط)
    // ===================================================
    public function cancel(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($order->order_status !== 'pending') {
            return response()->json(['message' => 'لا يمكن إلغاء الطلب بعد موافقة صاحب السيارة'], 422);
        }

        $car = Car::find($order->car_id);
        if ($car) $car->update(['is_available' => true]);

        $order->update(['order_status' => 'canceled']);

        Payment::where('order_id', $order->id)
               ->where('status', 'pending')
               ->update(['status' => 'failed']);

        return response()->json(['message' => 'تم إلغاء الطلب بنجاح']);
    }

    // ===================================================
    // تفاصيل طلب (العميل أو صاحب السيارة أو الأدمن)
    // ===================================================
    public function show(Request $request, Order $order)
    {
        $user = $request->user();
        $car  = Car::find($order->car_id);

        $isCustomer = $order->user_id === $user->id;
        $isCarOwner = $car && $car->user_id === $user->id;
        $isAdmin    = $user->role === 'admin';

        if (!$isCustomer && !$isCarOwner && !$isAdmin) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $order->load(['car.images', 'car.service', 'payments', 'user']);
        return response()->json($order);
    }

    // ===================================================
    // صاحب السيارة: الطلبات الواردة على سياراته
    // ✅ يدعم فلترة بالحالة: ?status=active | completed | all
    // active  = pending + confirmed
    // completed = completed + canceled + rejected
    // بدون status = كل الطلبات
    // ===================================================
    public function incomingOrders(Request $request)
    {
        $carIds = Car::where('user_id', $request->user()->id)->pluck('id');

        $query = Order::with(['user', 'car.images', 'car.service', 'payments'])
            ->whereIn('car_id', $carIds);

        $status = $request->query('status');

        if ($status === 'active') {
            // active = pending + confirmed + in_progress (التأجير الجاري)
            $query->whereIn('order_status', ['pending', 'confirmed', 'in_progress']);
        } elseif ($status === 'completed') {
            $query->whereIn('order_status', ['completed', 'canceled', 'rejected']);
        }
        // لو مفيش status أو status=all → بيرجع كل الطلبات

        $orders = $query->latest()->get();

        return response()->json($orders);
    }

    // ===================================================
    // صاحب السيارة: قبول الطلب
    // ===================================================
    public function approve(Request $request, Order $order)
    {
        $car = Car::findOrFail($order->car_id);

        if ($car->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($order->order_status !== 'pending') {
            return response()->json(['message' => 'الطلب مش في انتظار الموافقة'], 422);
        }

        $car->update(['is_available' => false]);
        $order->update(['order_status' => 'confirmed']);

        Payment::where('order_id', $order->id)
               ->where('status', 'pending')
               ->update(['status' => 'completed']);

        $order->load(['car.images', 'user']);

        return response()->json([
            'message' => 'تم قبول الطلب بنجاح',
            'order'   => $order,
        ]);
    }

    // ===================================================
    // صاحب السيارة: رفض الطلب
    // ===================================================
    public function reject(Request $request, Order $order)
    {
        $car = Car::findOrFail($order->car_id);

        if ($car->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($order->order_status !== 'pending') {
            return response()->json(['message' => 'لا يمكن رفض هذا الطلب'], 422);
        }

        $car->update(['is_available' => true]);
        $order->update(['order_status' => 'rejected']);

        Payment::where('order_id', $order->id)
               ->where('status', 'pending')
               ->update(['status' => 'failed']);

        return response()->json(['message' => 'تم رفض الطلب']);
    }

    // ===================================================
    // صاحب السيارة: تأكيد تسليم السيارة (شراء فقط)
    // ===================================================
    public function confirmReceive(Request $request, Order $order)
    {
        $car = Car::with('service')->findOrFail($order->car_id);

        if ($car->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح — فقط صاحب السيارة يستطيع تأكيد التسليم'], 403);
        }

        if ($order->order_status !== 'confirmed') {
            return response()->json(['message' => 'الطلب لازم يكون مقبول أولاً'], 422);
        }

        $serviceName = strtolower($car->service->name ?? '');
        $isSale      = str_contains($serviceName, 'sale') || str_contains($serviceName, 'sell') || str_contains($serviceName, 'بيع');

        if (!$isSale) {
            return response()->json(['message' => 'هذا الإجراء للبيع فقط — للتأجير استخدم إنهاء التأجير'], 422);
        }

        $car->update(['status' => 'completed', 'is_available' => false]);
        $order->update(['order_status' => 'completed']);

        return response()->json(['message' => 'تم تأكيد التسليم بنجاح 🎉 — اكتملت صفقة البيع']);
    }

    // ===================================================
    // صاحب السيارة: تأكيد تسليم السيارة للعميل (تأجير فقط)
    // ===================================================
    public function markDelivered(Request $request, Order $order)
    {
        $car = Car::with('service')->findOrFail($order->car_id);

        if ($car->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($order->order_status !== 'confirmed') {
            return response()->json(['message' => 'الطلب لازم يكون مقبول أولاً'], 422);
        }

        $serviceName = strtolower($car->service->name ?? '');
        $isRent = str_contains($serviceName, 'rent') || str_contains($serviceName, 'تأجير');

        if (!$isRent) {
            return response()->json(['message' => 'هذا الإجراء للتأجير فقط'], 422);
        }

        $order->update([
            'order_status' => 'in_progress',
        ]);

        return response()->json(['message' => 'تم تسجيل تسليم السيارة للعميل — في انتظار إعادتها']);
    }

    // ===================================================
    // صاحب السيارة: إنهاء التأجير (العميل رجّع السيارة)
    // ===================================================
    public function complete(Request $request, Order $order)
    {
        $car = Car::with('service')->findOrFail($order->car_id);

        if ($car->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($order->order_status !== 'in_progress') {
            return response()->json(['message' => 'السيارة لازم تكون مسلّمة للعميل أولاً'], 422);
        }

        $serviceName = strtolower($car->service->name ?? '');
        $isRent      = str_contains($serviceName, 'rent') || str_contains($serviceName, 'تأجير');

        if (!$isRent) {
            return response()->json(['message' => 'في الشراء، العميل هو من يؤكد الاستلام'], 422);
        }

        $car->update(['is_available' => true]);
        $order->update(['order_status' => 'completed']);

        return response()->json(['message' => 'تم إنهاء التأجير بنجاح، السيارة متاحة مرة أخرى']);
    }

    // ===================================================
    // إلغاء بالتراضي — متاح للعميل وصاحب السيارة
    // ===================================================
    public function cancelByAgreement(Request $request, Order $order)
    {
        $user = $request->user();
        $car  = Car::find($order->car_id);

        $isCustomer = $order->user_id === $user->id;
        $isCarOwner = $car && $car->user_id === $user->id;

        if (!$isCustomer && !$isCarOwner) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($order->order_status === 'in_progress') {
            return response()->json(['message' => 'لا يمكن إلغاء الطلب — السيارة بحوزة العميل بالفعل'], 422);
        }

        if ($order->order_status !== 'confirmed') {
            return response()->json(['message' => 'لا يمكن الإلغاء في هذه الحالة'], 422);
        }

        if ($car) {
            $car->update(['is_available' => true]);
        }

        $order->update(['order_status' => 'canceled']);

        Payment::where('order_id', $order->id)
               ->where('status', 'completed')
               ->update(['status' => 'refunded']);

        return response()->json(['message' => 'تم إلغاء الطلب بالتراضي، السيارة أصبحت متاحة مجدداً']);
    }

    // ===================================================
    // إلغاء تلقائي للطلبات المنتهية المدة
    // ===================================================
    public function autoExpire(Request $request)
    {
        // ① إلغاء الطلبات confirmed المنتهية (غير تأجير)
        $expired = Order::where('order_status', 'confirmed')
            ->whereDate('expected_delivery_date', '<', now()->toDateString())
            ->get();

        $count = 0;
        foreach ($expired as $order) {
            $car = Car::find($order->car_id);
            if ($car) $car->update(['is_available' => true]);
            $order->update(['order_status' => 'canceled']);
            Payment::where('order_id', $order->id)
                   ->where('status', 'completed')
                   ->update(['status' => 'refunded']);
            $count++;
        }

        // ② طلبات التأجير in_progress المنتهية return_date — بس تنبيه
        $overdueRentals = Order::where('order_status', 'in_progress')
            ->whereNotNull('return_date')
            ->whereDate('return_date', '<', now()->toDateString())
            ->with(['car.user'])
            ->get();

        $overdueList = $overdueRentals->map(fn($o) => [
            'order_id'    => $o->id,
            'car_id'      => $o->car_id,
            'return_date' => $o->return_date,
            'overdue_days'=> Carbon::parse($o->return_date)->diffInDays(now()),
        ]);

        return response()->json([
            'message'        => "تم إلغاء {$count} طلب منتهي المدة تلقائياً",
            'expired_count'  => $count,
            'overdue_rentals'=> $overdueList,
        ]);
    }

    // ===================================================
    // الأدمن: كل الطلبات
    // ===================================================
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $orders = Order::with(['user', 'car.images', 'car.service', 'car.user', 'payments'])
            ->latest()
            ->paginate(15);

        return response()->json($orders);
    }
}