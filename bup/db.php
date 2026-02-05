<?php
// db.php - połączenie z bazą (wersja dla v2 - Secure)

define('DB_HOST', 'localhost');
define('DB_NAME', 'host749284_loli');
define('DB_USER', 'host749284_loli');
define('DB_PASS', 'Dyntka2398');

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Wyłączenie emulacji prepare statement (lepsze bezpieczeństwo przeciw SQL Injection)
        PDO::ATTR_EMULATE_PREPARES => false, 
    ]);
} catch (PDOException $e) {
    // SECURITY FIX: Nie wyświetlamy $e->getMessage() użytkownikowi!
    // Logujemy błąd do pliku error_log na serwerze (niewidoczne dla usera)
    error_log('Database Error: ' . $e->getMessage());
    
    // Użytkownik widzi tylko to:
    die('Wystąpił problem z połączeniem do bazy danych. Spróbuj ponownie później.');
}
?>