<?php
// Fungsi untuk membuat database jika belum ada
function createDatabase($dbName) {
    if (!file_exists($dbName)) {
        $db = new SQLite3($dbName);
        echo "\033[0;32mDatabase created successfully.\033[0m\n";
    } else {
        $db = new SQLite3($dbName);
    }
    return $db;
}

// Fungsi untuk membuat tabel jika belum ada
function createTableIfNotExists($db) {
    $query = "CREATE TABLE IF NOT EXISTS folder (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chanel TEXT NOT NULL,
                links TEXT NOT NULL
              )";
    $db->exec($query);
    echo "\033[0;32mTable checked/created successfully.\033[0m\n";
}

// Fungsi untuk memeriksa apakah record sudah ada
function recordExists($db, $chanel, $links) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM folder WHERE chanel = :chanel AND links = :links");
    $stmt->bindValue(':chanel', $chanel, SQLITE3_TEXT);
    $stmt->bindValue(':links', $links, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray();
    return $row['count'] > 0;
}

// Fungsi untuk memasukkan data ke dalam tabel
function insertRecord($db, $chanel, $links) {
    if (!recordExists($db, $chanel, $links)) {
        $stmt = $db->prepare("INSERT INTO folder (chanel, links) VALUES (:chanel, :links)");
        $stmt->bindValue(':chanel', $chanel, SQLITE3_TEXT);
        $stmt->bindValue(':links', $links, SQLITE3_TEXT);
        $stmt->execute();
        echo "\033[0;36mRecord inserted:\033[0m $chanel - $links\n";
    } else {
        echo "\033[0;33mRecord already exists, skipping:\033[0m $chanel - $links\n";
    }
}

// Path ke database dan folder hasil
$dbName = 'dood.db';
$folderPath = 'hasil';

echo "\033[0;35m----------------------------------------\033[0m\n";
echo "\033[0;35mStarting processing...\033[0m\n";
echo "\033[0;35mDatabase:\033[0m $dbName\n";
echo "\033[0;35mFolder path:\033[0m $folderPath\n";
echo "\033[0;35m----------------------------------------\033[0m\n";

// Membuat database dan tabel jika belum ada
$db = createDatabase($dbName);
createTableIfNotExists($db);

// Membaca file txt dari folder hasil
$files = glob($folderPath . '/*.txt');

foreach ($files as $file) {
    $chanel = basename($file, '.txt');
    echo "\033[0;34mProcessing file:\033[0m $file\n";

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Mendapatkan semua karakter setelah /f/
        if (preg_match('/\/f\/(.+)/', $line, $matches)) {
            $link = $matches[1];
            insertRecord($db, $chanel, $link);
        }
    }
}

echo "\033[0;35m----------------------------------------\033[0m\n";
echo "\033[0;35mProcessing complete.\033[0m\n";
echo "\033[0;35m----------------------------------------\033[0m\n";
?>