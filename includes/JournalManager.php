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