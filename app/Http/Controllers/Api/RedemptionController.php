<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Services\RedemptionService;
use Illuminate\Http\Request;

class RedemptionController extends Controller
{
    public function rewards()
    {
        return response()->json([
            'data' => Reward::where('status', 'tersedia')->where('stock', '>', 0)->latest()->get(),
        ]);
    }

   public function redeem(Request $request, RedemptionService $service)
{
    $data = $request->validate([
        'id_reward' => ['required', 'exists:rewards,id_reward'],
    ]);

    $customer = $request->user()->customer;

if (!$customer) {
    return response()->json([
        'message' => 'Data customer tidak ditemukan.',
    ], 404);
}

    try {
       $redemption = $service->createPending(
    $customer,
    Reward::findOrFail($data['id_reward'])
);

       return response()->json([
    'message' => 'Redemption pending.',
    'data' => [
        'id_redemption' => $redemption->id_redemption,
        'id_customer' => $redemption->id_customer,
        'id_reward' => $redemption->id_reward,
        'redemption_code' => $redemption->redemption_code,
        'redeem_date' => $redemption->redeem_date,
        'point_used' => (int) $redemption->point_used,
        'status' => $redemption->status,
        'created_at' => $redemption->created_at,
        'updated_at' => $redemption->updated_at,
    ],
], 201);

    } catch (\RuntimeException $e) {

        return response()->json([
            'message' => $e->getMessage(),
        ], 422);

    } catch (\Throwable $e) {

        \Illuminate\Support\Facades\Log::error(
            'Gagal membuat redemption',
            [
                'user_id' => $request->user()->id,
                'id_reward' => $data['id_reward'],
                'exception' => $e,
            ]
        );

        return response()->json([
            'message' => 'Terjadi kesalahan pada server.',
        ], 500);
    }
}


    public function status(
        Request $request,
        string $code
    ) {
        $customer = $request->user()->customer;

        if (!$customer) {
    return response()->json([
        'message' => 'Data customer tidak ditemukan.',
    ], 404);
}
        $redemption = $customer
            ->redemptions()
            ->where('redemption_code', $code)
            ->first();

        if (!$redemption) {
            return response()->json([
                'message' => 'Redemption tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'redemption_code' => $redemption->redemption_code,
                'status' => $redemption->status,
                'point_used' => $redemption->point_used,
            ],
        ]);
    }
    
    public function history(Request $request)
{
    $customer = $request->user()->customer;

    if (!$customer) {
        return response()->json([
            'message' => 'Data customer tidak ditemukan.',
        ], 404);
    }

    $data = $customer->redemptions()
        ->with('reward')
        ->latest()
        ->get()
        ->map(function ($redemption) {
            return [
                'id_redemption' => $redemption->id_redemption,
                'id_customer' => $redemption->id_customer,
                'id_reward' => $redemption->id_reward,
                'redemption_code' => $redemption->redemption_code,
                'redeem_date' => $redemption->redeem_date,
                'point_used' => (int) $redemption->point_used,
                'status' => $redemption->status,
                'created_at' => $redemption->created_at,
                'updated_at' => $redemption->updated_at,

                'reward' => $redemption->reward
                    ? [
                        'id_reward' => $redemption->reward->id_reward,
                        'reward_name' => $redemption->reward->reward_name,
                        'description' => $redemption->reward->description,
                        'image_path' => $redemption->reward->image_path,
                        'point_required' => (int) $redemption->reward->point_required,
                        'stock' => (int) $redemption->reward->stock,
                        'status' => $redemption->reward->status,
                    ]
                    : null,
            ];
        });

    return response()->json([
        'data' => $data,
    ]);
}
}