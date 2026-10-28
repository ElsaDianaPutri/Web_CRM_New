<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    /**
     * Memulai registrasi dan mengirim OTP.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:100',
            'nomor_hp' => [
                'required',
                'string',
                'regex:/^08[0-9]{8,12}$/',
                'unique:users,username',
                'unique:customers,nomor_hp',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:customers,email',
            ],
            'tanggal_lahir' => 'nullable|date',
            'password' => 'required|string|min:8',
        ]);

        // Token acak untuk mengidentifikasi sesi registrasi API.
        $token = Str::random(64);
        $cacheKey = 'api_registration:' . hash('sha256', $token);

        $otp = (string) random_int(100000, 999999);

        $registration = [
            'nama' => $validated['nama'],
            'nomor_hp' => $validated['nomor_hp'],
            'email' => $validated['email'],
            'tanggal_lahir' => $validated['tanggal_lahir'] ?? null,
            'password' => Hash::make($validated['password']),
            'otp_hash' => Hash::make($otp),
            'otp_expires_at' => now()->addMinutes(5)->timestamp,
            'otp_resend_available_at' => now()->addMinutes(5)->timestamp,
            'attempts' => 0,
        ];

        try {
            Mail::raw(
                "Halo {$registration['nama']},\n\n"
                . "Kode OTP registrasi member SAÉ CAFE ROJEL kamu adalah:\n\n"
                . "{$otp}\n\n"
                . "Kode berlaku selama 5 menit dan hanya dapat digunakan satu kali.\n"
                . "Jangan bagikan kode ini kepada siapa pun.\n\n"
                . "Jika kamu tidak merasa melakukan registrasi, abaikan email ini.",
                function ($message) use ($registration) {
                    $message->to($registration['email'])
                        ->subject('Kode OTP Registrasi Member - SAÉ CAFE ROJEL');
                }
            );

            // Data registrasi disimpan sementara, bukan ke tabel users/customers.
            // TTL cache 30 menit agar pengguna masih punya waktu untuk meminta OTP baru.
            Cache::put($cacheKey, $registration, now()->addMinutes(30));
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::error(
                'OTP gagal dikirim. Periksa konfigurasi email dan coba kembali.',
                500
            );
        }

        return ApiResponse::success(
            'OTP berhasil dikirim ke email kamu.',
            [
                'registration_token' => $token,
                'email' => $registration['email'],
                'otp_expires_in' => 300,
                'resend_available_in' => 300,
            ],
            200
        );
    }

    /**
     * Memverifikasi OTP dan membuat akun setelah OTP benar.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'registration_token' => 'required|string',
            'otp' => 'required|digits:6',
        ]);

        $token = $request->input('registration_token');
        $cacheKey = 'api_registration:' . hash('sha256', $token);

        $registration = Cache::get($cacheKey);

        if (!$registration) {
            return ApiResponse::error(
                'Sesi registrasi berakhir. Silakan daftar kembali.',
                410
            );
        }

        if (now()->timestamp >= $registration['otp_expires_at']) {
            return ApiResponse::error(
                'OTP kedaluwarsa. Silakan kirim ulang OTP.',
                422
            );
        }

        if (($registration['attempts'] ?? 0) >= 5) {
            Cache::forget($cacheKey);

            return ApiResponse::error(
                'Batas percobaan OTP terlampaui. Silakan daftar kembali.',
                429
            );
        }

        $registration['attempts']++;

        if (!Hash::check($request->input('otp'), $registration['otp_hash'])) {
            Cache::put($cacheKey, $registration, now()->addMinutes(30));

            return ApiResponse::error(
                'Kode OTP salah. Periksa kembali email kamu.',
                422
            );
        }

        try {
            $customer = DB::transaction(function () use ($registration) {
                // Pemeriksaan ulang untuk mencegah data duplikat.
                if (
                    User::where('username', $registration['nomor_hp'])->exists()
                    || Customer::where('nomor_hp', $registration['nomor_hp'])->exists()
                    || Customer::where('email', $registration['email'])->exists()
                ) {
                    throw ValidationException::withMessages([
                        'register' => [
                            'Nomor HP atau email sudah terdaftar. Silakan login.',
                        ],
                    ]);
                }

                $user = User::create([
                    'username' => $registration['nomor_hp'],
                    'password' => $registration['password'],
                    'role' => 'customer',
                    'status' => 'aktif',
                ]);

                $customer = Customer::create([
                    'id_user' => $user->id_user,
                    'member_code' => 'TMP-' . Str::uuid(),
                    'qr_code' => 'SAE-QR-' . strtoupper(Str::random(12)),
                    'nama' => $registration['nama'],
                    'nomor_hp' => $registration['nomor_hp'],
                    'email' => $registration['email'],
                    'tanggal_lahir' => $registration['tanggal_lahir'],
                    'tanggal_daftar' => now(),
                    'saldo_point' => 0,
                    'status_member' => 'aktif',
                ]);

                // Membuat member code berdasarkan ID customer sebenarnya.
                $customer->member_code = 'SAE'
                    . str_pad(
                        (string) $customer->id_customer,
                        5,
                        '0',
                        STR_PAD_LEFT
                    );

                $customer->save();

                return $customer;
            });

            // OTP/token tidak bisa digunakan lagi setelah berhasil.
            Cache::forget($cacheKey);

            // Email sambutan bersifat tambahan.
            try {
                Mail::raw(
                    "Halo {$customer->nama},\n\n"
                    . "Selamat bergabung dengan SAÉ CAFE ROJEL!\n"
                    . "Akun member kamu berhasil diverifikasi.\n"
                    . "Silakan login menggunakan nomor WhatsApp dan password yang telah didaftarkan.",
                    function ($message) use ($customer) {
                        $message->to($customer->email)
                            ->subject('Selamat Bergabung - SAÉ CAFE ROJEL');
                    }
                );
            } catch (\Throwable $e) {
                report($e);
            }

            return ApiResponse::success(
                'Verifikasi berhasil. Silakan login.',
                [
                    'customer' => [
                        'nama' => $customer->nama,
                        'member_code' => $customer->member_code,
                        'qr_code' => $customer->qr_code,
                        'saldo_point' => $customer->saldo_point,
                    ],
                ],
                201
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Registrasi gagal diselesaikan. Silakan coba kembali.',
                500
            );
        }
    }

    /**
     * Mengirim ulang OTP setelah waktu tunggu selesai.
     */
    public function resend(Request $request)
    {
        $request->validate([
            'registration_token' => 'required|string',
        ]);

        $token = $request->input('registration_token');
        $cacheKey = 'api_registration:' . hash('sha256', $token);

        $registration = Cache::get($cacheKey);

        if (!$registration) {
            return ApiResponse::error(
                'Sesi registrasi berakhir. Silakan daftar kembali.',
                410
            );
        }

        if (
            now()->timestamp
            < $registration['otp_resend_available_at']
        ) {
            $remaining = $registration['otp_resend_available_at']
                - now()->timestamp;

            return ApiResponse::error(
                "Tunggu {$remaining} detik sebelum meminta OTP baru.",
                429
            );
        }

        $otp = (string) random_int(100000, 999999);

        try {
            Mail::raw(
                "Halo {$registration['nama']},\n\n"
                . "Kode OTP registrasi member SAÉ CAFE ROJEL kamu adalah:\n\n"
                . "{$otp}\n\n"
                . "Kode berlaku selama 5 menit dan hanya dapat digunakan satu kali.\n"
                . "Jangan bagikan kode ini kepada siapa pun.",
                function ($message) use ($registration) {
                    $message->to($registration['email'])
                        ->subject('Kode OTP Registrasi Member - SAÉ CAFE ROJEL');
                }
            );
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::error(
                'OTP gagal dikirim ulang. Silakan coba kembali.',
                500
            );
        }

        $registration['otp_hash'] = Hash::make($otp);
        $registration['otp_expires_at'] = now()->addMinutes(5)->timestamp;
        $registration['otp_resend_available_at'] = now()->addMinutes(5)->timestamp;
        $registration['attempts'] = 0;

        Cache::put($cacheKey, $registration, now()->addMinutes(30));

        return ApiResponse::success(
            'OTP baru berhasil dikirim ke email kamu.',
            [
                'otp_expires_in' => 300,
                'resend_available_in' => 300,
            ],
            200
        );
    }
}