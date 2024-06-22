<?php
// Fungsi untuk membuat database jika belum ada
function createDatabase($dbName) {
    if (!file_exists($dbName)) {
        $db = new SQLite3($dbName);
        echo "Database created successfully.\n";
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
    echo "Table checked/created successfully.\n";
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
        echo "Record inserted: $chanel - $links\n";
    } else {
        echo "Record already exists, skipping: $chanel - $links\n";
    }
}

// Path ke database dan folder hasil
$dbName = 'dood.db';
$folderPath = 'hasil';

// Membuat database dan tabel jika belum ada
$db = createDatabase($dbName);
createTableIfNotExists($db);

// Membaca file txt dari folder hasil
$files = glob($folderPath . '/*.txt');

foreach ($files as $file) {
    $chanel = basename($file, '.txt');
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Mendapatkan semua karakter setelah /f/
        if (preg_match('/\/f\/(.+)/', $line, $matches)) {
            $link = $matches[1];
            insertRecord($db, $chanel, $link);
        }
    }
}

echo "Processing complete.\n";
?>