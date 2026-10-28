<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Models\Customer;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use RuntimeException;



class StaffTransactionController extends Controller
{


    protected $transactionService;



    public function __construct(
        TransactionService $transactionService
    ) {

        $this->transactionService = $transactionService;
    }





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
                'max:255',
                'unique:transactions,kode_transaksi',
            ],
            'total_belanja' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Validasi gagal.',
                422,
                [
                    'errors' => $validator->errors(),
                ]
            );
        }

        $data = $validator->validated();

        try {
            $customer = Customer::find($data['id_customer']);

            if (!$customer) {
                return ApiResponse::error(
                    'Customer tidak ditemukan.',
                    404
                );
            }

            $transaction = $this->transactionService
                ->createManualTransaction(
                    $customer,
                    [
                        'kode_transaksi' => $data['kode_transaksi'],
                        'total_belanja' => $data['total_belanja'],
                    ]
                );

            return ApiResponse::success(
                'Transaksi berhasil.',
                [
                    'kode_transaksi' => $transaction->kode_transaksi,
                    'total_belanja' => $transaction->total_belanja,
                    'point_didapat' => $transaction->point_didapat,
                    'source' => $transaction->source,
                    'saldo_point' => $customer->fresh()->saldo_point,
                ],
                201
            );
        } catch (RuntimeException $e) {
            // Hanya pesan bisnis yang sudah dikenal boleh ditampilkan.
            $businessMessages = [
                'Customer tidak aktif.',
                'Total belanja harus lebih dari 0.',
            ];

            if (in_array($e->getMessage(), $businessMessages, true)) {
                return ApiResponse::error(
                    $e->getMessage(),
                    422
                );
            }

            Log::error('Gagal membuat transaksi melalui API staff.', [
                'id_customer' => $data['id_customer'],
                'kode_transaksi' => $data['kode_transaksi'],
                'exception' => $e,
            ]);

            return ApiResponse::error(
                'Transaksi gagal diproses. Silakan coba kembali.',
                500
            );
        } catch (\Throwable $e) {
            Log::error('Gagal membuat transaksi melalui API staff.', [
                'id_customer' => $data['id_customer'],
                'kode_transaksi' => $data['kode_transaksi'],
                'exception' => $e,
            ]);

            return ApiResponse::error(
                'Transaksi gagal diproses. Silakan coba kembali.',
                500
            );
        }
    }
}
