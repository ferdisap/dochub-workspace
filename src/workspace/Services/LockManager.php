<?php

namespace Dochub\Workspace\Services;

// catatan penting untuk production
// 1. Redis harus di-configure sebagai cluster-aware
// REDIS_CLIENT=predis
// REDIS_CLUSTER=redis
// 2. Pastikan Redis persistence aktif (RDB/AOF) untuk hindari lock hilang saat restart.
// 3. Monitor lock contention:
// # Di Redis CLI
// redis-cli --bigkeys
// redis-cli monitor | grep "lock:"
interface LockManager
{
    /**
     * Dapatkan lock untuk kunci tertentu
     * untuk 🔒 Mengamankan akses eksklusif ke sebuah sumber daya (resource) berdasarkan kunci ($key), selama durasi tertentu — untuk mencegah race condition saat operasi kritis berlangsung.
     *
     * @param string $key Nama unik lock (e.g., 'blob_dir:a1')
     * @param int $timeoutMs Waktu tunggu dalam milidetik (default: 30 detik)
     * @return bool true jika lock didapat, false jika timeout
     */
    public function acquire(string $key, int $timeoutMs = 30000): bool;

    /**
     * Lepaskan lock
     *
     * @param string $key Nama lock yang sama dengan acquire()
     */
    public function release(string $key): void;

    /**
     * Eksekusi kode dalam lock (helper aman)
     *
     * @param string $key
     * @param callable $callback
     * @param int $timeoutMs
     * @return mixed
     * @throws \RuntimeException Jika lock gagal
     */
    public function withLock(string $key, callable $callback, int $timeoutMs = 30000);

    /**
     * Mengecek apakah sedang di lock atau tdaik
     * @param string $key
     */
    public function isLocked(string $key): bool;
}

// contoh
// use App\Services\Locking\LockManager;

// class SomeService
// {
//     public function __construct(private LockManager $lockManager) {}

//     public function processImportantTask()
//     {
//         return $this->lockManager->withLock('critical_task', function () {
//             // Hanya 1 proses yang jalankan ini dalam satu waktu
//             // (di seluruh cluster jika pakai Redis)
//             return $this->doCriticalWork();
//         });
//     }
// }