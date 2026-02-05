<?php
// oral.php - Analytics Center V10 (Color Match, Calculation Fix, Clean UI)
require_once 'functions.php';

// Get Session ID
$selectedSessionId = $_GET['session_id'] ?? null;

// 1. FETCH BASIC DATA (Only active players for accurate ranking)
$players = $pdo->query("SELECT id, nick, is_loot_banned, is_out FROM players WHERE is_out = 0 ORDER BY nick ASC")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("SELECT id, name, icon FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Fetch sessions for menu
$allSessions = $pdo->query("SELECT id, name, boss, created_at FROM sessions ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

if (empty($selectedSessionId) && !empty($allSessions)) {
    $selectedSessionId = $allSessions[0]['id'];
}

// Mappings
$playersMap = [];
foreach ($players as $p) $playersMap[$p['id']] = $p;
$itemsMap = [];
foreach ($items as $i) $itemsMap[$i['id']] = $i;

// 2. ATTENDANCE STATISTICS ENGINE
$chronoSessions = $pdo->query("SELECT id FROM sessions ORDER BY created_at ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
$rawAttendance = $pdo->query("SELECT player_id, session_id FROM session_players")->fetchAll(PDO::FETCH_ASSOC);

$playerAttendance = []; 
foreach ($rawAttendance as $row) {
    $playerAttendance[$row['player_id']][] = $row['session_id'];
}

$playerStats = []; 
foreach ($players as $p) {
    $pid = $p['id'];
    $attendedSessionIds = $playerAttendance[$pid] ?? [];
    $attendedCount = count($attendedSessionIds);
    
    $firstSeenIndex = -1;
    if ($attendedCount > 0) {
        foreach ($chronoSessions as $idx => $sid) {
            if (in_array($sid, $attendedSessionIds)) {
                $firstSeenIndex = $idx;
                break;
            }
        }
    }

    if ($firstSeenIndex > -1) {
        $totalPossible = count($chronoSessions) - $firstSeenIndex;
        $perc = $totalPossible > 0 ? round(($attendedCount / $totalPossible) * 100) : 0;
        $playerStats[$pid] = "({$attendedCount}/{$totalPossible}-{$perc}%)";
    } else {
        $playerStats[$pid] = "(0/0-0%)";
    }
}

// 3. TIME MACHINE SIMULATION
$historySessions = $pdo->query("SELECT id, created_at FROM sessions ORDER BY created_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$simulatedQueues = [];
$playerIds = array_column($players, 'id');
foreach ($items as $i) {
    $simulatedQueues[$i['id']] = $playerIds; 
}

$targetSessionData = null;
$targetSessionLoot = [];
$targetSessionAttendance = [];

foreach ($historySessions as $histSess) {
    $sid = $histSess['id'];
    
    if ($sid == $selectedSessionId) {
        $stmt = $pdo->prepare("SELECT * FROM sessions WHERE id = ?");
        $stmt->execute([$sid]);
        $targetSessionData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT * FROM session_loot WHERE session_id = ?");
        $stmt->execute([$sid]);
        $targetSessionLoot = $stmt->fetchAll(PDO::FETCH_ASSOC); 
        
        $stmt = $pdo->prepare("SELECT player_id FROM session_players WHERE session_id = ?");
        $stmt->execute([$sid]);
        $targetSessionAttendance = $stmt->fetchAll(PDO::FETCH_COLUMN); 
        
        break; 
    }

    $sLoot = $pdo->prepare("SELECT item_id, winner_player_id FROM session_loot WHERE session_id = ?");
    $sLoot->execute([$sid]);
    $histLoots = $sLoot->fetchAll(PDO::FETCH_ASSOC);
    
    $sAtt = $pdo->prepare("SELECT player_id FROM session_players WHERE session_id = ?");
    $sAtt->execute([$sid]);
    $histPresence = $sAtt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($histLoots as $l) {
        $iid = $l['item_id'];
        $winId = $l['winner_player_id'];
        if ($winId && isset($simulatedQueues[$iid])) {
            $q = &$simulatedQueues[$iid];
            $key = array_search($winId, $q);
            if ($key !== false) {
                unset($q[$key]);
                $q[] = $winId;
                $q = array_values($q);
            }
        }
    }

    foreach ($simulatedQueues as &$queue) {
        $presentInQueue = [];
        $absentInQueue = [];
        foreach ($queue as $pid) {
            if (in_array($pid, $histPresence)) {
                $presentInQueue[] = $pid;
            } else {
                $absentInQueue[] = $pid;
            }
        }
        $queue = array_merge($presentInQueue, $absentInQueue);
    }
    unset($queue);
}

// 4. FETCH LOOT MATRIX & SORT
$lootMatrix = []; $lootTotals = []; $itemGlobalCounts = []; 
foreach ($items as $i) $itemGlobalCounts[$i['id']] = 0;
foreach ($players as $p) {
    $lootTotals[$p['id']] = 0;
    foreach ($items as $i) $lootMatrix[$p['id']][$i['id']] = 0;
}
$matrixQuery = $pdo->query("SELECT winner_player_id, item_id, COUNT(*) as qty FROM session_loot WHERE winner_player_id IS NOT NULL GROUP BY winner_player_id, item_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($matrixQuery as $row) {
    if (isset($lootMatrix[$row['winner_player_id']])) {
        $lootMatrix[$row['winner_player_id']][$row['item_id']] = $row['qty'];
        $lootTotals[$row['winner_player_id']] += $row['qty'];
        $itemGlobalCounts[$row['item_id']] += $row['qty'];
    }
}
usort($items, function($a, $b) use ($itemGlobalCounts) { return $itemGlobalCounts[$b['id']] - $itemGlobalCounts[$a['id']]; });
arsort($lootTotals);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Iron Legion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #111827; color: #e2e8f0; font-family: 'Inter', sans-serif; }
        .sidebar-scroll { scrollbar-width: thin; scrollbar-color: #475569 #1e293b; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <!-- SIDEBAR (Sessions List) -->
    <aside class="w-full md:w-80 bg-gray-900 border-b md:border-b-0 md:border-r border-gray-800 flex flex-col h-auto max-h-[40vh] md:max-h-screen md:h-screen shrink-0 z-20">
        <div class="p-6 border-b border-gray-800 bg-gray-900 sticky top-0">
            <h1 class="text-xl font-bold text-white tracking-wide uppercase">Analytics</h1>
            <a href="index.php" class="text-xs text-yellow-600 hover:text-yellow-500 mt-2 block font-bold uppercase">← Back to Site</a>
        </div>
        <div class="flex-1 overflow-y-auto sidebar-scroll p-2 space-y-1 bg-gray-950/50">
            <?php foreach($allSessions as $s): $isActive = ($s['id'] == $selectedSessionId); ?>
                <a href="oral.php?session_id=<?= $s['id'] ?>" class="block p-3 rounded-lg transition border border-transparent <?= $isActive ? 'bg-indigo-900/40 border-indigo-500/50' : 'hover:bg-gray-800 border-gray-800' ?>">
                    <div class="flex justify-between items-center">
                        <span class="font-bold text-sm <?= $isActive ? 'text-white' : 'text-gray-400' ?>"><?= htmlspecialchars($s['name']) ?></span>
                        <span class="text-[10px] bg-gray-900 px-1.5 py-0.5 rounded text-gray-500">#<?= $s['id'] ?></span>
                    </div>
                    <div class="flex justify-between items-center text-xs mt-1">
                        <span class="text-indigo-400 font-bold"><?= htmlspecialchars($s['boss']) ?></span>
                        <span class="text-gray-600"><?= date('d.m.Y', strtotime($s['created_at'])) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="flex-1 h-auto md:h-screen overflow-y-auto p-4 md:p-8 custom-scroll">
        <?php if(!$targetSessionData): ?>
            <div class="flex items-center justify-center h-full text-gray-500">Select a session from the list.</div>
        <?php else: ?>

            <!-- HEADER -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 border-b border-gray-700 pb-6 gap-4">
                <div>
                    <h2 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-2"><?= htmlspecialchars($targetSessionData['name']) ?></h2>
                    <div class="flex flex-wrap gap-2 text-xs font-bold uppercase">
                        <span class="text-yellow-500 bg-yellow-900/20 px-2 py-1 rounded">BOSS: <?= htmlspecialchars($targetSessionData['boss']) ?></span>
                        <span class="text-gray-400 bg-gray-800 px-2 py-1 rounded"><?= $targetSessionData['created_at'] ?></span>
                        <span class="text-green-500 bg-green-900/20 px-2 py-1 rounded">ATTENDANCE: <?= count($targetSessionAttendance) ?></span>
                    </div>
                </div>
                <a href="rules.php" target="_blank" class="bg-gray-800 hover:bg-gray-700 text-gray-300 border border-gray-600 px-4 py-2 rounded font-bold text-xs transition">RULES</a>
            </div>

            <!-- ANALYTICS PER ITEM -->
            <div class="space-y-16 mb-16">
                <?php foreach($targetSessionLoot as $loot): 
                    $itemId = $loot['item_id'];
                    $winnerId = $loot['winner_player_id'];
                    $itemInfo = $itemsMap[$itemId];
                    
                    $queueBefore = $simulatedQueues[$itemId] ?? [];
                    
                    // Logic Fix: Winner to absolute bottom
                    $tempQueue = [];
                    foreach($queueBefore as $pid) { 
                        if ($pid != $winnerId) $tempQueue[] = (int)$pid; 
                    }
                    
                    $presentPart = []; $absentPart = [];
                    foreach ($tempQueue as $pid) {
                        if (in_array($pid, $targetSessionAttendance)) $presentPart[] = $pid;
                        else $absentPart[] = $pid;
                    }
                    $queueAfter = array_merge($presentPart, $absentPart);
                    if ($winnerId) $queueAfter[] = (int)$winnerId;
                    
                    $limit = 15;
                ?>
                <div class="space-y-4">
                    <div class="flex items-center gap-3 border-b border-gray-800 pb-2">
                        <?php if($itemInfo['icon']): ?><img src="icons/<?= $itemInfo['icon'] ?>" class="w-8 h-8 object-contain"><?php endif; ?>
                        <h3 class="text-xl font-bold text-white uppercase"><?= htmlspecialchars($itemInfo['name']) ?></h3>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- LEFT: BEFORE -->
                        <div class="bg-gray-900 rounded border border-gray-800 overflow-hidden">
                            <div class="bg-gray-800 px-4 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest">Initial State & Change</div>
                            <table class="w-full text-xs text-left">
                                <thead class="bg-black/20 text-gray-500 uppercase text-[9px]">
                                    <tr><th class="p-2 w-8 text-center">#</th><th class="p-2">Player</th><th class="p-2 text-center">Change</th><th class="p-2 text-right">Status</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-800">
                                    <?php 
                                    $rank = 1;
                                    foreach($queueBefore as $pid):
                                        if ($rank > $limit) break;
                                        if (!isset($playersMap[$pid])) continue;
                                        $p = $playersMap[$pid];
                                        $isPresent = in_array($pid, $targetSessionAttendance);
                                        $isWin = ($pid == $winnerId);
                                        
                                        $rankAfter = array_search($pid, $queueAfter);
                                        $change = "-";
                                        $changeClass = "text-gray-600";
                                        if ($rankAfter !== false) {
                                            $rankAfter++;
                                            $diff = $rank - $rankAfter;
                                            if ($isWin) { $change = "RESET"; $changeClass = "text-yellow-600 font-bold"; }
                                            elseif ($diff > 0) { $change = "+$diff"; $changeClass = "text-green-500 font-bold"; }
                                            elseif ($diff < 0) { $change = "$diff"; $changeClass = "text-red-500 font-bold"; }
                                        }
                                    ?>
                                    <tr>
                                        <td class="p-2 text-center text-gray-600 font-mono"><?= $rank ?></td>
                                        <td class="p-2 font-bold <?= $isWin ? 'text-yellow-500' : ($isPresent ? 'text-gray-300' : 'text-gray-600') ?>"><?= htmlspecialchars($p['nick']) ?></td>
                                        <td class="p-2 text-center font-mono <?= $changeClass ?>"><?= $change ?></td>
                                        <td class="p-2 text-right text-[10px]"><?= $isPresent ? '<span class="text-green-600">●</span>' : '<span class="text-gray-800">○</span>' ?></td>
                                    </tr>
                                    <?php $rank++; endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- RIGHT: AFTER -->
                        <div class="bg-gray-900 rounded border border-gray-800 overflow-hidden">
                            <div class="bg-gray-800 px-4 py-2 text-[10px] font-bold text-gray-400 uppercase tracking-widest">New State & Reasoning</div>
                            <table class="w-full text-xs text-left">
                                <thead class="bg-black/20 text-gray-500 uppercase text-[9px]">
                                    <tr><th class="p-2 w-8 text-center">#</th><th class="p-2">Player</th><th class="p-2 text-right">Logic / Reason</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-800">
                                    <?php 
                                    $rank = 1;
                                    foreach($queueAfter as $pid):
                                        if ($rank > $limit) break;
                                        if (!isset($playersMap[$pid])) continue;
                                        $p = $playersMap[$pid];
                                        $isWin = ($pid == $winnerId);
                                        $isPresent = in_array($pid, $targetSessionAttendance);
                                        
                                        $prevRank = array_search($pid, $queueBefore);
                                        if ($prevRank !== false) $prevRank++;
                                        
                                        $logic = "Maintain";
                                        if ($isWin) $logic = "<span class='text-yellow-600 font-bold'>WINNER</span>";
                                        elseif (!$isPresent) $logic = "Absent (Frozen)";
                                        else {
                                            $diff = $prevRank - $rank;
                                            if ($diff > 0) $logic = "Jumped $diff absents";
                                        }
                                    ?>
                                    <tr>
                                        <td class="p-2 text-center text-gray-600 font-mono"><?= $rank ?></td>
                                        <td class="p-2 font-bold <?= $isWin ? 'text-yellow-500' : ($isPresent ? 'text-gray-300' : 'text-gray-600') ?>"><?= htmlspecialchars($p['nick']) ?></td>
                                        <td class="p-2 text-right text-[10px] text-gray-500 uppercase italic"><?= $logic ?></td>
                                    </tr>
                                    <?php $rank++; endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- GLOBAL LOOT MATRIX -->
            <div class="mt-20 border-t border-gray-800 pt-10 pb-20 overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-gray-900 text-gray-500 uppercase text-[9px]">
                        <tr>
                            <th class="p-3 sticky left-0 bg-gray-900 border-r border-gray-800 z-10 min-w-[120px]">Player Stats</th>
                            <th class="p-3 text-center border-r border-gray-800 bg-gray-900">Total</th>
                            <?php foreach($items as $i): ?>
                                <th class="p-3 text-center border-r border-gray-800/50 min-w-[45px]">
                                    <?php if($i['icon']): ?><img src="icons/<?= $i['icon'] ?>" class="w-5 h-5 mx-auto object-contain"><?php else: ?><?= substr($i['name'],0,3) ?><?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800 bg-gray-900/40">
                        <?php foreach($lootTotals as $pid => $total): 
                            if (!isset($playersMap[$pid])) continue;
                            $p = $playersMap[$pid];
                        ?>
                        <tr class="hover:bg-gray-800/30">
                            <td class="p-3 sticky left-0 bg-gray-900 border-r border-gray-800 z-10">
                                <div class="flex flex-col">
                                    <span class="text-gray-300 font-bold"><?= htmlspecialchars($p['nick']) ?></span>
                                    <span class="text-[9px] text-gray-600 font-mono tracking-tighter"><?= $playerStats[$pid] ?? '' ?></span>
                                </div>
                            </td>
                            <td class="p-3 text-center bg-gray-800/50 font-bold text-white"><?= $total ?: '-' ?></td>
                            <?php foreach($items as $i): 
                                $qty = $lootMatrix[$pid][$i['id']] ?? 0;
                            ?>
                                <td class="p-3 text-center border-r border-gray-800/20 <?= $qty > 0 ? 'text-green-500 font-bold bg-green-900/5' : 'text-gray-700' ?>">
                                    <?= $qty ?: '·' ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </main>
</body>
</html>