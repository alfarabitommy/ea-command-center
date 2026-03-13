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