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