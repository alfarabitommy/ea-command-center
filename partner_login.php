<?php
session_start();
require_once __DIR__ . '/includes/JournalManager.php';

// Jika sudah login, langsung lempar ke dashboard marketer
if (isset($_SESSION['affiliate_id'])) {
    header("Location: partner_dashboard");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $journal = new JournalManager();
    $user = $journal->loginAffiliate($username, $password);
    
    if ($user) {
        $_SESSION['affiliate_id'] = $user['affiliate_id'];
        $_SESSION['marketer_name'] = $user['marketer_name'];
        header("Location: partner_dashboard");
        exit();
    } else {
        $error = "Akses Ditolak: Username atau Password tidak valid.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Partner Login - EA Command Center</title>
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
        .input-dark { background-color: #000; border: 1px solid #333; color: #00FF00; font-family: 'JetBrains Mono', monospace; outline: none; transition: border 0.2s;}
        .input-dark:focus { border-color: #00E5FF; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')]">
    <div class="w-full max-w-md bg-terminal-panel/90 backdrop-blur-md p-8 rounded-2xl border border-gray-800 shadow-2xl">
        <div class="text-center mb-10">
            <h1 class="text-electric-blue font-bold font-mono text-2xl tracking-widest mb-2">EA.PARTNER_</h1>
            <p class="text-gray-500 font-mono text-xs">AUTHORIZED MARKETING ACCESS ONLY</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-900/50 border border-red-500 text-red-400 text-xs font-mono p-3 rounded mb-6 text-center animate-pulse">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-6">
            <div>
                <label class="block text-gray-400 text-xs font-mono mb-2">USERNAME</label>
                <input type="text" name="username" required autocomplete="off" class="input-dark w-full px-4 py-3 rounded-lg text-sm" placeholder="Enter your ID">
            </div>
            <div>
                <label class="block text-gray-400 text-xs font-mono mb-2">PASSWORD</label>
                <input type="password" name="password" required class="input-dark w-full px-4 py-3 rounded-lg text-sm" placeholder="Enter passphrase">
            </div>
            <button type="submit" class="w-full bg-electric-blue hover:bg-neon-green text-black font-mono font-bold py-4 rounded-lg transition-colors mt-4">
                AUTHENTICATE >
            </button>
        </form>
    </div>
</body>
</html>