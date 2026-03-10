<?php
require_once __DIR__ . '/../config/db.php';

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

    public function saveAccount($name, $initial_balance, $category = 'Personal') {
        $stmt = $this->conn->prepare("INSERT INTO accounts (account_name, initial_balance_cent, account_category) VALUES (:name, :balance, :category)");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':balance', $initial_balance);
        $stmt->bindParam(':category', $category);
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
    // MODUL CRM (PHASE 8 LOGIC)
    // ========================================================
    
    // Ambil daftar tim marketing
    public function getAffiliates() {
        $stmt = $this->conn->prepare("SELECT * FROM affiliates ORDER BY marketer_name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Pendaftaran Klien Baru (Otomatis set Trial 48 Jam)
    public function addClient($name, $tier_type, $referred_by = null) {
        $ref_val = empty($referred_by) ? null : $referred_by;
        
        $stmt = $this->conn->prepare("
            INSERT INTO clients (client_name, tier_type, status, trial_end_date, referred_by) 
            VALUES (:name, :tier, 'Trial', DATE_ADD(NOW(), INTERVAL 48 HOUR), :ref)
        ");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':tier', $tier_type);
        $stmt->bindParam(':ref', $ref_val);
        return $stmt->execute();
    }

    // Mengambil daftar klien beserta logika Auto-Expired
    public function getClients() {
        // 1. Eksekusi Auto-Update: Ubah status ke Expired jika Trial / Subscription sudah lewat batas waktu (NOW)
        $this->conn->query("UPDATE clients SET status = 'Expired' WHERE status = 'Trial' AND trial_end_date < NOW()");
        $this->conn->query("UPDATE clients SET status = 'Expired' WHERE status = 'Active' AND subscription_end_date < NOW()");

        // 2. Tarik data terupdate
        $stmt = $this->conn->prepare("
            SELECT c.*, a.marketer_name 
            FROM clients c 
            LEFT JOIN affiliates a ON c.referred_by = a.affiliate_id 
            ORDER BY 
                FIELD(c.status, 'Expired', 'Trial', 'Active'), 
                c.created_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
?>