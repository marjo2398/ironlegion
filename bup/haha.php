<?php
require_once 'functions.php';

// --- KONFIGURACJA CZATU ---
$uploadDir = 'uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// --- 1. AUTOMATYCZNA INSTALACJA TABEL (DIY Style) ---
try {
    // Tabela użytkowników czatu
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Tabela wiadomości
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT,
        image_path VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES chat_users(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
    die("Błąd instalacji bazy czatu: " . $e->getMessage());
}

// --- 2. LOGIKA PHP (API & AUTH) ---

// API: Pobieranie wiadomości (AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'fetch_messages') {
    header('Content-Type: application/json');
    $stmt = $pdo->query("
        SELECT m.*, u.username 
        FROM chat_messages m 
        JOIN chat_users u ON m.user_id = u.id 
        ORDER BY m.created_at ASC 
        LIMIT 100
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// LOGOWANIE / REJESTRACJA
$authError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];

    if ($_POST['auth_action'] === 'register') {
        // Sprawdź czy user istnieje
        $stmt = $pdo->prepare("SELECT id FROM chat_users WHERE username = ?");
        $stmt->execute([$user]);
        if ($stmt->fetch()) {
            $authError = "Ten nick jest już zajęty, Mordko!";
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO chat_users (username, password) VALUES (?, ?)");
            if ($stmt->execute([$user, $hash])) {
                $_SESSION['chat_user_id'] = $pdo->lastInsertId();
                $_SESSION['chat_username'] = $user;
                header("Location: ironlegionchat.php"); exit;
            }
        }
    } elseif ($_POST['auth_action'] === 'login') {
        $stmt = $pdo->prepare("SELECT id, password FROM chat_users WHERE username = ?");
        $stmt->execute([$user]);
        $userData = $stmt->fetch();
        if ($userData && password_verify($pass, $userData['password'])) {
            $_SESSION['chat_user_id'] = $userData['id'];
            $_SESSION['chat_username'] = $user;
            header("Location: ironlegionchat.php"); exit;
        } else {
            $authError = "Błędny nick lub hasło!";
        }
    }
}

// WYLOGOWANIE
if (isset($_GET['logout'])) {
    unset($_SESSION['chat_user_id']);
    unset($_SESSION['chat_username']);
    header("Location: ironlegionchat.php"); exit;
}

// WYSYŁANIE WIADOMOŚCI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_action']) && isset($_SESSION['chat_user_id'])) {
    $msg = trim($_POST['message']);
    $imgPath = null;

    // Obsługa zdjęcia
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $newName = uniqid('img_') . '.' . $ext;
            $target = $uploadDir . $newName;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $imgPath = $target;
            }
        }
    }

    if (!empty($msg) || $imgPath) {
        $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, message, image_path) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['chat_user_id'], htmlspecialchars($msg), $imgPath]);
    }
    // Przekierowanie, żeby nie wysyłać ponownie formularza przy F5
    header("Location: ironlegionchat.php"); exit;
}

// BANER VERSIONING
$bannerVer = get_setting($pdo, 'banner_version') ?? time();
$currentUser = $_SESSION['chat_username'] ?? null;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iron Legion CHAT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome dla ikonek -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #111827; color: #e5e7eb; }
        .chat-container { height: calc(100vh - 350px); min-height: 400px; }
        .msg-bubble { max-width: 85%; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #1f2937; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 3px; }
        .link-highlight { color: #eab308; text-decoration: underline; }
        .file-upload-label { cursor: pointer; transition: all 0.2s; }
        .file-upload-label:hover { color: #eab308; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Baner -->
    <div class="w-full bg-black">
        <img src="icons/baner.png?v=<?= $bannerVer ?>" alt="Banner" class="block w-full shadow-2xl mx-auto max-w-7xl">
    </div>

    <!-- Nawigacja -->
    <nav class="bg-gray-800 border-b border-gray-700 sticky top-0 z-50 shadow-md">
        <div class="container mx-auto px-4 py-3 flex justify-between items-center">
            <h1 class="text-xl font-bold text-yellow-500 tracking-wide">IRON LEGION <span class="text-white">COMMUNICATOR</span></h1>
            <div class="flex items-center gap-4">
                <a href="index2.php" class="bg-gray-700 hover:bg-gray-600 text-white text-sm font-bold py-2 px-4 rounded border border-gray-600 transition">
                    &larr; Powrót
                </a>
                <?php if ($currentUser): ?>
                    <a href="?logout=1" class="text-red-400 hover:text-red-300 text-sm font-bold">Wyloguj (<?= htmlspecialchars($currentUser) ?>)</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Główna zawartość -->
    <main class="container mx-auto px-4 py-6 flex-grow">
        
        <?php if (!$currentUser): ?>
            <!-- EKRAN LOGOWANIA / REJESTRACJI -->
            <div class="max-w-md mx-auto bg-gray-800 border border-gray-700 rounded-xl p-8 shadow-2xl mt-10">
                <h2 class="text-2xl font-bold text-center mb-6 text-white">Identyfikacja Członka</h2>
                
                <?php if($authError): ?>
                    <div class="bg-red-900/50 border border-red-500 text-red-200 p-3 rounded mb-4 text-center text-sm">
                        <?= $authError ?>
                    </div>
                <?php endif; ?>

                <div x-data="{ mode: 'login' }"> <!-- Prosty switch JS (native) -->
                    <!-- Formularz -->
                    <form method="POST" action="" class="space-y-4">
                        <div>
                            <label class="block text-gray-400 text-sm mb-1">Nick z gry</label>
                            <input type="text" name="username" required class="w-full bg-gray-900 border border-gray-600 rounded p-2 text-white focus:border-yellow-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-gray-400 text-sm mb-1">Hasło</label>
                            <input type="password" name="password" required class="w-full bg-gray-900 border border-gray-600 rounded p-2 text-white focus:border-yellow-500 focus:outline-none">
                        </div>
                        
                        <div id="login-btn-group">
                            <button type="submit" name="auth_action" value="login" class="w-full bg-yellow-600 hover:bg-yellow-500 text-white font-bold py-2 rounded transition">
                                Zaloguj się
                            </button>
                        </div>
                        
                        <div class="text-center pt-4 border-t border-gray-700 mt-4">
                            <p class="text-xs text-gray-500 mb-2">Nie masz jeszcze dostępu?</p>
                            <button type="submit" name="auth_action" value="register" class="text-yellow-500 hover:text-yellow-400 text-sm font-bold underline">
                                Stwórz nowe konto
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- INTERFEJS CZATU -->
            <div class="flex flex-col h-full max-w-5xl mx-auto bg-gray-800 border border-gray-700 rounded-xl shadow-2xl overflow-hidden">
                
                <!-- Okno wiadomości -->
                <div id="chat-window" class="flex-grow p-4 overflow-y-auto custom-scroll space-y-3 bg-gray-900/50 chat-container">
                    <div class="text-center text-gray-500 text-sm italic py-4">Ładowanie historii gildii...</div>
                </div>

                <!-- Input Area -->
                <div class="bg-gray-800 p-4 border-t border-gray-700">
                    <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-2">
                        <input type="hidden" name="message_action" value="1">
                        
                        <!-- Podgląd obrazka -->
                        <div id="image-preview" class="hidden mb-2 relative w-fit">
                            <img id="preview-img" src="" class="h-20 rounded border border-yellow-500">
                            <button type="button" onclick="clearImage()" class="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold">&times;</button>
                        </div>

                        <div class="flex items-end gap-2">
                            <!-- Przycisk dodawania zdjęcia -->
                            <label class="file-upload-label p-3 bg-gray-700 rounded-lg hover:bg-gray-600 border border-gray-600">
                                <i class="fas fa-image text-xl"></i>
                                <input type="file" name="image" id="image-input" class="hidden" accept="image/*" onchange="previewImage(this)">
                            </label>

                            <!-- Pole tekstowe -->
                            <textarea name="message" class="w-full bg-gray-900 border border-gray-600 rounded-lg p-3 text-white focus:border-yellow-500 focus:outline-none resize-none" rows="1" placeholder="Napisz wiadomość do legionu... (linki działają!)"></textarea>

                            <!-- Przycisk Wyślij -->
                            <button type="submit" class="bg-yellow-600 hover:bg-yellow-500 text-white px-6 py-3 rounded-lg font-bold transition flex items-center gap-2">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <script>
    // --- SKRYPTY JS (Front-End Logic) ---
    
    // 1. Zamiana linków na klikalne (Regex)
    function linkify(text) {
        var urlRegex =/(\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/ig;
        return text.replace(urlRegex, function(url) {
            return '<a href="' + url + '" target="_blank" class="link-highlight">' + url + '</a>';
        });
    }

    // 2. Pobieranie wiadomości (Polling)
    const currentUser = "<?= $currentUser ?>";
    let lastMsgCount = 0;
    const chatWindow = document.getElementById('chat-window');

    function fetchMessages() {
        if(!chatWindow) return; // Jeśli jesteśmy na ekranie logowania

        fetch('ironlegionchat.php?action=fetch_messages')
            .then(response => response.json())
            .then(data => {
                if (data.length === 0) {
                    chatWindow.innerHTML = '<div class="text-center text-gray-500 mt-10">Brak wiadomości. Bądź pierwszy!</div>';
                    return;
                }

                // Renderowanie tylko jeśli coś się zmieniło (prosta optymalizacja)
                // W wersji PRO sprawdzalibyśmy ID ostatniej wiadomości, tu upraszczamy dla DIY
                let html = '';
                data.forEach(msg => {
                    const isMe = msg.username === currentUser;
                    const date = new Date(msg.created_at).toLocaleString();
                    
                    html += `
                        <div class="flex flex-col ${isMe ? 'items-end' : 'items-start'} animate-fade-in">
                            <div class="text-xs text-gray-500 mb-1 px-1">
                                <span class="font-bold ${isMe ? 'text-yellow-500' : 'text-gray-300'}">${msg.username}</span> 
                                <span class="opacity-50">• ${date}</span>
                            </div>
                            <div class="msg-bubble p-3 rounded-xl shadow-md ${isMe ? 'bg-yellow-900/40 border border-yellow-700/50 text-white rounded-tr-none' : 'bg-gray-700 border border-gray-600 text-gray-100 rounded-tl-none'}">
                                ${msg.image_path ? `<a href="${msg.image_path}" target="_blank"><img src="${msg.image_path}" class="max-w-full h-auto rounded mb-2 border border-white/10 hover:opacity-90 transition"></a>` : ''}
                                ${msg.message ? `<p class="whitespace-pre-wrap break-words">${linkify(msg.message)}</p>` : ''}
                            </div>
                        </div>
                    `;
                });

                // Jeśli zmieniła się liczba wiadomości, odświeżamy HTML i scrollujemy
                // W produkcji lepiej używać append, ale dla prostoty nadpisujemy innerHTML
                if (chatWindow.innerHTML !== html) { 
                    const isScrolledToBottom = chatWindow.scrollHeight - chatWindow.scrollTop <= chatWindow.clientHeight + 100;
                    chatWindow.innerHTML = html;
                    if(isScrolledToBottom || lastMsgCount === 0) {
                        chatWindow.scrollTop = chatWindow.scrollHeight;
                    }
                    lastMsgCount = data.length;
                }
            })
            .catch(err => console.error('Błąd pobierania:', err));
    }

    // 3. Obsługa podglądu zdjęcia
    function previewImage(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('preview-img').src = e.target.result;
                document.getElementById('image-preview').classList.remove('hidden');
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function clearImage() {
        document.getElementById('image-input').value = "";
        document.getElementById('image-preview').classList.add('hidden');
    }

    // Uruchomienie
    if(chatWindow) {
        fetchMessages();
        setInterval(fetchMessages, 3000); // Odświeżanie co 3 sekundy
    }
    </script>
</body>
</html>