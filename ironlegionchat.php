<?php
require_once 'functions.php';

// --- KLUCZOWE: USTAWIENIE CIASTECZKA OSTATNIEJ WIZYTY ---
setcookie('chat_last_visit', date('Y-m-d H:i:s'), time() + (86400 * 30), "/");

// --- AUTO-DETEKCJA PLIKU ---
$self = basename(__FILE__);

// --- OBSŁUGA JĘZYKA ---
$lang = set_language(['pl', 'en', 'ru'], 'en');

// --- KONFIGURACJA ---
$uploadDir = 'uploads/';
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

// --- 1. TABELE BAZY DANYCH ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT,
        image_path VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_edited TINYINT(1) DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES chat_users(id) ON DELETE CASCADE
    )");
    try { $pdo->exec("ALTER TABLE chat_messages ADD COLUMN is_edited TINYINT(1) DEFAULT 0"); } catch(Exception $e){}
} catch (PDOException $e) { die("Błąd bazy: " . $e->getMessage()); }

// --- 2. LOGIKA EDYCJI I KASOWANIA (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['chat_user_id'])) {
    
    // KASOWANIE
    if (isset($_POST['action']) && $_POST['action'] === 'delete_msg') {
        $msgId = (int)$_POST['msg_id'];
        $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE id = ? AND user_id = ?");
        $stmt->execute([$msgId, $_SESSION['chat_user_id']]);
        echo json_encode(['status' => 'deleted']);
        exit;
    }

    // EDYCJA
    if (isset($_POST['action']) && $_POST['action'] === 'edit_msg') {
        $msgId = (int)$_POST['msg_id'];
        $newText = trim($_POST['message']);
        if (!empty($newText)) {
            $stmt = $pdo->prepare("UPDATE chat_messages SET message = ?, is_edited = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([htmlspecialchars($newText), $msgId, $_SESSION['chat_user_id']]);
        }
        echo json_encode(['status' => 'edited']);
        exit;
    }
}

// --- 3. API WIADOMOŚCI (AJAX) ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_messages') {
    header('Content-Type: application/json');
    $stmt = $pdo->query("
        SELECT * FROM (
            SELECT m.*, u.username 
            FROM chat_messages m 
            JOIN chat_users u ON m.user_id = u.id 
            ORDER BY m.created_at DESC 
            LIMIT 50
        ) sub ORDER BY created_at ASC
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// --- 4. AUTH & WYSYŁANIE ---
$authError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];
    if ($_POST['auth_action'] === 'register') {
        $stmt = $pdo->prepare("SELECT id FROM chat_users WHERE username = ?");
        $stmt->execute([$user]);
        if ($stmt->fetch()) $authError = "Nick zajęty!";
        else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO chat_users (username, password) VALUES (?, ?)");
            if ($stmt->execute([$user, $hash])) {
                $_SESSION['chat_user_id'] = $pdo->lastInsertId();
                $_SESSION['chat_username'] = $user;
                header("Location: " . $self); exit;
            }
        }
    } elseif ($_POST['auth_action'] === 'login') {
        $stmt = $pdo->prepare("SELECT id, password FROM chat_users WHERE username = ?");
        $stmt->execute([$user]);
        $userData = $stmt->fetch();
        if ($userData && password_verify($pass, $userData['password'])) {
            $_SESSION['chat_user_id'] = $userData['id'];
            $_SESSION['chat_username'] = $user;
            header("Location: " . $self); exit;
        } else $authError = "Błędny login!";
    }
}

if (isset($_GET['logout'])) { unset($_SESSION['chat_user_id'], $_SESSION['chat_username']); header("Location: " . $self); exit; }

// --- OBSŁUGA WYSYŁANIA WIADOMOŚCI (NORMALNY POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_action']) && isset($_SESSION['chat_user_id'])) {
    $msg = trim($_POST['message']);
    $imgPath = null;
    
    // Obsługa uploadu
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

    // Zapisz tylko jeśli jest treść LUB zdjęcie
    if (!empty($msg) || $imgPath) {
        $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, message, image_path) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['chat_user_id'], htmlspecialchars($msg), $imgPath]);
    }
    header("Location: " . $self); exit;
}

$bannerVer = get_setting($pdo, 'banner_version') ?? time();
$currentUser = $_SESSION['chat_username'] ?? null;
$currentUserId = $_SESSION['chat_user_id'] ?? 0;
?>
<?php
$pageTitle = 'Iron Legion CHAT';
$pageStyles = <<<CSS
body { font-family: 'Inter', sans-serif; background-color: #111827; color: #e5e7eb; overscroll-behavior: none; }
.msg-bubble { max-width: 85%; word-break: break-word; position: relative; }
.custom-scroll::-webkit-scrollbar { width: 4px; }
.custom-scroll::-webkit-scrollbar-track { background: #1f2937; }
.custom-scroll::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 2px; }
.link-highlight { color: #eab308; text-decoration: underline; }
.mobile-h-screen { height: 100vh; height: 100dvh; }
.chat-thumb {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #4b5563;
    transition: transform 0.2s, border-color 0.2s;
    cursor: pointer;
}
.chat-thumb:hover { border-color: #eab308; opacity: 0.9; }
.emoji-btn { font-size: 1.4rem; padding: 6px; cursor: pointer; transition: transform 0.1s; }
.emoji-btn:hover { transform: scale(1.2); }
#lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 100; justify-content: center; align-items: center; }
#lightbox img { max-width: 95%; max-height: 95vh; border-radius: 8px; box-shadow: 0 0 20px rgba(234, 179, 8, 0.3); }
.trans-menu { z-index: 50; min-width: 100px; }
.trans-result-box {
    position: absolute;
    top: 0;
    z-index: 40;
    font-size: 0.8rem;
    background: #1f2937;
    border: 1px solid #eab308;
    padding: 8px;
    border-radius: 6px;
    color: #d1d5db;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
    min-width: 180px;
    max-width: 60vw;
}
@media (min-width: 768px) {
    .trans-result-box.left-side { right: 100%; margin-right: 12px; }
    .trans-result-box.right-side { left: 100%; margin-left: 12px; }
}
@media (max-width: 767px) {
    .trans-result-box {
        position: relative;
        width: 100%;
        max-width: 100%;
        margin-top: 4px;
        right: auto !important;
        left: auto !important;
        border-color: #4b5563;
    }
}
CSS;
$bodyClass = 'mobile-h-screen flex flex-col overflow-hidden';
$viewport = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no';
$pageHeadExtra = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
require_once 'partials/header.php';
?>

    <!-- Lightbox -->
    <div id="lightbox" onclick="closeLightbox()">
        <img id="lightbox-img" src="">
        <button class="absolute top-4 right-4 text-white text-4xl font-bold">&times;</button>
    </div>

    <!-- Baner -->
    <div class="w-full bg-black h-20 md:h-28 shrink-0 relative overflow-hidden border-b border-gray-800">
        <div class="absolute inset-0 bg-gradient-to-t from-gray-900 to-transparent z-10"></div>
        <img src="icons/baner.png?v=<?= $bannerVer ?>" alt="Banner" class="w-full h-full object-cover object-center opacity-80">
        <div class="absolute bottom-1 left-4 z-20">
            <h1 class="text-lg md:text-xl font-bold text-white tracking-wide shadow-black drop-shadow-md">
                IRON LEGION <span class="text-yellow-500">CHAT</span>
            </h1>
        </div>
    </div>

    <!-- Pasek Nawigacji -->
    <nav class="bg-gray-800 border-b border-gray-700 shrink-0 shadow-md z-20">
        <div class="container mx-auto px-4 py-2 flex justify-between items-center">
            
            <div class="flex gap-4">
                <a href="index.php" class="text-gray-300 hover:text-white flex items-center gap-2 text-sm font-bold transition">
                    <i class="fas fa-arrow-left"></i> <span class="hidden sm:inline"><?= t('back') ?? 'Back' ?></span>
                </a>
                
                <a href="host.php" class="text-yellow-600 hover:text-yellow-400 flex items-center gap-2 text-sm font-bold transition">
                    <i class="fas fa-hammer"></i> <span class="hidden sm:inline"><?= t('start_kundun') ?? 'Host' ?></span>
                </a>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex bg-slate-700/50 rounded p-1 mr-2">
                    <a href="?lang=pl" class="px-2 text-[10px] font-bold <?= $lang=='pl'?'text-yellow-400':'text-gray-400' ?>">PL</a>
                    <a href="?lang=en" class="px-2 text-[10px] font-bold <?= $lang=='en'?'text-yellow-400':'text-gray-400' ?>">EN</a>
                    <a href="?lang=ru" class="px-2 text-[10px] font-bold <?= $lang=='ru'?'text-yellow-400':'text-gray-400' ?>">RU</a>
                </div>

                <?php if ($currentUser): ?>
                    <span class="text-yellow-500 font-bold text-sm hidden sm:inline"><?= htmlspecialchars($currentUser) ?></span>
                    <a href="?logout=1" class="text-red-400 hover:text-red-300 ml-1" title="Wyloguj">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Główna zawartość -->
    <main class="flex-grow flex flex-col relative overflow-hidden w-full max-w-5xl mx-auto md:px-4 md:py-2">
        
        <?php if (!$currentUser): ?>
            <!-- LOGOWANIE -->
            <div class="flex-grow flex items-center justify-center p-4">
                <div class="w-full max-w-sm bg-gray-800 border border-gray-700 rounded-xl p-6 shadow-2xl">
                    <h2 class="text-xl font-bold text-center mb-4 text-white">Identyfikacja</h2>
                    <?php if($authError): ?>
                        <div class="bg-red-900/50 border border-red-500 text-red-200 p-2 rounded mb-4 text-center text-xs"><?= $authError ?></div>
                    <?php endif; ?>
                    <form method="POST" action="<?= $self ?>" class="space-y-3">
                        <input type="text" name="username" placeholder="Twój Nick" required class="w-full bg-gray-900 border border-gray-600 rounded p-2 text-white text-sm focus:border-yellow-500 outline-none">
                        <input type="password" name="password" placeholder="Hasło" required class="w-full bg-gray-900 border border-gray-600 rounded p-2 text-white text-sm focus:border-yellow-500 outline-none">
                        <button type="submit" name="auth_action" value="login" class="w-full bg-yellow-600 hover:bg-yellow-500 text-white font-bold py-2 rounded transition text-sm">Zaloguj</button>
                        <div class="text-center pt-2">
                            <button type="submit" name="auth_action" value="register" class="text-gray-500 hover:text-gray-300 text-xs underline">Utwórz konto</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- CZAT -->
            <div class="flex flex-col h-full bg-gray-800 md:rounded-xl shadow-2xl overflow-hidden border-x border-gray-700 md:border-y">
                
                <div id="chat-window" class="flex-grow p-4 overflow-y-auto custom-scroll space-y-4 bg-gray-900/90">
                    <div class="flex items-center justify-center h-full">
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-yellow-500"></div>
                    </div>
                </div>

                <div id="emoji-bar" class="hidden bg-gray-700 px-2 py-1 flex gap-2 overflow-x-auto border-t border-gray-600">
                    <span class="emoji-btn" onclick="addEmoji('⚔️')">⚔️</span>
                    <span class="emoji-btn" onclick="addEmoji('🛡️')">🛡️</span>
                    <span class="emoji-btn" onclick="addEmoji('💎')">💎</span>
                    <span class="emoji-btn" onclick="addEmoji('😂')">😂</span>
                    <span class="emoji-btn" onclick="addEmoji('👍')">👍</span>
                    <span class="emoji-btn" onclick="addEmoji('👑')">👑</span>
                    <span class="emoji-btn" onclick="addEmoji('🍺')">🍺</span>
                    <span class="emoji-btn" onclick="addEmoji('💀')">💀</span>
                    <span class="emoji-btn" onclick="addEmoji('🐉')">🐉</span>
                    <span class="emoji-btn" onclick="addEmoji('🔥')">🔥</span>
                </div>

                <div class="bg-gray-800 p-2 border-t border-gray-700 shrink-0 z-30 relative">
                    <div id="edit-bar" class="hidden absolute -top-8 left-0 right-0 bg-yellow-900/95 text-yellow-100 px-4 py-1 text-xs flex justify-between items-center border-t border-yellow-600">
                        <span><i class="fas fa-pen mr-1"></i> Edytujesz wiadomość...</span>
                        <button type="button" onclick="cancelEdit()" class="text-white font-bold hover:text-red-400">&times; Anuluj</button>
                    </div>

                    <form id="chat-form" method="POST" action="<?= $self ?>" enctype="multipart/form-data" class="flex flex-col gap-2">
                        <input type="hidden" name="message_action" value="1">
                        
                        <div id="image-preview" class="hidden relative w-fit mx-auto md:mx-0 pb-1">
                            <img id="preview-img" src="" class="h-12 rounded border border-yellow-500">
                            <button type="button" onclick="clearImage()" class="absolute -top-2 -right-2 bg-red-600 text-white rounded-full w-4 h-4 flex items-center justify-center text-[10px] font-bold">&times;</button>
                        </div>

                        <div class="flex items-center gap-2 w-full">
                            <label class="shrink-0 w-10 h-10 flex items-center justify-center bg-gray-700 rounded-full hover:bg-gray-600 border border-gray-600 cursor-pointer text-gray-300 hover:text-yellow-400">
                                <i class="fas fa-camera"></i>
                                <input type="file" name="image" id="image-input" class="hidden" accept="image/*" onchange="previewImage(this)">
                            </label>

                            <button type="button" onclick="toggleEmojis()" class="shrink-0 w-10 h-10 flex items-center justify-center bg-gray-700 rounded-full hover:bg-gray-600 border border-gray-600 text-yellow-400">
                                <i class="far fa-smile"></i>
                            </button>

                            <!-- Dodano min-w-0 aby na Androidzie input nie rozpychał się -->
                            <input type="text" id="msg-input" name="message" class="flex-grow bg-gray-900 border border-gray-600 rounded-full px-4 py-2 text-white text-sm focus:border-yellow-500 outline-none min-w-0" placeholder="Nadaj coś..." autocomplete="off">

                            <button type="submit" id="send-btn" class="shrink-0 w-10 h-10 bg-yellow-600 hover:bg-yellow-500 text-white rounded-full font-bold shadow-lg shadow-yellow-600/20 flex items-center justify-center">
                                <i class="fas fa-paper-plane text-xs"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
    const currentFile = "<?= $self ?>";
    const currentUserId = <?= $currentUserId ?>;
    let lastMsgCount = 0;
    let editingId = null;
    let activeTranslation = { id: null, text: null, lang: null };

    const chatWindow = document.getElementById('chat-window');
    const msgInput = document.getElementById('msg-input');
    const chatForm = document.getElementById('chat-form');
    const sendBtn = document.getElementById('send-btn');

    // Obsługa Formularza (Wysyłanie)
    chatForm.addEventListener('submit', function(e) {
        // Jeśli nie edytujemy, to jest to normalne wysyłanie.
        // JS nie powinien blokować chyba że jest walidacja.
        if (!editingId) {
            // Zmień ikonę na kręciołek
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            sendBtn.classList.add('opacity-50', 'cursor-not-allowed');
            // Formularz wyśle się sam (nie robimy preventDefault)
        }
    });

    function openLightbox(src) {
        document.getElementById('lightbox-img').src = src;
        document.getElementById('lightbox').style.display = 'flex';
    }
    function closeLightbox() {
        document.getElementById('lightbox').style.display = 'none';
    }

    function deleteMsg(id) {
        if(!confirm('Usunąć wiadomość?')) return;
        fetch(currentFile, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=delete_msg&msg_id=${id}`
        }).then(() => fetchMessages());
    }

    function editMsg(id, text) {
        editingId = id;
        msgInput.value = text;
        msgInput.focus();
        document.getElementById('edit-bar').classList.remove('hidden');
        sendBtn.innerHTML = '<i class="fas fa-check"></i>';
        
        // Nadpisz wysyłanie formularza na AJAX
        chatForm.onsubmit = function(e) {
            e.preventDefault();
            fetch(currentFile, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=edit_msg&msg_id=${id}&message=${encodeURIComponent(msgInput.value)}`
            }).then(() => {
                cancelEdit();
                fetchMessages();
            });
        };
    }

    function cancelEdit() {
        editingId = null;
        msgInput.value = '';
        document.getElementById('edit-bar').classList.add('hidden');
        sendBtn.innerHTML = '<i class="fas fa-paper-plane text-xs"></i>';
        // Usuń nadpisanie submit - wróć do domyślnego zachowania
        chatForm.onsubmit = null; 
    }

    // --- TŁUMACZENIE ---
    function toggleTransMenu(menuId) {
        const menu = document.getElementById(menuId);
        // Najpierw zamknij inne
        document.querySelectorAll('.trans-menu').forEach(el => {
            if(el.id !== menuId) el.classList.add('hidden');
        });
        menu.classList.toggle('hidden');
    }

    function closeTranslation() {
        activeTranslation = { id: null, text: null, lang: null };
        document.querySelectorAll('.trans-result-box').forEach(el => el.classList.add('hidden'));
    }

    function doTranslate(msgId, targetLang) {
        document.getElementById('trans-menu-' + msgId).classList.add('hidden');
        activeTranslation = { id: msgId, text: 'Tłumaczenie...', lang: targetLang };
        
        updateTranslationBox(msgId, '<span class="text-yellow-500 animate-pulse">Szukam...</span>');

        const textElement = document.getElementById('msg-text-' + msgId);
        if(!textElement) return;
        let text = textElement.innerText;

        const apiUrl = `https://api.mymemory.translated.net/get?q=${encodeURIComponent(text)}&langpair=autodetect|${targetLang}`;

        fetch(apiUrl)
            .then(response => response.json())
            .then(data => {
                if(data.responseData && data.responseData.translatedText) {
                    const transText = data.responseData.translatedText;
                    activeTranslation.text = transText;
                    renderTranslation(msgId, transText, targetLang);
                } else throw new Error();
            })
            .catch(() => {
                const url = `https://translate.google.com/?sl=auto&tl=${targetLang}&text=${encodeURIComponent(text)}&op=translate`;
                const html = `<div class="text-red-400">Błąd. <a href="${url}" target="_blank" class="underline text-yellow-500">Google</a></div>`;
                activeTranslation.text = html;
                updateTranslationBox(msgId, html);
            });
    }

    function renderTranslation(msgId, text, lang) {
        const html = `
            <div class="flex justify-between items-start">
                <span><strong class="text-yellow-600 text-[10px] uppercase">${lang}:</strong> ${text}</span>
                <button onclick="closeTranslation()" class="ml-2 text-gray-500 hover:text-red-400 font-bold">&times;</button>
            </div>
        `;
        updateTranslationBox(msgId, html);
    }

    function updateTranslationBox(msgId, htmlContent) {
        const box = document.getElementById('trans-result-' + msgId);
        if(box) {
            box.classList.remove('hidden');
            box.innerHTML = htmlContent;
        }
    }

    // Nowa logika zamykania - mniej agresywna
    window.onclick = function(event) {
        // Jeśli kliknięto wewnątrz menu lub tłumaczenia - nic nie rób
        if (event.target.closest('.trans-menu') || event.target.closest('.trans-result-box')) return;
        
        // Jeśli kliknięto przycisk otwierania menu - nic nie rób (obsługuje to toggle)
        if (event.target.closest('button[onclick^="toggleTransMenu"]')) return;

        // Jeśli kliknięto w tło chatu (ale nie w dymek) - zamknij wszystko
        if (!event.target.closest('.msg-bubble')) {
            document.querySelectorAll('.trans-menu').forEach(el => el.classList.add('hidden'));
            // closeTranslation(); // Opcjonalnie: odkomentuj jeśli chcesz żeby tłumaczenie znikało po kliknięciu w tło
        } else {
             // Jeśli kliknięto w dymek, zamknij tylko menu, zostaw tłumaczenie
             document.querySelectorAll('.trans-menu').forEach(el => el.classList.add('hidden'));
        }
    }

    function fetchMessages() {
        if(!chatWindow || editingId) return; 

        fetch(currentFile + '?action=fetch_messages')
            .then(response => response.json())
            .then(data => {
                if (data.length === 0) {
                    chatWindow.innerHTML = '<div class="text-center text-gray-500 mt-10 text-xs">Cisza na kanale...</div>';
                    return;
                }

                let html = '';
                data.forEach(msg => {
                    const isMe = parseInt(msg.user_id) === currentUserId;
                    const d = new Date(msg.created_at);
                    const time = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                    const editedTag = msg.is_edited == 1 ? '<span class="text-[9px] opacity-50 ml-1 text-yellow-300">(edyt.)</span>' : '';
                    
                    const userActions = isMe ? `
                        <span class="ml-2 opacity-50 hover:opacity-100 transition">
                            <button onclick="editMsg(${msg.id}, '${msg.message.replace(/'/g, "\\'")}')" class="text-gray-400 hover:text-yellow-400 text-xs px-1"><i class="fas fa-pen"></i></button>
                            <button onclick="deleteMsg(${msg.id})" class="text-gray-400 hover:text-red-400 text-xs px-1"><i class="fas fa-trash"></i></button>
                        </span>
                    ` : '';

                    const transMenuId = `trans-menu-${msg.id}`;
                    // Menu tłumaczenia teraz jest widoczne tylko przy dymkach z tekstem
                    const transSection = msg.message ? `
                        <div class="relative flex flex-col justify-end pb-1 shrink-0">
                            <button onclick="toggleTransMenu('${transMenuId}')" class="text-gray-600 hover:text-yellow-500 transition text-lg px-2 p-2">
                                <i class="fas fa-language"></i>
                            </button>
                            <div id="${transMenuId}" class="trans-menu hidden absolute bottom-8 ${isMe ? 'right-0' : 'left-0'} bg-gray-800 border border-gray-600 rounded shadow-xl flex flex-col py-1 overflow-hidden min-w-[120px]">
                                <button onclick="doTranslate(${msg.id}, 'pl')" class="text-left px-3 py-2 text-xs hover:bg-gray-700 text-gray-300 border-b border-gray-700/50">🇵🇱 Polski</button>
                                <button onclick="doTranslate(${msg.id}, 'en')" class="text-left px-3 py-2 text-xs hover:bg-gray-700 text-gray-300 border-b border-gray-700/50">🇬🇧 English</button>
                                <button onclick="doTranslate(${msg.id}, 'de')" class="text-left px-3 py-2 text-xs hover:bg-gray-700 text-gray-300 border-b border-gray-700/50">🇩🇪 Deutsch</button>
                                <button onclick="doTranslate(${msg.id}, 'ru')" class="text-left px-3 py-2 text-xs hover:bg-gray-700 text-gray-300 border-b border-gray-700/50">🇷🇺 Русский</button>
                                <button onclick="doTranslate(${msg.id}, 'es')" class="text-left px-3 py-2 text-xs hover:bg-gray-700 text-gray-300">🇪🇸 Español</button>
                            </div>
                        </div>
                    ` : '';

                    let transBoxHtml = '';
                    let transBoxClass = 'hidden';
                    if (activeTranslation.id === msg.id) {
                        transBoxClass = '';
                        transBoxHtml = activeTranslation.text ? 
                            (activeTranslation.text.includes('Błąd') ? activeTranslation.text : 
                            `<div class="flex justify-between items-start">
                                <span><strong class="text-yellow-600 text-[10px] uppercase">${activeTranslation.lang}:</strong> ${activeTranslation.text}</span>
                                <button onclick="closeTranslation()" class="ml-2 text-gray-500 hover:text-red-400 font-bold">&times;</button>
                            </div>`) 
                            : '<span class="text-yellow-500 animate-pulse">Szukam...</span>';
                    }

                    const sideClass = isMe ? 'left-side' : 'right-side';

                    html += `
                        <div class="flex flex-col ${isMe ? 'items-end' : 'items-start'} mb-4 animate-fade-in group relative max-w-full">
                            <div class="px-1 mb-1 flex items-center">
                                <span class="text-base font-bold ${isMe ? 'text-yellow-500' : 'text-yellow-600'} drop-shadow-sm">${msg.username}</span> 
                                ${userActions}
                            </div>
                            
                            <div class="flex gap-1 ${isMe ? 'flex-row' : 'flex-row-reverse'} max-w-full relative">
                                ${transSection}
                                <div class="flex flex-col max-w-[85%] relative">
                                    <div class="msg-bubble px-4 py-3 rounded-2xl shadow-lg text-sm w-full ${isMe ? 'bg-yellow-800/80 text-white rounded-tr-none border border-yellow-700' : 'bg-gray-700/90 text-gray-200 rounded-tl-none border border-gray-600'}">
                                        ${msg.image_path ? `<img src="${msg.image_path}" class="chat-thumb mb-2 bg-black/50" onclick="openLightbox('${msg.image_path}')">` : ''}
                                        ${msg.message ? `<p id="msg-text-${msg.id}" class="whitespace-pre-wrap leading-relaxed">${linkify(msg.message)}</p>` : ''}
                                        <div class="text-[10px] opacity-60 text-right mt-1 -mb-1 flex justify-end items-center relative">
                                            ${editedTag} ${time}
                                        </div>
                                    </div>
                                    <div id="trans-result-${msg.id}" class="trans-result-box ${transBoxClass} ${sideClass} animate-fade-in">
                                        ${transBoxHtml}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });

                if (chatWindow.innerHTML !== html) { 
                    const isScrolledToBottom = chatWindow.scrollHeight - chatWindow.scrollTop <= chatWindow.clientHeight + 200;
                    chatWindow.innerHTML = html;
                    if(isScrolledToBottom || lastMsgCount === 0) chatWindow.scrollTop = chatWindow.scrollHeight;
                    lastMsgCount = data.length;
                }
            })
            .catch(err => console.error(err));
    }

    function toggleEmojis() { document.getElementById('emoji-bar').classList.toggle('hidden'); }
    function addEmoji(emoji) { msgInput.value += emoji + ' '; msgInput.focus(); }
    
    function linkify(text) {
        var urlRegex =/(\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/ig;
        return text.replace(urlRegex, function(url) {
            return '<a href="' + url + '" target="_blank" class="link-highlight break-all">' + url + '</a>';
        });
    }

    function previewImage(input) {
        // --- NOWOŚĆ: Sprawdzanie rozmiaru ---
        if (input.files && input.files[0]) {
            const file = input.files[0];
            // Limit 5MB (w bajtach)
            if(file.size > 5 * 1024 * 1024) {
                alert("Mordeczko, za duży plik! Max 5MB. Panda nie udźwignie. 🐼");
                input.value = ""; // Wyczyść input
                document.getElementById('image-preview').classList.add('hidden');
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('preview-img').src = e.target.result;
                document.getElementById('image-preview').classList.remove('hidden');
            }
            reader.readAsDataURL(file);
        }
    }
    function clearImage() {
        document.getElementById('image-input').value = "";
        document.getElementById('image-preview').classList.add('hidden');
    }

    if(chatWindow) { fetchMessages(); setInterval(fetchMessages, 3000); }
    </script>
</body>
</html>
