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