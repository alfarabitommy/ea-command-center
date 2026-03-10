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

    public function getAffiliates() {
        $stmt = $this->conn->prepare("SELECT * FROM affiliates ORDER BY marketer_name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addClient($name, $tier_type, $referred_by = null) {
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
        return $stmt->execute();
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

    // ========================================================
    // MODUL FASE 9: CRM BILLING & PAMM DISTRIBUTION
    // ========================================================

    // Fungsi untuk memproses tagihan dan otomatis mencatat komisi afiliasi
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

            // 1. Update Klien menjadi Active + 30 Hari
            $update_stmt = $this->conn->prepare("UPDATE clients SET status = 'Active', subscription_end_date = :end_date WHERE client_id = :id");
            $update_stmt->bindParam(':end_date', $new_end_date);
            $update_stmt->bindParam(':id', $client_id);
            $update_stmt->execute();

            // 2. Cetak Invoice
            $amount = ($client['tier_type'] === 'Tier_A') ? 400000 : 200000;
            $invoice_type = ($client['tier_type'] === 'Tier_A') ? 'Tier_A_400k' : 'Tier_B_200k';
            
            $inv_stmt = $this->conn->prepare("INSERT INTO billing_invoices (client_id, amount, payment_date, invoice_type) VALUES (:id, :amount, :date, :type)");
            $inv_stmt->bindParam(':id', $client_id);
            $inv_stmt->bindParam(':amount', $amount);
            $inv_stmt->bindParam(':date', $now);
            $inv_stmt->bindParam(':type', $invoice_type);
            $inv_stmt->execute();

            // 3. Distribusi Komisi: Jika Tier A dan ada referal, marketer dapat 100k
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

    // Fungsi untuk menghitung rasio modal klien Tier B di akun Master_Joint
    public function getClientFundsDistribution($master_account_id) {
        $stmt = $this->conn->prepare("
            SELECT cf.*, c.client_name 
            FROM client_funds cf
            JOIN clients c ON cf.client_id = c.client_id
            WHERE cf.associated_master_account_id = :master_id AND c.status = 'Active'
        ");
        $stmt->bindParam(':master_id', $master_account_id);
        $stmt->execute();
        $funds = $stmt->fetchAll();

        $total_pool = 0;
        foreach ($funds as $f) {
            $total_pool += (float)$f['capital_amount_idr'];
        }

        $distribution = [];
        foreach ($funds as $f) {
            $capital = (float)$f['capital_amount_idr'];
            $percentage = ($total_pool > 0) ? ($capital / $total_pool) * 100 : 0;
            $distribution[] = [
                'client_name' => $f['client_name'],
                'capital' => $capital,
                'percentage' => round($percentage, 2)
            ];
        }

        return [
            'total_pool' => $total_pool,
            'clients' => $distribution
        ];
    }
}
?>