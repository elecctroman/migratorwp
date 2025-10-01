<?php
# Cron job example: */30 * * * * /usr/bin/php /path/to/sync_services.php > /dev/null 2>&1

declare(strict_types=1);

const API_URL = 'https://fastersmm.com/api/v2';
const API_KEY = 'BENIM_API_KEYIM';
const API_ACTION = 'services';
const STORAGE_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'services.json';

try {
    $services = fetch_services();
    $existingServices = load_existing_services(STORAGE_FILE);

    $newServices = detect_new_services($services, $existingServices);

    save_services(STORAGE_FILE, $services);

    output_result($newServices);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Hata: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * Perform the API request to retrieve services.
 *
 * @return array<int, array<string, mixed>>
 */
function fetch_services(): array
{
    $payload = [
        'key'    => API_KEY,
        'action' => API_ACTION,
    ];

    $ch = curl_init(API_URL);

    if ($ch === false) {
        throw new RuntimeException('cURL başlatılamadı.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('API isteği başarısız: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('API beklenmeyen HTTP durum kodu döndürdü: ' . $statusCode);
    }

    $decoded = json_decode($response, true);

    if ($decoded === null) {
        throw new RuntimeException('API yanıtı JSON formatında değil.');
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('API yanıtı beklenen formatta değil.');
    }

    return array_values($decoded);
}

/**
 * Load existing services from storage file if present.
 *
 * @param string $file
 * @return array<int, array<string, mixed>>
 */
function load_existing_services(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $contents = file_get_contents($file);

    if ($contents === false) {
        throw new RuntimeException('services.json dosyası okunamadı.');
    }

    $decoded = json_decode($contents, true);

    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('services.json dosyası bozuk JSON içeriyor.');
    }

    if (!is_array($decoded)) {
        return [];
    }

    return array_values($decoded);
}

/**
 * Detect services that are not yet present in the stored list.
 *
 * @param array<int, array<string, mixed>> $currentServices
 * @param array<int, array<string, mixed>> $existingServices
 * @return array<int, array<string, mixed>>
 */
function detect_new_services(array $currentServices, array $existingServices): array
{
    $existingIds = [];

    foreach ($existingServices as $service) {
        if (isset($service['service'])) {
            $existingIds[(string) $service['service']] = true;
        } elseif (isset($service['service_id'])) {
            $existingIds[(string) $service['service_id']] = true;
        }
    }

    $newServices = [];

    foreach ($currentServices as $service) {
        $serviceId = null;

        if (isset($service['service'])) {
            $serviceId = (string) $service['service'];
        } elseif (isset($service['service_id'])) {
            $serviceId = (string) $service['service_id'];
        }

        if ($serviceId === null) {
            continue;
        }

        if (!isset($existingIds[$serviceId])) {
            $newServices[] = $service;
        }
    }

    return $newServices;
}

/**
 * Persist the full service list to the storage file.
 *
 * @param string $file
 * @param array<int, array<string, mixed>> $services
 * @return void
 */
function save_services(string $file, array $services): void
{
    $encoded = json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        throw new RuntimeException("Servis listesi JSON'a dönüştürülemedi.");
    }

    if (file_put_contents($file, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('services.json dosyası yazılamadı.');
    }
}

/**
 * Output the results according to requirements.
 *
 * @param array<int, array<string, mixed>> $newServices
 * @return void
 */
function output_result(array $newServices): void
{
    $count = count($newServices);

    if ($count === 0) {
        echo 'Yeni servis yok' . PHP_EOL;
        return;
    }

    echo 'Yeni servis sayısı: ' . $count . PHP_EOL;
    echo json_encode($newServices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
