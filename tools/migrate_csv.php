<?php
require_once __DIR__ . '/../includes/JournalManager.php';

$journal = new JournalManager();

// CATATAN EKSEKUSI: 
// Buat folder 'csv_imports' di dalam folder 'tools', lalu masukkan file .csv (Maret-Sept) ke dalamnya.
$csv_folder = __DIR__ . '/csv_imports/';
$files = glob($csv_folder . "*.csv");

if (empty($files)) {
    die("Folder csv_imports kosong. Silakan masukkan file CSV Anda.");
}

echo "<h3>Memulai Migrasi Data CSV...</h3>";

// Array untuk menyimpan temporary mapping akun berdasarkan urutan kolom di CSV
// Asumsi ACC ID 1 - 5 sudah dimasukkan ke database manual atau akan dibuatkan otomatis jika belum ada
$account_map = [
    1 => 1, // Index Akun 1 di DB
    2 => 2, // Index Akun 2 di DB
    3 => 3, // Index Akun 3 di DB
    4 => 4, // Index Akun 4 di DB
    5 => 5  // Index Akun 5 di DB
];

foreach ($files as $file) {
    echo "Memproses: " . basename($file) . "<br>";
    $handle = fopen($file, "r");
    
    $current_date = "";
    $temp_pnl = []; 
    $temp_remarks = "";

    if ($handle !== FALSE) {
        // Berdasarkan struktur CSV yang dianalisis, baris data dimulai setelah header panjang
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            
            // Deteksi baris "Profit" (Melihat struktur data: Kolom 1 Tanggal, Kolom 2 "Profit")
            if (isset($data[2]) && trim($data[2]) == 'Profit' && !empty($data[1])) {
                $current_date = trim($data[1]); // Format YYYY-MM-DD
                $temp_remarks = isset($data[8]) ? trim($data[8]) : ''; // Remarks biasanya di kolom ke-8/9
                
                // Asumsi PNL per akun ada di kolom 3, 4, 5, 6, 7 (sesuaikan dengan offset CSV)
                $temp_pnl[1] = (float)($data[3] ?? 0);
                $temp_pnl[2] = (float)($data[4] ?? 0);
                $temp_pnl[3] = (float)($data[5] ?? 0);
                $temp_pnl[4] = (float)($data[6] ?? 0);
                $temp_pnl[5] = (float)($data[7] ?? 0);
            }
            
            // Deteksi baris "Max DD" (Biasanya persis di bawah baris Profit)
            if (isset($data[2]) && trim($data[2]) == 'Max DD' && !empty($current_date)) {
                $max_dd[1] = (float)($data[3] ?? 0);
                $max_dd[2] = (float)($data[4] ?? 0);
                $max_dd[3] = (float)($data[5] ?? 0);
                $max_dd[4] = (float)($data[6] ?? 0);
                $max_dd[5] = (float)($data[7] ?? 0);

                // Eksekusi Insert ke Database
                foreach ($account_map as $csv_acc_idx => $db_acc_id) {
                    $pnl = $temp_pnl[$csv_acc_idx] ?? 0;
                    $dd = $max_dd[$csv_acc_idx] ?? 0;

                    // Insert jika ada nilai (tidak kosong)
                    if ($pnl != 0 || $dd != 0) {
                        $journal->addDailyLog($current_date, $db_acc_id, $pnl, $dd, $temp_remarks);
                    }
                }
                
                // Reset setelah insert
                $current_date = "";
                $temp_pnl = [];
                $temp_remarks = "";
            }
        }
        fclose($handle);
    }
}

echo "<h3 style='color:green;'>Migrasi Selesai!</h3>";
echo "Silakan cek tabel daily_logs di database Anda.";
?>