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