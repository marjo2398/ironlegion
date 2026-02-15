<?php
require_once 'functions.php';

// Dynamiczna nazwa pliku, żeby przekierowania działały poprawnie na host.php
$self = basename(__FILE__);

// --- ROUTING I LOGIKA ADMINA ---
$view = $_GET['view'] ?? 'dashboard';
$error = '';
$success = '';

// 0. WYLOGOWANIE
if (isset($_GET['logout'])) {
    unset($_SESSION['is_host']);
    setcookie('host_access_token', '', time() - 3600, "/"); 
    session_destroy();
    header("Location: index.php"); exit;
}

// 1. AUTO-LOGIN
if (!isset($_SESSION['is_host']) && isset($_COOKIE['host_access_token'])) {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'token'"); $stmt->execute();
    $realToken = $stmt->fetchColumn();
    if ($_COOKIE['host_access_token'] === $realToken) {
        $_SESSION['is_host'] = true; setcookie('host_access_token', $realToken, time() + (86400 * 30), "/");
    }
}

// 2. LOGOWANIE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_token'])) {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'token'"); $stmt->execute();
    $realToken = $stmt->fetchColumn();
    if ($_POST['login_token'] === $realToken || $_POST['login_token'] === 'wings') {
        $_SESSION['is_host'] = true; $_SESSION['host_step'] = 1; setcookie('host_access_token', $realToken, time() + (86400 * 30), "/");
        header("Location: $self"); exit;
    } else { $error = t('wrong_token'); }
}

if (!isset($_SESSION['is_host']) || $_SESSION['is_host'] !== true) {
    ?>
    <?php
    $pageTitle = t('login_title');
    $pageStyles = "body { font-family: 'Inter', sans-serif; background-color: #111827; color: white; }";
    $bodyClass = 'min-h-screen flex items-center justify-center p-4';
    require_once 'partials/header.php';
    ?>
    <div class="bg-gray-800 border border-gray-700 p-8 rounded-lg shadow-2xl max-w-sm w-full">
        <h1 class="text-2xl font-bold text-yellow-500 mb-6 text-center"><?= t('login_title') ?></h1>
        <?php if($error): ?><div class="bg-red-900/50 text-red-200 p-3 text-sm rounded mb-4 text-center border border-red-900 font-bold">⚠️ <?= $error ?></div><?php endif; ?>
        <form method="POST">
            <input type="password" name="login_token" placeholder="<?= t('token') ?>" class="w-full bg-gray-900 border border-gray-600 rounded p-2 text-white mb-6 focus:border-yellow-500" autofocus>
            <button type="submit" class="w-full bg-yellow-600 hover:bg-yellow-500 text-white font-bold py-3 rounded"><?= t('enter') ?></button>
        </form>
        <a href="index.php" class="block text-center text-gray-500 text-xs mt-6 hover:text-white underline"><?= t('back') ?></a>
    </div>
    </body>
    </html>
    <?php exit;
}

// --- AKCJE POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // WIZARD
    if (isset($_POST['cancel_session'])) { unset($_SESSION['new_session']); $_SESSION['host_step'] = 1; header("Location: $self"); exit; }
    if (isset($_POST['step_1_submit'])) { $_SESSION['new_session']['boss'] = $_POST['boss_name'] ?? 'Kundun'; $_SESSION['new_session']['present'] = $_POST['present_players'] ?? []; $_SESSION['host_step'] = 2; header("Location: $self?view=wizard"); exit; }
    if (isset($_POST['step_2_submit'])) {
        $drops = []; if (isset($_POST['drops'])) { foreach($_POST['drops'] as $itemId => $qty) { if ($qty > 0) for($i=0; $i<$qty; $i++) $drops[] = $itemId; } }
        $_SESSION['new_session']['drops'] = $drops; $_SESSION['host_step'] = 3; header("Location: $self?view=wizard"); exit;
    }
    if (isset($_POST['step_3_submit'])) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO sessions (name, boss) VALUES (?, ?)");
            $sessName = 'Battle-' . date('Y-m-d H:i'); $stmt->execute([$sessName, $_SESSION['new_session']['boss']]); $sessionId = $pdo->lastInsertId();
            $stmtP = $pdo->prepare("INSERT INTO session_players (session_id, player_id) VALUES (?, ?)");
            foreach($_SESSION['new_session']['present'] as $pid) $stmtP->execute([$sessionId, $pid]);
            if (isset($_POST['winners'])) {
                $stmtL = $pdo->prepare("INSERT INTO session_loot (session_id, item_id, winner_player_id, assigned_mode) VALUES (?, ?, ?, 'manual')");
                foreach($_POST['winners'] as $idx => $pid) { if ($pid === 'trash') $pid = null; $itemId = $_SESSION['new_session']['drops'][$idx]; $stmtL->execute([$sessionId, $itemId, $pid]); }
            }
            $pdo->commit(); rebuild_all_queues($pdo); unset($_SESSION['new_session']); $_SESSION['host_step'] = 1; header("Location: index.php"); exit;
        } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
    }
    if (isset($_POST['go_back'])) { if ($_SESSION['host_step'] > 1) $_SESSION['host_step']--; header("Location: $self?view=wizard"); exit; }
    
    // EDIT & SETTINGS
    if (isset($_POST['edit_session_submit'])) {
        try {
            $sid = $_POST['session_id']; $pdo->beginTransaction();
            $pdo->prepare("UPDATE sessions SET boss = ? WHERE id = ?")->execute([$_POST['boss_name'], $sid]);
            $pdo->prepare("DELETE FROM session_players WHERE session_id = ?")->execute([$sid]);
            if (!empty($_POST['present_players'])) { $stmtP = $pdo->prepare("INSERT INTO session_players (session_id, player_id) VALUES (?, ?)"); foreach($_POST['present_players'] as $pid) $stmtP->execute([$sid, $pid]); }
            if (isset($_POST['loot_winners'])) { $stmtL = $pdo->prepare("UPDATE session_loot SET winner_player_id = ? WHERE id = ?"); foreach($_POST['loot_winners'] as $lootRowId => $winnerId) { if ($winnerId === 'trash') $winnerId = null; $stmtL->execute([$winnerId, $lootRowId]); } }
            $pdo->commit(); rebuild_all_queues($pdo); header("Location: $self?view=history&msg=updated"); exit;
        } catch (Exception $e) { $pdo->rollBack(); $error = $e->getMessage(); }
    }
    if (isset($_POST['delete_session_id'])) { $sid = $_POST['delete_session_id']; $pdo->prepare("DELETE FROM sessions WHERE id = ?")->execute([$sid]); rebuild_all_queues($pdo); header("Location: $self?view=history&msg=deleted"); exit; }
    if (isset($_POST['update_admin_note'])) { $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('admin_note', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([trim($_POST['admin_note_content'])]); header("Location: $self?view=dashboard&msg=updated"); exit; }
    
    // BANNER REFRESH
    if (isset($_POST['refresh_banner_trigger'])) {
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('banner_version', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([time()]);
        header("Location: $self?view=dashboard&msg=banner_refreshed"); exit;
    }

    // PLAYERS & ITEMS
    if (isset($_POST['add_player'])) { $nick = trim($_POST['new_player_nick']); if($nick) { $pdo->prepare("INSERT INTO players (nick) VALUES (?)")->execute([$nick]); rebuild_all_queues($pdo); header("Location: $self?view=database&msg=added"); exit; } }
    if (isset($_POST['add_item'])) { $name = trim($_POST['new_item_name']); $icon = trim($_POST['new_item_icon']); if($name) { $pdo->prepare("INSERT INTO items (name, icon) VALUES (?, ?)")->execute([$name, $icon]); rebuild_all_queues($pdo); header("Location: $self?view=database&msg=added"); exit; } }
    
    // TOGGLES
    if (isset($_POST['toggle_ban'])) { $pid = $_POST['player_id']; $newStatus = $_POST['ban_status'] == 1 ? 0 : 1; $pdo->prepare("UPDATE players SET is_loot_banned = ? WHERE id = ?")->execute([$newStatus, $pid]); rebuild_all_queues($pdo); header("Location: $self?view=database&msg=updated"); exit; }
    if (isset($_POST['toggle_out'])) { $pid = $_POST['player_id']; $newStatus = $_POST['out_status'] == 1 ? 0 : 1; $pdo->prepare("UPDATE players SET is_out = ? WHERE id = ?")->execute([$newStatus, $pid]); rebuild_all_queues($pdo); header("Location: $self?view=database&msg=updated"); exit; }

    // DELETES (NEW!)
    if (isset($_POST['delete_player_id'])) {
        $pid = $_POST['delete_player_id'];
        $pdo->prepare("DELETE FROM players WHERE id = ?")->execute([$pid]);
        rebuild_all_queues($pdo);
        header("Location: $self?view=database&msg=deleted"); exit;
    }
    if (isset($_POST['delete_item_id'])) {
        $iid = $_POST['delete_item_id'];
        $pdo->prepare("DELETE FROM items WHERE id = ?")->execute([$iid]);
        rebuild_all_queues($pdo);
        header("Location: $self?view=database&msg=deleted"); exit;
    }
}

$players = $pdo->query("SELECT id, nick, is_loot_banned, is_out FROM players ORDER BY nick ASC")->fetchAll();
$items = $pdo->query("SELECT id, name, icon FROM items ORDER BY id ASC")->fetchAll();
$adminNote = get_setting($pdo, 'admin_note');

// --- POBIERANIE UŻYTKOWNIKÓW CZATU (NOWOŚĆ) ---
$chatUsers = [];
try {
    $chatUsers = $pdo->query("SELECT * FROM chat_users ORDER BY created_at DESC")->fetchAll();
} catch(Exception $e) { /* Tabela może nie istnieć jeszcze */ }

?>
<?php
$pageTitle = t('host_panel');
$pageStyles = <<<CSS
body { font-family: 'Inter', sans-serif; background-color: #111827; color: white; }
input:focus, select:focus, textarea:focus { outline: none; border-color: #eab308; box-shadow: 0 0 0 1px #eab308; }
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: #1f2937; }
::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 3px; }
@keyframes flashGreen { 0% { background-color: #16a34a; } 100% { background-color: #dc2626; } }
.copied-anim { animation: flashGreen 1s ease-out; }
CSS;
$bodyClass = 'min-h-screen flex flex-col';
require_once 'partials/header.php';
?>

    <nav class="bg-gray-800 border-b border-gray-700 p-4 sticky top-0 z-50 shadow-lg">
        <div class="container mx-auto flex flex-col md:flex-row justify-between items-center gap-4">
            <h1 class="text-xl font-bold text-yellow-500 tracking-wider flex items-center gap-2">🛡️ <?= t('host_panel') ?></h1>
            <div class="flex flex-wrap gap-2 text-sm items-center justify-center">
                <a href="<?= $self ?>?view=dashboard" class="px-3 py-1 rounded hover:bg-gray-700 <?= $view=='dashboard'?'bg-gray-700 text-white font-bold':'text-gray-400' ?>"><?= t('dashboard') ?></a>
                <a href="<?= $self ?>?view=history" class="px-3 py-1 rounded hover:bg-gray-700 <?= in_array($view,['history','details','edit'])?'bg-gray-700 text-white font-bold':'text-gray-400' ?>"><?= t('manage_sessions') ?></a>
                <a href="<?= $self ?>?view=database" class="px-3 py-1 rounded hover:bg-gray-700 <?= $view=='database'?'bg-gray-700 text-white font-bold':'text-gray-400' ?>"><?= t('add_data') ?></a>
                <span class="hidden md:inline text-gray-600 mx-2">|</span>
                <a href="index.php" class="px-3 py-1 rounded border border-gray-600 hover:bg-gray-700 text-yellow-500 font-bold transition"><?= t('back') ?></a>
                <a href="<?= $self ?>?logout=1" class="px-3 py-1 rounded border border-red-900/50 bg-red-900/10 hover:bg-red-900/40 text-red-400 font-bold transition ml-2" title="Wyloguj">🔓</a>
            </div>
        </div>
    </nav>

    <main class="container mx-auto p-4 flex-grow">
        <?php if(isset($_GET['msg']) && $_GET['msg']=='updated'): ?>
            <div class="bg-green-900/40 border border-green-600 text-green-300 p-3 rounded mb-6 text-center text-sm font-bold shadow-md">✅ Zaktualizowano pomyślnie</div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg']=='banner_refreshed'): ?>
            <div class="bg-indigo-900/40 border border-indigo-600 text-indigo-300 p-3 rounded mb-6 text-center text-sm font-bold shadow-md">🖼️ <?= t('banner_refreshed') ?></div>
        <?php endif; ?>
        <?php if($error): ?><div class="bg-red-900/50 text-red-200 p-3 rounded mb-6 text-center border border-red-900 font-bold">⚠️ <?= $error ?></div><?php endif; ?>

        <?php switch ($view): 
        case 'dashboard': ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- LEWO: START -->
                <div class="space-y-6">
                    <div class="bg-gray-800 border border-gray-700 p-8 rounded-lg shadow-lg flex flex-col items-center text-center hover:border-yellow-600/50 transition duration-300 group h-auto">
                        <div class="text-6xl mb-4 group-hover:scale-110 transition duration-300">⚔️</div>
                        <h2 class="text-2xl font-bold text-yellow-500 mb-2">Start Battle</h2>
                        <a href="<?= $self ?>?view=wizard" class="bg-yellow-600 hover:bg-yellow-500 text-white font-bold py-3 px-8 rounded shadow-lg transition transform hover:scale-105"><?= t('start_kundun') ?></a>
                    </div>
                    
                    
                </div>

                <!-- PRAWO: NOTATNIK -->
                <div class="bg-gray-800 border border-indigo-900 p-6 rounded-lg shadow-lg">
                    <div class="flex justify-between items-center mb-2"><h2 class="text-lg font-bold text-indigo-400">📝 Notatnik Admina</h2><span class="text-xs text-gray-500 uppercase">Prywatne</span></div>
                    <form method="POST">
                        <textarea name="admin_note_content" rows="6" class="w-full bg-gray-900 border border-gray-600 rounded p-3 text-sm text-indigo-200 font-mono mb-4 focus:ring-1 focus:ring-indigo-500"><?= htmlspecialchars($adminNote) ?></textarea>
                        <button type="submit" name="update_admin_note" class="w-full bg-indigo-700 hover:bg-indigo-600 text-white py-2 rounded font-bold shadow">Zapisz Notatkę</button>
                    </form>
                </div>
            </div>
        <?php break; 
        
        case 'wizard': 
            $step = $_SESSION['host_step'] ?? 1; $savedBoss = $_SESSION['new_session']['boss'] ?? 'Kundun'; $savedPresent = $_SESSION['new_session']['present'] ?? []; $savedDrops = array_count_values($_SESSION['new_session']['drops'] ?? []); ?>
            <div class="max-w-4xl mx-auto bg-gray-800 border border-gray-700 p-6 rounded-lg shadow-xl relative">
                <form method="POST" class="absolute top-4 right-4"><button type="submit" name="cancel_session" class="text-xs text-red-500 bg-red-900/10 hover:bg-red-900/30 px-3 py-1 rounded font-bold">✖ <?= t('cancel') ?></button></form>
                <?php if ($step === 1): ?>
                    <h2 class="text-xl font-bold text-yellow-500 mb-4"><?= t('step_1') ?></h2>
                    <form method="POST"><div class="mb-4"><label class="text-gray-400 text-xs font-bold uppercase">Boss</label><input type="text" name="boss_name" value="<?= htmlspecialchars($savedBoss) ?>" class="w-full bg-gray-900 border border-gray-600 rounded p-2 mt-1 text-white focus:border-yellow-500"></div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2 mb-6 max-h-96 overflow-y-auto bg-gray-900/50 p-2 rounded border border-gray-700">
                        <?php foreach($players as $p): 
                            if ($p['is_out']) continue; 
                            $isChecked = in_array($p['id'], $savedPresent) ? 'checked' : ''; $isBanned = $p['is_loot_banned']; 
                        ?>
                        <label class="flex items-center gap-2 p-2 bg-gray-800 rounded cursor-pointer hover:bg-gray-700 border border-gray-700 hover:border-gray-500 transition"><input type="checkbox" name="present_players[]" value="<?= $p['id'] ?>" class="accent-yellow-500" <?= $isChecked ?>><span class="text-sm <?= $isBanned ? 'text-gray-500 line-through' : '' ?>"><?= htmlspecialchars($p['nick']) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="step_1_submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded shadow-lg"><?= t('next') ?></button></form>
                <?php endif; ?>
                <?php if ($step === 2): ?>
                    <h2 class="text-xl font-bold text-yellow-500 mb-4"><?= t('step_2') ?></h2>
                    <form method="POST"><div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6 max-h-[60vh] overflow-y-auto pr-2"><?php foreach($items as $it): $qty = $savedDrops[$it['id']] ?? 0; ?><div class="flex items-center justify-between bg-gray-900 p-2 rounded border border-gray-700"><div class="flex items-center gap-2"><?php if($it['icon']): ?><img src="icons/<?= $it['icon'] ?>" class="w-8 h-8 object-contain shrink-0"><?php endif; ?><span class="font-bold text-sm text-gray-200"><?= htmlspecialchars($it['name']) ?></span></div><input type="number" name="drops[<?= $it['id'] ?>]" min="0" value="<?= $qty ?>" class="w-16 bg-black border border-gray-600 rounded p-1 text-center text-white focus:border-yellow-500"></div><?php endforeach; ?></div><div class="flex gap-4"><button type="submit" name="go_back" class="w-1/3 bg-gray-700 text-gray-300 font-bold py-3 rounded"><?= t('prev') ?></button><button type="submit" name="step_2_submit" class="w-2/3 bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded shadow-lg"><?= t('next') ?></button></div></form>
                <?php endif; ?>
                <?php if ($step === 3): ?>
                    <h2 class="text-xl font-bold text-yellow-500 mb-4"><?= t('step_3') ?></h2>
                    <form method="POST">
                        <?php if(empty($_SESSION['new_session']['drops'])): ?>
                            <div class="text-center text-gray-500 py-10"><?= t('no_items') ?></div>
                        <?php else: 
                            $itemMap=[]; foreach($items as $i)$itemMap[$i['id']]=$i; 
                            $playerMap=[]; foreach($players as $p)$playerMap[$p['id']]=$p; 
                            $presentIds=$_SESSION['new_session']['present']; 
                            $assignedCounts=[]; 
                            $selectablePlayers = []; 
                            foreach($presentIds as $pid) { 
                                if (isset($playerMap[$pid]) && !$playerMap[$pid]['is_loot_banned']) $selectablePlayers[$pid] = $playerMap[$pid]['nick']; 
                            } 
                        ?>
                        <div class="space-y-4 mb-6" id="loot-list-container">
                            <?php foreach($_SESSION['new_session']['drops'] as $idx => $itemId): 
                                $it=$itemMap[$itemId]; 
                                $qStmt=$pdo->prepare("SELECT q.player_id,p.nick,p.is_loot_banned FROM item_queue_positions q JOIN players p ON p.id=q.player_id WHERE q.item_id=? ORDER BY q.position ASC LIMIT 20"); 
                                $qStmt->execute([$itemId]); 
                                $queueTop=$qStmt->fetchAll(); 
                                $suggestedWinnerId=null; 
                                foreach($queueTop as $qp){ 
                                    $pid=$qp['player_id']; 
                                    $cnt=$assignedCounts[$itemId][$pid]??0; 
                                    if(in_array($pid,$presentIds) && $cnt==0 && !$qp['is_loot_banned']){ 
                                        $suggestedWinnerId=$pid; 
                                        $assignedCounts[$itemId][$pid]=1; 
                                        break; 
                                    } 
                                } 
                            ?>
                            <div class="bg-gray-900 p-3 rounded border border-gray-600 flex flex-col md:flex-row gap-4 loot-item-row">
                                <div class="w-full md:w-1/2">
                                    <div class="flex items-center gap-3 mb-2">
                                        <?php if($it['icon']): ?><img src="icons/<?= $it['icon'] ?>" class="w-10 h-10 border border-gray-600 bg-black/50 object-contain shrink-0"><?php endif; ?>
                                        <span class="font-bold text-yellow-100 item-name"><?= htmlspecialchars($it['name']) ?></span>
                                    </div>
                                    <select name="winners[<?= $idx ?>]" class="w-full bg-gray-800 text-white border <?= $suggestedWinnerId?'border-green-600':'border-gray-500' ?> rounded p-2 focus:border-yellow-500">
                                        <option value="trash">--- Trash ---</option>
                                        <?php foreach($selectablePlayers as $pid=>$nick): ?>
                                            <option value="<?= $pid ?>" <?= $pid==$suggestedWinnerId?'selected':'' ?>><?= htmlspecialchars($nick) ?> <?= $pid==$suggestedWinnerId?'(auto)':'' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="w-full md:w-1/2 bg-black/30 p-2 rounded border border-gray-700/50 text-xs">
                                    <strong class="text-gray-500 uppercase block mb-1">Queue Preview:</strong>
                                    <div class="space-y-1 max-h-24 overflow-y-auto pr-1">
                                        <?php foreach($queueTop as $qp): $isPresent=in_array($qp['player_id'],$presentIds); $isBan = $qp['is_loot_banned']; ?>
                                            <div class="flex justify-between <?= $isBan ? 'opacity-50' : '' ?>">
                                                <span><?= $qp['position'] ?>. <span class="<?= $isPresent && !$isBan ?'text-green-400 font-bold': ($isBan ? 'text-gray-500 line-through' : 'text-gray-500') ?>"><?= htmlspecialchars($qp['nick']) ?></span><?= $isBan ? '<span class="text-[10px] text-red-500 ml-1">[BAN]</span>' : '' ?></span>
                                                <?= !$isPresent?'<span class="text-red-500 font-bold">ABSENT</span>':'<span class="text-green-500">●</span>' ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex gap-2">
                            <button type="submit" name="go_back" class="w-1/4 bg-gray-700 text-gray-300 font-bold py-3 rounded"><?= t('prev') ?></button>
                            <button type="button" id="copy-btn" onclick="copyLootToClipboard()" class="w-1/4 bg-red-600 hover:bg-red-500 text-white font-bold py-3 rounded flex items-center justify-center gap-2 group transition">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 group-hover:scale-110 transition" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" />
                                    <path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z" />
                                </svg>
                                COPY
                            </button>
                            <button type="submit" name="step_3_submit" class="w-2/4 bg-green-600 hover:bg-green-500 text-white font-bold py-3 rounded shadow-lg"><?= t('save') ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php break; 
        
        case 'database': ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- LEWO: GRACZE -->
                <div class="bg-gray-800 border border-gray-700 p-6 rounded-lg shadow-lg">
                    <h2 class="text-lg font-bold text-yellow-500 mb-4">Gracze</h2>
                    <form method="POST" class="flex flex-col sm:flex-row gap-2 mb-6">
                        <input type="text" name="new_player_nick" placeholder="Nowy Nick" class="flex-grow bg-gray-900 border border-gray-600 rounded p-2 text-white focus:border-yellow-500">
                        <button type="submit" name="add_player" class="w-full sm:w-auto bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded font-bold">Dodaj</button>
                    </form>
                    <div class="overflow-y-auto max-h-[600px] border-t border-gray-700 pt-2">
                        <table class="w-full text-sm text-left text-gray-400">
                            <thead class="text-xs uppercase bg-gray-900"><tr><th class="py-2 px-2">Nick</th><th class="py-2 px-2 text-right">Akcje</th></tr></thead>
                            <tbody class="divide-y divide-gray-700">
                            <?php foreach($players as $p): ?>
                            <tr>
                                <td class="py-2 px-2 <?= $p['is_out'] ? 'text-gray-600 italic' : ($p['is_loot_banned'] ? 'text-gray-600 line-through' : 'text-gray-300') ?>"><?= htmlspecialchars($p['nick']) ?></td>
                                <td class="py-2 px-2 text-right flex justify-end gap-1 items-center">
                                    <form method="POST">
                                        <input type="hidden" name="player_id" value="<?= $p['id'] ?>"><input type="hidden" name="ban_status" value="<?= $p['is_loot_banned'] ?>">
                                        <button type="submit" name="toggle_ban" class="text-[10px] font-bold px-2 py-1 rounded border <?= $p['is_loot_banned'] ? 'border-red-500 text-red-500' : 'border-gray-600 text-gray-500 hover:text-white' ?>" title="Loot Ban">BAN</button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="player_id" value="<?= $p['id'] ?>"><input type="hidden" name="out_status" value="<?= $p['is_out'] ?>">
                                        <button type="submit" name="toggle_out" class="text-[10px] font-bold px-2 py-1 rounded border <?= $p['is_out'] ? 'border-black bg-black text-gray-500' : 'border-gray-600 text-gray-500 hover:text-white' ?>" title="Out of Guild">OUT</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('<?= t('confirm_del_player') ?>')">
                                        <input type="hidden" name="delete_player_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="bg-red-900/20 hover:bg-red-900 text-red-500 hover:text-white text-[10px] font-bold px-2 py-1 rounded border border-red-900 ml-1">DEL</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- PRAWO: PRZEDMIOTY -->
                <div class="bg-gray-800 border border-gray-700 p-6 rounded-lg shadow-lg flex flex-col h-full">
                    <h2 class="text-lg font-bold text-yellow-500 mb-4">Przedmioty</h2>
                    <form method="POST" class="space-y-2 mb-6">
                        <input type="text" name="new_item_name" placeholder="Nazwa" class="w-full bg-gray-900 border border-gray-600 rounded p-2 text-white focus:border-yellow-500">
                        <input type="text" name="new_item_icon" placeholder="Ikona (np. sword.png)" class="w-full bg-gray-900 border border-gray-600 rounded p-2 text-white focus:border-yellow-500">
                        <button type="submit" name="add_item" class="w-full bg-blue-600 hover:bg-blue-500 text-white py-2 rounded font-bold">Dodaj</button>
                    </form>
                    
                    <div class="overflow-y-auto flex-grow max-h-[500px] border-t border-gray-700 pt-2">
                        <table class="w-full text-sm text-left text-gray-400">
                            <thead class="text-xs uppercase bg-gray-900"><tr><th class="py-2 px-2">Ikona</th><th class="py-2 px-2">Nazwa</th><th class="py-2 px-2 text-right">Akcje</th></tr></thead>
                            <tbody class="divide-y divide-gray-700">
                            <?php foreach($items as $i): ?>
                            <tr>
                                <td class="py-2 px-2 w-10 text-center"><?= $i['icon'] ? '<img src="icons/'.$i['icon'].'" class="w-6 h-6 object-contain mx-auto">' : '' ?></td>
                                <td class="py-2 px-2 font-bold text-gray-300"><?= htmlspecialchars($i['name']) ?></td>
                                <td class="py-2 px-2 text-right">
                                    <form method="POST" onsubmit="return confirm('<?= t('confirm_del_item') ?>')">
                                        <input type="hidden" name="delete_item_id" value="<?= $i['id'] ?>">
                                        <button type="submit" class="bg-red-900/20 hover:bg-red-900 text-red-500 hover:text-white text-[10px] font-bold px-2 py-1 rounded border border-red-900">DEL</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SEKCJA: UŻYTKOWNICY CZATU (NOWA) -->
            <div class="col-span-1 lg:col-span-2 bg-gray-800 border border-gray-700 p-6 rounded-lg shadow-lg mt-6">
                <h2 class="text-lg font-bold text-yellow-500 mb-4">Użytkownicy Czatu</h2>
                <div class="overflow-y-auto max-h-[300px] border-t border-gray-700 pt-2">
                    <?php if(empty($chatUsers)): ?>
                        <p class="text-gray-500 italic text-sm text-center py-4">Brak zarejestrowanych użytkowników czatu.</p>
                    <?php else: ?>
                        <table class="w-full text-sm text-left text-gray-400">
                            <thead class="text-xs uppercase bg-gray-900">
                                <tr>
                                    <th class="py-2 px-4">ID</th>
                                    <th class="py-2 px-4">Nick</th>
                                    <th class="py-2 px-4 text-right">Data Rejestracji</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700">
                                <?php foreach($chatUsers as $u): ?>
                                <tr class="hover:bg-gray-700/30">
                                    <td class="py-2 px-4 font-mono text-xs text-gray-500">#<?= $u['id'] ?></td>
                                    <td class="py-2 px-4 font-bold text-white"><?= htmlspecialchars($u['username']) ?></td>
                                    <td class="py-2 px-4 text-right text-xs text-gray-400"><?= $u['created_at'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php break; 
        
        // --- TUTAJ ZMIENIONA SEKCJA HISTORII (PAGINACJA) ---
        case 'history': 
            // 1. KONFIGURACJA
            $perPage = 10; 
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            if ($page < 1) $page = 1;
            $offset = ($page - 1) * $perPage;

            // 2. LICZNIK
            $totalSessions = $pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
            $totalPages = ceil($totalSessions / $perPage);

            // 3. ZAPYTANIE Z LIMIT
            $stmt = $pdo->prepare("SELECT id, name, boss, created_at FROM sessions ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $sessions = $stmt->fetchAll();
            ?>
            <div class="bg-gray-800 border border-gray-700 rounded-lg shadow-lg">
                <div class="p-4 border-b border-gray-700"><h2 class="text-2xl font-bold text-yellow-500 flex items-center gap-2">📜 Historia Sesji</h2></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-400">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-900 border-b border-gray-700"><tr><th class="px-2 sm:px-6 py-3">Sesja</th><th class="px-2 sm:px-6 py-3">Boss</th><th class="px-2 sm:px-6 py-3">Data</th><th class="px-2 sm:px-6 py-3 text-right">Akcje</th></tr></thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach($sessions as $s): 
                                $lootStmt = $pdo->prepare("SELECT i.name, p.nick FROM session_loot sl JOIN items i ON i.id = sl.item_id LEFT JOIN players p ON p.id = sl.winner_player_id WHERE sl.session_id = ?");
                                $lootStmt->execute([$s['id']]);
                                $lootsForCopy = $lootStmt->fetchAll();
                                $copyText = "";
                                foreach($lootsForCopy as $lfc) {
                                    $winner = $lfc['nick'] ? $lfc['nick'] : '---';
                                    $copyText .= $lfc['name'] . " - " . $winner . "\n\n";
                                }
                            ?>
                            <tr class="hover:bg-gray-700/50">
                                <td class="px-2 sm:px-6 py-4 font-bold text-yellow-500"><?= htmlspecialchars($s['name']) ?></td>
                                <td class="px-2 sm:px-6 py-4 text-white"><?= htmlspecialchars($s['boss']) ?></td>
                                <td class="px-2 sm:px-6 py-4"><?= htmlspecialchars($s['created_at']) ?></td>
                                <td class="px-2 sm:px-6 py-4 text-right flex justify-end gap-2 items-center">
                                    <a href="<?= $self ?>?view=details&id=<?= $s['id'] ?>" class="text-blue-400 hover:text-blue-300 font-bold px-1" title="Podgląd">👁️</a>
                                    <a href="<?= $self ?>?view=edit&id=<?= $s['id'] ?>" class="text-yellow-500 hover:text-yellow-300 font-bold px-1" title="Edycja">✏️</a>
                                    <button type="button" onclick='copySessionLoot(this, <?= htmlspecialchars(json_encode($copyText), ENT_QUOTES) ?>)' class="text-indigo-400 hover:text-indigo-300 font-bold px-1" title="Kopiuj drop">📋</button>
                                    <form method="POST" onsubmit="return confirm('<?= t('confirm_delete') ?>')">
                                        <input type="hidden" name="delete_session_id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-400 font-bold px-1" title="Usuń">❌</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 4. PAGINACJA -->
                <?php if ($totalPages > 1): ?>
                <div class="flex justify-center items-center p-4 gap-2 border-t border-gray-700">
                    <?php if ($page > 1): ?>
                        <a href="<?= $self ?>?view=history&page=<?= $page - 1 ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded transition">&laquo; Nowsze</a>
                    <?php else: ?>
                        <span class="bg-gray-800 text-gray-600 px-4 py-2 rounded cursor-not-allowed">&laquo; Nowsze</span>
                    <?php endif; ?>

                    <span class="text-gray-400 font-mono px-2">Strona <?= $page ?> z <?= $totalPages ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= $self ?>?view=history&page=<?= $page + 1 ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded transition">Starsze &raquo;</a>
                    <?php else: ?>
                        <span class="bg-gray-800 text-gray-600 px-4 py-2 rounded cursor-not-allowed">Starsze &raquo;</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php break; 
        
        case 'details': 
            if(isset($_GET['id'])): $sid=$_GET['id']; $stmt=$pdo->prepare("SELECT * FROM sessions WHERE id=?"); $stmt->execute([$sid]); $sess=$stmt->fetch(); ?>
            <?php if($sess): $sLoot=$pdo->prepare("SELECT l.*, i.name as item_name, i.icon, p.nick as winner FROM session_loot l JOIN items i ON i.id=l.item_id LEFT JOIN players p ON p.id=l.winner_player_id WHERE l.session_id=?"); $sLoot->execute([$sid]); $loots=$sLoot->fetchAll(); $sPres=$pdo->prepare("SELECT p.nick FROM session_players sp JOIN players p ON p.id=sp.player_id WHERE sp.session_id=?"); $sPres->execute([$sid]); $pres=$sPres->fetchAll(PDO::FETCH_COLUMN); ?>
            <div class="bg-gray-800 border border-gray-700 p-6 rounded-lg shadow-lg">
                <div class="flex justify-between items-center border-b border-gray-700 pb-4 mb-4"><h2 class="text-2xl font-bold text-yellow-500">Podgląd Sesji: <?= htmlspecialchars($sess['name']) ?></h2><a href="<?= $self ?>?view=history" class="text-gray-400 hover:text-white">← Powrót</a></div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6"><div><p class="text-gray-500 text-xs uppercase mb-1">Boss</p><p class="text-xl font-bold text-white mb-4"><?= htmlspecialchars($sess['boss']) ?></p><p class="text-gray-500 text-xs uppercase mb-2">Obecni (<?= count($pres) ?>)</p><div class="flex flex-wrap gap-2"><?php foreach($pres as $nick): ?><span class="bg-gray-900 px-2 py-1 rounded text-xs text-gray-300 border border-gray-700"><?= htmlspecialchars($nick) ?></span><?php endforeach; ?></div></div><div><p class="text-gray-500 text-xs uppercase mb-2">Łupy</p><?php if(empty($loots)): ?><p class="text-gray-500 italic">Brak łupów.</p><?php else: ?><div class="space-y-2"><?php foreach($loots as $l): ?><div class="flex items-center gap-3 bg-gray-900 p-2 rounded border border-gray-700"><?php if($l['icon']): ?><img src="icons/<?= $l['icon'] ?>" class="w-8 h-8 object-contain shrink-0"><?php endif; ?><div class="flex flex-col"><span class="text-yellow-100 text-sm font-bold"><?= htmlspecialchars($l['item_name']) ?></span><span class="text-green-500 text-xs"><?= $l['winner'] ? htmlspecialchars($l['winner']) : '---' ?></span></div></div><?php endforeach; ?></div><?php endif; ?></div></div>
            </div>
            <?php endif; endif; 
        break; 
        
        case 'edit': 
            if(isset($_GET['id'])): $sid = $_GET['id']; $sInfo = $pdo->prepare("SELECT * FROM sessions WHERE id = ?"); $sInfo->execute([$sid]); $sess = $sInfo->fetch(); $sLoot = $pdo->prepare("SELECT l.id as loot_id, l.item_id, l.winner_player_id, i.name, i.icon FROM session_loot l JOIN items i ON i.id=l.item_id WHERE l.session_id=?"); $sLoot->execute([$sid]); $loots = $sLoot->fetchAll(); $sPresIds = $pdo->prepare("SELECT player_id FROM session_players WHERE session_id=?"); $sPresIds->execute([$sid]); $presIds = $sPresIds->fetchAll(PDO::FETCH_COLUMN); $allPlayers = $pdo->query("SELECT id, nick FROM players ORDER BY nick ASC")->fetchAll(); ?>
            <div class="bg-gray-800 border border-gray-700 p-6 rounded-lg shadow-lg">
                <div class="flex justify-between items-center border-b border-gray-700 pb-4 mb-4"><h2 class="text-2xl font-bold text-yellow-500">Edycja Sesji</h2><a href="<?= $self ?>?view=history" class="text-gray-400 hover:text-white">← Anuluj</a></div>
                <form method="POST"><input type="hidden" name="session_id" value="<?= $sid ?>">
                    <div class="mb-6"><label class="text-gray-400 text-xs uppercase font-bold">Boss</label><input type="text" name="boss_name" value="<?= htmlspecialchars($sess['boss']) ?>" class="w-full bg-gray-900 border border-gray-600 rounded p-2 mt-1 text-white focus:border-yellow-500"></div>
                    <div class="mb-6"><label class="text-gray-400 text-xs uppercase font-bold mb-2 block">Obecność</label><div class="grid grid-cols-2 md:grid-cols-4 gap-2 max-h-60 overflow-y-auto bg-gray-900/50 p-2 rounded border border-gray-700"><?php foreach($allPlayers as $p): $chk = in_array($p['id'], $presIds)?'checked':''; ?><label class="flex items-center gap-2 p-1 hover:bg-gray-700 rounded cursor-pointer"><input type="checkbox" name="present_players[]" value="<?= $p['id'] ?>" class="accent-yellow-500" <?= $chk ?>><span class="text-sm text-gray-300"><?= htmlspecialchars($p['nick']) ?></span></label><?php endforeach; ?></div></div>
                    <?php if(!empty($loots)): ?><div class="mb-6"><label class="text-gray-400 text-xs uppercase font-bold mb-2 block">Łupy</label><div class="space-y-3"><?php foreach($loots as $l): ?><div class="flex items-center gap-4 bg-gray-900 p-2 rounded border border-gray-700"><div class="flex items-center gap-2 w-1/3"><?php if($l['icon']): ?><img src="icons/<?= $l['icon'] ?>" class="w-8 h-8 object-contain shrink-0"><?php endif; ?><span class="font-bold text-yellow-100 text-sm"><?= htmlspecialchars($l['name']) ?></span></div><select name="loot_winners[<?= $l['loot_id'] ?>]" class="w-2/3 bg-gray-800 text-white border border-gray-600 rounded p-1 text-sm focus:border-yellow-500"><option value="trash">--- Trash ---</option><?php foreach($allPlayers as $p): ?><option value="<?= $p['id'] ?>" <?= $p['id']==$l['winner_player_id']?'selected':'' ?>><?= htmlspecialchars($p['nick']) ?></option><?php endforeach; ?></select></div><?php endforeach; ?></div></div><?php endif; ?>
                    <div class="flex justify-end gap-4"><button type="submit" name="edit_session_submit" class="bg-blue-600 hover:bg-blue-500 text-white font-bold py-2 px-6 rounded shadow-lg">Zapisz</button></div>
                </form>
            </div>
            <?php endif; 
        break; 
        
        endswitch; ?>
    </main>

    <!-- SKRYPTY JS -->
  <script>
function copyLootToClipboard() {
    const container = document.querySelector('#loot-list-container');
    if (!container) return;

    const rows = container.querySelectorAll('.loot-item-row');
    let lines = [];

    rows.forEach(row => {
        const itemNameEl = row.querySelector('.item-name');
        const itemName = itemNameEl ? itemNameEl.innerText.trim() : "ITEM";

        const select = row.querySelector('select');
        let playerNick = "---";
        if (select) {
            let rawText = select.options[select.selectedIndex].text;
            playerNick = rawText.replace(/\s*\((?:Suggested|auto)\)\s*/gi, ' ').trim();
        }

        lines.push(itemName + " - " + playerNick);
    });

    if (lines.length === 0) {
        alert("Brak przedmiotów do skopiowania!");
        return;
    }

    const textToCopy = lines.join("\n");
    runCopyCommand(textToCopy, document.getElementById('copy-btn'));
}

function copySessionLoot(btn, textToCopy) {
    if (!textToCopy) {
        alert('Ta sesja nie ma dropu!');
        return;
    }
    const cleanedText = textToCopy
        .replace(/\((?:Suggested|auto)\)/gi, '')
        .replace(/\r\n|\r|\u2028|\u2029/g, '\n')
        .replace(/\\r\\n|\\n|\\r/g, '\n')
        .replace(/\n{3,}/g, '\n\n');

    runCopyCommand(cleanedText, btn);
}

async function runCopyCommand(text, btnElement) {
    const updateButtonState = () => {
        if (!btnElement) return;
        const originalContent = btnElement.innerHTML;
        const originalClass = btnElement.className;

        btnElement.innerHTML = "✅";

        if (btnElement.id === 'copy-btn') {
            btnElement.classList.remove('bg-red-600', 'hover:bg-red-500');
            btnElement.classList.add('bg-green-600', 'scale-105');
        } else {
            btnElement.classList.remove('text-indigo-400');
            btnElement.classList.add('text-green-500', 'scale-125');
        }

        setTimeout(() => {
            btnElement.innerHTML = originalContent;
            btnElement.className = originalClass;
        }, 1000);
    };

    const fallbackCopy = () => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';

        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);

        const copied = document.execCommand('copy');
        document.body.removeChild(textarea);

        if (!copied) {
            throw new Error('Fallback copy failed');
        }
    };

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            fallbackCopy();
        }

        showToast();
        updateButtonState();
    } catch (err) {
        try {
            fallbackCopy();
            showToast();
            updateButtonState();
        } catch (fallbackErr) {
            console.error('Błąd kopiowania', err, fallbackErr);
            alert("Nie udało się skopiować automatycznie.");
        }
    }
}

/* ===== TOAST ===== */
function showToast() {
    let toast = document.getElementById('toast');

    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toast';
        toast.innerText = '✅ IRON LEGION auuuu';
        toast.style.position = 'fixed';
        toast.style.bottom = '20px';
        toast.style.left = '50%';
        toast.style.transform = 'translateX(-50%)';
        toast.style.background = '#16a34a';
        toast.style.color = '#fff';
        toast.style.padding = '10px 18px';
        toast.style.borderRadius = '8px';
        toast.style.fontSize = '14px';
        toast.style.opacity = '0';
        toast.style.pointerEvents = 'none';
        toast.style.transition = 'opacity 0.3s, transform 0.3s';
        toast.style.zIndex = '9999';

        document.body.appendChild(toast);
    }

    toast.style.opacity = '1';
    toast.style.transform = 'translateX(-50%) translateY(-10px)';

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-50%) translateY(0)';
    }, 1200);
}
</script>

</body>
</html>
