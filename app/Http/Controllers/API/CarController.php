<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Car;
use App\Models\CarImage;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CarController extends Controller
{
    // ===================================================
    // عرض كل السيارات النشطة والمتاحة (للعموم)
    // ===================================================
    public function index(Request $request)
    {
        $query = Car::with(['user', 'service', 'images'])
            ->where('status', 'active')
            ->where('is_available', true);

        if ($request->service_id) {
            $query->where('service_id', $request->service_id);
        }

        if ($request->brand) {
            $query->where('brand', 'like', '%' . $request->brand . '%');
        }

        if ($request->min_price) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->max_price) {
            $query->where('price', '<=', $request->max_price);
        }

        $cars = $query->latest()->paginate(12);
        return response()->json($cars);
    }

    // ===================================================
    // تفاصيل سيارة
    // ===================================================
    public function show(Car $car)
    {
        $car->load(['user', 'service', 'images']);
        return response()->json($car);
    }

    // ===================================================
    // صاحب السيارة: إضافة إعلان جديد
    // ✅ الصور بتتبعت في نفس الـ request (multipart/form-data)
    // ===================================================
    public function store(Request $request)
    {
        $request->validate([
            'service_id'       => 'required|exists:services,id',
            'brand'            => 'required|string|max:100',
            'model'            => 'required|string|max:100',
            'manufacture_year' => 'required|integer|min:1990|max:' . (date('Y') + 1),
            'car_condition'    => 'required|in:new,used,old',
            'fuel_type'        => 'required|in:petrol,electric,diesel,hybrid',
            'transmission'     => 'required|in:automatic,manual',
            'mileage'          => 'required|integer|min:0',
            'seats'            => 'required|integer|min:2|max:12',
            'color'            => 'required|string|max:50',
            'price'            => 'required|numeric|min:0',
            'description'      => 'nullable|string|max:2000',
            'city'             => 'required|string|max:100',
            'address'          => 'required|string|max:255',
            'contact_phone'    => 'required|string|max:20|regex:/^[0-9+\-\s]+$/',
            // ✅ الصور required هنا — الـ frontend لازم يبعتها في نفس الـ request
            'images'           => 'required|array|min:1|max:10',
            'images.*'         => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $carNumber = 'CAR-' . time() . '-' . $request->user()->id;

        $car = Car::create([
            'user_id'          => $request->user()->id,
            'service_id'       => $request->service_id,
            'car_number'       => $carNumber,
            'brand'            => $request->brand,
            'model'            => $request->model,
            'manufacture_year' => $request->manufacture_year,
            'car_condition'    => $request->car_condition,
            'fuel_type'        => $request->fuel_type,
            'transmission'     => $request->transmission,
            'mileage'          => $request->mileage,
            'seats'            => $request->seats,
            'color'            => $request->color,
            'price'            => $request->price,
            'description'      => $request->description,
            'status'           => 'pending',
            'is_available'     => false,
            'city'             => $request->city,
            'address'          => $request->address,
            'contact_phone'    => $request->contact_phone,
        ]);

        foreach ($request->file('images') as $image) {
            $path = $image->store('cars', 'public');
            CarImage::create([
                'car_id'     => $car->id,
                'image_path' => $path,
                'image_url'  => Storage::url($path),  // ✅ بدل asset() — أكثر موثوقية
            ]);
        }

        $car->load(['service', 'images']);
        return response()->json([
            'message' => 'تم رفع الإعلان بنجاح، بانتظار موافقة الإدارة',
            'car'     => $car,
        ], 201);
    }

    // ===================================================
    // صاحب السيارة: تعديل إعلان
    // ===================================================
    public function update(Request $request, Car $car)
    {
        if ($car->user_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($car->status === 'completed') {
            return response()->json(['message' => 'لا يمكن تعديل سيارة تم إتمام صفقتها'], 422);
        }

        $request->validate([
            'service_id'       => 'sometimes|exists:services,id',
            'brand'            => 'sometimes|string|max:100',
            'model'            => 'sometimes|string|max:100',
            'manufacture_year' => 'sometimes|integer|min:1990|max:' . (date('Y') + 1),
            'car_condition'    => 'sometimes|in:new,used,old',
            'fuel_type'        => 'sometimes|in:petrol,electric,diesel,hybrid',
            'transmission'     => 'sometimes|in:automatic,manual',
            'mileage'          => 'sometimes|integer|min:0',
            'seats'            => 'sometimes|integer|min:2|max:12',
            'color'            => 'sometimes|string|max:50',
            'price'            => 'sometimes|numeric|min:0',
            'description'      => 'nullable|string|max:2000',
            'city'             => 'sometimes|string|max:100',
            'address'          => 'nullable|string|max:255',
            'contact_phone'    => 'sometimes|string|max:20|regex:/^[0-9+\-\s]+$/',
            'images'           => 'sometimes|array|min:1|max:10',
            'images.*'         => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $updateData = $request->only([
            'service_id', 'brand', 'model', 'manufacture_year', 'car_condition',
            'fuel_type', 'transmission', 'mileage', 'seats',
            'color', 'price', 'description', 'city', 'address', 'contact_phone',
        ]);

        // ✅ لو كانت active وبتتعدل → ترجع pending للمراجعة
        if ($car->status === 'active') {
            $updateData['status']       = 'pending';
            $updateData['is_available'] = false;
        }

        $car->update($updateData);

        if ($request->hasFile('images')) {
            foreach ($car->images as $img) {
                Storage::disk('public')->delete($img->image_path);
                $img->delete();
            }
            foreach ($request->file('images') as $image) {
                $path = $image->store('cars', 'public');
                CarImage::create([
                    'car_id'     => $car->id,
                    'image_path' => $path,
                    'image_url'  => Storage::url($path),
                ]);
            }
        }

        $car->load(['service', 'images']);
        return response()->json([
            'message' => 'تم تعديل الإعلان بنجاح',
            'car'     => $car,
        ]);
    }

    // ===================================================
    // صاحب السيارة أو الأدمن: حذف إعلان
    // ===================================================
    public function destroy(Request $request, Car $car)
    {
        $user = $request->user();

        if ($car->user_id !== $user->id && $user->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        foreach ($car->images as $img) {
            Storage::disk('public')->delete($img->image_path);
            $img->delete();
        }

        $car->delete();
        return response()->json(['message' => 'تم حذف الإعلان']);
    }

    // ===================================================
    // صاحب السيارة: سياراتي
    // ===================================================
    public function myCars(Request $request)
    {
        $cars = Car::with(['service', 'images'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($cars);
    }

    // ===================================================
    // الأدمن: الإعلانات بانتظار الموافقة
    // ===================================================
    public function pendingCars(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $cars = Car::with(['user', 'service', 'images'])
            ->where('status', 'pending')
            ->latest()
            ->paginate(15);

        return response()->json($cars);
    }

    // ===================================================
    // الأدمن: قبول إعلان سيارة
    // ===================================================
    public function approveCar(Request $request, Car $car)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($car->status !== 'pending') {
            return response()->json(['message' => 'الإعلان مش في انتظار الموافقة'], 422);
        }

        $car->load('service');

        $car->update([
            'status'       => 'active',
            'is_available' => true,
        ]);

        // ✅ تسجيل رسوم نشر الإعلان تلقائياً
        if ($car->service && $car->service->service_fees > 0) {
            Payment::create([
                'user_id'        => $car->user_id,
                'car_id'         => $car->id,
                'order_id'       => null,
                'swap_id'        => null,
                'amount'         => $car->service->service_fees,
                'payment_type'   => 'service_fees',
                'payment_method' => 'cash',
                'status'         => 'completed',
            ]);
        }

        return response()->json([
            'message' => 'تم قبول الإعلان ونشره بنجاح، وتم تسجيل رسوم الخدمة',
            'car'     => $car,
        ]);
    }

    // ===================================================
    // الأدمن: رفض إعلان سيارة
    // ===================================================
    public function rejectCar(Request $request, Car $car)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($car->status !== 'pending') {
            return response()->json(['message' => 'الإعلان مش في انتظار الموافقة'], 422);
        }

        $car->update(['status' => 'rejected']);

        return response()->json(['message' => 'تم رفض الإعلان']);
    }
}
