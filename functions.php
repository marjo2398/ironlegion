<?php
// functions.php - V19 (Logic Update: Hard Drop - Winner goes to absolute bottom)
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. TŁUMACZENIA
function set_language(array $supported, string $default = 'en'): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_GET['lang']) && in_array($_GET['lang'], $supported, true)) {
        $_SESSION['lang'] = $_GET['lang'];
    }
    return $_SESSION['lang'] ?? $default;
}

$lang = set_language(['pl', 'en', 'ru'], 'en');

function t($key) { 
    $trans = [
        'en' => ['title'=>'Iron Legion','start_kundun'=>'Start Kundun','host_panel'=>'Command Center','last_battle'=>'Last Battle','dropped_items'=>'Dropped Items','queues'=>'Item Queues','empty_queue'=>'Queue is empty','loot_history'=>'Loot History','attendance'=>'Attendance','footer'=>'Iron Legion System','login_title'=>'Admin Access','token'=>'Security Token','enter'=>'Authenticate','step_1'=>'Step 1: Who is present?','step_2'=>'Step 2: What dropped?','step_3'=>'Step 3: Assign Loot','next'=>'Next Step','prev'=>'Previous','cancel'=>'Cancel','save'=>'Save Session','back'=>'Back to Site','dashboard'=>'Dashboard','manage_sessions'=>'History & Edit','add_data'=>'Database','delete'=>'Delete','edit'=>'Edit','view'=>'View','actions'=>'Actions','confirm_delete'=>'Delete this session? Queues will be recalculated!','updated'=>'Updated successfully','rules'=>'Queue Rules','wrong_token'=>'Invalid Token','banned'=>'Loot Banned','out'=>'OUT of Guild','confirm_del_player'=>'Permanently delete this player?','confirm_del_item'=>'Delete item? Queue will be lost!','refresh_banner'=>'Force Refresh Banner','banner_refreshed'=>'Banner cache cleared!','no_items'=>'No items','total'=>'Total'],
        'pl' => ['title'=>'Iron Legion','start_kundun'=>'Start Kundun','host_panel'=>'Centrum Dowodzenia','last_battle'=>'Ostatnia Bitwa','dropped_items'=>'Zdobyte Przedmioty','queues'=>'Kolejki Przedmiotów','empty_queue'=>'Kolejka pusta','loot_history'=>'Historia Łupów','attendance'=>'Obecność','footer'=>'System Iron Legion','login_title'=>'Dostęp Admina','token'=>'Token Zabezpieczający','enter'=>'Autoryzacja','step_1'=>'Krok 1: Kto jest obecny?','step_2'=>'Krok 2: Co wypadło?','step_3'=>'Krok 3: Przydziel łupy','next'=>'Dalej','prev'=>'Wstecz','cancel'=>'Anuluj','save'=>'Zapisz Sesję','back'=>'Powrót na Stronę','dashboard'=>'Pulpit','manage_sessions'=>'Historia i Edycja','add_data'=>'Baza Danych','delete'=>'Usuń','edit'=>'Edytuj','view'=>'Podgląd','actions'=>'Akcje','confirm_delete'=>'Usunąć sesję? Kolejki zostaną przeliczone!','updated'=>'Zaktualizowano pomyślnie','rules'=>'Zasady Kolejki','wrong_token'=>'Błędny Token','banned'=>'Blokada Dropu','out'=>'OUT (Odszedł)','confirm_del_player'=>'Trwale usunąć gracza?','confirm_del_item'=>'Usunąć przedmiot? Kolejka przepadnie!','refresh_banner'=>'Wymuś Odświeżenie Banera','banner_refreshed'=>'Cache banera wyczyszczony!','no_items'=>'Brak przedmiotów','total'=>'Suma'],
        'ru' => ['title'=>'Iron Legion','start_kundun'=>'Start Kundun','host_panel'=>'Командный Центр','last_battle'=>'Последняя Битва','dropped_items'=>'Выпавшие Предметы','queues'=>'Очереди','empty_queue'=>'Пусто','loot_history'=>'История','attendance'=>'Посещаемость','footer'=>'System Iron Legion','login_title'=>'Доступ Админа','token'=>'Токен','enter'=>'Войти','step_1'=>'Шаг 1: Кто здесь?','step_2'=>'Шаг 2: Что выпало?','step_3'=>'Шаг 3: Назначить','next'=>'Далее','prev'=>'Назад','cancel'=>'Отмена','save'=>'Сохранить','back'=>'На сайт','dashboard'=>'Панель','manage_sessions'=>'История','add_data'=>'База Данных','delete'=>'Удалить','edit'=>'Редактировать','view'=>'Просмотр','actions'=>'Действия','confirm_delete'=>'Удалить? Очереди будут пересчитаны!','updated'=>'Обновлено','rules'=>'Правила','wrong_token'=>'Неверный токен','banned'=>'Бан лута','out'=>'OUT (Вышел)','confirm_del_player'=>'Delete player?','confirm_del_item'=>'Delete item?','refresh_banner'=>'Refresh Banner','banner_refreshed'=>'Cache cleared','no_items'=>'Нет предметов','total'=>'Итого']
    ];
    global $lang; return $trans[$lang][$key] ?? $key; 
}

function get_setting($pdo, $key) { 
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?"); 
    $stmt->execute([$key]); 
    return $stmt->fetchColumn(); 
}

// ==========================================================
// SYSTEM TABEL
// ==========================================================
function ensure_db_structure($pdo) {
    $columns = $pdo->query("SHOW COLUMNS FROM players LIKE 'is_out'")->fetchAll();
    if (empty($columns)) {
        try {
            $pdo->exec("ALTER TABLE players ADD COLUMN is_out TINYINT DEFAULT 0");
        } catch (Exception $e) { }
    }
}
ensure_db_structure($pdo);

// ==========================================================
// LOGIKA KOLEJEK (V19 - Hard Drop Logic)
// ==========================================================
function rebuild_all_queues($pdo): void
{
    // 1. Pobierz graczy
    try {
        $stmt = $pdo->query("SELECT id, is_loot_banned, is_out FROM players ORDER BY id ASC");
        $allPlayersDB = $stmt->fetchAll(PDO::FETCH_ASSOC); 
    } catch (PDOException $e) { return; }
    
    $validPlayerIds = []; 
    $activePlayersIDs = [];
    $bannedPlayersIDs = [];
    $outPlayersIDs = [];

    foreach($allPlayersDB as $p) {
        $pid = (int)$p['id'];
        $validPlayerIds[$pid] = true;

        if ($p['is_out']) {
            $outPlayersIDs[] = $pid;
        } elseif ($p['is_loot_banned']) {
            $bannedPlayersIDs[] = $pid;
        } else {
            $activePlayersIDs[] = $pid;
        }
    }

    $stmt = $pdo->query("SELECT id FROM items ORDER BY id ASC");
    $allItems = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    if (empty($allPlayersDB) || empty($allItems)) return;

    $stmt = $pdo->query("SELECT id FROM sessions ORDER BY created_at ASC, id ASC");
    $sessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $queues = [];
    foreach ($allItems as $iid) $queues[(int)$iid] = [];

    // 4. Symulacja Historii
    if (!empty($sessionIds)) {
        foreach ($sessionIds as $sid) {
            $sid = (int)$sid;
            
            // Obecność na sesji
            $stmt = $pdo->prepare("SELECT player_id FROM session_players WHERE session_id = ?");
            $stmt->execute([$sid]);
            $present = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
            $presentSet = array_flip($present); 

            // Dropy na sesji
            $stmt = $pdo->prepare("SELECT id, item_id, winner_player_id FROM session_loot WHERE session_id = ? ORDER BY id ASC");
            $stmt->execute([$sid]);
            $lootRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $lootByItem = [];
            foreach ($lootRows as $lr) $lootByItem[(int)$lr['item_id']][] = $lr;
            $droppedItemIds = array_keys($lootByItem);

            foreach ($queues as $itemId => &$queue) {
                $itemId = (int)$itemId;

                // Dodaj brakujących graczy do kolejki
                foreach ($present as $pid) {
                    if (!in_array($pid, $queue, true)) {
                        $queue[] = $pid;
                    }
                }

                // ZASADA 0: Jeśli item nie wypadł, kolejka jest zamrożona.
                if (!in_array($itemId, $droppedItemIds)) continue; 

                $itemLoot = $lootByItem[$itemId] ?? [];
                $winnersSet = [];
                foreach ($itemLoot as $lr) if ($lr['winner_player_id'] !== null) $winnersSet[(int)$lr['winner_player_id']] = true;

                // A. ZASADA WINDY (Obecni w górę)
                $n = count($queue);
                for ($i = 1; $i < $n; $i++) {
                    $pid = $queue[$i]; 
                    $prev = $queue[$i - 1];
                    // Jeśli ten wyżej właśnie wygrał, to go nie ruszamy (zaraz spadnie)
                    if (isset($winnersSet[$prev])) continue; 
                    
                    // Zamiana: Niższy obecny przeskakuje wyższego nieobecnego
                    if (isset($presentSet[$pid]) && !isset($presentSet[$prev])) {
                        $queue[$i] = $prev; 
                        $queue[$i - 1] = $pid;
                    }
                }

                // B. ZASADA DROPU (Spadek na absolutny koniec)
                if (!empty($winnersSet)) {
                    foreach($winnersSet as $winId => $true) {
                        // 1. Usuń zwycięzcę z kolejki
                        $queue = array_values(array_diff($queue, [$winId]));
                        // 2. Wstaw na sam koniec (za wszystkich, nawet nieobecnych)
                        $queue[] = $winId;
                    }
                }
            }
            unset($queue);
        }
    }

    // 5. DOCZYSZCZANIE (BEZPIECZNE)
    foreach ($queues as $itemId => &$queue) {
        foreach ($activePlayersIDs as $pid) {
            if (!in_array($pid, $queue, true)) {
                $queue[] = $pid;
            }
        }
        foreach ($bannedPlayersIDs as $pid) {
            if (!in_array($pid, $queue, true)) {
                $queue[] = $pid;
            }
        }
        
        $queue = array_filter($queue, function($pid) use ($outPlayersIDs, $validPlayerIds) {
            return isset($validPlayerIds[$pid]) && !in_array($pid, $outPlayersIDs);
        });
        
        $queue = array_unique($queue);
        $queue = array_values($queue); 
    }
    unset($queue);

    // 6. ZAPIS DO BAZY
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM item_queue_positions");
        $stmtIns = $pdo->prepare("INSERT INTO item_queue_positions (item_id, player_id, position) VALUES (?, ?, ?)");
        
        foreach ($queues as $itemId => $queue) {
            $pos = 1;
            foreach ($queue as $pid) {
                if ($pid > 0) { 
                    $stmtIns->execute([$itemId, $pid, $pos]);
                    $pos++;
                }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Rebuild Error: " . $e->getMessage());
    }
}
?>
