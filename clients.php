<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/JournalManager.php';

if (isset($_GET['switch_portfolio'])) {
    $_SESSION['active_portfolio'] = $_GET['switch_portfolio'];
    header("Location: clients"); 
    exit();
}
if (!isset($_SESSION['active_portfolio'])) {
    $_SESSION['active_portfolio'] = 'Personal';
}
$active_portfolio = $_SESSION['active_portfolio'];
$portfolio_label = ($active_portfolio === 'Personal') ? 'PERSONAL EQUITY' : 'MANAGED FUNDS (PAMM)';

$journal = new JournalManager();

// Proses Add Klien 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_client') {
    $client_name = trim($_POST['client_name']);
    $tier_type = $_POST['tier_type'];
    $referred_by = $_POST['referred_by'];
    
    $master_account_id = $_POST['master_account_id'] ?? null;
    $capital_amount = (isset($_POST['capital_amount']) && $_POST['capital_amount'] !== '') ? (float)$_POST['capital_amount'] : 0;

    if ($journal->addClient($client_name, $tier_type, $referred_by, $master_account_id, $capital_amount)) {
        $_SESSION['flash_msg'] = "<div class='bg-neon-green text-terminal-black font-mono px-4 py-2 rounded mb-6 font-bold'>[SUCCESS] KLIEN BARU DIDAFTARKAN. MASA TRIAL 48 JAM DIMULAI.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6 font-bold'>[ERROR] GAGAL MENDAFTARKAN KLIEN.</div>";
    }
    header("Location: clients");
    exit();
}

// Proses Billing & Komisi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'process_billing') {
    $client_id = $_POST['client_id'];
    if ($journal->processClientBilling($client_id)) {
        $_SESSION['flash_msg'] = "<div class='bg-electric-blue text-terminal-black font-mono px-4 py-2 rounded mb-6 font-bold'>[SUCCESS] BILLING APPROVED. SUBSCRIPTION EXTENDED 30 DAYS.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='bg-neon-red text-white font-mono px-4 py-2 rounded mb-6 font-bold'>[ERROR] BILLING FAILED.</div>";
    }
    header("Location: clients");
    exit();
}

$message = $_SESSION['flash_msg'] ?? '';
unset($_SESSION['flash_msg']);

$affiliates = $journal->getAffiliates();
$clients_data = $journal->getClients();

// Ambil pemisahan identitas akun dari database
$tier_a_accounts = $journal->getActiveAccounts('Client_External');
$tier_b_accounts = $journal->getActiveAccounts('Master_Joint'); 
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - Client CRM</title>
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
        .input-dark { background-color: #000; border: 1px solid #333; color: #00FF00; font-family: 'JetBrains Mono', monospace; outline: none; transition: border 0.2s;}
        .input-dark:focus { border-color: #00E5FF; }
        .sidebar-transition { transition: width 0.3s ease-in-out; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #111; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <aside id="sidebar" class="hidden md:flex bg-terminal-panel w-64 border-r border-gray-800 sidebar-transition flex-col z-10 relative shrink-0">
        <div class="h-16 flex items-center justify-between px-5 border-b border-gray-800 shrink-0">
            <span id="logo-text" class="font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
            <button id="toggle-sidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            </button>
        </div>
        <nav class="flex-1 px-2 py-4 space-y-2 mt-2 flex flex-col justify-between overflow-y-auto">
            <div>
                <a href="index" class="group flex items-center py-2 px-3 hover:bg-gray-800 rounded border-l-2 border-transparent text-gray-400 hover:text-white transition-colors whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors group-hover:text-neon-green" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
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
                <a href="clients" class="group flex items-center py-2 px-3 bg-gray-800 rounded border-l-2 border-neon-green text-neon-green whitespace-nowrap overflow-hidden mb-2">
                    <svg class="w-5 h-5 shrink-0 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
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

    <main class="flex-1 flex flex-col h-screen overflow-y-auto relative pb-20 md:pb-0">
        <header class="h-16 bg-terminal-panel border-b border-gray-800 flex items-center justify-between px-4 md:px-6 shrink-0 sticky top-0 z-20">
            <div class="flex items-center space-x-4 md:space-x-6">
                <span class="md:hidden font-bold text-electric-blue text-lg tracking-widest">EA.CMD_</span>
                
                <span class="bg-black border border-gray-700 text-electric-blue font-mono text-xs md:text-sm px-3 py-1 font-bold rounded">DATABASE: CLIENT WATCHLIST</span>
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

        <div class="p-4 md:p-6 flex-1 flex flex-col">
            <h1 class="text-lg md:text-xl font-bold font-mono text-gray-400 border-b border-gray-800 pb-2 mb-6 mt-2">CLIENT_CRM_MODULE</h1>
            
            <?= $message ?>

            <div class="bg-terminal-panel p-5 md:p-6 rounded border border-gray-800 shadow-lg mb-8 shrink-0">
                <h2 class="text-electric-blue font-mono text-xs md:text-sm font-bold mb-4">[ NEW CLIENT DEPLOYMENT ]</h2>
                <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <input type="hidden" name="action" value="add_client">
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">NAMA KLIEN</label>
                        <input type="text" name="client_name" required autocomplete="off" class="input-dark w-full px-3 py-2 rounded">
                    </div>
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">TIER PAKET (30 HARI)</label>
                        <select id="tier_selector" name="tier_type" required class="input-dark w-full px-3 py-2 rounded">
                            <option value="" disabled selected>-- Pilih Tier Klien --</option>
                            <option value="Tier_A">Tier A (EA VPS - 400k)</option>
                            <option value="Tier_B">Tier B (Joint Slot - 200k)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-500 text-xs font-mono mb-2">REFERRED BY (MARKETER)</label>
                        <select name="referred_by" class="input-dark w-full px-3 py-2 rounded text-gray-400">
                            <option value="">-- Organik / Tanpa Marketer --</option>
                            <?php foreach($affiliates as $af): ?>
                                <option value="<?= $af['affiliate_id'] ?>"><?= htmlspecialchars($af['marketer_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="w-full bg-gray-800 hover:bg-neon-green hover:text-black text-neon-green font-mono font-bold py-2 px-4 rounded transition-colors border border-gray-700 hover:border-neon-green">
                            EXECUTE >
                        </button>
                    </div>

                    <div id="extended_panel" class="hidden col-span-1 md:col-span-4 grid grid-cols-1 md:grid-cols-2 gap-4 mt-2 p-4 border border-electric-blue rounded bg-gray-900">
                        <div>
                            <label id="account_label" class="block text-electric-blue text-xs font-mono mb-2">LINK TO ACCOUNT ID</label>
                            <select name="master_account_id" id="dynamic_account_select" class="input-dark w-full px-3 py-2 rounded border-electric-blue" required>
                                <option value="">-- Pilih Akun --</option>
                            </select>
                        </div>
                        <div id="capital_panel">
                            <label class="block text-electric-blue text-xs font-mono mb-2">CLIENT CAPITAL DEPOSIT (USC)</label>
                            <input type="number" step="0.01" name="capital_amount" placeholder="Misal: 50000" class="input-dark w-full px-3 py-2 rounded text-neon-green border-electric-blue">
                        </div>
                    </div>
                </form>
            </div>

            <div class="bg-terminal-panel rounded border border-gray-800 shadow-lg overflow-x-auto shrink-0 mb-6">
                <table class="w-full text-left border-collapse whitespace-nowrap md:whitespace-normal">
                    <thead>
                        <tr class="bg-gray-900 border-b border-gray-700 font-mono text-[10px] md:text-xs text-gray-400">
                            <th class="p-3 md:p-4 uppercase tracking-wider">ID</th>
                            <th class="p-3 md:p-4 uppercase tracking-wider">Client Name</th>
                            <th class="p-3 md:p-4 uppercase tracking-wider">Tier Type</th>
                            <th class="p-3 md:p-4 uppercase tracking-wider">Status</th>
                            <th class="p-3 md:p-4 uppercase tracking-wider">Time Remaining</th>
                            <th class="p-3 md:p-4 uppercase tracking-wider">Marketer</th>
                            <th class="p-3 md:p-4 uppercase tracking-wider text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-xs md:text-sm">
                        <?php if(empty($clients_data)): ?>
                            <tr><td colspan="7" class="p-4 text-center text-gray-600">-- NO ACTIVE CLIENTS DETECTED --</td></tr>
                        <?php else: ?>
                            <?php foreach($clients_data as $client): 
                                $now_ts = time();
                                $target_date = ($client['status'] == 'Trial') ? $client['trial_end_date'] : $client['subscription_end_date'];
                                $target_ts = strtotime($target_date);
                                $diff_seconds = $target_ts - $now_ts;
                                
                                $status_color = "";
                                if ($client['status'] == 'Expired') {
                                    $status_color = "bg-red-900 text-neon-red border border-red-500";
                                } else if ($client['status'] == 'Trial') {
                                    $status_color = "bg-yellow-900 text-warning-yellow border border-yellow-500";
                                } else { 
                                    $status_color = "bg-green-900 text-neon-green border border-green-500";
                                }
                            ?>
                            <tr class="border-b border-gray-800 hover:bg-gray-800 transition-colors">
                                <td class="p-3 md:p-4 text-gray-500">#<?= str_pad($client['client_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td class="p-3 md:p-4 text-white font-bold"><?= htmlspecialchars($client['client_name']) ?></td>
                                <td class="p-3 md:p-4 text-gray-400"><?= str_replace('_', ' ', $client['tier_type']) ?></td>
                                <td class="p-3 md:p-4">
                                    <span class="px-2 py-1 text-[10px] md:text-xs rounded font-bold <?= $status_color ?>">
                                        <?= strtoupper($client['status']) ?>
                                    </span>
                                </td>
                                <td class="p-3 md:p-4">
                                    <?php if ($client['status'] == 'Expired'): ?>
                                        <span class="text-neon-red animate-pulse font-bold">TIME IS UP</span>
                                    <?php else: ?>
                                        <span class="countdown-timer font-bold <?= $client['status'] == 'Trial' ? 'text-warning-yellow' : 'text-neon-green' ?>" 
                                              data-remaining-seconds="<?= $diff_seconds ?>" 
                                              data-status="<?= $client['status'] ?>">
                                              CALCULATING...
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 md:p-4 text-gray-500"><?= $client['marketer_name'] ?? '-' ?></td>
                                <td class="p-3 md:p-4 text-right">
                                    <?php if ($client['status'] == 'Expired' || $client['status'] == 'Trial'): ?>
                                        <form method="POST" action="" class="inline-block">
                                            <input type="hidden" name="action" value="process_billing">
                                            <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                            <button type="submit" onclick="return confirm('Proses penagihan untuk klien ini?')" class="text-[10px] md:text-xs bg-transparent border border-gray-600 text-gray-400 hover:text-white hover:bg-electric-blue hover:border-electric-blue px-3 py-1 rounded transition-colors">
                                                PROCESS BILLING
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-[10px] md:text-xs text-gray-600">NO ACTION REQ.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="flex-1"></div>
        </div>

        <footer class="mt-auto border-t border-gray-800 bg-[#0a0a0a] py-4 text-center shrink-0 hidden md:block">
            <p class="font-mono text-xs text-gray-600">
                &copy; <?= date('Y') ?> Tommy Alfarabi. All rights reserved.
            </p>
        </footer>
    </main>

    <div class="md:hidden fixed bottom-0 w-full bg-black/80 backdrop-blur-lg border-t border-gray-800 z-50 flex justify-around items-center pt-2 pb-safe" style="padding-bottom: env(safe-area-inset-bottom, 12px);">
        <a href="index" class="flex flex-col items-center p-2 text-gray-500 hover:text-white transition-colors">
            <svg class="w-6 h-6 mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
            <span class="text-[10px] font-mono font-bold">Dash</span>
        </a>
        <a href="input" class="flex flex-col items-center p-2 text-gray-500 hover:text-white transition-colors">
            <svg class="w-6 h-6 mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
            <span class="text-[10px] font-mono font-bold">Entry</span>
        </a>
        <a href="clients" class="flex flex-col items-center p-2 text-neon-green">
            <svg class="w-6 h-6 mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            <span class="text-[10px] font-mono font-bold">CRM</span>
        </a>
        <a href="distribution" class="flex flex-col items-center p-2 text-gray-500 hover:text-white transition-colors">
            <svg class="w-6 h-6 mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            <span class="text-[10px] font-mono font-bold">PAMM</span>
        </a>
        
        <button id="mobile-more-btn" class="flex flex-col items-center p-2 text-gray-500 hover:text-electric-blue focus:outline-none transition-colors">
            <svg class="w-6 h-6 mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
            <span class="text-[10px] font-mono font-bold">Menu</span>
        </button>
    </div>

    <div id="mobile-more-sheet" class="md:hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden flex-col justify-end">
        <div id="mobile-more-content" class="bg-gray-900 rounded-t-2xl border-t border-gray-700 p-6 transform translate-y-full transition-transform duration-300 ease-out pb-10">
            <div class="flex justify-between items-center mb-6 border-b border-gray-800 pb-4">
                <span class="text-electric-blue font-mono font-bold tracking-widest">SYSTEM_MENU</span>
                <button id="close-more-btn" class="text-gray-400 hover:text-white bg-black rounded-full p-1 border border-gray-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <div class="space-y-2 font-mono text-sm">
                <a href="report" class="flex items-center text-gray-400 hover:text-white p-3 rounded hover:bg-gray-800 transition-colors">
                    <svg class="w-5 h-5 mr-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    Annual Report
                </a>
                <a href="accounts" class="flex items-center text-gray-400 hover:text-white p-3 rounded hover:bg-gray-800 transition-colors">
                    <svg class="w-5 h-5 mr-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    Accounts Ledger
                </a>
                <a href="affiliates" class="flex items-center text-gray-400 hover:text-white p-3 rounded hover:bg-gray-800 transition-colors">
                    <svg class="w-5 h-5 mr-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666M19.242 21.25a11.966 11.966 0 01-8.242 2.25 11.966 11.966 0 01-8.242-2.25m16.484 0a12.01 12.01 0 00-3.32-3.32m-3.32 3.32A11.966 11.966 0 0111 23.5c-2.87 0-5.54-.954-7.72-2.58m16.484 0A12.01 12.01 0 0019 18m-8.5-4a4.5 4.5 0 100-9 4.5 4.5 0 000 9z" /></svg>
                    Affiliate Engine
                </a>
                <a href="logout" class="flex items-center text-neon-red mt-4 pt-4 border-t border-gray-800 p-3 hover:bg-red-900/30 rounded transition-colors">
                    <svg class="w-5 h-5 mr-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                    System Logout
                </a>
            </div>
        </div>
    </div>
    <script>
        // Logika Desktop Sidebar
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggle-sidebar');
        const navTexts = document.querySelectorAll('.nav-text');
        const logoText = document.getElementById('logo-text');

        if(localStorage.getItem('ea_sidebar_collapsed') === 'true') collapseSidebar();

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                if (sidebar.classList.contains('w-64')) {
                    collapseSidebar();
                    localStorage.setItem('ea_sidebar_collapsed', 'true');
                } else {
                    expandSidebar();
                    localStorage.setItem('ea_sidebar_collapsed', 'false');
                }
            });
        }

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

        setInterval(() => {
            document.querySelectorAll('.countdown-timer').forEach(el => {
                let seconds = parseInt(el.getAttribute('data-remaining-seconds'));
                let status = el.getAttribute('data-status');
                
                if (seconds <= 0) {
                    el.innerText = "TIME IS UP";
                    el.className = "text-neon-red animate-pulse font-bold";
                    return; 
                }
                
                seconds--;
                el.setAttribute('data-remaining-seconds', seconds);
                
                if (status === 'Trial') {
                    let h = Math.floor(seconds / 3600);
                    let m = Math.floor((seconds % 3600) / 60);
                    let s = seconds % 60;
                    el.innerText = `${h.toString().padStart(2, '0')}H ${m.toString().padStart(2, '0')}M ${s.toString().padStart(2, '0')}S`;
                } else {
                    let d = Math.floor(seconds / 86400);
                    el.innerText = `${d} DAYS`;
                }
            });
        }, 1000);

        // =======================================================================
        // LOGIKA MOBILE BOTTOM SHEET ANIMATION
        // =======================================================================
        const moreBtn = document.getElementById('mobile-more-btn');
        const closeMoreBtn = document.getElementById('close-more-btn');
        const moreSheet = document.getElementById('mobile-more-sheet');
        const moreContent = document.getElementById('mobile-more-content');

        function openMoreMenu() {
            moreSheet.classList.remove('hidden');
            moreSheet.classList.add('flex');
            setTimeout(() => {
                moreContent.classList.remove('translate-y-full');
            }, 10);
        }

        function closeMoreMenu() {
            moreContent.classList.add('translate-y-full');
            setTimeout(() => {
                moreSheet.classList.add('hidden');
                moreSheet.classList.remove('flex');
            }, 300);
        }

        if(moreBtn) moreBtn.addEventListener('click', openMoreMenu);
        if(closeMoreBtn) closeMoreBtn.addEventListener('click', closeMoreMenu);
        
        if(moreSheet) {
            moreSheet.addEventListener('click', (e) => {
                if (e.target === moreSheet) closeMoreMenu();
            });
        }
        // =======================================================================

        // LOGIKA DYNAMIC ACCOUNT SELECTOR (TIER A & TIER B)
        const tierSelector = document.getElementById('tier_selector');
        const extendedPanel = document.getElementById('extended_panel');
        const capitalPanel = document.getElementById('capital_panel');
        const dynamicSelect = document.getElementById('dynamic_account_select');
        const accountLabel = document.getElementById('account_label');

        const accountsA = [
            <?php foreach($tier_a_accounts as $a) echo "{id:'{$a['account_id']}', name:'".addslashes($a['account_name'])."'},"; ?>
        ];
        const accountsB = [
            <?php foreach($tier_b_accounts as $a) echo "{id:'{$a['account_id']}', name:'".addslashes($a['account_name'])."'},"; ?>
        ];

        function updatePanel() {
            dynamicSelect.innerHTML = '<option value="">-- Pilih Akun Target --</option>';
            if (tierSelector.value === 'Tier_A') {
                extendedPanel.classList.remove('hidden');
                capitalPanel.classList.add('hidden'); 
                accountLabel.innerText = "LINK TO EXTERNAL ACCOUNT (TIER A)";
                accountsA.forEach(acc => {
                    dynamicSelect.innerHTML += `<option value="${acc.id}">${acc.name}</option>`;
                });
                document.querySelector('input[name="capital_amount"]').removeAttribute('required');
            } else if (tierSelector.value === 'Tier_B') {
                extendedPanel.classList.remove('hidden');
                capitalPanel.classList.remove('hidden'); 
                accountLabel.innerText = "LINK TO MASTER JOINT ACCOUNT (TIER B)";
                accountsB.forEach(acc => {
                    dynamicSelect.innerHTML += `<option value="${acc.id}">${acc.name}</option>`;
                });
                document.querySelector('input[name="capital_amount"]').setAttribute('required', 'true');
            } else {
                extendedPanel.classList.add('hidden');
            }
        }

        if(tierSelector) tierSelector.addEventListener('change', updatePanel);
        if(tierSelector) updatePanel();
    </script>
</body>
</html>