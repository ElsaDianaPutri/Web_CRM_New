<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(
        TransactionService $transactionService
    ) {
        $this->transactionService = $transactionService;
    }

    /**
     * Membuat transaksi manual oleh admin.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_customer' => [
                'required',
                'integer',
                'exists:customers,id_customer',
            ],
            'kode_transaksi' => [
                'required',
                'string',
                'max:100',
                'unique:transactions,kode_transaksi',
            ],
            'total_belanja' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $customer = Customer::findOrFail(
                $data['id_customer']
            );

            $transaction = $this->transactionService
                ->createManualTransaction(
                    $customer,
                    [
                        'kode_transaksi' => $data['kode_transaksi'],
                        'total_belanja' => $data['total_belanja'],
                    ]
                );

            return response()->json([
                'message' => 'Transaksi berhasil.',
                'data' => [
                    'kode_transaksi' => $transaction->kode_transaksi,
                    'total_belanja' => $transaction->total_belanja,
                    'point_didapat' => $transaction->point_didapat,
                    'source' => $transaction->source,
                    'saldo_point' => $customer->fresh()->saldo_point,
                ],
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Gagal membuat transaksi melalui API admin.', [
                'id_customer' => $data['id_customer'],
                'kode_transaksi' => $data['kode_transaksi'],
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Transaksi gagal diproses. Silakan coba kembali.',
            ], 500);
        }
    }

    /**
     * Mengambil riwayat transaksi customer
     */
    public function history()
    {
        $user = auth()->user();

        $customer = $user->customer ?? null;

        if (!$customer) {
            return response()->json([
                'message' => 'Data customer tidak ditemukan.'
            ], 404);
        }

        if ($customer->status_member !== 'aktif') {
            return response()->json([
                'message' => 'Member tidak aktif.',
                'data' => []
            ]);
        }

        $transactions = Transaction::where(
            'id_customer',
            $customer->id_customer
        )
            ->orderBy('tanggal_transaksi', 'desc')
            ->get([
                'id_transaction',
                'kode_transaksi',
                'tanggal_transaksi',
                'total_belanja',
                'point_didapat',
                'status'
            ]);

        return response()->json([
            'message' => 'Riwayat transaksi berhasil diambil.',
            'data' => $transactions
        ]);
    }
}
