<?php
// rules.php – Updated for V19 Logic (Hard Drop + Freeze)

if (session_status() === PHP_SESSION_NONE) session_start();

// language detection
if (isset($_GET['lang'])) {
    $lang = $_GET['lang'];
    if (!in_array($lang, ['pl','en','ru'], true)) $lang = 'pl';
    $_SESSION['lang'] = $lang;
} else {
    $lang = $_SESSION['lang'] ?? 'pl';
}

$rules = [

    'pl' => [
        'title' => 'Zasady kolejki',
        'intro' => 'Jak działa system Iron Legion:',
        'list' => [
            '1. Każdy przedmiot ma swoją własną, niezależną kolejkę.',
            '2. ❄️ <strong>ZAMRAŻANIE:</strong> Jeśli przedmiot NIE wypadł na sesji, jego kolejka stoi w miejscu (nawet dla obecnych).',
            '3. 🚀 <strong>AWANS:</strong> Jeśli przedmiot wypadł, a Ty jesteś OBECNY (i go nie dostałeś) — przeskakujesz graczy nieobecnych.',
            '4. 💀 <strong>RESET:</strong> Jeśli OTRZYMASZ przedmiot — spadasz na sam koniec kolejki (pod wszystkich, nawet nieobecnych).',
            '5. Jeśli przedmiot trafi w "Trash" (nikt go nie weźmie) — nikt nie spada, a obecni awansują.',
            '6. Nowy gracz zawsze zaczyna na szarym końcu.',
        ],
        'back' => 'Powrót'
    ],

    'en' => [
        'title' => 'Queue Rules',
        'intro' => 'How the Iron Legion system works:',
        'list' => [
            '1. Each item has its own independent queue.',
            '2. ❄️ <strong>FREEZE:</strong> If the item did NOT drop, the queue remains frozen (unchanged), even for present players.',
            '3. 🚀 <strong>BOOST:</strong> If the item dropped and you are PRESENT (and didn\'t get it) — you jump over absent players.',
            '4. 💀 <strong>RESET:</strong> If you RECEIVE the item — you drop to the absolute bottom of the queue (below everyone).',
            '5. If the item goes to "Trash" — no one drops, but present players still move up.',
            '6. New players always start at the very bottom.',
        ],
        'back' => 'Back'
    ],

    'ru' => [
        'title' => 'Правила очереди',
        'intro' => 'Как работает система Iron Legion:',
        'list' => [
            '1. У каждого предмета своя независимая очередь.',
            '2. ❄️ <strong>ЗАМОРОЗКА:</strong> Если предмет НЕ выпал, очередь стоит на месте (даже для присутствующих).',
            '3. 🚀 <strong>ПОДЪЕМ:</strong> Если предмет выпал, а ты ПРИСУТСТВУЕШЬ (и не получил его) — ты обгоняешь отсутствующих.',
            '4. 💀 <strong>СБРОС:</strong> Если ты ПОЛУЧИЛ предмет — падаешь в самый конец очереди (ниже всех).',
            '5. Если предмет ушел в "Мусор" — никто не падает, а присутствующие поднимаются.',
            '6. Новый игрок всегда начинает в самом конце.',
        ],
        'back' => 'Назад'
    ]

];

$doc = $rules[$lang];
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($doc['title']) ?></title>
<style>
    body{background:#0f1113;color:#e7e9eb;font-family:'Inter', sans-serif;margin:0;padding:20px}
    .wrap{max-width:900px;margin:0 auto}
    .card{background:#1f2937;padding:24px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.5); border: 1px solid #374151;}
    h1{margin:0 0 16px;font-size:28px;text-align:center; color: #eab308; font-weight: bold;}
    p.intro {text-align:center;color:#9ca3af; margin-bottom: 24px;}
    ul {list-style: none; padding: 0;}
    li {
        background: #111827;
        margin-bottom: 12px;
        padding: 16px;
        border-radius: 8px;
        border-left: 4px solid #4b5563;
        font-size: 16px;
        line-height: 1.5;
    }
    li strong { color: #fff; }
    /* Kolorowanie specyficznych zasad */
    li:nth-child(2) { border-left-color: #3b82f6; } /* Zamrażanie - Niebieski */
    li:nth-child(3) { border-left-color: #22c55e; } /* Awans - Zielony */
    li:nth-child(4) { border-left-color: #ef4444; } /* Reset - Czerwony */

    .lang{color:#6b7280;text-decoration:none;margin-right:12px;font-weight:bold; font-size:14px;}
    .lang:hover, .lang.active {color:#eab308;}
    
    .back-btn {
        display:inline-block;
        padding:8px 16px;
        background:#374151;
        color:#fff;
        border-radius:6px;
        text-decoration:none;
        font-weight: bold;
        transition: background 0.2s;
    }
    .back-btn:hover { background: #4b5563; }

    .back-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
</style>
</head>
<body>
<div class="wrap">

    <div class="back-row">
        <div>
            <a class="lang <?= $lang=='pl'?'active':'' ?>" href="?lang=pl">PL</a>
            <a class="lang <?= $lang=='en'?'active':'' ?>" href="?lang=en">EN</a>
            <a class="lang <?= $lang=='ru'?'active':'' ?>" href="?lang=ru">RU</a>
        </div>
        <div>
            <a class="back-btn" href="index.php">← <?= htmlspecialchars($doc['back']) ?></a>
        </div>
    </div>

    <div class="card">
        <h1><?= htmlspecialchars($doc['title']) ?></h1>
        <p class="intro"><?= htmlspecialchars($doc['intro']) ?></p>

        <ul>
            <?php foreach ($doc['list'] as $r): ?>
                <li><?= $r // Allow HTML tags like <strong> ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

</div>
</body>
</html>