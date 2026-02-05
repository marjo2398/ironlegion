<?php
require_once 'functions.php';

// Upewniamy się, że $lang istnieje
if (!isset($lang)) {
    $lang = 'en';
}

// Logika danych
$items = $pdo->query("SELECT id, name, icon FROM items ORDER BY id ASC")->fetchAll();
$queuesByItem = [];
$playersBanned = [];
$pStmt = $pdo->query("SELECT id, is_loot_banned FROM players");
while($row = $pStmt->fetch()) {
    $playersBanned[$row['id']] = $row['is_loot_banned'];
}

if ($items) {
    $rows = $pdo->query("SELECT q.item_id, q.position, p.nick, p.id as pid FROM item_queue_positions q JOIN players p ON p.id = q.player_id ORDER BY q.item_id ASC, q.position ASC")->fetchAll();
    foreach ($rows as $r) $queuesByItem[$r['item_id']][] = $r;
}

$lastSession = null;
$sessionData = $pdo->query("SELECT * FROM sessions ORDER BY created_at DESC, id DESC LIMIT 1")->fetch();
if ($sessionData) {
    $lootData = $pdo->prepare("SELECT i.name as item_name, i.icon, p.nick as winner FROM session_loot sl JOIN items i ON i.id = sl.item_id LEFT JOIN players p ON p.id = sl.winner_player_id WHERE sl.session_id = ?");
    $lootData->execute([$sessionData['id']]);
    $presentData = $pdo->prepare("SELECT p.nick FROM session_players sp JOIN players p ON p.id = sp.player_id WHERE sp.session_id = ? ORDER BY p.nick ASC");
    $presentData->execute([$sessionData['id']]);
    $lastSession = ['info' => $sessionData, 'loot' => $lootData->fetchAll(), 'present' => $presentData->fetchAll(PDO::FETCH_COLUMN)];
}

// ==========================================================
// SYMULACJA ZMIAN POZYCJI (DELTA) - SILNIK V19
// ==========================================================
$deltas = []; 
if ($sessionData) {
    $allP = $pdo->query("SELECT id, is_out, is_loot_banned FROM players")->fetchAll(PDO::FETCH_ASSOC);
    $allI = $pdo->query("SELECT id FROM items")->fetchAll(PDO::FETCH_COLUMN);
    $allSess = $pdo->query("SELECT id FROM sessions ORDER BY created_at ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
    
    $pMap = []; foreach($allP as $p) $pMap[$p['id']] = $p;
    $sLootMap = []; $stmt = $pdo->query("SELECT session_id, item_id, winner_player_id FROM session_loot"); while($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $sLootMap[$r['session_id']][$r['item_id']][] = $r['winner_player_id']; }
    $sPresMap = []; $stmt = $pdo->query("SELECT session_id, player_id FROM session_players"); while($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $sPresMap[$r['session_id']][$r['player_id']] = true; }
    $simQueues = []; $allPIds = array_column($allP, 'id'); foreach($allI as $iid) $simQueues[$iid] = $allPIds;
    $lastSid = $sessionData['id'];
    foreach ($allSess as $sid) {
        $isLastSession = ($sid == $lastSid);
        if ($isLastSession) {
            foreach ($simQueues as $iid => $q) {
                $rank = 1;
                foreach ($q as $pid) {
                    if (!($pMap[$pid]['is_out'] ?? false)) {
                        $deltas[$iid][$pid] = $rank;
                        $rank++;
                    }
                }
            }
        }
        $presentSet = $sPresMap[$sid] ?? []; $droppedItems = isset($sLootMap[$sid]) ? array_keys($sLootMap[$sid]) : [];
        foreach ($simQueues as $itemId => &$queue) {
            foreach ($presentSet as $pid => $true) { if (!in_array($pid, $queue)) $queue[] = $pid; }
            if (!in_array($itemId, $droppedItems)) continue;
            $winners = $sLootMap[$sid][$itemId] ?? []; $winnersSet = []; foreach($winners as $w) { if($w) $winnersSet[$w] = true; }
            $n = count($queue); for ($i = 1; $i < $n; $i++) { $pid = $queue[$i]; $prev = $queue[$i-1]; if (isset($winnersSet[$prev])) continue; if (isset($presentSet[$pid]) && !isset($presentSet[$prev])) { $queue[$i] = $prev; $queue[$i-1] = $pid; } }
            if (!empty($winnersSet)) { foreach($winnersSet as $winId => $true) { $queue = array_values(array_diff($queue, [$winId])); $queue[] = $winId; } }
        }
        unset($queue);
    }
    foreach ($simQueues as $iid => $q) {
        $rank = 1;
        foreach ($q as $pid) {
            if (!($pMap[$pid]['is_out'] ?? false)) {
                $oldRank = $deltas[$iid][$pid] ?? $rank;
                $deltas[$iid][$pid] = $oldRank - $rank; 
                $rank++;
            }
        }
    }
}

// --- LOGIKA POWIADOMIEŃ CZATU ---
$chatNotify = 0;
// Jeśli tabela istnieje, sprawdź nowe wiadomości
try {
    // Sprawdzamy ciasteczko
    $lastVisit = $_COOKIE['chat_last_visit'] ?? date('Y-m-d H:i:s', strtotime('-1 day'));
    
    // Liczymy wiadomości nowsze niż ostatnia wizyta
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE created_at > ?");
    $stmt->execute([$lastVisit]);
    $chatNotify = $stmt->fetchColumn();
} catch(Exception $e) {}


// --- STATYSTYKI LOOTU ---
$statsPlayers = [];
$playersData = $pdo->query("SELECT id, nick, is_out FROM players ORDER BY nick ASC")->fetchAll();
$allLoot = $pdo->query("SELECT winner_player_id, item_id, COUNT(*) as cnt FROM session_loot WHERE winner_player_id IS NOT NULL GROUP BY winner_player_id, item_id")->fetchAll();
$lootCounts = [];
foreach($allLoot as $r) {
    $lootCounts[$r['winner_player_id']][$r['item_id']] = $r['cnt'];
}
foreach ($playersData as $p) {
    $total = 0; $pItems = [];
    foreach ($items as $it) {
        $c = $lootCounts[$p['id']][$it['id']] ?? 0;
        $pItems[$it['id']] = $c;
        $total += $c;
    }
    $statsPlayers[] = ['nick' => $p['nick'], 'items' => $pItems, 'total' => $total, 'is_out' => $p['is_out']];
}
usort($statsPlayers, function($a, $b) { return $b['total'] <=> $a['total']; });


// --- SYSTEM OBECNOŚCI ---
$presenceStats = [];
$allSessStmt = $pdo->query("SELECT id FROM sessions ORDER BY created_at ASC, id ASC");
$allSessionIds = $allSessStmt->fetchAll(PDO::FETCH_COLUMN);

if (!empty($allSessionIds)) {
    $totalGlobalSess = count($allSessionIds);
    $presQuery = "SELECT p.nick, p.is_out, COUNT(sp.session_id) as c, MIN(sp.session_id) as first_sid 
                  FROM players p 
                  LEFT JOIN session_players sp ON p.id = sp.player_id 
                  GROUP BY p.id, p.nick, p.is_out";
    $presData = $pdo->query($presQuery)->fetchAll();

    foreach($presData as $pd) {
        $firstSid = $pd['first_sid'];
        $attendanceCount = $pd['c'];
        
        if ($firstSid) {
            $possibleSessions = 0;
            foreach ($allSessionIds as $sid) {
                if ($sid >= $firstSid) {
                    $possibleSessions++;
                }
            }
        } else {
            $possibleSessions = $totalGlobalSess;
        }

        $pct = ($possibleSessions > 0) ? round(($attendanceCount / $possibleSessions) * 100, 1) : 0;
        
        $presenceStats[] = [
            'nick'       => $pd['nick'], 
            'count'      => $attendanceCount, 
            'total_sess' => $possibleSessions,
            'pct'        => $pct, 
            'is_out'     => $pd['is_out']
        ];
    }
    
    usort($presenceStats, function($a, $b) {
        if ($b['pct'] == $a['pct']) {
            return $b['count'] <=> $a['count'];
        }
        return $b['pct'] <=> $a['pct'];
    });
}

$bannerVer = get_setting($pdo, 'banner_version') ?? time();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #111827; color: #e5e7eb; }
        .card { background-color: #1f2937; border: 1px solid #374151; }
        table { width: 100%; border-collapse: collapse; }
        .table-header { background-color: #111827; color: #9ca3af; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; cursor: pointer; user-select: none; }
        .table-header:hover { background-color: #374151; color: white; }
        .table-row { border-bottom: 1px solid #374151; }
        .table-row:last-child { border-bottom: none; }
        td, th { vertical-align: middle; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #1f2937; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 3px; }
        th.sort-asc::after { content: " ▲"; font-size: 0.7em; }
        th.sort-desc::after { content: " ▼"; font-size: 0.7em; }
        @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); } 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); } }
        .notify-badge { animation: pulse-red 2s infinite; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <div class="w-full bg-black">
        <img src="icons/baner.png?v=<?= $bannerVer ?>" alt="Banner" class="block w-full shadow-2xl" style="width: 100%; height: auto; display: block;">
    </div>

    <nav class="bg-gray-800 border-b border-gray-700 sticky top-0 z-50 shadow-md">
        <div class="container mx-auto px-4 py-3 flex flex-wrap justify-between items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white tracking-wide"><?= t('title') ?></h1>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-sm font-semibold flex gap-2">
                    <a href="?lang=en" class="<?= $lang=='en'?'text-yellow-400':'text-gray-400 hover:text-white' ?>">EN</a>
                    <span class="text-gray-600">|</span>
                    <a href="?lang=pl" class="<?= $lang=='pl'?'text-yellow-400':'text-gray-400 hover:text-white' ?>">PL</a>
                    <span class="text-gray-600">|</span>
                    <a href="?lang=ru" class="<?= $lang=='ru'?'text-yellow-400':'text-gray-400 hover:text-white' ?>">RU</a>
                </div>
                <a href="rules.php" class="text-sm text-gray-400 hover:text-yellow-400 uppercase font-bold tracking-wide mr-2 transition"><?= t('rules') ?></a>
                
                <a href="host.php" class="bg-yellow-600 hover:bg-yellow-500 text-white text-sm font-bold py-2 px-4 rounded shadow transition border border-yellow-500 hover:border-yellow-400">
                    <?= t('start_kundun') ?>
                </a>
            </div>
        </div>
    </nav>

    <main class="container mx-auto px-4 py-8 space-y-10 flex-grow">
            <!-- PRZYCISK CZATU - Z LICZNIKIEM (POPRAWIONY LINK) -->
            <div class="flex justify-center mb-8 relative">
                <a href="ironlegionchat.php" class="inline-block bg-gray-700 hover:bg-gray-600 transition-all transform hover:scale-102 border-2 border-red-600 rounded-xl px-10 py-3 shadow-lg relative">
                    <span class="text-white text-1xl uppercase tracking-[0.15em] font-medium">IRON LEGION CHAT</span>
                    <?php if($chatNotify > 0): ?>
                        <div class="absolute -top-3 -right-3 bg-red-600 text-white text-xs font-bold w-8 h-8 flex items-center justify-center rounded-full border-2 border-gray-900 notify-badge z-10">
                            <?= $chatNotify > 99 ? '99+' : $chatNotify ?>
                        </div>
                    <?php endif; ?>
                </a>
            </div>
        
        <?php if($lastSession): ?>
        <section class="card rounded-lg p-6 shadow-lg">
            <div class="flex justify-between items-center mb-6 border-b border-gray-700 pb-2">
                <div class="flex items-center gap-4">
                    <h2 class="text-xl font-bold text-yellow-500"><?= t('last_battle') ?></h2>
                    <a href="oral.php" class="bg-gray-800 hover:bg-gray-700 text-white-400 hover:text-white font-bold py-1.5 px-4 rounded border border-red-600 transition text-medium uppercase">ALL BATTLES</a>
                </div>
                <div class="text-right">
                                    <span class="block text-white font-bold text-lg"><?= htmlspecialchars($lastSession['info']['boss']) ?></span>
                    <span class="text-gray-500 text-xs"><?= $lastSession['info']['created_at'] ?></span>
                </div>
            </div>
            
            <div class="mb-6">
                <p class="text-gray-400 text-sm uppercase mb-3"><?= t('dropped_items') ?></p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <?php if(empty($lastSession['loot'])): ?><span class="text-gray-500 italic">-</span><?php else: foreach($lastSession['loot'] as $loot): ?>
                    <div class="bg-gray-900 p-2 rounded border border-gray-700 flex items-center gap-3">
                        <div class="w-10 h-10 flex items-center justify-center bg-black/50 rounded shrink-0">
                            <?php if($loot['icon']): ?><img src="icons/<?= htmlspecialchars($loot['icon']) ?>" class="max-w-full max-h-full object-contain"><?php endif; ?>
                        </div>
                        <div class="flex flex-col"><span class="text-yellow-100 text-sm font-bold"><?= htmlspecialchars($loot['item_name']) ?></span><span class="text-green-500 text-xs"><?= $loot['winner'] ? htmlspecialchars($loot['winner']) : '---' ?></span></div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="border-t border-gray-700 pt-4">
                <p class="text-gray-400 text-sm uppercase mb-2">Obecni (<?= count($lastSession['present']) ?>)</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach($lastSession['present'] as $pNick): ?>
                        <span class="text-xs bg-gray-800 border border-gray-600 px-3 py-1 rounded-full text-gray-300 shadow-sm"><?= htmlspecialchars($pNick) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section>
            <h2 class="text-2xl font-bold text-white mb-6 pl-2 border-l-4 border-yellow-600"><?= t('queues') ?></h2>
           <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-5 items-start">
                <?php foreach ($items as $item): $iid = $item['id']; $queue = $queuesByItem[$iid] ?? []; ?>
               <div class="card rounded-lg hover:border-yellow-600 transition duration-300">
                    <div class="bg-gray-800 p-3 border-b border-gray-700 flex items-center gap-3">
                        <div class="w-10 h-10 bg-gray-900 rounded border border-gray-600 flex items-center justify-center shrink-0">
                            <?php if(!empty($item['icon'])): ?><img src="icons/<?= htmlspecialchars($item['icon']) ?>" class="w-8 h-8 object-contain p-1"><?php endif; ?>
                        </div>
                        <h3 class="font-bold text-yellow-500 truncate"><?= htmlspecialchars($item['name']) ?></h3>
                    </div>
                    <div class="p-0 max-h-[70vh] overflow-y-auto custom-scroll">
                        <ul class="divide-y divide-gray-700">
                            <?php foreach($queue as $row): $isBanned = $playersBanned[$row['pid']] ?? 0; $delta = $deltas[$iid][$row['pid']] ?? 0; ?>
                            <li class="px-4 py-2 text-sm flex items-center gap-2 <?= $row['position'] == 1 && !$isBanned ? 'bg-yellow-900/10' : '' ?> <?= $isBanned ? 'opacity-40 grayscale' : '' ?>">
                                <span class="text-gray-500 w-4"><?= $row['position'] ?>.</span>
                                <div class="flex-grow flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <?php if($row['position'] == 1 && !$isBanned): ?><span class="w-2 h-2 rounded-full bg-green-500 shrink-0"></span><span class="text-white font-bold"><?= htmlspecialchars($row['nick']) ?></span>
                                        <?php else: ?><span class="w-2 h-2 rounded-full <?= $isBanned ? 'bg-red-900' : 'bg-gray-600' ?> shrink-0"></span><span class="<?= $isBanned ? 'text-gray-500 line-through' : 'text-gray-400' ?>"><?= htmlspecialchars($row['nick']) ?></span><?php endif; ?>
                                    </div>
                                    <?php if ($delta != 0 && !$isBanned): ?><span class="text-[10px] font-bold <?= $delta > 0 ? 'text-green-500' : 'text-red-500' ?>"><?= $delta > 0 ? '▲' : '▼' ?> <?= abs($delta) ?></span><?php endif; ?>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-3 gap-8 mt-12">
            <div class="xl:col-span-2 card rounded-lg p-5 overflow-x-auto">
                <h2 class="text-lg font-bold text-white mb-4"><?= t('loot_history') ?></h2>
                <table class="w-full text-sm text-left table-fixed" id="lootTable">
                    <thead><tr class="table-header"><th class="p-2 w-32" onclick="sortTable(0)">Name</th><?php $col=1; foreach ($items as $item): ?><th class="p-2 text-center w-12" onclick="sortTable(<?= $col++ ?>)"><?php if($item['icon']): ?><img src="icons/<?= htmlspecialchars($item['icon']) ?>" class="w-5 h-5 mx-auto opacity-70 pointer-events-none object-contain"><?php else: ?><?= mb_substr($item['name'],0,3) ?><?php endif; ?></th><?php endforeach; ?><th class="p-2 text-right text-yellow-500 w-16" onclick="sortTable(<?= $col ?>)"><?= t('total') ?></th></tr></thead>
                    <tbody>
                        <?php foreach($statsPlayers as $row): ?>
                        <tr class="table-row hover:bg-gray-800"><td class="p-2 font-bold <?= $row['is_out'] ? 'text-gray-600 italic' : 'text-gray-300' ?> truncate"><?= htmlspecialchars($row['nick']) ?></td><?php foreach ($items as $item): $cnt = $row['items'][$item['id']]; ?><td class="p-2 text-center <?= $cnt > 0 ? 'text-yellow-500 font-bold' : 'text-gray-700' ?>"><?= $cnt > 0 ? $cnt : '-' ?></td><?php endforeach; ?><td class="p-2 text-right font-bold text-white"><?= $row['total'] ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card rounded-lg p-5">
                <h2 class="text-lg font-bold text-white mb-4"><?= t('attendance') ?></h2>
                <div class="overflow-y-auto max-h-[500px] custom-scroll">
                    <table class="w-full text-sm" id="attendanceTable">
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach($presenceStats as $p): ?>
                            <tr class="hover:bg-gray-800">
                                <td class="py-3 px-2">
                                    <div class="flex justify-between mb-1"><span class="<?= $p['is_out'] ? 'text-gray-600 italic' : 'text-gray-300 font-semibold' ?>"><?= htmlspecialchars($p['nick']) ?></span><div class="text-right"><span class="text-gray-500 text-xs mr-2 font-mono">(<?= $p['count'] ?> / <?= $p['total_sess'] ?>)</span><span class="text-green-400 text-xs"><?= $p['pct'] ?>%</span></div></div>
                                    <div class="w-full bg-gray-900 rounded-full h-1.5"><div class="<?= $p['is_out'] ? 'bg-gray-600' : 'bg-green-600' ?> h-1.5 rounded-full" style="width: <?= $p['pct'] ?>%"></div></div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <footer class="bg-gray-900 border-t border-gray-800 py-6 text-center text-gray-500 text-xs">Lolipop / IronLegion &reg; All rights reserved <?= date('Y') ?></footer>

    <script>
    function sortTable(n) {
        var table = document.getElementById("lootTable"), rows, switching = true, i, x, y, shouldSwitch, dir = "asc", switchcount = 0;
        var ths = table.getElementsByTagName("th");
        for (var k = 0; k < ths.length; k++) { ths[k].className = ths[k].className.replace(" sort-asc", "").replace(" sort-desc", ""); }
        while (switching) { switching = false; rows = table.rows; for (i = 1; i < (rows.length - 1); i++) { shouldSwitch = false; x = rows[i].getElementsByTagName("TD")[n]; y = rows[i + 1].getElementsByTagName("TD")[n]; var xContent = x.innerText === '-' ? 0 : (isNaN(x.innerText) ? x.innerText.toLowerCase() : parseFloat(x.innerText)); var yContent = y.innerText === '-' ? 0 : (isNaN(y.innerText) ? y.innerText.toLowerCase() : parseFloat(y.innerText)); if (dir == "asc") { if (xContent > yContent) { shouldSwitch = true; break; } } else if (dir == "desc") { if (xContent < yContent) { shouldSwitch = true; break; } } } if (shouldSwitch) { rows[i].parentNode.insertBefore(rows[i + 1], rows[i]); switching = true; switchcount++; } else { if (switchcount == 0 && dir == "asc") { dir = "desc"; switching = true; } } }
        ths[n].className += (dir === "asc" ? " sort-asc" : " sort-desc");
    }
    // Set cookie on click (Backup for robustness)
    document.addEventListener('DOMContentLoaded', function() {
        const chatBtn = document.querySelector('a[href="ironlegionchat.php"]');
        if(chatBtn) {
            chatBtn.addEventListener('click', function() {
                const now = new Date();
                const mysqlDate = now.toISOString().slice(0, 19).replace('T', ' ');
                document.cookie = "chat_last_visit=" + mysqlDate + "; path=/; max-age=" + (60*60*24*30);
            });
        }
    });
    </script>
</body>
</html>