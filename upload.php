<?php
// upload.php - полностью исправленная версия

// Включаем вывод ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Заголовки для правильного JSON ответа
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запроса (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Конфигурация
$uploadDir = 'uploads/';
$baseUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/';

// Создаем папку, если её нет
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        echo json_encode(['error' => 'Cannot create uploads directory']);
        exit();
    }
}

// Проверяем права на запись
if (!is_writable($uploadDir)) {
    echo json_encode(['error' => 'Uploads directory is not writable']);
    exit();
}

// Функция для безопасного имени файла
function cleanFileName($fileName) {
    // Сохраняем оригинальное имя, но удаляем опасные символы
    $fileName = preg_replace('/[^a-zA-Z0-9_\-\.\sа-яА-Я]/u', '_', $fileName);
    // Добавляем timestamp, чтобы избежать конфликтов
    $ext = pathinfo($fileName, PATHINFO_EXTENSION);
    $name = pathinfo($fileName, PATHINFO_FILENAME);
    return $name . '_' . time() . '.' . $ext;
}

// Получаем параметры от Resumable.js
$resumableIdentifier = isset($_REQUEST['resumableIdentifier']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_REQUEST['resumableIdentifier']) : null;
$resumableFilename = isset($_REQUEST['resumableFilename']) ? cleanFileName($_REQUEST['resumableFilename']) : null;
$resumableChunkNumber = isset($_REQUEST['resumableChunkNumber']) ? (int)$_REQUEST['resumableChunkNumber'] : null;
$resumableTotalChunks = isset($_REQUEST['resumableTotalChunks']) ? (int)$_REQUEST['resumableTotalChunks'] : null;
$resumableTotalSize = isset($_REQUEST['resumableTotalSize']) ? (int)$_REQUEST['resumableTotalSize'] : null;

// Проверяем обязательные параметры
if (!$resumableIdentifier || !$resumableFilename) {
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

// Создаем временную директорию
$tempDir = $uploadDir . 'temp_' . $resumableIdentifier . '/';
if (!file_exists($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Обработка загрузки чанка
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Получаем данные чанка
    $chunkData = null;
    
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $chunkData = file_get_contents($_FILES['file']['tmp_name']);
    } else {
        $chunkData = file_get_contents('php://input');
    }
    
    if ($chunkData === false || empty($chunkData)) {
        echo json_encode(['error' => 'Cannot read chunk data']);
        exit();
    }
    
    // Сохраняем чанк
    $chunkFile = $tempDir . 'chunk_' . $resumableChunkNumber;
    if (file_put_contents($chunkFile, $chunkData) === false) {
        echo json_encode(['error' => 'Cannot save chunk']);
        exit();
    }
    
    // Проверяем, все ли чанки загружены
    $allChunksLoaded = true;
    $loadedChunks = 0;
    
    for ($i = 1; $i <= $resumableTotalChunks; $i++) {
        if (file_exists($tempDir . 'chunk_' . $i)) {
            $loadedChunks++;
        } else {
            $allChunksLoaded = false;
        }
    }
    
    // Если все чанки загружены - собираем файл
    if ($allChunksLoaded && $resumableTotalChunks > 0) {
        $finalFilePath = $uploadDir . $resumableFilename;
        $finalFile = fopen($finalFilePath, 'wb');
        
        if (!$finalFile) {
            echo json_encode(['error' => 'Cannot create final file']);
            exit();
        }
        
        // Объединяем все чанки
        for ($i = 1; $i <= $resumableTotalChunks; $i++) {
            $chunkPath = $tempDir . 'chunk_' . $i;
            $chunkContent = file_get_contents($chunkPath);
            fwrite($finalFile, $chunkContent);
            fclose(fopen($chunkPath, 'a')); // Освобождаем файл
            unlink($chunkPath);
        }
        
        fclose($finalFile);
        
        // Проверяем размер
        if ($resumableTotalSize > 0 && filesize($finalFilePath) != $resumableTotalSize) {
            unlink($finalFilePath);
            echo json_encode(['error' => 'File size mismatch after assembly']);
            exit();
        }
        
        // Удаляем временную папку
        rmdir($tempDir);
        
        // Возвращаем успешный ответ С ССЫЛКОЙ НА ФАЙЛ
        $downloadUrl = $baseUrl . basename($finalFilePath);
        
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'filename' => basename($finalFilePath),
            'originalName' => $resumableFilename,
            'size' => filesize($finalFilePath),
            'downloadUrl' => $downloadUrl,
            'deleteUrl' => 'delete.php?file=' . urlencode(basename($finalFilePath))
        ]);
        exit();
    }
    
    // Если не все чанки, возвращаем прогресс
    echo json_encode([
        'success' => true,
        'chunk' => $resumableChunkNumber,
        'loaded' => $loadedChunks,
        'total' => $resumableTotalChunks,
        'progress' => round(($loadedChunks / $resumableTotalChunks) * 100, 2)
    ]);
    exit();
}

// Проверка существования чанка (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $chunkFile = $tempDir . 'chunk_' . $resumableChunkNumber;
    if (file_exists($chunkFile)) {
        http_response_code(200);
        echo json_encode(['chunkExists' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['chunkExists' => false]);
    }
    exit();
}

echo json_encode(['error' => 'Method not allowed']);
