<?php
/**
 * API: AI Assistant untuk analisis stok & penjualan.
 * - Input: JSON { question, keyword?, from?, to? }
 * - Output: { ok, answer, stock[], sales[], daily[] }
 */
session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Pastikan sudah login (admin/pegawai/supplier)
if (!isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Silakan login terlebih dahulu.']);
    exit;
}

// Ambil payload (JSON atau form-urlencoded)
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$question = trim($payload['question'] ?? '');
$keyword  = trim($payload['keyword'] ?? '');
$from     = trim($payload['from'] ?? '');
$to       = trim($payload['to'] ?? '');

if ($question === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Pertanyaan tidak boleh kosong.']);
    exit;
}

// Validasi tanggal & default range (90 hari ke belakang)
$today = (new DateTime('today'))->format('Y-m-d');
$defaultFrom = (new DateTime('today -90 days'))->format('Y-m-d');
if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $defaultFrom;
}
if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $today;
}

$geminiKey = getenv('GEMINI_API_KEY') ?: '';
if ($geminiKey === '' && isset($GLOBALS['GEMINI_API_KEY']) && $GLOBALS['GEMINI_API_KEY']) {
    $geminiKey = $GLOBALS['GEMINI_API_KEY'];
}

$openaiKey = getenv('OPENAI_API_KEY') ?: '';
if ($openaiKey === '' && isset($GLOBALS['OPENAI_API_KEY']) && $GLOBALS['OPENAI_API_KEY']) {
    $openaiKey = $GLOBALS['OPENAI_API_KEY'];
}
$openaiModel = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';

if ($geminiKey === '' && $openaiKey === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Set salah satu: GEMINI_API_KEY atau OPENAI_API_KEY.']);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

/* ========== Helper Schema ========== */
function column_exists(PDO $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t AND COLUMN_NAME = :c";
    $st = $conn->prepare($sql);
    $st->execute([':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
}

function pick_column(PDO $conn, string $table, array $candidates, ?string $fallback = null): ?string {
    foreach ($candidates as $col) {
        if (column_exists($conn, $table, $col)) {
            return $col;
        }
    }
    return $fallback;
}

function resolve_vbarang_schema(PDO $conn): array {
    $kode    = pick_column($conn, 'V_BARANG', ['KODEBRG', 'KODE', 'KODE_BARANG']);
    $nama    = pick_column($conn, 'V_BARANG', ['NAMABRG', 'NAMA', 'NAMA_BARANG']);
    if (!$kode || !$nama) {
        throw new RuntimeException('Kolom kode/nama barang tidak ditemukan di V_BARANG.');
    }

    $artikel    = pick_column($conn, 'V_BARANG', ['ARTIKELBRG', 'ARTIKEL', 'ARTIKEL_BARANG']);
    $lastBuy    = pick_column($conn, 'V_BARANG', ['TGLBELITERAKHIR', 'TGL_BELI_TERAKHIR', 'LASTBUY', 'LAST_BUY', 'LAST_PURCHASE_DATE']);
    $umur       = pick_column($conn, 'V_BARANG', ['UMUR', 'UMUR_STOK', 'UMURHARI']);
    $hargaBeli  = pick_column($conn, 'V_BARANG', ['HARGABELI', 'HGBELI', 'H_BELI']);
    $hargaJual  = pick_column($conn, 'V_BARANG', ['HARGAJUAL', 'HGJUAL', 'H_JUAL']);

    $stokCols = [];
    foreach (['ST00', 'ST01', 'ST02', 'ST03', 'ST04'] as $st) {
        $stokCols[$st] = column_exists($conn, 'V_BARANG', $st);
    }

    return [
        'kode'      => $kode,
        'nama'      => $nama,
        'artikel'   => $artikel,
        'last_buy'  => $lastBuy,
        'umur'      => $umur,
        'harga_beli'=> $hargaBeli,
        'harga_jual'=> $hargaJual,
        'stok_cols' => $stokCols,
    ];
}

/* ========== Data Fetchers ========== */
function fetch_stock(PDO $conn, string $keyword, int $limit = 5): array {
    $schema = resolve_vbarang_schema($conn);
    $stokExprParts = [];
    foreach ($schema['stok_cols'] as $col => $exists) {
        if ($exists) {
            $stokExprParts[] = "ISNULL(v.$col,0)";
        }
    }
    $stokExpr = $stokExprParts ? implode(' + ', $stokExprParts) : '0';

    $fields = "
        TOP $limit
        v.{$schema['kode']} AS kode,
        RTRIM(LTRIM(v.{$schema['nama']})) AS nama," .
        ($schema['artikel'] ? " RTRIM(LTRIM(v.{$schema['artikel']})) AS artikel," : " '-' AS artikel,") .
        "$stokExpr AS stok_total";

    foreach ($schema['stok_cols'] as $col => $exists) {
        if ($exists) {
            $fields .= ", ISNULL(v.$col,0) AS $col";
        }
    }
    if ($schema['umur']) {
        $fields .= ", TRY_CAST(v.{$schema['umur']} AS INT) AS umur_hari";
    } elseif ($schema['last_buy']) {
        $fields .= ", DATEDIFF(DAY, TRY_CONVERT(date, v.{$schema['last_buy']}), CAST(GETDATE() AS date)) AS umur_hari";
    } else {
        $fields .= ", NULL AS umur_hari";
    }
    if ($schema['last_buy']) {
        $fields .= ", TRY_CONVERT(date, v.{$schema['last_buy']}) AS tgl_beli_terakhir";
    }
    if ($schema['harga_beli']) {
        $fields .= ", TRY_CAST(v.{$schema['harga_beli']} AS NUMERIC(18,2)) AS harga_beli";
    }
    if ($schema['harga_jual']) {
        $fields .= ", TRY_CAST(v.{$schema['harga_jual']} AS NUMERIC(18,2)) AS harga_jual";
    }

    $sql = "SELECT $fields FROM V_BARANG v WHERE 1=1";
    $params = [];
    if ($keyword !== '') {
        $sql .= " AND (v.{$schema['kode']} LIKE :kw1 OR v.{$schema['nama']} LIKE :kw2" .
                ($schema['artikel'] ? " OR v.{$schema['artikel']} LIKE :kw2" : "") . ")";
        $params[':kw1'] = $keyword . '%';
        $params[':kw2'] = '%' . $keyword . '%';
    }
    $sql .= " ORDER BY $stokExpr DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_sales(PDO $conn, string $keyword, string $from, string $to, int $limit = 5): array {
    $params = [':from' => $from, ':to' => $to];
    $filter = '';
    if ($keyword !== '') {
        $filter = " AND (KODEBRG LIKE :kw1 OR NAMABRG LIKE :kw2 OR ARTIKELBRG LIKE :kw2)";
        $params[':kw1'] = $keyword . '%';
        $params[':kw2'] = '%' . $keyword . '%';
    }

    $sql = "
        SELECT TOP $limit
            KODEBRG,
            MAX(NAMABRG) AS NAMABRG,
            MAX(ARTIKELBRG) AS ARTIKELBRG,
            SUM(QTY) AS TOTAL_QTY,
            SUM(NETTO) AS TOTAL_NETTO,
            MAX(TGL) AS LAST_SOLD,
            MIN(TGL) AS FIRST_SOLD,
            COUNT(DISTINCT CAST(TGL AS date)) AS ACTIVE_DAYS
        FROM V_JUAL
        WHERE TGL BETWEEN :from AND :to
        $filter
        GROUP BY KODEBRG
        ORDER BY SUM(QTY) DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_daily(PDO $conn, string $keyword, string $from, string $to, int $limit = 30): array {
    $params = [':from' => $from, ':to' => $to];
    $filter = '';
    if ($keyword !== '') {
        $filter = " AND (KODEBRG LIKE :kw1 OR NAMABRG LIKE :kw2 OR ARTIKELBRG LIKE :kw2)";
        $params[':kw1'] = $keyword . '%';
        $params[':kw2'] = '%' . $keyword . '%';
    }

    $sql = "
        SELECT TOP $limit
            CAST(TGL AS date) AS TANGGAL,
            SUM(QTY) AS QTY,
            SUM(NETTO) AS NETTO
        FROM V_JUAL
        WHERE TGL BETWEEN :from AND :to
        $filter
        GROUP BY CAST(TGL AS date)
        ORDER BY CAST(TGL AS date) DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_reverse($rows); // urut kronologis
}

/* ========== OpenAI Helper ========== */
function ask_openai(string $prompt, string $apiKey, string $model = 'gpt-4o-mini'): string {
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Kamu asisten data inventory. Jawab singkat, fokus pada angka, jangan berhalusinasi di luar konteks yang diberikan.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.2,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('OpenAI curl error: ' . $err);
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if ($http >= 300) {
        $msg = $data['error']['message'] ?? $resp;
        throw new Exception("OpenAI error ($http): " . $msg);
    }
    return $data['choices'][0]['message']['content'] ?? 'Tidak ada jawaban dari OpenAI.';
}

/* ========== Ambil Data ========== */
try {
    $stock = fetch_stock($conn, $keyword);
    $sales = fetch_sales($conn, $keyword, $from, $to);
    $daily = fetch_daily($conn, $keyword, $from, $to);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal mengambil data: ' . $e->getMessage()]);
    exit;
}

/* ========== Build Context for Gemini ========== */
$contextParts = [];
$contextParts[] = "PERIODE DATA: $from s/d $to";
if ($keyword !== '') {
    $contextParts[] = "FILTER KATA KUNCI: $keyword";
}

if ($stock) {
    $contextParts[] = "RINGKASAN STOK (V_BARANG):";
    foreach ($stock as $row) {
        $stokDetail = [];
        foreach (['ST00','ST01','ST02','ST03','ST04'] as $st) {
            if (isset($row[$st])) {
                $stokDetail[] = "$st={$row[$st]}";
            }
        }
        $contextParts[] = "- {$row['kode']} | {$row['nama']} (artikel: {$row['artikel']}); stok_total={$row['stok_total']}; " .
            'rinci_stok=' . implode(',', $stokDetail) .
            '; umur_hari=' . ($row['umur_hari'] ?? '-') .
            '; tgl_beli_terakhir=' . ($row['tgl_beli_terakhir'] ?? '-');
    }
}

if ($sales) {
    $contextParts[] = "RINGKASAN PENJUALAN (V_JUAL):";
    foreach ($sales as $row) {
        $contextParts[] = "- {$row['KODEBRG']} | {$row['NAMABRG']} (artikel: {$row['ARTIKELBRG']}); qty={$row['TOTAL_QTY']}; omzet={$row['TOTAL_NETTO']}; " .
            "hari_aktif={$row['ACTIVE_DAYS']}; terakhir_terjual={$row['LAST_SOLD']}";
    }
}

if ($daily) {
    $contextParts[] = "TREND HARIAN (TANGGAL, QTY, OMZET):";
    foreach ($daily as $row) {
        $contextParts[] = "- {$row['TANGGAL']}: qty={$row['QTY']}; omzet={$row['NETTO']}";
    }
}

$context = implode("\n", $contextParts);

/* ========== AI Call (OpenAI > Gemini fallback) ========== */
$prompt = "Kamu adalah asisten data untuk tim inventory Mode Stok. Jawab singkat, fokus pada angka, dan beri rekomendasi jika diminta."
    . "\nGunakan data pada bagian CONTEXT saja, jika tidak ada datanya jawab jujur.\n"
    . "\nPERTANYAAN: $question\n\nCONTEXT:\n$context";

$answer = null;
$provider = null;
$errors = [];

// 1) Coba OpenAI jika tersedia
if ($openaiKey) {
    try {
        $answer = ask_openai($prompt, $openaiKey, $openaiModel);
        $provider = 'openai';
    } catch (Exception $e) {
        $errors[] = 'OpenAI: ' . $e->getMessage();
    }
}

// 2) Fallback ke Gemini jika OpenAI tidak ada/juga error
if ($answer === null && $geminiKey) {
    try {
        $client = Gemini::client($geminiKey);
        $preferred = getenv('GEMINI_MODEL');
        $modelNames = $preferred
            ? [$preferred, 'gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-flash-8b']
            : ['gemini-2.5-pro', 'gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-flash-8b'];
        $model = null;
        $lastErr = null;
        foreach ($modelNames as $mn) {
            try {
                $model = $client->generativeModel($mn);
                $modelNameUsed = $mn;
                break;
            } catch (Exception $e) {
                $lastErr = $e;
                continue;
            }
        }
        if (!$model) {
            throw $lastErr ?: new Exception('Tidak ada model Gemini yang tersedia.');
        }
        $result = $model->generateContent($prompt);
        $answer = $result->text() ?? 'Tidak ada jawaban dari model.';
        $provider = 'gemini';
    } catch (Exception $e) {
        $errors[] = 'Gemini: ' . $e->getMessage();
    }
}

if ($answer === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memanggil AI: ' . implode(' | ', $errors), 'context' => $contextParts]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'answer'  => $answer,
    'provider'=> $provider,
    'context' => $contextParts,
    'stock'   => $stock,
    'sales'   => $sales,
    'daily'   => $daily,
    'range'   => ['from' => $from, 'to' => $to],
]);
