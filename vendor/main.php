<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

const API_KEY = '350871o0uomobcm787efod';
const BASE_API_URL = 'https://doodapi.com/api';
const BASE_DOOD_URL = 'https://d000d.com/f';

// Fungsi untuk membuat atau terhubung ke database SQLite
function connectDatabase($dbName) {
    $db = new SQLite3($dbName);
    if (!$db) {
        die("Failed to connect to database '$dbName'");
    }
    return $db;
}

// Fungsi untuk membuat atau memperbarui tabel di database
function createOrUpdateTable($db, $tableName, $schema, $columnsToAdd) {
    $db->exec("CREATE TABLE IF NOT EXISTS $tableName ($schema)");

    // Periksa kolom yang sudah ada di tabel
    $existingColumns = [];
    $result = $db->query("PRAGMA table_info($tableName)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $existingColumns[] = $row['name'];
    }

    // Tambahkan kolom baru jika belum ada
    foreach ($columnsToAdd as $column => $type) {
        if (!in_array($column, $existingColumns)) {
            $db->exec("ALTER TABLE $tableName ADD COLUMN $column $type");
        }
    }
    
    echo "'$tableName' table setup completed.\n";
}

// Fungsi untuk menandai record yang sudah diproses
function markAsProcessed($db, $chanel, $link) {
    $stmt = $db->prepare("UPDATE folder SET processed = 1 WHERE chanel = :chanel AND links = :link");
    $stmt->bindValue(':chanel', $chanel, SQLITE3_TEXT);
    $stmt->bindValue(':link', $link, SQLITE3_TEXT);
    $stmt->execute();
}

// Fungsi untuk menyisipkan record ke dalam tabel
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
        echo "✔️ $fileName\n";
    } else {
        echo "❌ Failed to insert record: $fileName\n";
        var_dump($db->lastErrorMsg()); // Menampilkan pesan kesalahan dari SQLite
    }
}

// Fungsi untuk membuat folder baru di Dood API
function createDoodFolder($client, $folderName) {
    $url = BASE_API_URL . "/folder/create?key=" . API_KEY . "&name=" . urlencode($folderName);
    $response = $client->request('GET', $url);
    $data = json_decode($response->getBody(), true);

    if ($data['status'] == 200 && isset($data['result']['fld_id'])) {
        return $data['result']['fld_id'];
    }
    return null;
}

// Fungsi untuk melakukan upload file ke Dood API
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

// Koneksi ke database SQLite
$dbName = 'dood.db';
$db = connectDatabase($dbName);

// Membuat atau memperbarui tabel-tabel yang dibutuhkan
createOrUpdateTable($db, 'folder', 'id INTEGER PRIMARY KEY AUTOINCREMENT, chanel TEXT, links TEXT, processed INTEGER DEFAULT 0', []);
createOrUpdateTable($db, 'extract_folder', 'id INTEGER PRIMARY KEY AUTOINCREMENT, chanel TEXT, link TEXT, file_name TEXT, file_url TEXT, folder_name TEXT, fld_id TEXT, file_code TEXT', []);

// Mengambil data yang belum diproses dari tabel 'folder'
$results = $db->query("SELECT chanel, links FROM folder WHERE processed = 0");

// Menggunakan Guzzle HTTP Client untuk permintaan HTTP
$client = new Client();

// Looping untuk setiap data yang belum diproses
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $chanel = $row['chanel'];
    $linkCode = $row['links'];
    $url = BASE_DOOD_URL . "/$linkCode";
    
    try {
        $response = $client->request('GET', $url);
        $html = $response->getBody()->getContents();
        $crawler = new Crawler($html);
        
        // Mendapatkan nama folder dari halaman HTML
        $folderName = $crawler->filter('h1')->text();

        // Membuat folder baru di Dood API
        $fldId = createDoodFolder($client, $folderName);

        // Memproses item-item di dalam list
        $items = $crawler->filter('li');
        foreach ($items as $item) {
            $itemCrawler = new Crawler($item);

            // Mendapatkan informasi file dari setiap item
            if ($itemCrawler->filter('h4')->count() > 0 && $itemCrawler->filter('a')->count() > 0) {
                $fileName = $itemCrawler->filter('h4')->text();
                $fileUrl = $itemCrawler->filter('a')->attr('href');

                // Memproses URL file untuk diupload ke Dood API
                if (preg_match('/\/[ed]\/(.+)/', $fileUrl, $matches)) {
                    $fileUrl = $matches[1];
                    $fileCode = remoteUploadDood($client, "https://d000d.com/$fileUrl", $fldId);
                    insertRecord($db, $chanel, $linkCode, $fileName, $fileUrl, $folderName, $fldId, $fileCode);
                } else {
                    echo "Invalid URL format: $fileUrl\n================\n";
                }
            } else {
                echo "Missing details in list item.\n================\n";
            }
        }

        // Tandai data yang sudah diproses
        markAsProcessed($db, $chanel, $linkCode);
        
    } catch (Exception $e) {
        echo "Failed to process $url: " . $e->getMessage() . "\n================\n";
        markAsProcessed($db, $chanel, $linkCode);
    }
}

echo "Processing complete.\n";
?>