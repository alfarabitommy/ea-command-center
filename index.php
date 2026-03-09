<?php
require_once __DIR__ . '/includes/auth.php'; // Proteksi Keamanan
require_once __DIR__ . '/includes/JournalManager.php';

$journal = new JournalManager();
$metrics = $journal->getDashboardMetrics();
$usd_rate = $journal->getUsdRate();
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

    <aside id="sidebar" class="bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex flex-col z-10 relative">
        <div class="h-16 flex items-center justify-between px-4 border-b border-gray-800">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                &#9776;
            </button>
        </div>
        <nav class="flex-1 p-4 space-y-2 mt-2 flex flex-col justify-between">
            <div>
                <a href="input" class="group block py-2 px-3 bg-gray-800 rounded text-neon-green border-l-2 border-neon-green flex items-center whitespace-nowrap overflow-hidden mb-2">
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

    <main class="flex-1 flex flex-col h-screen overflow-y-auto">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-6 shrink-0">
            <div class="text-sm font-mono">
                <span class="text-gray-500">SYS.STATUS:</span> <span class="text-neon-green animate-pulse">ONLINE</span>
                <span class="text-gray-500 ml-4">USER:</span> <span class="text-white"><?= strtoupper($_SESSION['username']) ?></span>
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
            <h1 class="text-xl font-bold mb-6 font-mono text-gray-400 border-b border-gray-800 pb-2">PORTFOLIO_OVERVIEW</h1>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-terminal-panel p-4 rounded border border-gray-800 shadow-lg relative h-80">
                    <h2 class="text-gray-500 text-xs font-mono mb-2 absolute top-4 left-4 z-10">CUMULATIVE EQUITY CURVE</h2>
                    <canvas id="equityChart"></canvas>
                </div>
                
                <div class="bg-terminal-panel p-4 rounded border border-gray-800 shadow-lg relative h-80">
                    <h2 class="text-gray-500 text-xs font-mono mb-2 absolute top-4 left-4 z-10">DAILY PNL VS MAX DRAWDOWN</h2>
                    <canvas id="pnlDdChart"></canvas>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Logika UI & Jam
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

        // ==========================================
        // ENGINE GRAFIK ANALITIK (CHART.JS)
        // ==========================================
        document.addEventListener("DOMContentLoaded", function() {
            // Tarik data JSON dari Endpoint PHP
            fetch('api/chart_data.php')
                .then(response => response.json())
                .then(data => {
                    if(data.error) {
                        console.error('API Error:', data.error);
                        return;
                    }

                    // Setup Tema Global Chart.js (Institutional Dark)
                    Chart.defaults.color = '#888';
                    Chart.defaults.font.family = "'JetBrains Mono', monospace";
                    Chart.defaults.scale.grid.color = '#222';
                    Chart.defaults.scale.grid.borderColor = '#444';

                    // 1. Render Cumulative Equity Curve (Line Chart)
                    const ctxEquity = document.getElementById('equityChart').getContext('2d');
                    new Chart(ctxEquity, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                label: 'Cumulative Cent',
                                data: data.cumulative,
                                borderColor: '#00E5FF', // Electric Blue
                                backgroundColor: 'rgba(0, 229, 255, 0.1)',
                                borderWidth: 2,
                                pointRadius: 1,
                                pointHoverRadius: 5,
                                fill: true,
                                tension: 0.3 // Membuat kurva agak halus
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            layout: { padding: { top: 30 } } // Ruang untuk judul absolut
                        }
                    });

                    // 2. Render PNL vs Drawdown (Bar Chart)
                    const ctxPnlDd = document.getElementById('pnlDdChart').getContext('2d');
                    new Chart(ctxPnlDd, {
                        type: 'bar',
                        data: {
                            labels: data.labels,
                            datasets: [
                                {
                                    label: 'Daily Profit',
                                    data: data.pnl,
                                    backgroundColor: '#00FF00', // Neon Green
                                    borderRadius: 2
                                },
                                {
                                    label: 'Max Drawdown',
                                    data: data.dd,
                                    backgroundColor: '#FF3333', // Bright Red
                                    borderRadius: 2
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { 
                                legend: { position: 'bottom', labels: { boxWidth: 12 } }
                            },
                            layout: { padding: { top: 30 } },
                            scales: {
                                x: { stacked: false }, // Ubah ke true jika ingin batangnya ditumpuk
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