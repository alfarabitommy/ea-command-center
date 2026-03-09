<?php
// Mencegah akses file langsung dari browser (Security Header)
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    die('Direct access not permitted');
}

class Database {
    // Kredensial localhost default XAMPP
    private $host = "127.0.0.1"; // Menggunakan IP untuk menghindari issue DNS resolution localhost
    private $db_name = "ea_journal";
    private $username = "root";
    private $password = ""; 
    private $charset = "utf8mb4";
    public $conn;

    public function getConnection() {
        $this->conn = null;

        $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
        
        // Opsi PDO tingkat Enterprise untuk Keamanan & Kecepatan
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lempar exception jika ada error SQL
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch data sebagai associative array
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Matikan emulasi untuk keamanan Anti-SQL Injection sejati
            PDO::ATTR_PERSISTENT         => true                    // Gunakan persistent connection agar lebih cepat jika data sangat masif
        ];

        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch(PDOException $exception) {
            // Dalam mode production, error ini harus dicatat ke file log, bukan ditampilkan di layar
            die("Koneksi Database Gagal: Cek status MySQL di XAMPP. Pesan: " . $exception->getMessage());
        }

        return $this->conn;
    }
}
?>