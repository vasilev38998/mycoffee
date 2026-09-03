<?php
declare(strict_types=1);

function evotor_crypto_key(): string
{
    global $config;
    $secret = $config['security']['encryption_key'] ?? (($config['db']['name'] ?? '') . '|' . ($config['db']['user'] ?? '') . '|' . ($config['db']['pass'] ?? ''));
    return hash('sha256', (string)$secret, true);
}

function evotor_encrypt_token(string $token): array
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($token, 'aes-256-gcm', evotor_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Не удалось зашифровать токен Эвотор.');
    }
    return [base64_encode($cipher), base64_encode($iv), base64_encode($tag)];
}

function evotor_decrypt_token(array $connection): string
{
    $token = openssl_decrypt(
        base64_decode((string)$connection['token_ciphertext'], true),
        'aes-256-gcm',
        evotor_crypto_key(),
        OPENSSL_RAW_DATA,
        base64_decode((string)$connection['token_iv'], true),
        base64_decode((string)$connection['token_tag'], true)
    );
    if ($token === false) {
        throw new RuntimeException('Не удалось расшифровать токен Эвотор.');
    }
    return $token;
}

function evotor_request(array $connection, string $path, array $query = []): array
{
    $url = 'https://api.evotor.ru' . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.evotor.v2+json;charset=UTF-8',
            'Authorization: ' . evotor_decrypt_token($connection),
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Ошибка соединения с Эвотор при запросе ' . $path . ': ' . $error);
    }

    $data = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$body;
        throw new RuntimeException("Эвотор API вернул HTTP {$status} при запросе {$path}: {$message}");
    }
    if (!is_array($data)) {
        throw new RuntimeException('Эвотор API вернул некорректный JSON при запросе ' . $path . '.');
    }
    return $data;
}

function evotor_connection(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM evotor_connections WHERE id=? AND enabled=1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function evotor_sync_products(array $connection): int
{
    $pdo = db();
    $processed = 0;
    $cursor = null;
    $first = true;
    $syncUntilMs = (int)floor(microtime(true) * 1000);

    do {
        $query = [];
        if ($first && !empty($connection['last_products_sync_ms'])) {
            $query['since'] = (int)$connection['last_products_sync_ms'];
        }
        if ($cursor) {
            $query = ['cursor' => $cursor];
        }

        $response = evotor_request($connection, '/stores/' . rawurlencode($connection['store_id']) . '/products', $query);
        foreach (($response['items'] ?? []) as $item) {
            if (empty($item['id']) || empty($item['name'])) continue;

            $pdo->beginTransaction();
            try {
                $find = $pdo->prepare('SELECT local_product_id FROM evotor_products WHERE connection_id=? AND evotor_product_id=?');
                $find->execute([(int)$connection['id'], (string)$item['id']]);
                $localId = $find->fetchColumn();

                if (!$localId) {
                    $local = $pdo->prepare('INSERT INTO products(name,category,sale_price,active) VALUES(?,?,?,?)');
                    $local->execute([(string)$item['name'], 'Эвотор', (float)($item['price'] ?? 0), !empty($item['allow_to_sell']) ? 1 : 0]);
                    $localId = (int)$pdo->lastInsertId();
                } else {
                    $local = $pdo->prepare('UPDATE products SET name=?,sale_price=?,active=? WHERE id=?');
                    $local->execute([(string)$item['name'], (float)($item['price'] ?? 0), !empty($item['allow_to_sell']) ? 1 : 0, (int)$localId]);
                }

                $upsert = $pdo->prepare('INSERT INTO evotor_products(connection_id,evotor_product_id,local_product_id,code,name,price,cost_price,quantity,measure_name,updated_at_evotor,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE local_product_id=VALUES(local_product_id),code=VALUES(code),name=VALUES(name),price=VALUES(price),cost_price=VALUES(cost_price),quantity=VALUES(quantity),measure_name=VALUES(measure_name),updated_at_evotor=VALUES(updated_at_evotor),raw_json=VALUES(raw_json)');
                $upsert->execute([
                    (int)$connection['id'], (string)$item['id'], (int)$localId,
                    $item['code'] ?? null, (string)$item['name'], (float)($item['price'] ?? 0),
                    (float)($item['cost_price'] ?? 0), array_key_exists('quantity', $item) ? (float)$item['quantity'] : null,
                    $item['measure_name'] ?? null, $item['updated_at'] ?? null,
                    json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);
                $pdo->commit();
                $processed++;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        $cursor = $response['paging']['next_cursor'] ?? null;
        $first = false;
    } while ($cursor);

    $stmt = $pdo->prepare('UPDATE evotor_connections SET last_products_sync_ms=? WHERE id=?');
    $stmt->execute([$syncUntilMs, (int)$connection['id']]);
    return $processed;
}

function evotor_payment_method(array $body): string
{
    $hasCash = false;
    $hasElectron = false;
    foreach (($body['payments'] ?? []) as $payment) {
        $hasCash = $hasCash || (($payment['type'] ?? '') === 'CASH');
        $hasElectron = $hasElectron || (($payment['type'] ?? '') === 'ELECTRON');
    }
    if ($hasCash && !$hasElectron) return 'cash';
    if ($hasElectron && !$hasCash) return 'card';
    return 'other';
}

function evotor_position_product_id(array $position): string
{
    foreach (['product_id', 'productId', 'product_uuid', 'productUuid'] as $key) {
        if (isset($position[$key]) && is_scalar($position[$key]) && trim((string)$position[$key]) !== '') {
            return trim((string)$position[$key]);
        }
    }
    return '';
}

function evotor_local_product_id(int $connectionId, array $position): int
{
    $pdo = db();
    $evotorProductId = evotor_position_product_id($position);
    if ($evotorProductId !== '') {
        $stmt = $pdo->prepare('SELECT local_product_id FROM evotor_products WHERE connection_id=? AND evotor_product_id=?');
        $stmt->execute([$connectionId, $evotorProductId]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) return $id;
    }

    $name = trim((string)($position['name'] ?? $position['product_name'] ?? $position['productName'] ?? 'Позиция Эвотор'));
    if ($name === '') $name = 'Позиция Эвотор';
    $price = (float)($position['result_price'] ?? $position['resultPrice'] ?? $position['price'] ?? 0);

    // Если ID товара в чеке отсутствует, переиспользуем уже загруженную номенклатуру по имени и цене.
    $stmt = $pdo->prepare('SELECT local_product_id FROM evotor_products WHERE connection_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND ABS(price-?) < 0.01 AND local_product_id IS NOT NULL ORDER BY id LIMIT 1');
    $stmt->execute([$connectionId, $name, $price]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) return $id;

    // Fallback для чеков, пришедших раньше полной синхронизации номенклатуры.
    $stmt = $pdo->prepare("SELECT id FROM products WHERE category='Эвотор' AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND ABS(sale_price-?) < 0.01 ORDER BY id LIMIT 1");
    $stmt->execute([$name, $price]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) return $id;

    $stmt = $pdo->prepare('INSERT INTO products(name,category,sale_price,active) VALUES(?,?,?,1)');
    $stmt->execute([$name, 'Эвотор', $price]);
    return (int)$pdo->lastInsertId();
}

function evotor_cleanup_orphan_product_duplicates(int $connectionId): int
{
    $pdo = db();
    $orphans = $pdo->query("SELECT p.id,p.name,p.sale_price FROM products p LEFT JOIN evotor_products ep ON ep.local_product_id=p.id WHERE p.category='Эвотор' AND ep.id IS NULL ORDER BY p.id")->fetchAll();
    $cleaned = 0;

    foreach ($orphans as $orphan) {
        $recipe = $pdo->prepare('SELECT COUNT(*) FROM recipe_items WHERE product_id=?');
        $recipe->execute([(int)$orphan['id']]);
        if ((int)$recipe->fetchColumn() > 0) continue;

        $canonical = $pdo->prepare("SELECT p.id FROM products p JOIN evotor_products ep ON ep.local_product_id=p.id WHERE ep.connection_id=? AND LOWER(TRIM(p.name))=LOWER(TRIM(?)) AND ABS(p.sale_price-?) < 0.01 ORDER BY p.id LIMIT 1");
        $canonical->execute([$connectionId, (string)$orphan['name'], (float)$orphan['sale_price']]);
        $canonicalId = (int)$canonical->fetchColumn();

        if ($canonicalId <= 0) {
            $canonical = $pdo->prepare("SELECT id FROM products WHERE category='Эвотор' AND id < ? AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND ABS(sale_price-?) < 0.01 ORDER BY id LIMIT 1");
            $canonical->execute([(int)$orphan['id'], (string)$orphan['name'], (float)$orphan['sale_price']]);
            $canonicalId = (int)$canonical->fetchColumn();
        }

        if ($canonicalId <= 0 || $canonicalId === (int)$orphan['id']) continue;

        $pdo->beginTransaction();
        try {
            $updateSales = $pdo->prepare('UPDATE sale_items SET product_id=? WHERE product_id=?');
            $updateSales->execute([$canonicalId, (int)$orphan['id']]);
            $delete = $pdo->prepare('DELETE FROM products WHERE id=?');
            $delete->execute([(int)$orphan['id']]);
            $pdo->commit();
            $cleaned++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    return $cleaned;
}

function evotor_document_datetime(array $document): string
{
    $date = new DateTime((string)($document['close_date'] ?? 'now'));
    global $config;
    $date->setTimezone(new DateTimeZone($config['app']['timezone'] ?? 'Europe/Moscow'));
    return $date->format('Y-m-d H:i:s');
}

function evotor_import_receipt(array $connection, array $document): ?int
{
    if (!in_array($document['type'] ?? '', ['SELL','PAYBACK'], true)) return null;
    $pdo = db();
    $body = is_array($document['body'] ?? null) ? $document['body'] : [];
    $sign = ($document['type'] === 'PAYBACK') ? -1 : 1;
    $total = (float)($body['result_sum'] ?? 0) * $sign;

    $sale = $pdo->prepare('INSERT INTO sales(sold_at,total_amount,payment_method,note) VALUES(?,?,?,?)');
    $sale->execute([evotor_document_datetime($document), $total, evotor_payment_method($body), 'Импорт Эвотор: ' . ($document['id'] ?? '')]);
    $saleId = (int)$pdo->lastInsertId();

    foreach (($body['positions'] ?? []) as $position) {
        $localId = evotor_local_product_id((int)$connection['id'], $position);
        $quantity = (float)($position['quantity'] ?? 1) * $sign;
        $unitPrice = (float)($position['result_price'] ?? $position['resultPrice'] ?? $position['price'] ?? 0);
        $unitCost = product_cost($localId);
        $item = $pdo->prepare('INSERT INTO sale_items(sale_id,product_id,quantity,unit_price,unit_cost) VALUES(?,?,?,?,?)');
        $item->execute([$saleId, $localId, $quantity, $unitPrice, $unitCost]);
    }
    return $saleId;
}

function evotor_sync_documents(array $connection): int
{
    $pdo = db();
    $processed = 0;
    $cursor = null;
    $first = true;
    $syncUntilMs = (int)floor(microtime(true) * 1000);

    do {
        $query = [];
        if ($first) {
            if (!empty($connection['last_documents_sync_ms'])) $query['since'] = (int)$connection['last_documents_sync_ms'];
            $query['until'] = $syncUntilMs;
            $query['type'] = 'SELL,PAYBACK';
        } elseif ($cursor) {
            $query = ['cursor' => $cursor];
        }

        $response = evotor_request($connection, '/stores/' . rawurlencode($connection['store_id']) . '/documents', $query);
        foreach (($response['items'] ?? []) as $document) {
            if (empty($document['id'])) continue;
            $exists = $pdo->prepare('SELECT id FROM evotor_documents WHERE connection_id=? AND evotor_document_id=?');
            $exists->execute([(int)$connection['id'], (string)$document['id']]);
            if ($exists->fetchColumn()) continue;

            $pdo->beginTransaction();
            try {
                $saleId = evotor_import_receipt($connection, $document);
                $body = is_array($document['body'] ?? null) ? $document['body'] : [];
                $insert = $pdo->prepare('INSERT INTO evotor_documents(connection_id,evotor_document_id,document_type,document_number,device_id,session_id,session_number,close_date,result_sum,imported_sale_id,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                $insert->execute([
                    (int)$connection['id'], (string)$document['id'], (string)($document['type'] ?? 'UNKNOWN'),
                    $document['number'] ?? null, $document['device_id'] ?? null, $document['session_id'] ?? null,
                    $document['session_number'] ?? null, evotor_document_datetime($document),
                    isset($body['result_sum']) ? (float)$body['result_sum'] : null, $saleId,
                    json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);
                $pdo->commit();
                $processed++;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        $cursor = $response['paging']['next_cursor'] ?? null;
        $first = false;
    } while ($cursor);

    $stmt = $pdo->prepare('UPDATE evotor_connections SET last_documents_sync_ms=? WHERE id=?');
    $stmt->execute([$syncUntilMs, (int)$connection['id']]);
    return $processed;
}

function evotor_run_sync(array $connection, string $type = 'full'): array
{
    $started = date('Y-m-d H:i:s');
    $count = 0;
    $cleaned = 0;
    try {
        if ($type === 'products' || $type === 'full') {
            try {
                $count += evotor_sync_products($connection);
            } catch (Throwable $e) {
                throw new RuntimeException('Ошибка синхронизации номенклатуры: ' . $e->getMessage(), 0, $e);
            }
        }

        $connection = evotor_connection((int)$connection['id']) ?? $connection;

        if ($type === 'documents' || $type === 'full') {
            try {
                $count += evotor_sync_documents($connection);
            } catch (Throwable $e) {
                throw new RuntimeException('Ошибка синхронизации чеков: ' . $e->getMessage(), 0, $e);
            }
        }

        $cleaned = evotor_cleanup_orphan_product_duplicates((int)$connection['id']);
        $status = 'success';
        $message = $cleaned > 0 ? 'Удалено дублей позиций: ' . $cleaned : null;
    } catch (Throwable $e) {
        $status = 'error';
        $message = $e->getMessage();
    }

    $stmt = db()->prepare('INSERT INTO evotor_sync_log(connection_id,sync_type,status,processed_count,message,started_at,finished_at) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([(int)$connection['id'], $type, $status, $count, $message, $started, date('Y-m-d H:i:s')]);

    if ($status === 'error') throw new RuntimeException((string)$message);
    return ['status'=>$status,'processed'=>$count,'cleaned'=>$cleaned];
}
