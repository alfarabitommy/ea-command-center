<?php
session_start();
require_once __DIR__ . '/includes/JournalManager.php';
require_once __DIR__ . '/config/db.php';

// Proteksi: Hanya bisa diakses oleh Marketer yang login
if (!isset($_SESSION['affiliate_id'])) {
    header("Location: partner_login");
    exit();
}

// Handle Logout Eksternal
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: partner_login");
    exit();
}

$journal = new JournalManager();
$affiliate_id = $_SESSION['affiliate_id'];
$marketer_name = $_SESSION['marketer_name'];

// Handle Payout Request oleh Marketer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_payout'])) {
    $journal->requestAffiliatePayout($affiliate_id);
    header("Location: partner_dashboard");
    exit();
}

// Mengambil data Live Komisi Marketer menggunakan koneksi database manual agar tidak merombak Core Engine
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare("SELECT total_unpaid_commission, payout_status FROM affiliates WHERE affiliate_id = :id");
$stmt->bindParam(':id', $affiliate_id);
$stmt->execute();
$my_data = $stmt->fetch();

$total_commission = (float)$my_data['total_unpaid_commission'];
$payout_status = $my_data['payout_status'];

// Mengambil daftar Klien khusus milik marketer ini
$my_clients = $journal->getAffiliateClients($affiliate_id);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Dashboard - Partner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'terminal-black': '#000000',
                        'terminal-panel': '#111111',
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
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: #111; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 2px; }
    </style>
</head>
<body class="flex flex-col min-h-screen pb-safe">

    <header class="bg-terminal-panel border-b border-gray-800 px-5 py-4 flex items-center justify-between sticky top-0 z-20 shadow-md">
        <div>
            <div class="text-electric-blue font-bold font-mono text-sm tracking-widest">EA.PARTNER_</div>
            <div class="text-xs text-gray-500 font-mono mt-1">Hello, <span class="text-white"><?= htmlspecialchars($marketer_name) ?></span></div>
        </div>
        <a href="?action=logout" class="text-[10px] border border-gray-700 hover:border-neon-red text-gray-400 hover:text-neon-red px-3 py-1 rounded font-mono transition-colors">
            LOGOUT
        </a>
    </header>

    <main class="flex-1 p-4 md:p-6 max-w-3xl w-full mx-auto">
        
        <div class="bg-gradient-to-br from-gray-900 to-black border border-gray-800 rounded-2xl p-6 mb-8 relative overflow-hidden shadow-2xl">
            <div class="absolute -right-10 -top-10 w-32 h-32 bg-neon-green rounded-full blur-[80px] opacity-20"></div>

            <div class="text-gray-400 font-mono text-xs mb-2">AVAILABLE COMMISSION</div>
            <div class="text-3xl md:text-4xl font-bold font-mono text-neon-green mb-6 number-format">
                Rp <?= number_format($total_commission, 0, ',', '.') ?>
            </div>

            <?php if ($total_commission > 0 && $payout_status === 'Idle'): ?>
                <form method="POST" action="" onsubmit="return confirm('Tarik komisi sekarang? Saldo akan dikirimkan ke rekening Anda oleh Admin.')">
                    <input type="hidden" name="request_payout" value="1">
                    <button type="submit" class="w-full bg-electric-blue text-black font-mono font-bold py-3 rounded-lg hover:bg-white transition-colors shadow-[0_0_15px_rgba(0,229,255,0.4)]">
                        REQUEST PAYOUT
                    </button>
                </form>
            <?php elseif ($payout_status === 'Requested'): ?>
                <div class="w-full bg-gray-800 text-warning-yellow font-mono text-xs md:text-sm font-bold py-3 rounded-lg text-center border border-warning-yellow animate-pulse">
                    PAYOUT IN PROGRESS...
                </div>
            <?php else: ?>
                <button disabled class="w-full bg-gray-800 text-gray-600 font-mono font-bold py-3 rounded-lg cursor-not-allowed">
                    NO FUNDS AVAILABLE
                </button>
            <?php endif; ?>
        </div>

        <div class="mb-4 flex items-end justify-between">
            <h2 class="text-electric-blue font-mono text-sm font-bold border-b border-gray-800 pb-1">MY CLIENTS PORTFOLIO</h2>
            <span class="text-[10px] font-mono text-gray-500">Total: <?= count($my_clients) ?></span>
        </div>

        <?php if(empty($my_clients)): ?>
            <div class="bg-terminal-panel border border-gray-800 rounded-xl p-8 text-center">
                <p class="text-gray-500 font-mono text-xs">Anda belum memiliki klien aktif.</p>
                <p class="text-gray-600 font-mono text-[10px] mt-2">Sebarkan link dan bawa klien untuk mendapatkan pasif income!</p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach($my_clients as $c): 
                    $now_ts = time();
                    $target_date = ($c['status'] == 'Trial') ? $c['trial_end_date'] : $c['subscription_end_date'];
                    $target_ts = strtotime($target_date);
                    $diff_seconds = $target_ts - $now_ts;
                    
                    $card_border = "border-gray-800";
                    $status_text = strtoupper($c['status']);
                    $status_color = "";

                    if ($c['status'] == 'Expired') {
                        $card_border = "border-neon-red shadow-[0_0_8px_rgba(255,51,51,0.2)]";
                        $status_color = "text-neon-red";
                        $time_text = "EXPIRED - PLEASE FOLLOW UP!";
                    } else if ($c['status'] == 'Trial') {
                        $card_border = "border-warning-yellow";
                        $status_color = "text-warning-yellow";
                        $time_text = "Trial Ends: " . date('d M, H:i', $target_ts);
                    } else { 
                        $status_color = "text-neon-green";
                        $days_left = floor($diff_seconds / 86400);
                        if ($days_left <= 3) {
                            $card_border = "border-warning-yellow"; // Alert jika kurang dari 3 hari
                            $time_text = $days_left . " Days Left (Follow Up Soon)";
                        } else {
                            $time_text = $days_left . " Days Remaining";
                        }
                    }
                ?>
                <div class="bg-terminal-panel border <?= $card_border ?> rounded-xl p-4 transition-all">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <div class="font-bold text-white text-sm md:text-base"><?= htmlspecialchars($c['client_name']) ?></div>
                            <div class="text-[10px] font-mono text-gray-500 mt-1"><?= str_replace('_', ' ', $c['tier_type']) ?></div>
                        </div>
                        <div class="px-2 py-1 rounded bg-gray-900 text-[10px] font-mono font-bold <?= $status_color ?>">
                            <?= $status_text ?>
                        </div>
                    </div>
                    
                    <div class="mt-3 pt-3 border-t border-gray-800/50 flex justify-between items-center">
                        <div class="text-[10px] font-mono <?= $c['status'] == 'Expired' ? 'text-neon-red animate-pulse font-bold' : 'text-gray-400' ?>">
                            <?= $time_text ?>
                        </div>
                        <?php if($c['status'] == 'Expired'): ?>
                            <a href="https://wa.me/" target="_blank" class="bg-neon-green/20 text-neon-green border border-neon-green px-3 py-1 rounded text-[10px] font-bold font-mono hover:bg-neon-green hover:text-black transition-colors">
                                CHAT CLIENT
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
    </main>
</body>
</html>