<!-- /api/chart_data.php -->
<?php
session_start();
// Proteksi Endpoint
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'UNAUTHORIZED ACCESS']);
    exit();
}

require_once __DIR__ . '/../config/db.php';

// Ambil status portofolio aktif dari session, default ke 'Personal'
$active_portfolio = $_SESSION['active_portfolio'] ?? 'Personal';

$database = new Database();
$conn = $database->getConnection();

// Kueri terfilter berdasarkan kategori akun (Personal / Master_Joint)
$query = "
    SELECT 
        d.date, 
        SUM(d.pnl_cent) as total_pnl, 
        MIN(d.max_dd_cent) as total_max_dd 
    FROM daily_logs d
    JOIN accounts a ON d.account_id = a.account_id
    WHERE a.status = 'Active' AND a.account_category = :category
    GROUP BY d.date
    ORDER BY d.date ASC
";

$stmt = $conn->prepare($query);
$stmt->bindParam(':category', $active_portfolio);
$stmt->execute();
$results = $stmt->fetchAll();

$labels = [];
$pnl_data = [];
$dd_data = [];
$cumulative_data = [];
$cumulative_sum = 0;

foreach ($results as $row) {
    $labels[] = date('d M', strtotime($row['date'])); 
    $pnl_data[] = (float)$row['total_pnl'];
    $dd_data[] = (float)$row['total_max_dd'];
    
    $cumulative_sum += (float)$row['total_pnl'];
    $cumulative_data[] = $cumulative_sum;
}

header('Content-Type: application/json');
echo json_encode([
    'labels' => $labels,
    'pnl' => $pnl_data,
    'dd' => $dd_data,
    'cumulative' => $cumulative_data,
    'portfolio' => $active_portfolio
]);
?>
<!-- end file /api/chart_data.php -->

<!-- /config/db.php -->
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
<!-- end file /config/db.php -->

<!-- /includes/auth.php -->
<?php
session_start();

// Jika session user_id tidak ada, tendang ke login.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
?>
<!-- end file /includes/auth.php -->

<!-- /includes/JournalManager.php -->
<?php
// Pastikan file config db.php di-include saat class ini dipanggil
require_once __DIR__ . '/../config/db.php';

class JournalManager {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // --------------------------------------------------------
    // MENGAMBIL NILAI KURS USD TO IDR
    // --------------------------------------------------------
    public function getUsdRate() {
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_idr_rate'");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? (float)$row['setting_value'] : 168; // Default 168 jika tidak ditemukan
    }

    // --------------------------------------------------------
    // MENGAMBIL SEMUA AKUN AKTIF (TERFILTER BERDASARKAN KATEGORI)
    // --------------------------------------------------------
    public function getActiveAccounts($category = null) {
        $sql = "SELECT * FROM accounts WHERE status = 'Active'";
        if ($category) {
            $sql .= " AND account_category = :category";
        }
        $sql .= " ORDER BY account_id ASC";
        
        $stmt = $this->conn->prepare($sql);
        if ($category) {
            $stmt->bindParam(':category', $category);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // --------------------------------------------------------
    // MEMASUKKAN ATAU MEMPERBARUI AKUN
    // --------------------------------------------------------
    public function saveAccount($name, $initial_balance, $category = 'Personal') {
        $stmt = $this->conn->prepare("INSERT INTO accounts (account_name, initial_balance_cent, account_category) VALUES (:name, :balance, :category)");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':balance', $initial_balance);
        $stmt->bindParam(':category', $category);
        return $stmt->execute();
    }

    // --------------------------------------------------------
    // MEMASUKKAN LOG HARIAN BARU
    // --------------------------------------------------------
    public function addDailyLog($date, $account_id, $pnl_cent, $max_dd_cent, $remarks = '') {
        $stmt = $this->conn->prepare("INSERT INTO daily_logs (date, account_id, pnl_cent, max_dd_cent, remarks) 
                                      VALUES (:date, :account_id, :pnl, :max_dd, :remarks)");
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':account_id', $account_id);
        $stmt->bindParam(':pnl', $pnl_cent);
        $stmt->bindParam(':max_dd', $max_dd_cent);
        $stmt->bindParam(':remarks', $remarks);
        return $stmt->execute();
    }

    // --------------------------------------------------------
    // MENGHITUNG TOTAL METRIK UNTUK DASHBOARD (TERFILTER KATEGORI)
    // --------------------------------------------------------
    public function getDashboardMetrics($category = 'Personal') {
        $stmtBalance = $this->conn->prepare("SELECT SUM(initial_balance_cent) as total_initial FROM accounts WHERE status = 'Active' AND account_category = :category");
        $stmtBalance->bindParam(':category', $category);
        $stmtBalance->execute();
        $total_initial = $stmtBalance->fetch()['total_initial'] ?? 0;

        $stmtPnl = $this->conn->prepare("SELECT SUM(d.pnl_cent) as total_pnl FROM daily_logs d JOIN accounts a ON d.account_id = a.account_id WHERE a.status = 'Active' AND a.account_category = :category");
        $stmtPnl->bindParam(':category', $category);
        $stmtPnl->execute();
        $total_pnl = $stmtPnl->fetch()['total_pnl'] ?? 0;

        $current_balance = $total_initial + $total_pnl;
        $growth_percentage = ($total_initial > 0) ? ($total_pnl / $total_initial) * 100 : 0;

        return [
            'total_initial_cent' => $total_initial,
            'current_balance_cent' => $current_balance,
            'total_pnl_cent' => $total_pnl,
            'growth_percentage' => round($growth_percentage, 2)
        ];
    }

    // --------------------------------------------------------
    // MENGAMBIL REKAP PNL BULANAN (TERFILTER KATEGORI)
    // --------------------------------------------------------
    public function getMonthlyReport($year, $category = 'Personal') {
        $stmt = $this->conn->prepare("
            SELECT 
                MONTH(d.date) as month, 
                SUM(d.pnl_cent) as total_pnl, 
                MIN(d.max_dd_cent) as max_dd
            FROM daily_logs d
            JOIN accounts a ON d.account_id = a.account_id
            WHERE YEAR(d.date) = :year AND a.status = 'Active' AND a.account_category = :category
            GROUP BY MONTH(d.date)
            ORDER BY MONTH(d.date) ASC
        ");
        $stmt->bindParam(':year', $year);
        $stmt->bindParam(':category', $category);
        $stmt->execute();
        $results = $stmt->fetchAll();

        $report = array_fill(1, 12, ['total_pnl' => 0, 'max_dd' => 0]);
        
        foreach ($results as $row) {
            $report[(int)$row['month']]['total_pnl'] = (float)$row['total_pnl'];
            $report[(int)$row['month']]['max_dd'] = (float)$row['max_dd'];
        }
        
        return $report;
    }
}
?>
<!-- end file /includes/JournalManager.php -->

<!-- /tools/migrate_csv.php -->
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
<!-- end file /tools/migrate_csv.php -->

<!-- /index.php -->
<?php
require_once __DIR__ . '/includes/auth.php'; // Proteksi Keamanan
require_once __DIR__ . '/includes/JournalManager.php';

// ---------------------------------------------------------
// LOGIKA SWITCHER PORTOFOLIO V2.0
// ---------------------------------------------------------
if (isset($_GET['switch_portfolio'])) {
    $_SESSION['active_portfolio'] = $_GET['switch_portfolio'];
    // Redirect ke clean URL untuk membuang parameter GET dari address bar
    header("Location: index"); 
    exit();
}
// Default state jika belum ada
if (!isset($_SESSION['active_portfolio'])) {
    $_SESSION['active_portfolio'] = 'Personal';
}
$active_portfolio = $_SESSION['active_portfolio'];

// Tarik data menggunakan mesin yang sudah terfilter
$journal = new JournalManager();
$metrics = $journal->getDashboardMetrics($active_portfolio);
$usd_rate = $journal->getUsdRate();

// Label untuk UI
$portfolio_label = ($active_portfolio === 'Personal') ? 'PERSONAL EQUITY' : 'MANAGED FUNDS (PAMM)';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'terminal-black': '#000000',
                        'terminal-panel': '#111111',
                        'terminal-text': '#E0E0E0',
                        'neon-green': '#00FF00',
                        'neon-red': '#FF3333',
                        'electric-blue': '#00E5FF'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #000000; color: #E0E0E0; }
        .number-format { font-family: 'JetBrains Mono', monospace; }
        .sidebar-transition { transition: width 0.3s ease-in-out; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between">
            <div>
                <a href="index" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    <span class="nav-text">Dashboard</span>
                </a>

                <a href="input" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                    <span class="nav-text">Data Entry</span>
                </a>

                <a href="report" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    <span class="nav-text">Annual Report</span>
                </a>
            </div>

            <a href="logout" class="group block py-2 px-3 hover:bg-red-900 rounded text-gray-400 hover:text-red-500 transition-colors flex items-center whitespace-nowrap overflow-hidden border border-transparent hover:border-red-500 mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-red-500 group-hover:text-red-400 transition-colors">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                </svg>
                <span class="nav-text text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto">
        
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0">
            <div class="flex items-center space-x-6">
                <form method="GET" action="" class="flex items-center bg-black border border-gray-700 rounded px-2">
                    <span class="text-gray-500 font-mono text-xs mr-2">LEDGER:</span>
                    <select name="switch_portfolio" onchange="this.form.submit()" class="bg-black text-electric-blue font-mono text-sm py-1 outline-none font-bold cursor-pointer">
                        <option value="Personal" <?= $active_portfolio === 'Personal' ? 'selected' : '' ?>>PERSONAL EQUITY</option>
                        <option value="Master_Joint" <?= $active_portfolio === 'Master_Joint' ? 'selected' : '' ?>>MANAGED FUNDS (PAMM)</option>
                    </select>
                </form>
            </div>
            
            <div class="flex space-x-6 text-sm">
                <div>
                    <span class="text-gray-500 font-mono">USC/IDR:</span> 
                    <span class="number-format text-electric-blue">Rp <?= number_format($usd_rate, 0, ',', '.') ?></span>
                </div>
                <div>
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6">
            <div class="flex justify-between items-end border-b border-gray-800 pb-2 mb-6">
                <h1 class="text-xl font-bold font-mono text-gray-400">PORTFOLIO_OVERVIEW <span class="text-sm text-electric-blue ml-2">[<?= $portfolio_label ?>]</span></h1>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-terminal-panel p-5 rounded border border-gray-800 shadow-lg">
                    <div class="text-gray-500 text-xs font-mono mb-2">TOTAL INITIAL BALANCE (CENT)</div>
                    <div class="text-2xl number-format"><?= number_format($metrics['total_initial_cent'], 2, '.', ',') ?></div>
                </div>
                <div class="bg-terminal-panel p-5 rounded border border-gray-800 shadow-lg">
                    <div class="text-gray-500 text-xs font-mono mb-2">CURRENT BALANCE (CENT)</div>
                    <div class="text-2xl number-format <?= $metrics['current_balance_cent'] >= $metrics['total_initial_cent'] ? 'text-neon-green' : 'text-neon-red' ?>">
                        <?= number_format($metrics['current_balance_cent'], 2, '.', ',') ?>
                    </div>
                </div>
                <div class="bg-terminal-panel p-5 rounded border border-gray-800 shadow-lg">
                    <div class="text-gray-500 text-xs font-mono mb-2">TOTAL PNL (CENT)</div>
                    <div class="text-2xl number-format <?= $metrics['total_pnl_cent'] >= 0 ? 'text-neon-green' : 'text-neon-red' ?>">
                        <?= $metrics['total_pnl_cent'] >= 0 ? '+' : '' ?><?= number_format($metrics['total_pnl_cent'], 2, '.', ',') ?>
                    </div>
                </div>
                <div class="bg-terminal-panel p-5 rounded border border-gray-800 shadow-lg">
                    <div class="text-gray-500 text-xs font-mono mb-2">GROWTH / ROI (%)</div>
                    <div class="text-2xl number-format <?= $metrics['growth_percentage'] >= 0 ? 'text-neon-green' : 'text-neon-red' ?>">
                        <?= $metrics['growth_percentage'] >= 0 ? '+' : '' ?><?= $metrics['growth_percentage'] ?>%
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-terminal-panel p-4 rounded border border-gray-800 shadow-lg relative h-80">
                    <h2 class="text-gray-500 text-xs font-mono mb-2 absolute top-4 left-4 z-10">CUMULATIVE EQUITY CURVE</h2>
                    <canvas id="equityChart"></canvas>
                </div>
                
                <div class="bg-terminal-panel p-4 rounded border border-gray-800 shadow-lg relative h-80">
                    <h2 class="text-gray-500 text-xs font-mono mb-2 absolute top-4 left-4 z-10">DAILY PNL VS MAX DRAWDOWN</h2>
                    <canvas id="pnlDdChart"></canvas>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Logika UI Sidebar & Jam
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggle-sidebar');
        const navTexts = document.querySelectorAll('.nav-text');
        const logoText = document.getElementById('logo-text');

        if(localStorage.getItem('ea_sidebar_collapsed') === 'true') collapseSidebar();

        toggleBtn.addEventListener('click', () => {
            if (sidebar.classList.contains('w-64')) {
                collapseSidebar();
                localStorage.setItem('ea_sidebar_collapsed', 'true');
            } else {
                expandSidebar();
                localStorage.setItem('ea_sidebar_collapsed', 'false');
            }
        });

        function collapseSidebar() {
            sidebar.classList.replace('w-64', 'w-16');
            logoText.classList.add('opacity-0');
            setTimeout(() => logoText.classList.add('hidden'), 150);
            navTexts.forEach(txt => txt.classList.add('hidden'));
        }

        function expandSidebar() {
            sidebar.classList.replace('w-16', 'w-64');
            logoText.classList.remove('hidden');
            setTimeout(() => logoText.classList.remove('opacity-0'), 10);
            navTexts.forEach(txt => txt.classList.remove('hidden'));
        }

        setInterval(() => {
            document.getElementById('clock').innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);

        // ==========================================
        // ENGINE GRAFIK ANALITIK (CHART.JS)
        // ==========================================
        document.addEventListener("DOMContentLoaded", function() {
            fetch('api/chart_data.php')
                .then(response => response.json())
                .then(data => {
                    if(data.error) {
                        console.error('API Error:', data.error);
                        return;
                    }

                    Chart.defaults.color = '#888';
                    Chart.defaults.font.family = "'JetBrains Mono', monospace";
                    Chart.defaults.scale.grid.color = '#222';
                    Chart.defaults.scale.grid.borderColor = '#444';

                    const ctxEquity = document.getElementById('equityChart').getContext('2d');
                    new Chart(ctxEquity, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                label: 'Cumulative Cent',
                                data: data.cumulative,
                                borderColor: '#00E5FF',
                                backgroundColor: 'rgba(0, 229, 255, 0.1)',
                                borderWidth: 2,
                                pointRadius: 1,
                                pointHoverRadius: 5,
                                fill: true,
                                tension: 0.3
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            layout: { padding: { top: 30 } }
                        }
                    });

                    const ctxPnlDd = document.getElementById('pnlDdChart').getContext('2d');
                    new Chart(ctxPnlDd, {
                        type: 'bar',
                        data: {
                            labels: data.labels,
                            datasets: [
                                {
                                    label: 'Daily Profit',
                                    data: data.pnl,
                                    backgroundColor: '#00FF00',
                                    borderRadius: 2
                                },
                                {
                                    label: 'Max Drawdown',
                                    data: data.dd,
                                    backgroundColor: '#FF3333',
                                    borderRadius: 2
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                            layout: { padding: { top: 30 } },
                            scales: {
                                x: { stacked: false },
                                y: { beginAtZero: true }
                            }
                        }
                    });
                })
                .catch(error => console.error('Gagal memuat data grafik:', error));
        });
    </script>
</body>
</html>
<!-- end file /index.php -->

<!-- /input.php -->
<?php
require_once __DIR__ . '/includes/auth.php'; // Proteksi Keamanan
require_once __DIR__ . '/includes/JournalManager.php';

$journal = new JournalManager();
$accounts = $journal->getActiveAccounts();
$message = '';
$usd_rate = $journal->getUsdRate();

// Proses form jika ada submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $account_id = $_POST['account_id'];
    $pnl_cent = $_POST['pnl_cent'];
    $max_dd_cent = $_POST['max_dd_cent'];
    $remarks = $_POST['remarks'];

    if ($journal->addDailyLog($date, $account_id, $pnl_cent, $max_dd_cent, $remarks)) {
        $message = "<div class='bg-neon-green text-terminal-black font-mono px-4 py-2 rounded mb-6'>[SUCCESS] LOG ENTRI BERHASIL DISIMPAN KE DATABASE.</div>";
    } else {
        $message = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6'>[ERROR] GAGAL MENYIMPAN DATA.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Data Entry</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'terminal-black': '#000000',
                        'terminal-panel': '#111111',
                        'terminal-text': '#E0E0E0',
                        'neon-green': '#00FF00',
                        'neon-red': '#FF3333',
                        'electric-blue': '#00E5FF'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #000000; color: #E0E0E0; }
        .number-format { font-family: 'JetBrains Mono', monospace; }
        .input-dark { background-color: #000; border: 1px solid #333; color: #00FF00; font-family: 'JetBrains Mono', monospace; outline: none; transition: border 0.2s;}
        .input-dark:focus { border-color: #00E5FF; }
        .sidebar-transition { transition: width 0.3s ease-in-out; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">&#9776;</button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between">
            <div>
                <a href="index" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    <span class="nav-text">Dashboard</span>
                </a>

                <a href="input" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                    <span class="nav-text">Data Entry</span>
                </a>

                <a href="report" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    <span class="nav-text">Annual Report</span>
                </a>
            </div>

            <a href="logout" class="group block py-2 px-3 hover:bg-red-900 rounded text-gray-400 hover:text-red-500 transition-colors flex items-center whitespace-nowrap overflow-hidden border border-transparent hover:border-red-500 mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-red-500 group-hover:text-red-400 transition-colors">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                </svg>
                <span class="nav-text text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 overflow-y-auto p-6 flex flex-col items-center">
        <div class="w-full flex justify-between items-center border-b border-gray-800 pb-4 mb-6 text-sm">
            <div class="font-mono text-gray-500">
                SYS.STATUS: <span class="text-neon-green animate-pulse">ONLINE</span>
            </div>
            <div class="font-mono text-gray-500">
                USC/IDR: <span class="text-electric-blue number-format">Rp <?= number_format($usd_rate, 0, ',', '.') ?></span>
            </div>
        </div>

        <div class="w-full max-w-2xl">
            <h1 class="text-xl font-bold mb-6 font-mono text-gray-400 border-b border-gray-800 pb-2">DATA_ENTRY_MODULE</h1>
            
            <?= $message ?>

            <div class="bg-terminal-panel p-6 rounded border border-gray-800 shadow-lg">
                <form method="POST" action="">
                    <div class="grid grid-cols-2 gap-6 mb-4">
                        <div>
                            <label class="block text-gray-500 text-xs font-mono mb-2">TANGGAL TRANSAKSI</label>
                            <input type="date" name="date" required value="<?= date('Y-m-d') ?>" class="input-dark w-full px-3 py-2 rounded">
                        </div>
                        <div>
                            <label class="block text-gray-500 text-xs font-mono mb-2">TARGET AKUN</label>
                            <select name="account_id" required class="input-dark w-full px-3 py-2 rounded">
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6 mb-4">
                        <div>
                            <label class="block text-gray-500 text-xs font-mono mb-2">PNL HARIAN (CENT)</label>
                            <input type="number" step="0.01" name="pnl_cent" required placeholder="Contoh: 150.50 atau -50.00" class="input-dark w-full px-3 py-2 rounded">
                        </div>
                        <div>
                            <label class="block text-gray-500 text-xs font-mono mb-2">MAX DRAWDOWN (CENT)</label>
                            <input type="number" step="0.01" name="max_dd_cent" required placeholder="Contoh: -20.50" class="input-dark w-full px-3 py-2 rounded text-neon-red">
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-500 text-xs font-mono mb-2">REMARKS / STRATEGY NOTES (Opsional)</label>
                        <input type="text" name="remarks" placeholder="Contoh: Golden Risk v3.05 Jarak 70" class="input-dark w-full px-3 py-2 rounded text-gray-300">
                    </div>

                    <button type="submit" class="w-full bg-gray-800 hover:bg-electric-blue hover:text-black text-electric-blue font-mono font-bold py-3 px-4 rounded transition-colors border border-gray-700 hover:border-electric-blue">
                        [ EXECUTE DATA INSERT ]
                    </button>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Logika Expand/Collapse Sidebar menggunakan Vanilla JS & Local Storage
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggle-sidebar');
        const navTexts = document.querySelectorAll('.nav-text');
        const logoText = document.getElementById('logo-text');

        if(localStorage.getItem('ea_sidebar_collapsed') === 'true') collapseSidebar();

        toggleBtn.addEventListener('click', () => {
            if (sidebar.classList.contains('w-64')) {
                collapseSidebar();
                localStorage.setItem('ea_sidebar_collapsed', 'true');
            } else {
                expandSidebar();
                localStorage.setItem('ea_sidebar_collapsed', 'false');
            }
        });

        function collapseSidebar() {
            sidebar.classList.replace('w-64', 'w-16');
            logoText.classList.add('opacity-0');
            setTimeout(() => logoText.classList.add('hidden'), 150);
            navTexts.forEach(txt => txt.classList.add('hidden'));
        }

        function expandSidebar() {
            sidebar.classList.replace('w-16', 'w-64');
            logoText.classList.remove('hidden');
            setTimeout(() => logoText.classList.remove('opacity-0'), 10);
            navTexts.forEach(txt => txt.classList.remove('hidden'));
        }
    </script>
</body>
</html>
<!-- end file /input.php -->

<!-- /login.php -->
<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Jika sudah login, langsung arahkan ke dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $database = new Database();
    $conn = $database->getConnection();

    $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1");
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Login berhasil, set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header("Location: index");
        exit();
    } else {
        $error = "ACCESS DENIED. INVALID CREDENTIALS.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - SYSTEM LOGIN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000000; color: #E0E0E0; font-family: 'JetBrains Mono', monospace; }
        .input-terminal { background-color: #000; border: 1px solid #333; color: #00FF00; outline: none; transition: border 0.3s;}
        .input-terminal:focus { border-color: #00E5FF; box-shadow: 0 0 5px rgba(0, 229, 255, 0.3); }
    </style>
</head>
<body class="flex items-center justify-center h-screen relative overflow-hidden">
    <div class="absolute inset-0 z-0 opacity-10" style="background-image: linear-gradient(#333 1px, transparent 1px), linear-gradient(90deg, #333 1px, transparent 1px); background-size: 30px 30px;"></div>

    <div class="z-10 bg-[#111111] p-8 rounded border border-gray-800 shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <span class="text-electric-blue text-3xl font-bold tracking-widest text-[#00E5FF]">EA.CMD_</span>
            <p class="text-gray-500 text-xs mt-2">SECURE AUTHENTICATION GATEWAY</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-900 border border-red-500 text-red-400 px-4 py-2 rounded mb-6 text-sm text-center animate-pulse">
                [ <?= $error ?> ]
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-5">
                <label class="block text-gray-500 text-xs mb-2">USERNAME ID</label>
                <input type="text" name="username" required autocomplete="off" class="input-terminal w-full px-4 py-3 rounded" placeholder="_">
            </div>
            <div class="mb-8">
                <label class="block text-gray-500 text-xs mb-2">SECURITY PASSPHRASE</label>
                <input type="password" name="password" required class="input-terminal w-full px-4 py-3 rounded text-red-500" placeholder="***">
            </div>
            <button type="submit" class="w-full bg-transparent hover:bg-[#00E5FF] text-[#00E5FF] hover:text-black font-bold py-3 px-4 rounded transition-colors border border-[#00E5FF]">
                [ INITIATE LOGIN ]
            </button>
        </form>
    </div>
</body>
</html>
<!-- end file /login.php -->

<!-- /logout.php -->
<?php
session_start();
session_unset();
session_destroy();
header("Location: login");
exit();
?>
<!-- end file /logout.php -->

<!-- /report.php -->
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

$journal = new JournalManager();
$usd_rate = $journal->getUsdRate();

// Menentukan tahun yang akan ditampilkan (Default: Tahun ini)
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$monthly_data = $journal->getMonthlyReport($selected_year);

$months_label = [
    1 => 'JANUARY', 2 => 'FEBRUARY', 3 => 'MARCH', 4 => 'APRIL', 
    5 => 'MAY', 6 => 'JUNE', 7 => 'JULY', 8 => 'AUGUST', 
    9 => 'SEPTEMBER', 10 => 'OCTOBER', 11 => 'NOVEMBER', 12 => 'DECEMBER'
];

$total_annual_pnl = 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Annual Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'terminal-black': '#000000',
                        'terminal-panel': '#111111',
                        'terminal-text': '#E0E0E0',
                        'neon-green': '#00FF00',
                        'neon-red': '#FF3333',
                        'electric-blue': '#00E5FF'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #000000; color: #E0E0E0; }
        .number-format { font-family: 'JetBrains Mono', monospace; }
        .sidebar-transition { transition: width 0.3s ease-in-out; }
        /* Kustomisasi scrollbar untuk tabel */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">&#9776;</button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between">
            <div>
                <a href="index" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    <span class="nav-text">Dashboard</span>
                </a>

                <a href="input" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                    <span class="nav-text">Data Entry</span>
                </a>

                <a href="report" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-neon-green transition-colors">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    <span class="nav-text">Annual Report</span>
                </a>
            </div>

            <a href="logout" class="group block py-2 px-3 hover:bg-red-900 rounded text-gray-400 hover:text-red-500 transition-colors flex items-center whitespace-nowrap overflow-hidden border border-transparent hover:border-red-500 mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-red-500 group-hover:text-red-400 transition-colors">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                </svg>
                <span class="nav-text text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0">
            <div class="text-sm font-mono">
                <span class="text-gray-500">SYS.STATUS:</span> <span class="text-neon-green animate-pulse">ONLINE</span>
            </div>
            <div class="flex space-x-6 text-sm">
                <div>
                    <span class="text-gray-500 font-mono">USC/IDR:</span> 
                    <span class="number-format text-electric-blue">Rp <?= number_format($usd_rate, 0, ',', '.') ?></span>
                </div>
                <div>
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6">
            <div class="flex justify-between items-end border-b border-gray-800 pb-2 mb-6">
                <h1 class="text-xl font-bold font-mono text-gray-400">ANNUAL_PNL_MATRIX_<?= $selected_year ?></h1>
                
                <form method="GET" action="" class="flex items-center space-x-2">
                    <span class="text-gray-500 font-mono text-xs">FISCAL YEAR:</span>
                    <select name="year" onchange="this.form.submit()" class="bg-black border border-gray-700 text-electric-blue font-mono text-sm px-2 py-1 rounded outline-none focus:border-electric-blue">
                        <?php 
                        $start_year = 2024;
                        for($y = date('Y'); $y >= $start_year; $y--): ?>
                            <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>

            <div class="bg-terminal-panel rounded border border-gray-800 shadow-lg overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-900 border-b border-gray-700 font-mono text-xs text-gray-400">
                            <th class="p-4 uppercase tracking-wider">Month</th>
                            <th class="p-4 uppercase tracking-wider text-right">Net PNL (Cent)</th>
                            <th class="p-4 uppercase tracking-wider text-right">Net PNL (IDR)</th>
                            <th class="p-4 uppercase tracking-wider text-right">Max DD (Cent)</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-sm">
                        <?php foreach($months_label as $month_num => $month_name): 
                            $pnl_cent = $monthly_data[$month_num]['total_pnl'];
                            $dd_cent = $monthly_data[$month_num]['max_dd'];
                            $pnl_idr = $pnl_cent * $usd_rate;
                            $total_annual_pnl += $pnl_cent;
                            
                            $pnl_color = $pnl_cent > 0 ? 'text-neon-green' : ($pnl_cent < 0 ? 'text-neon-red' : 'text-gray-500');
                        ?>
                        <tr class="border-b border-gray-800 hover:bg-gray-800 transition-colors">
                            <td class="p-4 text-gray-300"><?= $month_name ?></td>
                            <td class="p-4 text-right <?= $pnl_color ?>">
                                <?= $pnl_cent > 0 ? '+' : '' ?><?= number_format($pnl_cent, 2, '.', ',') ?>
                            </td>
                            <td class="p-4 text-right <?= $pnl_color ?>">
                                <?= $pnl_idr > 0 ? '+' : '' ?>Rp <?= number_format($pnl_idr, 0, ',', '.') ?>
                            </td>
                            <td class="p-4 text-right <?= $dd_cent < 0 ? 'text-neon-red' : 'text-gray-500' ?>">
                                <?= number_format($dd_cent, 2, '.', ',') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-900 border-t border-gray-700 font-mono font-bold">
                            <td class="p-4 text-electric-blue">TOTAL YTD</td>
                            <td class="p-4 text-right <?= $total_annual_pnl > 0 ? 'text-neon-green' : 'text-neon-red' ?>">
                                <?= $total_annual_pnl > 0 ? '+' : '' ?><?= number_format($total_annual_pnl, 2, '.', ',') ?>
                            </td>
                            <td class="p-4 text-right <?= $total_annual_pnl > 0 ? 'text-neon-green' : 'text-neon-red' ?>">
                                <?= $total_annual_pnl > 0 ? '+' : '' ?>Rp <?= number_format($total_annual_pnl * $usd_rate, 0, ',', '.') ?>
                            </td>
                            <td class="p-4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </main>

    <script>
        // Logika Sidebar & Jam
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggle-sidebar');
        const navTexts = document.querySelectorAll('.nav-text');
        const logoText = document.getElementById('logo-text');

        if(localStorage.getItem('ea_sidebar_collapsed') === 'true') collapseSidebar();

        toggleBtn.addEventListener('click', () => {
            if (sidebar.classList.contains('w-64')) {
                collapseSidebar();
                localStorage.setItem('ea_sidebar_collapsed', 'true');
            } else {
                expandSidebar();
                localStorage.setItem('ea_sidebar_collapsed', 'false');
            }
        });

        function collapseSidebar() {
            sidebar.classList.replace('w-64', 'w-16');
            logoText.classList.add('opacity-0');
            setTimeout(() => logoText.classList.add('hidden'), 150);
            navTexts.forEach(txt => txt.classList.add('hidden'));
        }

        function expandSidebar() {
            sidebar.classList.replace('w-16', 'w-64');
            logoText.classList.remove('hidden');
            setTimeout(() => logoText.classList.remove('opacity-0'), 10);
            navTexts.forEach(txt => txt.classList.remove('hidden'));
        }

        setInterval(() => {
            document.getElementById('clock').innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);
    </script>
</body>
</html>
<!-- end file /report.php -->