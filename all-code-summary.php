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
require_once __DIR__ . '/../config/db.php';

date_default_timezone_set('Asia/Jakarta');

class JournalManager {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function getUsdRate() {
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'usd_idr_rate'");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? (float)$row['setting_value'] : 168;
    }

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

    public function saveAccount($name, $broker_name, $initial_balance, $category = 'Personal') {
        $stmt = $this->conn->prepare("
            INSERT INTO accounts (account_name, broker_name, initial_balance_cent, account_category, status) 
            VALUES (:name, :broker, :balance, :category, 'Active')
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':broker', $broker_name);
        $stmt->bindParam(':balance', $initial_balance);
        $stmt->bindParam(':category', $category);
        return $stmt->execute();
    }

    public function getAllAccounts() {
        $stmt = $this->conn->prepare("SELECT * FROM accounts ORDER BY account_category ASC, status ASC, account_id DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateAccountStatus($account_id, $status) {
        $stmt = $this->conn->prepare("UPDATE accounts SET status = :status WHERE account_id = :id");
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $account_id);
        return $stmt->execute();
    }

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

    public function getMonthlyReport($year, $category = 'Personal') {
        $stmt = $this->conn->prepare("
            SELECT MONTH(d.date) as month, SUM(d.pnl_cent) as total_pnl, MIN(d.max_dd_cent) as max_dd
            FROM daily_logs d JOIN accounts a ON d.account_id = a.account_id
            WHERE YEAR(d.date) = :year AND a.status = 'Active' AND a.account_category = :category
            GROUP BY MONTH(d.date) ORDER BY MONTH(d.date) ASC
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

    // ========================================================
    // MODUL AFILIASI & MARKETING (BARU)
    // ========================================================

    public function getAffiliates() {
        $stmt = $this->conn->prepare("SELECT * FROM affiliates ORDER BY total_unpaid_commission DESC, marketer_name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addAffiliate($marketer_name) {
        $stmt = $this->conn->prepare("INSERT INTO affiliates (marketer_name, total_unpaid_commission) VALUES (:name, 0.00)");
        $stmt->bindParam(':name', $marketer_name);
        return $stmt->execute();
    }

    public function payoutAffiliate($affiliate_id) {
        $stmt = $this->conn->prepare("UPDATE affiliates SET total_unpaid_commission = 0.00 WHERE affiliate_id = :id");
        $stmt->bindParam(':id', $affiliate_id);
        return $stmt->execute();
    }

    // ========================================================
    // MODUL CRM & PAMM 
    // ========================================================

    public function addClient($name, $tier_type, $referred_by = null, $master_account_id = null, $capital_amount = 0) {
        try {
            $this->conn->beginTransaction();

            $ref_val = empty($referred_by) ? null : $referred_by;
            $trial_end = date('Y-m-d H:i:s', strtotime('+48 hours'));
            
            $stmt = $this->conn->prepare("
                INSERT INTO clients (client_name, tier_type, status, trial_end_date, referred_by) 
                VALUES (:name, :tier, 'Trial', :trial_end, :ref)
            ");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':tier', $tier_type);
            $stmt->bindParam(':trial_end', $trial_end);
            $stmt->bindParam(':ref', $ref_val);
            $stmt->execute();

            $client_id = $this->conn->lastInsertId();

            if ($tier_type === 'Tier_B' && !empty($master_account_id) && $capital_amount > 0) {
                $stmtFund = $this->conn->prepare("
                    INSERT INTO client_funds (client_id, capital_amount_idr, associated_master_account_id) 
                    VALUES (:cid, :cap, :acc_id)
                ");
                $stmtFund->bindParam(':cid', $client_id);
                $stmtFund->bindParam(':cap', $capital_amount);
                $stmtFund->bindParam(':acc_id', $master_account_id);
                $stmtFund->execute();
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    public function getClients() {
        $now = date('Y-m-d H:i:s');
        $this->conn->query("UPDATE clients SET status = 'Expired' WHERE status = 'Trial' AND trial_end_date < '$now'");
        $this->conn->query("UPDATE clients SET status = 'Expired' WHERE status = 'Active' AND subscription_end_date < '$now'");

        $stmt = $this->conn->prepare("
            SELECT c.*, a.marketer_name 
            FROM clients c 
            LEFT JOIN affiliates a ON c.referred_by = a.affiliate_id 
            ORDER BY FIELD(c.status, 'Expired', 'Trial', 'Active'), c.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function processClientBilling($client_id) {
        $stmt = $this->conn->prepare("SELECT * FROM clients WHERE client_id = :id");
        $stmt->bindParam(':id', $client_id);
        $stmt->execute();
        $client = $stmt->fetch();

        if (!$client) return false;

        $now = date('Y-m-d H:i:s');
        $new_end_date = date('Y-m-d H:i:s', strtotime('+30 days'));

        try {
            $this->conn->beginTransaction();

            $update_stmt = $this->conn->prepare("UPDATE clients SET status = 'Active', subscription_end_date = :end_date WHERE client_id = :id");
            $update_stmt->bindParam(':end_date', $new_end_date);
            $update_stmt->bindParam(':id', $client_id);
            $update_stmt->execute();

            $amount = ($client['tier_type'] === 'Tier_A') ? 400000 : 200000;
            $invoice_type = ($client['tier_type'] === 'Tier_A') ? 'Tier_A_400k' : 'Tier_B_200k';
            
            $inv_stmt = $this->conn->prepare("INSERT INTO billing_invoices (client_id, amount, payment_date, invoice_type) VALUES (:id, :amount, :date, :type)");
            $inv_stmt->bindParam(':id', $client_id);
            $inv_stmt->bindParam(':amount', $amount);
            $inv_stmt->bindParam(':date', $now);
            $inv_stmt->bindParam(':type', $invoice_type);
            $inv_stmt->execute();

            // Trigger Komisi 100k khusus Tier A
            if ($client['tier_type'] === 'Tier_A' && !empty($client['referred_by'])) {
                $aff_stmt = $this->conn->prepare("UPDATE affiliates SET total_unpaid_commission = total_unpaid_commission + 100000 WHERE affiliate_id = :aff_id");
                $aff_stmt->bindParam(':aff_id', $client['referred_by']);
                $aff_stmt->execute();
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    public function get1on1Distribution($master_account_id) {
        $stmtAcc = $this->conn->prepare("SELECT account_name, initial_balance_cent FROM accounts WHERE account_id = :id");
        $stmtAcc->bindParam(':id', $master_account_id);
        $stmtAcc->execute();
        $account = $stmtAcc->fetch();

        if (!$account) return null;

        $usd_rate = $this->getUsdRate();
        $total_account_usd = $account['initial_balance_cent'] / 100;
        $total_account_idr = $total_account_usd * $usd_rate;

        $stmtFund = $this->conn->prepare("
            SELECT cf.capital_amount_idr, c.client_name 
            FROM client_funds cf
            JOIN clients c ON cf.client_id = c.client_id
            WHERE cf.associated_master_account_id = :master_id AND c.status = 'Active'
            LIMIT 1
        ");
        $stmtFund->bindParam(':master_id', $master_account_id);
        $stmtFund->execute();
        $fund = $stmtFund->fetch();

        if (!$fund) {
            return [
                'account_name' => $account['account_name'],
                'total_capital_idr' => $total_account_idr,
                'tommy_capital_idr' => $total_account_idr,
                'tommy_ratio' => 100,
                'has_client' => false,
                'client_name' => 'N/A (Personal Equity)',
                'client_capital_idr' => 0,
                'client_ratio' => 0
            ];
        }

        $client_idr = (float)$fund['capital_amount_idr'];
        $tommy_idr = $total_account_idr - $client_idr;

        if ($total_account_idr <= 0) {
            $client_ratio = 50;
            $tommy_ratio = 50;
        } else {
            $client_ratio = ($client_idr / $total_account_idr) * 100;
            $tommy_ratio = ($tommy_idr / $total_account_idr) * 100;
        }

        return [
            'account_name' => $account['account_name'],
            'total_capital_idr' => $total_account_idr,
            'tommy_capital_idr' => $tommy_idr,
            'tommy_ratio' => round($tommy_ratio, 2),
            'has_client' => true,
            'client_name' => $fund['client_name'],
            'client_capital_idr' => $client_idr,
            'client_ratio' => round($client_ratio, 2)
        ];
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

<!-- /accounts.php -->
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

if (isset($_GET['switch_portfolio'])) {
    $_SESSION['active_portfolio'] = $_GET['switch_portfolio'];
    header("Location: accounts"); 
    exit();
}
if (!isset($_SESSION['active_portfolio'])) {
    $_SESSION['active_portfolio'] = 'Personal';
}
$active_portfolio = $_SESSION['active_portfolio'];
$usd_rate = (new JournalManager())->getUsdRate();

$journal = new JournalManager();

// Proses Add Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_account') {
    $account_name = trim($_POST['account_name']);
    $broker_name = trim($_POST['broker_name']);
    $initial_balance = (float)$_POST['initial_balance'];
    $category = $_POST['account_category'];

    if ($journal->saveAccount($account_name, $broker_name, $initial_balance, $category)) {
        $_SESSION['flash_msg'] = "<div class='bg-neon-green text-terminal-black font-mono px-4 py-2 rounded mb-6 font-bold'>[SUCCESS] AKUN TRADING BARU BERHASIL DIDAFTARKAN.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6'>[ERROR] GAGAL MENDAFTARKAN AKUN.</div>";
    }
    header("Location: accounts");
    exit();
}

// Proses Update Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $account_id = $_POST['account_id'];
    $new_status = $_POST['new_status'];
    $journal->updateAccountStatus($account_id, $new_status);
    header("Location: accounts");
    exit();
}

$message = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

$all_accounts = $journal->getAllAccounts();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Account Management</title>
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
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between overflow-y-auto">
            <div>
                <a href="index" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="input" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                    <span class="nav-text">Data Entry</span>
                </a>
                <a href="report" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    <span class="nav-text">Annual Report</span>
                </a>
                <a href="accounts" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="nav-text">Accounts</span>
                </a>
                <a href="clients" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <span class="nav-text">Client CRM</span>
                </a>
                <a href="distribution" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span class="nav-text">Profit Dist.</span>
                </a>
                <a href="affiliates" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    <span class="nav-text">Affiliates</span>
                </a>
            </div>

            <a href="logout" class="group block py-2 px-3 hover:bg-red-900 rounded text-gray-400 hover:text-red-500 transition-colors flex items-center whitespace-nowrap overflow-hidden border border-transparent hover:border-red-500 mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-red-500 group-hover:text-red-400 transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                <span class="nav-text text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-6">
                <span class="text-electric-blue font-mono text-sm font-bold">DATABASE: ACCOUNTS LEDGER</span>
            </div>
            <div class="flex space-x-6 text-sm">
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SYS.STATUS:</span> 
                    <span class="text-neon-green animate-pulse font-mono font-bold">ONLINE</span>
                </div>
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 flex flex-col">
            <h1 class="text-xl font-bold font-mono text-gray-400 border-b border-gray-800 pb-2 mb-6">ACCOUNT_MANAGEMENT_MODULE</h1>
            
            <?= $message ?>

            <div class="bg-terminal-panel p-6 rounded border border-gray-800 shadow-lg mb-8 shrink-0">
                <h2 class="text-electric-blue font-mono text-sm font-bold mb-4">[ REGISTER NEW ACCOUNT ]</h2>
                <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <input type="hidden" name="action" value="add_account">
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">ACCOUNT NAME / ID</label>
                        <input type="text" name="account_name" required autocomplete="off" placeholder="Misal: 240289034" class="input-dark w-full px-3 py-2 rounded">
                    </div>
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">BROKER NAME</label>
                        <input type="text" name="broker_name" required autocomplete="off" placeholder="Misal: Exness, FBS" class="input-dark w-full px-3 py-2 rounded">
                    </div>
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">INITIAL BALANCE (CENT)</label>
                        <input type="number" step="0.01" name="initial_balance" required placeholder="Modal Awal" class="input-dark w-full px-3 py-2 rounded text-neon-green">
                    </div>
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">LEDGER CATEGORY</label>
                        <select name="account_category" required class="input-dark w-full px-3 py-2 rounded">
                            <option value="Personal">Personal Equity</option>
                            <option value="Master_Joint">Managed Funds (PAMM)</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="w-full bg-gray-800 hover:bg-electric-blue hover:text-black text-electric-blue font-mono font-bold py-2 px-4 rounded transition-colors border border-gray-700 hover:border-electric-blue">
                            EXECUTE >
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-terminal-panel rounded border border-gray-800 shadow-lg overflow-x-auto shrink-0 mb-6">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-900 border-b border-gray-700 font-mono text-xs text-gray-400">
                            <th class="p-4 uppercase tracking-wider">Account ID</th>
                            <th class="p-4 uppercase tracking-wider">Broker</th>
                            <th class="p-4 uppercase tracking-wider">Category</th>
                            <th class="p-4 uppercase tracking-wider text-right">Initial Balance (Cent)</th>
                            <th class="p-4 uppercase tracking-wider text-center">Status</th>
                            <th class="p-4 uppercase tracking-wider text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-sm">
                        <?php if(empty($all_accounts)): ?>
                            <tr><td colspan="6" class="p-4 text-center text-gray-600">-- NO ACCOUNTS DETECTED --</td></tr>
                        <?php else: ?>
                            <?php foreach($all_accounts as $acc): ?>
                            <tr class="border-b border-gray-800 hover:bg-gray-800 transition-colors <?= $acc['status'] == 'Inactive' ? 'opacity-50' : '' ?>">
                                <td class="p-4 text-white font-bold"><?= htmlspecialchars($acc['account_name']) ?></td>
                                <td class="p-4 text-gray-400"><?= htmlspecialchars($acc['broker_name'] ?? 'N/A') ?></td>
                                <td class="p-4 text-electric-blue"><?= str_replace('_', ' ', $acc['account_category']) ?></td>
                                <td class="p-4 text-right number-format"><?= number_format($acc['initial_balance_cent'], 2, '.', ',') ?></td>
                                <td class="p-4 text-center">
                                    <span class="px-2 py-1 text-xs rounded font-bold <?= $acc['status'] == 'Active' ? 'bg-green-900 text-neon-green border border-green-500' : 'bg-gray-700 text-gray-400 border border-gray-500' ?>">
                                        <?= strtoupper($acc['status']) ?>
                                    </span>
                                </td>
                                <td class="p-4 text-right">
                                    <form method="POST" action="" class="inline-block">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="account_id" value="<?= $acc['account_id'] ?>">
                                        <?php if ($acc['status'] == 'Active'): ?>
                                            <input type="hidden" name="new_status" value="Inactive">
                                            <button type="submit" onclick="return confirm('Nonaktifkan akun ini? Akun ini tidak akan muncul lagi di halaman entri data.')" class="text-xs bg-transparent border border-red-800 text-neon-red hover:text-white hover:bg-neon-red px-3 py-1 rounded transition-colors">
                                                DISABLE
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="Active">
                                            <button type="submit" class="text-xs bg-transparent border border-green-800 text-neon-green hover:text-black hover:bg-neon-green px-3 py-1 rounded transition-colors">
                                                ENABLE
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="flex-1"></div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0 w-full">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved. | EA Command Center V2.0
            </p>
        </footer>
    </main>

    <script>
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
            const clockEl = document.getElementById('clock');
            if(clockEl) clockEl.innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);
    </script>
</body>
</html>
<!-- end file /accounts.php -->

<!-- /affiliates.php -->
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

$journal = new JournalManager();
$usd_rate = $journal->getUsdRate();

// Proses Add Marketer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_affiliate') {
    $marketer_name = trim($_POST['marketer_name']);

    if ($journal->addAffiliate($marketer_name)) {
        $_SESSION['flash_msg'] = "<div class='bg-neon-green text-terminal-black font-mono px-4 py-2 rounded mb-6 font-bold'>[SUCCESS] MARKETER BARU BERHASIL DIDAFTARKAN.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6'>[ERROR] GAGAL MENDAFTARKAN MARKETER.</div>";
    }
    header("Location: affiliates");
    exit();
}

// Proses Payout Komisi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'payout') {
    $affiliate_id = $_POST['affiliate_id'];
    
    if ($journal->payoutAffiliate($affiliate_id)) {
        $_SESSION['flash_msg'] = "<div class='bg-electric-blue text-terminal-black font-mono px-4 py-2 rounded mb-6 font-bold'>[SUCCESS] KOMISI DIBAYARKAN. LEDGER DIRESET KE NOL.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6'>[ERROR] GAGAL MEMPROSES PAYOUT.</div>";
    }
    header("Location: affiliates");
    exit();
}

$message = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

$affiliates = $journal->getAffiliates();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Marketing Engine</title>
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
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between overflow-y-auto">
            <div>
                <a href="index" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="input" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                    <span class="nav-text">Data Entry</span>
                </a>
                <a href="report" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    <span class="nav-text">Annual Report</span>
                </a>
                <a href="accounts" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="nav-text">Accounts</span>
                </a>
                <a href="clients" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <span class="nav-text">Client CRM</span>
                </a>
                <a href="distribution" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span class="nav-text">Profit Dist.</span>
                </a>
                <a href="affiliates" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    <span class="nav-text">Affiliates</span>
                </a>
            </div>

            <a href="logout" class="group block py-2 px-3 hover:bg-red-900 rounded text-gray-400 hover:text-red-500 transition-colors flex items-center whitespace-nowrap overflow-hidden border border-transparent hover:border-red-500 mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-red-500 group-hover:text-red-400 transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                <span class="nav-text text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-6">
                <span class="text-electric-blue font-mono text-sm font-bold">DATABASE: MARKETING LEDGER</span>
            </div>
            <div class="flex space-x-6 text-sm">
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SYS.STATUS:</span> 
                    <span class="text-neon-green animate-pulse font-mono font-bold">ONLINE</span>
                </div>
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 flex flex-col">
            <h1 class="text-xl font-bold font-mono text-gray-400 border-b border-gray-800 pb-2 mb-6">AFFILIATE_MARKETING_MODULE</h1>
            
            <?= $message ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8 shrink-0">
                <div class="bg-terminal-panel p-6 rounded border border-gray-800 shadow-lg lg:col-span-1">
                    <h2 class="text-electric-blue font-mono text-sm font-bold mb-4">[ REGISTER NEW MARKETER ]</h2>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_affiliate">
                        <div class="mb-4">
                            <label class="block text-gray-500 text-xs font-mono mb-2">NAMA LENGKAP MARKETER</label>
                            <input type="text" name="marketer_name" required autocomplete="off" placeholder="Misal: Budi Santoso" class="input-dark w-full px-3 py-2 rounded">
                        </div>
                        <button type="submit" class="w-full bg-gray-800 hover:bg-electric-blue hover:text-black text-electric-blue font-mono font-bold py-2 px-4 rounded transition-colors border border-gray-700 hover:border-electric-blue">
                            EXECUTE >
                        </button>
                    </form>
                </div>
                
                <div class="bg-gray-900 border border-gray-800 p-6 rounded lg:col-span-2 flex flex-col justify-center">
                    <h3 class="text-gray-400 font-mono text-sm font-bold mb-2">SYSTEM PROTOCOL:</h3>
                    <ul class="text-xs text-gray-500 space-y-2 font-mono">
                        <li>> Marketer akan muncul di pilihan Dropdown saat Anda mendaftarkan klien baru di menu <span class="text-white">Client CRM</span>.</li>
                        <li>> Komisi tetap senilai <span class="text-neon-green">Rp 100.000</span> akan otomatis ditambahkan ke saldo Marketer setiap kali klien Tier A (VPS+EA) mereka berubah status menjadi PAID.</li>
                        <li>> Gunakan tombol <span class="text-electric-blue">PAYOUT</span> HANYA setelah Anda berhasil mentransfer komisi tersebut ke rekening Marketer. Saldo akan hangus (direset ke nol).</li>
                    </ul>
                </div>
            </div>

            <div class="bg-terminal-panel rounded border border-gray-800 shadow-lg overflow-x-auto shrink-0 mb-6">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-900 border-b border-gray-700 font-mono text-xs text-gray-400">
                            <th class="p-4 uppercase tracking-wider">ID</th>
                            <th class="p-4 uppercase tracking-wider">Marketer Name</th>
                            <th class="p-4 uppercase tracking-wider text-right">Total Unpaid Commission</th>
                            <th class="p-4 uppercase tracking-wider text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-sm">
                        <?php if(empty($affiliates)): ?>
                            <tr><td colspan="4" class="p-4 text-center text-gray-600">-- NO MARKETER DETECTED --</td></tr>
                        <?php else: ?>
                            <?php foreach($affiliates as $af): ?>
                            <tr class="border-b border-gray-800 hover:bg-gray-800 transition-colors">
                                <td class="p-4 text-gray-500">#<?= str_pad($af['affiliate_id'], 3, '0', STR_PAD_LEFT) ?></td>
                                <td class="p-4 text-white font-bold"><?= htmlspecialchars($af['marketer_name']) ?></td>
                                <td class="p-4 text-right">
                                    <span class="<?= $af['total_unpaid_commission'] > 0 ? 'text-neon-green font-bold text-lg' : 'text-gray-600' ?>">
                                        Rp <?= number_format($af['total_unpaid_commission'], 0, ',', '.') ?>
                                    </span>
                                </td>
                                <td class="p-4 text-right">
                                    <?php if ($af['total_unpaid_commission'] > 0): ?>
                                        <form method="POST" action="" class="inline-block">
                                            <input type="hidden" name="action" value="payout">
                                            <input type="hidden" name="affiliate_id" value="<?= $af['affiliate_id'] ?>">
                                            <button type="submit" onclick="return confirm('Anda yakin telah men-transfer komisi sebesar Rp <?= number_format($af['total_unpaid_commission'], 0, ',', '.') ?> kepada <?= htmlspecialchars($af['marketer_name']) ?>? Data ini akan direset menjadi Rp 0.')" class="text-xs bg-transparent border border-electric-blue text-electric-blue hover:text-black hover:bg-electric-blue px-3 py-1 rounded transition-colors font-bold">
                                                PROCESS PAYOUT
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-600">CLEARED</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="flex-1"></div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0 w-full">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved. | EA Command Center V2.0
            </p>
        </footer>
    </main>

    <script>
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
            const clockEl = document.getElementById('clock');
            if(clockEl) clockEl.innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);
    </script>
</body>
</html>
<!-- end file /affiliates.php -->

<!-- /clients.php -->
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

if (isset($_GET['switch_portfolio'])) {
    $_SESSION['active_portfolio'] = $_GET['switch_portfolio'];
    header("Location: clients"); 
    exit();
}
if (!isset($_SESSION['active_portfolio'])) {
    $_SESSION['active_portfolio'] = 'Personal';
}
$active_portfolio = $_SESSION['active_portfolio'];
$portfolio_label = ($active_portfolio === 'Personal') ? 'PERSONAL EQUITY' : 'MANAGED FUNDS (PAMM)';

$journal = new JournalManager();
$usd_rate = $journal->getUsdRate();

// Proses Add Klien (Kini mendukung input modal secara langsung)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_client') {
    $client_name = trim($_POST['client_name']);
    $tier_type = $_POST['tier_type'];
    $referred_by = $_POST['referred_by'];
    
    // Variabel khusus Tier B
    $master_account_id = $_POST['master_account_id'] ?? null;
    $capital_amount = isset($_POST['capital_amount']) ? (float)$_POST['capital_amount'] : 0;

    if ($journal->addClient($client_name, $tier_type, $referred_by, $master_account_id, $capital_amount)) {
        $_SESSION['flash_msg'] = "<div class='bg-neon-green text-terminal-black font-mono px-4 py-2 rounded mb-6 font-bold'>[SUCCESS] KLIEN BARU DIDAFTARKAN. MASA TRIAL 48 JAM DIMULAI.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6'>[ERROR] GAGAL MENDAFTARKAN KLIEN.</div>";
    }
    header("Location: clients");
    exit();
}

// Proses Billing & Komisi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'process_billing') {
    $client_id = $_POST['client_id'];
    if ($journal->processClientBilling($client_id)) {
        $_SESSION['flash_msg'] = "<div class='bg-electric-blue text-terminal-black font-mono px-4 py-2 rounded mb-6 font-bold'>[SUCCESS] BILLING APPROVED. SUBSCRIPTION EXTENDED 30 DAYS.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6'>[ERROR] BILLING FAILED.</div>";
    }
    header("Location: clients");
    exit();
}

$message = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

$affiliates = $journal->getAffiliates();
$clients_data = $journal->getClients();
$master_accounts = $journal->getActiveAccounts('Master_Joint'); // Untuk dropdown
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Client CRM</title>
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
                        'electric-blue': '#00E5FF',
                        'warning-yellow': '#FFD700'
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
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between overflow-y-auto">
            <div>
                <a href="index" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="input" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                    <span class="nav-text">Data Entry</span>
                </a>
                <a href="report" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    <span class="nav-text">Annual Report</span>
                </a>
                <a href="accounts" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="nav-text">Accounts</span>
                </a>
                <a href="clients" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <span class="nav-text">Client CRM</span>
                </a>
                <a href="distribution" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span class="nav-text">Profit Dist.</span>
                </a>
                <a href="affiliates" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    <span class="nav-text">Affiliates</span>
                </a>
            </div>

            <a href="logout" class="group block py-2 px-3 hover:bg-red-900 rounded text-gray-400 hover:text-red-500 transition-colors flex items-center whitespace-nowrap overflow-hidden border border-transparent hover:border-red-500 mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-red-500 group-hover:text-red-400 transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                <span class="nav-text text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-6">
                <form method="GET" action="" class="flex items-center bg-black border border-gray-700 rounded px-2 py-1">
                    <span class="text-gray-500 font-mono text-xs mr-2">LEDGER:</span>
                    <select name="switch_portfolio" onchange="this.form.submit()" class="bg-black text-electric-blue font-mono text-sm outline-none font-bold cursor-pointer">
                        <option value="Personal" <?= $active_portfolio === 'Personal' ? 'selected' : '' ?>>PERSONAL EQUITY</option>
                        <option value="Master_Joint" <?= $active_portfolio === 'Master_Joint' ? 'selected' : '' ?>>MANAGED FUNDS (PAMM)</option>
                    </select>
                </form>
            </div>
            <div class="flex space-x-6 text-sm">
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SYS.STATUS:</span> 
                    <span class="text-neon-green animate-pulse font-mono font-bold">ONLINE</span>
                </div>
                <div>
                    <span class="text-gray-500 font-mono">USC/IDR:</span> 
                    <span class="number-format text-electric-blue font-bold">Rp <?= number_format($usd_rate, 0, ',', '.') ?></span>
                </div>
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 flex flex-col">
            <h1 class="text-xl font-bold font-mono text-gray-400 border-b border-gray-800 pb-2 mb-6">CLIENT_WATCHLIST_MODULE</h1>
            
            <?= $message ?>

            <div class="bg-terminal-panel p-6 rounded border border-gray-800 shadow-lg mb-8 shrink-0">
                <h2 class="text-electric-blue font-mono text-sm font-bold mb-4">[ NEW CLIENT DEPLOYMENT ]</h2>
                <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <input type="hidden" name="action" value="add_client">
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">NAMA KLIEN</label>
                        <input type="text" name="client_name" required autocomplete="off" class="input-dark w-full px-3 py-2 rounded">
                    </div>
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">TIER PAKET (30 HARI)</label>
                        <select id="tier_selector" name="tier_type" required class="input-dark w-full px-3 py-2 rounded">
                            <option value="Tier_A">Tier A (EA VPS - 400k)</option>
                            <option value="Tier_B">Tier B (Joint Slot - 200k)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">REFERRED BY (MARKETER)</label>
                        <select name="referred_by" class="input-dark w-full px-3 py-2 rounded text-gray-400">
                            <option value="">-- Organik / Tanpa Marketer --</option>
                            <?php foreach($affiliates as $af): ?>
                                <option value="<?= $af['affiliate_id'] ?>"><?= htmlspecialchars($af['marketer_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="w-full bg-gray-800 hover:bg-neon-green hover:text-black text-neon-green font-mono font-bold py-2 px-4 rounded transition-colors border border-gray-700 hover:border-neon-green">
                            EXECUTE >
                        </button>
                    </div>

                    <div id="tier_b_panel" class="hidden col-span-1 md:col-span-4 grid grid-cols-1 md:grid-cols-2 gap-4 mt-2 p-4 border border-electric-blue rounded bg-gray-900">
                        <div>
                            <label class="block text-electric-blue text-xs font-mono mb-2">LINK TO MASTER ACCOUNT (KHUSUS TIER B)</label>
                            <select name="master_account_id" class="input-dark w-full px-3 py-2 rounded border-electric-blue">
                                <option value="">-- Pilih Akun Master Tempat Modal Digabung --</option>
                                <?php foreach($master_accounts as $acc): ?>
                                    <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-electric-blue text-xs font-mono mb-2">CLIENT CAPITAL DEPOSIT (IDR)</label>
                            <input type="number" step="0.01" name="capital_amount" placeholder="Misal: 5000000" class="input-dark w-full px-3 py-2 rounded text-neon-green border-electric-blue">
                        </div>
                    </div>
                </form>
            </div>

            <div class="bg-terminal-panel rounded border border-gray-800 shadow-lg overflow-x-auto shrink-0 mb-6">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-900 border-b border-gray-700 font-mono text-xs text-gray-400">
                            <th class="p-4 uppercase tracking-wider">ID</th>
                            <th class="p-4 uppercase tracking-wider">Client Name</th>
                            <th class="p-4 uppercase tracking-wider">Tier Type</th>
                            <th class="p-4 uppercase tracking-wider">Status</th>
                            <th class="p-4 uppercase tracking-wider">Time Remaining</th>
                            <th class="p-4 uppercase tracking-wider">Marketer</th>
                            <th class="p-4 uppercase tracking-wider text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-sm">
                        <?php if(empty($clients_data)): ?>
                            <tr><td colspan="7" class="p-4 text-center text-gray-600">-- NO ACTIVE CLIENTS DETECTED --</td></tr>
                        <?php else: ?>
                            <?php foreach($clients_data as $client): 
                                $now_ts = time();
                                $target_date = ($client['status'] == 'Trial') ? $client['trial_end_date'] : $client['subscription_end_date'];
                                $target_ts = strtotime($target_date);
                                $diff_seconds = $target_ts - $now_ts;
                                
                                $status_color = "";
                                if ($client['status'] == 'Expired') {
                                    $status_color = "bg-red-900 text-neon-red border border-red-500";
                                } else if ($client['status'] == 'Trial') {
                                    $status_color = "bg-yellow-900 text-warning-yellow border border-yellow-500";
                                } else { 
                                    $status_color = "bg-green-900 text-neon-green border border-green-500";
                                }
                            ?>
                            <tr class="border-b border-gray-800 hover:bg-gray-800 transition-colors">
                                <td class="p-4 text-gray-500">#<?= str_pad($client['client_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td class="p-4 text-white font-bold"><?= htmlspecialchars($client['client_name']) ?></td>
                                <td class="p-4 text-gray-400"><?= str_replace('_', ' ', $client['tier_type']) ?></td>
                                <td class="p-4">
                                    <span class="px-2 py-1 text-xs rounded font-bold <?= $status_color ?>">
                                        <?= strtoupper($client['status']) ?>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <?php if ($client['status'] == 'Expired'): ?>
                                        <span class="text-neon-red animate-pulse font-bold">TIME IS UP</span>
                                    <?php else: ?>
                                        <span class="countdown-timer font-bold <?= $client['status'] == 'Trial' ? 'text-warning-yellow' : 'text-neon-green' ?>" 
                                              data-remaining-seconds="<?= $diff_seconds ?>" 
                                              data-status="<?= $client['status'] ?>">
                                              CALCULATING...
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-gray-500"><?= $client['marketer_name'] ?? '-' ?></td>
                                <td class="p-4 text-right">
                                    <?php if ($client['status'] == 'Expired' || $client['status'] == 'Trial'): ?>
                                        <form method="POST" action="" class="inline-block">
                                            <input type="hidden" name="action" value="process_billing">
                                            <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                            <button type="submit" onclick="return confirm('Proses penagihan untuk klien ini? Fitur otomatisasi komisi (Tier A) akan berjalan.')" class="text-xs bg-transparent border border-gray-600 text-gray-400 hover:text-white hover:bg-electric-blue hover:border-electric-blue px-3 py-1 rounded transition-colors">
                                                PROCESS BILLING
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-600">NO ACTION REQ.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="flex-1"></div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0 w-full">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved. | EA Command Center V2.0
            </p>
        </footer>
    </main>

    <script>
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
            const clockEl = document.getElementById('clock');
            if(clockEl) clockEl.innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);

        setInterval(() => {
            document.querySelectorAll('.countdown-timer').forEach(el => {
                let seconds = parseInt(el.getAttribute('data-remaining-seconds'));
                let status = el.getAttribute('data-status');
                
                if (seconds <= 0) {
                    el.innerText = "TIME IS UP";
                    el.className = "text-neon-red animate-pulse font-bold";
                    return; 
                }
                
                seconds--;
                el.setAttribute('data-remaining-seconds', seconds);
                
                if (status === 'Trial') {
                    let h = Math.floor(seconds / 3600);
                    let m = Math.floor((seconds % 3600) / 60);
                    let s = seconds % 60;
                    el.innerText = `${h.toString().padStart(2, '0')}H ${m.toString().padStart(2, '0')}M ${s.toString().padStart(2, '0')}S`;
                } else {
                    let d = Math.floor(seconds / 86400);
                    el.innerText = `${d} DAYS`;
                }
            });
        }, 1000);

        // LOGIKA TOGGLE PANEL TIER B
        const tierSelector = document.getElementById('tier_selector');
        const tierBPanel = document.getElementById('tier_b_panel');

        tierSelector.addEventListener('change', function() {
            if (this.value === 'Tier_B') {
                tierBPanel.classList.remove('hidden');
            } else {
                tierBPanel.classList.add('hidden');
                // Reset nilai input jika dikembalikan ke Tier A
                tierBPanel.querySelector('select').value = '';
                tierBPanel.querySelector('input').value = '';
            }
        });
    </script>
</body>
</html>
<!-- end file /clients.php -->

<!-- /distribution.php -->
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

$_SESSION['active_portfolio'] = 'Master_Joint'; 

$journal = new JournalManager();
$usd_rate = $journal->getUsdRate();

$master_accounts = $journal->getActiveAccounts('Master_Joint');
$distribution_result = null;
$wd_amount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $master_account_id = $_POST['master_account_id'];
    $wd_amount = (float)$_POST['wd_amount'];
    
    // Panggil Engine 1-on-1 Dynamic Ratio
    $data = $journal->get1on1Distribution($master_account_id);
    
    if ($data) {
        $tommy_share = $wd_amount * ($data['tommy_ratio'] / 100);
        $client_share = $wd_amount * ($data['client_ratio'] / 100);

        $distribution_result = [
            'account_name' => $data['account_name'],
            'total_capital' => $data['total_capital_idr'],
            'tommy_capital' => $data['tommy_capital_idr'],
            'tommy_ratio' => $data['tommy_ratio'],
            'tommy_share' => $tommy_share,
            'client_name' => $data['client_name'],
            'client_capital' => $data['client_capital_idr'],
            'client_ratio' => $data['client_ratio'],
            'client_share' => $client_share,
            'has_client' => $data['has_client']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Profit Distribution</title>
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

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between overflow-y-auto">
            <div>
                <a href="index" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="input" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                    <span class="nav-text">Data Entry</span>
                </a>
                <a href="report" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    <span class="nav-text">Annual Report</span>
                </a>
                <a href="accounts" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="nav-text">Accounts</span>
                </a>
                <a href="clients" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <span class="nav-text">Client CRM</span>
                </a>
                <a href="distribution" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span class="nav-text">Profit Dist.</span>
                </a>
                <a href="affiliates" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    <span class="nav-text">Affiliates</span>
                </a>
            </div>

            <a href="logout" class="group block py-2 px-3 hover:bg-red-900 rounded text-gray-400 hover:text-red-500 transition-colors flex items-center whitespace-nowrap overflow-hidden border border-transparent hover:border-red-500 mt-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 text-red-500 group-hover:text-red-400 transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                <span class="nav-text text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-6">
                <span class="bg-black border border-gray-700 text-electric-blue font-mono text-sm px-3 py-1 font-bold rounded">LEDGER: MANAGED FUNDS (PAMM) LOCKED</span>
            </div>
            <div class="flex space-x-6 text-sm">
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SYS.STATUS:</span> 
                    <span class="text-neon-green animate-pulse font-mono font-bold">ONLINE</span>
                </div>
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 flex flex-col">
            <h1 class="text-xl font-bold font-mono text-gray-400 border-b border-gray-800 pb-2 mb-6">DYNAMIC_PAMM_DISTRIBUTION_LEDGER (1-ON-1)</h1>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-1">
                    <div class="bg-terminal-panel p-6 rounded border border-gray-800 shadow-lg">
                        <h2 class="text-electric-blue font-mono text-sm font-bold mb-6">[ WD CALCULATION EXECUTION ]</h2>
                        <form method="POST" action="">
                            <div class="mb-4">
                                <label class="block text-gray-500 text-xs font-mono mb-2">PILIH MASTER ACCOUNT (1-ON-1)</label>
                                <select name="master_account_id" required class="input-dark w-full px-3 py-2 rounded">
                                    <?php foreach($master_accounts as $acc): ?>
                                        <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-6">
                                <label class="block text-gray-500 text-xs font-mono mb-2">TOTAL WD PROFIT (IDR)</label>
                                <input type="number" step="0.01" name="wd_amount" required placeholder="Contoh: 2000000" value="<?= $wd_amount > 0 ? $wd_amount : '' ?>" class="input-dark w-full px-3 py-2 rounded font-bold text-neon-green">
                            </div>
                            <button type="submit" class="w-full bg-gray-800 hover:bg-neon-green hover:text-black text-neon-green font-mono font-bold py-3 px-4 rounded transition-colors border border-gray-700 hover:border-neon-green">
                                CALCULATE DYNAMIC RATIO
                            </button>
                        </form>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <?php if($distribution_result): ?>
                        <div class="bg-terminal-panel p-6 rounded border border-electric-blue shadow-lg relative">
                            <div class="absolute top-0 right-0 bg-electric-blue text-black font-mono text-xs font-bold px-3 py-1 rounded-bl">CALCULATION: SECURED</div>
                            
                            <h2 class="text-gray-400 font-mono text-lg font-bold mb-4 mt-2 border-b border-gray-800 pb-2">1-ON-1 DISTRIBUTION LEDGER</h2>
                            
                            <div class="grid grid-cols-2 gap-4 mb-6">
                                <div class="bg-gray-900 border border-gray-700 p-4 rounded">
                                    <div class="text-gray-500 font-mono text-xs mb-1">TOTAL ACCOUNT CAPITAL</div>
                                    <div class="text-lg font-bold font-mono text-white">Rp <?= number_format($distribution_result['total_capital'], 0, ',', '.') ?></div>
                                </div>
                                <div class="bg-gray-900 border border-gray-700 p-4 rounded">
                                    <div class="text-gray-500 font-mono text-xs mb-1">WD PROFIT TO SPLIT</div>
                                    <div class="text-lg font-bold font-mono text-neon-green">Rp <?= number_format($wd_amount, 0, ',', '.') ?></div>
                                </div>
                            </div>

                            <div class="bg-black rounded border border-gray-800 overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="border-b border-gray-800 text-gray-500 font-mono text-xs">
                                            <th class="p-4 uppercase">Entity</th>
                                            <th class="p-4 text-right uppercase">Fund Capital</th>
                                            <th class="p-4 text-right uppercase">Dynamic Ratio</th>
                                            <th class="p-4 text-right text-white uppercase">Profit Share (WD)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="font-mono text-sm">
                                        <tr class="border-b border-gray-800 transition-colors bg-gray-900">
                                            <td class="p-4 text-neon-green font-bold">TOMMY ALFARABI (MASTER)</td>
                                            <td class="p-4 text-right text-gray-400">Rp <?= number_format($distribution_result['tommy_capital'], 0, ',', '.') ?></td>
                                            <td class="p-4 text-right text-neon-green font-bold"><?= $distribution_result['tommy_ratio'] ?>%</td>
                                            <td class="p-4 text-right text-neon-green font-bold text-lg">Rp <?= number_format($distribution_result['tommy_share'], 0, ',', '.') ?></td>
                                        </tr>
                                        
                                        <tr class="transition-colors hover:bg-gray-800">
                                            <td class="p-4 text-electric-blue font-bold"><?= htmlspecialchars($distribution_result['client_name']) ?></td>
                                            <td class="p-4 text-right text-gray-400">Rp <?= number_format($distribution_result['client_capital'], 0, ',', '.') ?></td>
                                            <td class="p-4 text-right text-electric-blue font-bold"><?= $distribution_result['client_ratio'] ?>%</td>
                                            <td class="p-4 text-right text-electric-blue font-bold text-lg">Rp <?= number_format($distribution_result['client_share'], 0, ',', '.') ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <?php if(!$distribution_result['has_client']): ?>
                                <div class="mt-4 text-warning-yellow text-xs font-mono text-center animate-pulse">
                                    *WARNING: No active client fund detected in this account. 100% profit allocated to Master.
                                </div>
                            <?php endif; ?>

                        </div>
                    <?php else: ?>
                        <div class="h-full flex items-center justify-center border-2 border-dashed border-gray-800 rounded opacity-50">
                            <span class="text-gray-600 font-mono">AWAITING WD INPUT...</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="flex-1"></div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0 w-full">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved. | EA Command Center V2.0
            </p>
        </footer>
    </main>

    <script>
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
            const clockEl = document.getElementById('clock');
            if(clockEl) clockEl.innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);
    </script>
</body>
</html>
<!-- end file /distribution.php -->

<!-- /index.php -->
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

// ---------------------------------------------------------
// LOGIKA SWITCHER PORTOFOLIO V2.0
// ---------------------------------------------------------
if (isset($_GET['switch_portfolio'])) {
    $_SESSION['active_portfolio'] = $_GET['switch_portfolio'];
    header("Location: index"); 
    exit();
}
if (!isset($_SESSION['active_portfolio'])) {
    $_SESSION['active_portfolio'] = 'Personal';
}
$active_portfolio = $_SESSION['active_portfolio'];

$journal = new JournalManager();
$metrics = $journal->getDashboardMetrics($active_portfolio);
$usd_rate = $journal->getUsdRate();

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

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-5 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 px-2 py-4 space-y-2 mt-2 flex flex-col justify-between overflow-y-auto">
            <div>
                <a href="index" class="group flex items-center py-2 px-3 bg-gray-800 rounded border-l-2 border-neon-green text-neon-green whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    <span class="nav-text ml-3">Dashboard</span>
                </a>

                <a href="input" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                    <span class="nav-text ml-3">Data Entry</span>
                </a>

                <a href="report" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    <span class="nav-text ml-3">Annual Report</span>
                </a>

                <a href="accounts" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="nav-text ml-3">Accounts</span>
                </a>

                <a href="clients" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <span class="nav-text ml-3">Client CRM</span>
                </a>

                <a href="distribution" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span class="nav-text ml-3">Profit Dist.</span>
                </a>

                <a href="affiliates" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    <span class="nav-text ml-3">Affiliates</span>
                </a>
            </div>

            <a href="logout" class="group flex items-center py-2 px-3 hover:bg-red-900 rounded border-l-2 border-transparent hover:border-red-500 text-gray-400 hover:text-red-500 transition-colors whitespace-nowrap overflow-hidden mt-auto">
                <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                <span class="nav-text ml-3 text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-6">
                <form method="GET" action="" class="flex items-center bg-black border border-gray-700 rounded px-2 py-1">
                    <span class="text-gray-500 font-mono text-xs mr-2">LEDGER:</span>
                    <select name="switch_portfolio" onchange="this.form.submit()" class="bg-black text-electric-blue font-mono text-sm outline-none font-bold cursor-pointer">
                        <option value="Personal" <?= $active_portfolio === 'Personal' ? 'selected' : '' ?>>PERSONAL EQUITY</option>
                        <option value="Master_Joint" <?= $active_portfolio === 'Master_Joint' ? 'selected' : '' ?>>MANAGED FUNDS (PAMM)</option>
                    </select>
                </form>
            </div>
            
            <div class="flex space-x-6 text-sm">
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SYS.STATUS:</span> 
                    <span class="text-neon-green animate-pulse font-mono font-bold">ONLINE</span>
                </div>
                <div>
                    <span class="text-gray-500 font-mono">USC/IDR:</span> 
                    <span class="number-format text-electric-blue font-bold">Rp <?= number_format($usd_rate, 0, ',', '.') ?></span>
                </div>
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 flex flex-col">
            <div class="flex justify-between items-end border-b border-gray-800 pb-2 mb-6 shrink-0">
                <h1 class="text-xl font-bold font-mono text-gray-400">PORTFOLIO_OVERVIEW <span class="text-sm text-electric-blue ml-2">[<?= $portfolio_label ?>]</span></h1>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 shrink-0">
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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 shrink-0">
                <div class="bg-terminal-panel p-4 rounded border border-gray-800 shadow-lg relative h-80">
                    <h2 class="text-gray-500 text-xs font-mono mb-2 absolute top-4 left-4 z-10">CUMULATIVE EQUITY CURVE</h2>
                    <canvas id="equityChart"></canvas>
                </div>
                
                <div class="bg-terminal-panel p-4 rounded border border-gray-800 shadow-lg relative h-80">
                    <h2 class="text-gray-500 text-xs font-mono mb-2 absolute top-4 left-4 z-10">DAILY PNL VS MAX DRAWDOWN</h2>
                    <canvas id="pnlDdChart"></canvas>
                </div>
            </div>
            
            <div class="flex-1"></div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved. | EA Command Center V2.0
            </p>
        </footer>
    </main>

    <script>
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
            const clockEl = document.getElementById('clock');
            if(clockEl) clockEl.innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);

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
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

// ---------------------------------------------------------
// LOGIKA SWITCHER PORTOFOLIO V2.0
// ---------------------------------------------------------
if (isset($_GET['switch_portfolio'])) {
    $_SESSION['active_portfolio'] = $_GET['switch_portfolio'];
    header("Location: input"); 
    exit();
}
if (!isset($_SESSION['active_portfolio'])) {
    $_SESSION['active_portfolio'] = 'Personal';
}
$active_portfolio = $_SESSION['active_portfolio'];

$journal = new JournalManager();
$accounts = $journal->getActiveAccounts($active_portfolio);
$message = '';
$usd_rate = $journal->getUsdRate();

$portfolio_label = ($active_portfolio === 'Personal') ? 'PERSONAL EQUITY' : 'MANAGED FUNDS (PAMM)';

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

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-5 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 px-2 py-4 space-y-2 mt-2 flex flex-col justify-between overflow-y-auto">
            <div>
                <a href="index" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    <span class="nav-text ml-3">Dashboard</span>
                </a>

                <a href="input" class="group flex items-center py-2 px-3 bg-gray-800 rounded border-l-2 border-neon-green text-neon-green whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                    <span class="nav-text ml-3">Data Entry</span>
                </a>

                <a href="report" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    <span class="nav-text ml-3">Annual Report</span>
                </a>

                <a href="accounts" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="nav-text ml-3">Accounts</span>
                </a>

                <a href="clients" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <span class="nav-text ml-3">Client CRM</span>
                </a>

                <a href="distribution" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span class="nav-text ml-3">Profit Dist.</span>
                </a>

                <a href="affiliates" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    <span class="nav-text ml-3">Affiliates</span>
                </a>
            </div>

            <a href="logout" class="group flex items-center py-2 px-3 hover:bg-red-900 rounded border-l-2 border-transparent hover:border-red-500 text-gray-400 hover:text-red-500 transition-colors whitespace-nowrap overflow-hidden mt-auto">
                <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                <span class="nav-text ml-3 text-sm">System Logout</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-6">
                <form method="GET" action="" class="flex items-center bg-black border border-gray-700 rounded px-2 py-1">
                    <span class="text-gray-500 font-mono text-xs mr-2">LEDGER:</span>
                    <select name="switch_portfolio" onchange="this.form.submit()" class="bg-black text-electric-blue font-mono text-sm outline-none font-bold cursor-pointer">
                        <option value="Personal" <?= $active_portfolio === 'Personal' ? 'selected' : '' ?>>PERSONAL EQUITY</option>
                        <option value="Master_Joint" <?= $active_portfolio === 'Master_Joint' ? 'selected' : '' ?>>MANAGED FUNDS (PAMM)</option>
                    </select>
                </form>
            </div>
            
            <div class="flex space-x-6 text-sm">
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SYS.STATUS:</span> 
                    <span class="text-neon-green animate-pulse font-mono font-bold">ONLINE</span>
                </div>
                <div>
                    <span class="text-gray-500 font-mono">USC/IDR:</span> 
                    <span class="number-format text-electric-blue font-bold">Rp <?= number_format($usd_rate, 0, ',', '.') ?></span>
                </div>
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 flex flex-col items-center">
            <div class="w-full max-w-2xl">
                <h1 class="text-xl font-bold mb-6 font-mono text-gray-400 border-b border-gray-800 pb-2">DATA_ENTRY_MODULE <span class="text-sm text-electric-blue ml-2">[<?= $portfolio_label ?>]</span></h1>
                
                <?= $message ?>

                <div class="bg-terminal-panel p-6 rounded border border-gray-800 shadow-lg mb-6 shrink-0">
                    <form method="POST" action="">
                        <div class="grid grid-cols-2 gap-6 mb-4">
                            <div>
                                <label class="block text-gray-500 text-xs font-mono mb-2">TANGGAL TRANSAKSI</label>
                                <input type="date" name="date" required value="<?= date('Y-m-d') ?>" class="input-dark w-full px-3 py-2 rounded">
                            </div>
                            <div>
                                <label class="block text-gray-500 text-xs font-mono mb-2">TARGET AKUN</label>
                                <select name="account_id" required class="input-dark w-full px-3 py-2 rounded">
                                    <?php if(empty($accounts)): ?>
                                        <option value="">-- TIDAK ADA AKUN AKTIF --</option>
                                    <?php else: ?>
                                        <?php foreach($accounts as $acc): ?>
                                            <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
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
                
                <div class="flex-1"></div>
            </div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0 w-full">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved. | EA Command Center V2.0
            </p>
        </footer>
    </main>

    <script>
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
            const clockEl = document.getElementById('clock');
            if(clockEl) clockEl.innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);
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

// ---------------------------------------------------------
// LOGIKA SWITCHER PORTOFOLIO V2.0
// ---------------------------------------------------------
if (isset($_GET['switch_portfolio'])) {
    $_SESSION['active_portfolio'] = $_GET['switch_portfolio'];
    header("Location: report"); 
    exit();
}
if (!isset($_SESSION['active_portfolio'])) {
    $_SESSION['active_portfolio'] = 'Personal';
}
$active_portfolio = $_SESSION['active_portfolio'];

$journal = new JournalManager();
$usd_rate = $journal->getUsdRate();

// Menentukan tahun yang akan ditampilkan (Default: Tahun ini)
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Mengambil data report bulanan khusus untuk kategori portofolio aktif
$monthly_data = $journal->getMonthlyReport($selected_year, $active_portfolio);

$portfolio_label = ($active_portfolio === 'Personal') ? 'PERSONAL EQUITY' : 'MANAGED FUNDS (PAMM)';

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
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
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
                <a href="accounts" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    <span class="nav-text">Accounts</span>
                </a>
                <a href="clients" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <span class="nav-text">Client CRM</span>
                </a>
                <a href="distribution" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span class="nav-text">Profit Dist.</span>
                </a>
                <a href="affiliates" class="group block py-2 px-3 hover:bg-gray-800 rounded text-gray-400 hover:text-white transition-colors flex items-center whitespace-nowrap overflow-hidden mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 shrink-0 group-hover:text-neon-green transition-colors"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    <span class="nav-text">Affiliates</span>
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

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative">
        
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-6">
                <form method="GET" action="" class="flex items-center bg-black border border-gray-700 rounded px-2 py-1">
                    <span class="text-gray-500 font-mono text-xs mr-2">LEDGER:</span>
                    <select name="switch_portfolio" onchange="this.form.submit()" class="bg-black text-electric-blue font-mono text-sm outline-none font-bold cursor-pointer">
                        <option value="Personal" <?= $active_portfolio === 'Personal' ? 'selected' : '' ?>>PERSONAL EQUITY</option>
                        <option value="Master_Joint" <?= $active_portfolio === 'Master_Joint' ? 'selected' : '' ?>>MANAGED FUNDS (PAMM)</option>
                    </select>
                </form>
            </div>
            
            <div class="flex space-x-6 text-sm">
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SYS.STATUS:</span> 
                    <span class="text-neon-green animate-pulse font-mono font-bold">ONLINE</span>
                </div>
                <div>
                    <span class="text-gray-500 font-mono">USC/IDR:</span> 
                    <span class="number-format text-electric-blue font-bold">Rp <?= number_format($usd_rate, 0, ',', '.') ?></span>
                </div>
                <div class="hidden md:block">
                    <span class="text-gray-500 font-mono">SERVER TIME:</span> 
                    <span id="clock" class="number-format text-terminal-text"></span>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 flex flex-col">
            <div class="flex justify-between items-end border-b border-gray-800 pb-2 mb-6 shrink-0">
                <h1 class="text-xl font-bold font-mono text-gray-400">ANNUAL_PNL_MATRIX_<?= $selected_year ?> <span class="text-sm text-electric-blue ml-2">[<?= $portfolio_label ?>]</span></h1>
                
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

            <div class="bg-terminal-panel rounded border border-gray-800 shadow-lg overflow-x-auto shrink-0 mb-6">
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
            
            <div class="flex-1"></div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0 w-full">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved. | EA Command Center V2.0
            </p>
        </footer>
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
            const clockEl = document.getElementById('clock');
            if(clockEl) clockEl.innerText = new Date().toLocaleTimeString('en-GB');
        }, 1000);
    </script>
</body>
</html>
<!-- end file /report.php -->