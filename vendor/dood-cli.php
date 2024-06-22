<?php
require 'vendor/autoload.php';
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

const API_KEY = '350871o0uomobcm787efod';
const BASE_API_URL = 'https://doodapi.com/api';
const BASE_DOOD_URL = 'https://d000d.com/f';

// Fungsi untuk membuat database jika belum ada
function createDatabase($dbName) {
    if (!file_exists($dbName)) {
        $db = new SQLite3($dbName);
        echo "\033[1;34m[INFO] ✔️ Database '$dbName' created.\033[0m\n";
    } else {
        $db = new SQLite3($dbName);
    }
    return $db;
}

// Fungsi untuk membuat atau memperbarui tabel
function createOrUpdateTable($db, $tableName, $schema, $columnsToAdd) {
    $db->exec("CREATE TABLE IF NOT EXISTS $tableName ($schema)");

    $existingColumns = [];
    $result = $db->query("PRAGMA table_info($tableName)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $existingColumns[] = $row['name'];
    }

    foreach ($columnsToAdd as $column => $type) {
        if (!in_array($column, $existingColumns)) {
            $db->exec("ALTER TABLE $tableName ADD COLUMN $column $type");
        }
    }
    
    echo "\033[1;34m[INFO] ✔️ '$tableName' table setup completed.\033[0m\n";
}

// Fungsi untuk menandai record sebagai sudah diproses
function markAsProcessed($db, $chanel, $link) {
    $stmt = $db->prepare("UPDATE folder SET processed = 1 WHERE chanel = :chanel AND links = :link");
    $stmt->bindValue(':chanel', $chanel, SQLITE3_TEXT);
    $stmt->bindValue(':link', $link, SQLITE3_TEXT);
    $stmt->execute();
}

// Fungsi untuk memasukkan record ke dalam tabel extract_folder
function insertRecord($db, $chanel, $link, $fileName, $fileUrl, $folderName, $fldId = null, $fileCode = null) {
    $stmt = $db->prepare("INSERT INTO extract_folder (chanel, link, file_name, file_url, folder_name, fld_id, file_code) VALUES (:chanel, :link, :file_name, :file_url, :folder_name, :fld_id, :file_code)");
    $stmt->bindValue(':chanel', $chanel, SQLITE3_TEXT);
    $stmt->bindValue(':link', $link, SQLITE3_TEXT);
    $stmt->bindValue(':file_name', $fileName, SQLITE3_TEXT);
    $stmt->bindValue(':file_url', $fileUrl, SQLITE3_TEXT);
    $stmt->bindValue(':folder_name', $folderName, SQLITE3_TEXT);
    $stmt->bindValue(':fld_id', $fldId, SQLITE3_TEXT);
    $stmt->bindValue(':file_code', $fileCode, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result) {
        echo "\033[1;32m[SUCCESS] ✔️ Uploaded $fileName to Dood successfully.\033[0m\n";
    } else {
        echo "\033[1;31m[ERROR] ❌ Failed to upload $fileName to Dood.\033[0m\n";
    }
}

// Fungsi untuk membuat folder baru di Dood
function createDoodFolder($client, $folderName) {
    $url = BASE_API_URL . "/folder/create?key=" . API_KEY . "&name=" . urlencode($folderName);
    $response = $client->request('GET', $url);
    $data = json_decode($response->getBody(), true);

    if ($data['status'] == 200 && isset($data['result']['fld_id'])) {
        return $data['result']['fld_id'];
    }
    return null;
}

// Fungsi untuk upload file ke Dood
function remoteUploadDood($client, $uploadUrl, $fldId = null) {
    $url = BASE_API_URL . "/upload/url?key=" . API_KEY . "&url=" . $uploadUrl;
    if ($fldId) {
        $url .= "&fld_id=$fldId";
    }

    $response = $client->request('GET', $url);
    $data = json_decode($response->getBody(), true);

    if ($data['status'] == 200 && isset($data['result']['filecode'])) {
        return $data['result']['filecode'];
    }
    return null;
}

// Nama database
$dbName = 'dood.db';

// Membuat koneksi database
$db = createDatabase($dbName);

// Membuat atau memperbarui tabel folder dan extract_folder jika belum ada
createOrUpdateTable($db, 'folder', 'id INTEGER PRIMARY KEY AUTOINCREMENT, chanel TEXT, links TEXT', [
    'processed' => 'INTEGER DEFAULT 0'
]);
createOrUpdateTable($db, 'extract_folder', 'id INTEGER PRIMARY KEY AUTOINCREMENT, chanel TEXT, link TEXT, file_name TEXT, file_url TEXT, folder_name TEXT, fld_id TEXT, file_code TEXT', []);

// Mengambil data dari tabel folder yang belum diproses
$results = $db->query("SELECT chanel, links FROM folder WHERE processed = 0");

$client = new Client();

// Memproses setiap baris hasil
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $chanel = $row['chanel'];
    $linkCode = $row['links'];
    $url = BASE_DOOD_URL . "/$linkCode";
    
    echo "\n\033[1;35m[PROCESSING] Processing channel: $chanel\033[0m\n";

    try {
        // Mengambil halaman HTML dari URL Dood
        $response = $client->request('GET', $url);
        $html = $response->getBody()->getContents();
        $crawler = new Crawler($html);
        
        // Mendapatkan nama folder dari judul h1
        if ($crawler->filter('h1')->count() > 0) {
            $folderName = $crawler->filter('h1')->text();
            echo "\033[1;36m[INFO] Folder name: $folderName\033[0m\n";
        } else {
            echo "\033[1;33m[WARNING] No folder name found for URL: $url\033[0m\n================\n";
            markAsProcessed($db, $chanel, $linkCode);
            continue;
        }

        // Membuat folder baru di Dood
        $fldId = createDoodFolder($client, $folderName);
        if (!$fldId) {
            echo "\033[1;31m[ERROR] Failed to create folder: $folderName\033[0m\n================\n";
            markAsProcessed($db, $chanel, $linkCode);
            continue;
        }

        // Memproses setiap item dalam list
        if ($crawler->filter('li')->count() > 0) {
            $items = $crawler->filter('li');
            echo "\n\033[1;35m[PROCESSING] Processing items...\033[0m\n";

            foreach ($items as $item) {
                $itemCrawler = new Crawler($item);

                if ($itemCrawler->filter('h4')->count() > 0 && $itemCrawler->filter('a')->count() > 0) {
                    $fileName = $itemCrawler->filter('h4')->text();
                    $fileUrl = $itemCrawler->filter('a')->attr('href');

                    // Mengambil kode file dari URL
                    if (preg_match('/\/[ed]\/(.+)/', $fileUrl, $matches)) {
                        $fileUrl = $matches[1];
                        // Upload file ke Dood
                        $fileCode = remoteUploadDood($client, "https://d000d.com/$fileUrl", $fldId);
                        // Memasukkan record ke dalam tabel extract_folder
                        insertRecord($db, $chanel, $linkCode, $fileName, $fileUrl, $folderName, $fldId, $fileCode);
                    } else {
                        echo "\033[1;31m[ERROR] Invalid URL format: $fileUrl\033[0m\n================\n";
                    }
                } else {
                    echo "\033[1;31m[ERROR] Missing details in list item.\033[0m\n================\n";
                }
            }
        } else {
            echo "\033[1;33m[WARNING] No items found for URL: $url\033[0m\n================\n";
        }

        // Menandai record sebagai sudah diproses
        markAsProcessed($db, $chanel, $linkCode);
        
    } catch (Exception $e) {
        echo "\033[1;31m[ERROR] Failed to process $url: " . $e->getMessage() . "\033[0m\n================\n";
        markAsProcessed($db, $chanel, $linkCode);
    }
}

echo "\n\033