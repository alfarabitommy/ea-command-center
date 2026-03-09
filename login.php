<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Jika sudah login, langsung arahkan ke dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $database = new Database();
    $conn = $database->getConnection();

    $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1");
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Login berhasil, set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header("Location: index");
        exit();
    } else {
        $error = "ACCESS DENIED. INVALID CREDENTIALS.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EA Command Center - SYSTEM LOGIN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000000; color: #E0E0E0; font-family: 'JetBrains Mono', monospace; }
        .input-terminal { background-color: #000; border: 1px solid #333; color: #00FF00; outline: none; transition: border 0.3s;}
        .input-terminal:focus { border-color: #00E5FF; box-shadow: 0 0 5px rgba(0, 229, 255, 0.3); }
    </style>
</head>
<body class="flex items-center justify-center h-screen relative overflow-hidden">
    <div class="absolute inset-0 z-0 opacity-10" style="background-image: linear-gradient(#333 1px, transparent 1px), linear-gradient(90deg, #333 1px, transparent 1px); background-size: 30px 30px;"></div>

    <div class="z-10 bg-[#111111] p-8 rounded border border-gray-800 shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <span class="text-electric-blue text-3xl font-bold tracking-widest text-[#00E5FF]">EA.CMD_</span>
            <p class="text-gray-500 text-xs mt-2">SECURE AUTHENTICATION GATEWAY</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-900 border border-red-500 text-red-400 px-4 py-2 rounded mb-6 text-sm text-center animate-pulse">
                [ <?= $error ?> ]
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-5">
                <label class="block text-gray-500 text-xs mb-2">USERNAME ID</label>
                <input type="text" name="username" required autocomplete="off" class="input-terminal w-full px-4 py-3 rounded" placeholder="_">
            </div>
            <div class="mb-8">
                <label class="block text-gray-500 text-xs mb-2">SECURITY PASSPHRASE</label>
                <input type="password" name="password" required class="input-terminal w-full px-4 py-3 rounded text-red-500" placeholder="***">
            </div>
            <button type="submit" class="w-full bg-transparent hover:bg-[#00E5FF] text-[#00E5FF] hover:text-black font-bold py-3 px-4 rounded transition-colors border border-[#00E5FF]">
                [ INITIATE LOGIN ]
            </button>
        </form>
    </div>
</body>
</html>