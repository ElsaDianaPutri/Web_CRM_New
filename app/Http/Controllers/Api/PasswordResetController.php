<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Memulai permintaan lupa password dan mengirim OTP.
     */
    public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer = Customer::where('email', $validated['email'])
            ->first();

        if (!$customer) {
            return ApiResponse::error(
                'Email tidak terdaftar sebagai member.',
                422
            );
        }

        $user = User::where('id_user', $customer->id_user)
            ->where('role', 'customer')
            ->first();

        if (!$user || $user->status !== 'aktif') {
            return ApiResponse::error(
                'Akun tidak ditemukan atau sedang tidak aktif.',
                422
            );
        }

        $otp = (string) random_int(100000, 999999);
        $token = Str::random(64);

        $cacheKey = 'api_password_reset:' . hash('sha256', $token);

        $reset = [
            'id_user' => $user->id_user,
            'nama' => $customer->nama,
            'email' => $customer->email,

            // Password baru disimpan sementara dalam bentuk hash.
            'new_password_hash' => Hash::make($validated['password']),

            // OTP juga disimpan dalam bentuk hash.
            'otp_hash' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes(5)->timestamp,
            'otp_resend_available_at' => now()->addMinutes(5)->timestamp,
            'attempts' => 0,
        ];

        Cache::put($cacheKey, $reset, now()->addMinutes(30));

        try {
            Mail::raw(
                "Halo {$customer->nama},\n\n"
                . "Kami menerima permintaan untuk mengubah password "
                . "akun member SAÉ CAFE ROJEL kamu.\n\n"
                . "Kode OTP kamu adalah:\n\n"
                . "{$otp}\n\n"
                . "Kode berlaku selama 5 menit dan hanya dapat digunakan "
                . "satu kali.\n"
                . "Jangan bagikan kode ini kepada siapa pun.\n\n"
                . "Jika kamu tidak meminta perubahan password, "
                . "abaikan email ini.",
                function ($message) use ($customer) {
                    $message->to($customer->email)
                        ->subject(
                            'Kode OTP Ubah Password - SAÉ CAFE ROJEL'
                        );
                }
            );
        } catch (\Throwable $e) {
            report($e);
            Cache::forget($cacheKey);

            return ApiResponse::error(
                'Email OTP gagal dikirim. Silakan coba kembali.',
                500
            );
        }

        return ApiResponse::success(
            'Kode OTP telah dikirim ke email kamu.',
            [
                'reset_token' => $token,
                'email' => $customer->email,
                'otp_expires_in' => 300,
                'resend_available_in' => 300,
            ],
            200
        );
    }

    /**
     * Memverifikasi OTP dan mengubah password.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'reset_token' => ['required', 'string'],
            'otp' => ['required', 'digits:6'],
        ]);

        $cacheKey = 'api_password_reset:' .
            hash('sha256', $request->input('reset_token'));

        $reset = Cache::get($cacheKey);

        if (!$reset) {
            return ApiResponse::error(
                'Sesi perubahan password berakhir. Silakan ulangi proses.',
                410
            );
        }

        if (now()->timestamp >= $reset['otp_expires_at']) {
            return ApiResponse::error(
                'Kode OTP sudah kedaluwarsa. Silakan kirim ulang kode.',
                422
            );
        }

        if (($reset['attempts'] ?? 0) >= 5) {
            Cache::forget($cacheKey);

            return ApiResponse::error(
                'Batas percobaan OTP terlampaui. Silakan ulangi proses.',
                429
            );
        }

        $reset['attempts']++;

        if (!Hash::check($request->input('otp'), $reset['otp_hash'])) {
            if ($reset['attempts'] >= 5) {
                Cache::forget($cacheKey);

                return ApiResponse::error(
                    'Batas percobaan OTP terlampaui. Silakan ulangi proses.',
                    429
                );
            }

            Cache::put(
                $cacheKey,
                $reset,
                now()->addMinutes(30)
            );

            return ApiResponse::error(
                'Kode OTP salah. Periksa kembali email kamu.',
                422
            );
        }

        try {
            DB::transaction(function () use ($reset) {
                $user = User::where('id_user', $reset['id_user'])
                    ->lockForUpdate()
                    ->first();

                if (
                    !$user ||
                    $user->role !== 'customer' ||
                    $user->status !== 'aktif'
                ) {
                    throw new \RuntimeException(
                        'Akun tidak aktif atau tidak valid.'
                    );
                }

                // Password diubah hanya setelah OTP benar.
                $user->password = $reset['new_password_hash'];
                $user->save();
                $user->tokens()->delete();
            });
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Password gagal diperbarui. Silakan coba kembali.',
                500
            );
        }

        // Token tidak bisa digunakan kembali setelah berhasil.
        Cache::forget($cacheKey);

        return ApiResponse::success(
            'Password berhasil diperbarui. Silakan login.',
            [],
            200
        );
    }

    /**
     * Mengirim ulang OTP.
     */
    public function resendOtp(Request $request)
    {
        $request->validate([
            'reset_token' => ['required', 'string'],
        ]);

        $cacheKey = 'api_password_reset:' .
            hash('sha256', $request->input('reset_token'));

        $reset = Cache::get($cacheKey);

        if (!$reset) {
            return ApiResponse::error(
                'Sesi perubahan password berakhir. Silakan ulangi proses.',
                410
            );
        }

        $resendAvailableAt = (int) (
            $reset['otp_resend_available_at'] ?? 0
        );

        if (now()->timestamp < $resendAvailableAt) {
            $remaining = $resendAvailableAt - now()->timestamp;

            return ApiResponse::error(
                "Tunggu {$remaining} detik sebelum meminta OTP baru.",
                429
            );
        }

        $otp = (string) random_int(100000, 999999);

        try {
            Mail::raw(
                "Halo {$reset['nama']},\n\n"
                . "Kode OTP baru untuk mengubah password "
                . "SAÉ CAFE ROJEL kamu adalah:\n\n"
                . "{$otp}\n\n"
                . "Kode berlaku selama 5 menit dan hanya dapat digunakan "
                . "satu kali.\n"
                . "Jangan bagikan kode ini kepada siapa pun.",
                function ($message) use ($reset) {
                    $message->to($reset['email'])
                        ->subject(
                            'Kode OTP Ubah Password - SAÉ CAFE ROJEL'
                        );
                }
            );
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::error(
                'OTP gagal dikirim ulang. Silakan coba kembali.',
                500
            );
        }

        $reset['otp_hash'] = Hash::make($otp);
        $reset['otp_expires_at'] = now()->addMinutes(5)->timestamp;
        $reset['otp_resend_available_at'] =
            now()->addMinutes(5)->timestamp;
        $reset['attempts'] = 0;

        Cache::put(
            $cacheKey,
            $reset,
            now()->addMinutes(30)
        );

        return ApiResponse::success(
            'Kode OTP baru berhasil dikirim ke email kamu.',
            [
                'otp_expires_in' => 300,
                'resend_available_in' => 300,
            ],
            200
        );
    }
}