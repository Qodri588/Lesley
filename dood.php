<?php
require 'vendor/autoload.php';
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

const API_KEY = '350871o0uomobcm787efod';
const BASE_API_URL = 'https://doodapi.com/api';
const BASE_DOOD_URL = 'https://d000d.com/f';

function createDatabase($dbName) {
    if (!file_exists($dbName)) {
        $db = new SQLite3($dbName);
        echo "Database '$dbName' created.\n";
    } else {
        $db = new SQLite3($dbName);
    }
    return $db;
}

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
    
    echo "'$tableName' table setup completed.\n";
}

function markAsProcessed($db, $chanel, $link) {
    $stmt = $db->prepare("UPDATE folder SET processed = 1 WHERE chanel = :chanel AND links = :link");
    $stmt->bindValue(':chanel', $chanel, SQLITE3_TEXT);
    $stmt->bindValue(':link', $link, SQLITE3_TEXT);
    $stmt->execute();
}

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
        echo "❌ $fileName\n";
    }
}

function createDoodFolder($client, $folderName) {
    $url = BASE_API_URL . "/folder/create?key=" . API_KEY . "&name=" . urlencode($folderName);
    $response = $client->request('GET', $url);
    $data = json_decode($response->getBody(), true);

    if ($data['status'] == 200 && isset($data['result']['fld_id'])) {
        return $data['result']['fld_id'];
    }
    return null;
}

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

$dbName = 'dood.db';

$db = createDatabase($dbName);
createOrUpdateTable($db, 'folder', 'id INTEGER PRIMARY KEY AUTOINCREMENT, chanel TEXT, links TEXT', [
    'processed' => 'INTEGER DEFAULT 0'
]);
createOrUpdateTable($db, 'extract_folder', 'id INTEGER PRIMARY KEY AUTOINCREMENT, chanel TEXT, link TEXT, file_name TEXT, file_url TEXT, folder_name TEXT, fld_id TEXT, file_code TEXT', []);

$results = $db->query("SELECT chanel, links FROM folder WHERE processed = 0");

$client = new Client();

while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $chanel = $row['chanel'];
    $linkCode = $row['links'];
    $url = BASE_DOOD_URL . "/$linkCode";
    
    try {
        $response = $client->request('GET', $url);
        $html = $response->getBody()->getContents();
        $crawler = new Crawler($html);
        
        if ($crawler->filter('h1')->count() > 0) {
            $folderName = $crawler->filter('h1')->text();
        } else {
            echo "No folder name found for URL: $url\n================\n";
            markAsProcessed($db, $chanel, $linkCode);
            continue;
        }

        $fldId = createDoodFolder($client, $folderName);
        if (!$fldId) {
            echo "Failed to create folder: $folderName\n================\n";
            markAsProcessed($db, $chanel, $linkCode);
            continue;
        }

        if ($crawler->filter('li')->count() > 0) {
            $items = $crawler->filter('li');

            foreach ($items as $item) {
                $itemCrawler = new Crawler($item);

                if ($itemCrawler->filter('h4')->count() > 0 && $itemCrawler->filter('a')->count() > 0) {
                    $fileName = $itemCrawler->filter('h4')->text();
                    $fileUrl = $itemCrawler->filter('a')->attr('href');

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
        } else {
            echo "No items found for URL: $url\n================\n";
        }

        markAsProcessed($db, $chanel, $linkCode);
        
    } catch (Exception $e) {
        echo "Failed to process $url: " . $e->getMessage() . "\n================\n";
        markAsProcessed($db, $chanel, $linkCode);
    }
}

echo "Processing complete.\n";
?>