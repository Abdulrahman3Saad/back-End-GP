<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    // عرض كل الخدمات
    public function index()
    {
        $services = Service::all();
        return response()->json($services);
    }

    // إضافة خدمة (أدمن فقط)
    public function store(Request $request)
    {
        $request->validate([
            'name'         => 'required|string|unique:services',
            'service_fees' => 'required|numeric|min:0',
            'description'  => 'nullable|string',
        ]);

        $service = Service::create($request->all());
        return response()->json($service, 201);
    }

    // تعديل خدمة (أدمن فقط)
    public function update(Request $request, Service $service)
    {
        $request->validate([
            'name'         => 'sometimes|string|unique:services,name,' . $service->id,
            'service_fees' => 'sometimes|numeric|min:0',
            'description'  => 'nullable|string',
        ]);

        $service->update($request->all());
        return response()->json($service);
    }

    // حذف خدمة (أدمن فقط)
    public function destroy(Service $service)
    {
        $service->delete();
        return response()->json(['message' => 'تم الحذف']);
    }
}