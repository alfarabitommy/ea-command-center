<?php
session_start();
// Proteksi Endpoint: Hanya user yang login yang bisa menarik data ini
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'UNAUTHORIZED ACCESS']);
    exit();
}

require_once __DIR__ . '/../config/db.php';

$database = new Database();
$conn = $database->getConnection();

// Kueri tingkat lanjut: Menggabungkan (SUM/MIN) performa seluruh akun aktif per hari
$query = "
    SELECT 
        d.date, 
        SUM(d.pnl_cent) as total_pnl, 
        MIN(d.max_dd_cent) as total_max_dd 
    FROM daily_logs d
    JOIN accounts a ON d.account_id = a.account_id
    WHERE a.status = 'Active'
    GROUP BY d.date
    ORDER BY d.date ASC
";

$stmt = $conn->prepare($query);
$stmt->execute();
$results = $stmt->fetchAll();

$labels = [];
$pnl_data = [];
$dd_data = [];
$cumulative_data = [];
$cumulative_sum = 0;

foreach ($results as $row) {
    // Format tanggal menjadi lebih ringkas (DD MMM)
    $labels[] = date('d M', strtotime($row['date'])); 
    
    $pnl_data[] = (float)$row['total_pnl'];
    $dd_data[] = (float)$row['total_max_dd'];
    
    // Kalkulasi Equity Berjalan (Growth)
    $cumulative_sum += (float)$row['total_pnl'];
    $cumulative_data[] = $cumulative_sum;
}

// Kirim data ke Frontend dalam format JSON
header('Content-Type: application/json');
echo json_encode([
    'labels' => $labels,
    'pnl' => $pnl_data,
    'dd' => $dd_data,
    'cumulative' => $cumulative_data
]);
?>