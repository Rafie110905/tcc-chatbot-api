<?php
/**
 * ============================================================
 * ENDPOINT CHAT — AI ASSISTANT TCC (versi Render, dengan CORS)
 * ============================================================
 * Sama seperti versi InfinityFree, tapi:
 * - Ditambah header CORS supaya bisa dipanggil dari domain lain
 *   (situs utama di InfinityFree).
 * - Origin yang diizinkan diambil dari env var ALLOWED_ORIGIN
 *   (isi di dashboard Render). Default "*" untuk testing awal.
 * ============================================================
 */

// ---------- CORS ----------
$allowedOrigin = getenv('ALLOWED_ORIGIN');
if ($allowedOrigin === false || $allowedOrigin === '') {
  $allowedOrigin = '*'; // ganti ke domain situsmu di env var setelah testing berhasil
}
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Browser mengirim preflight OPTIONS dulu sebelum POST lintas-domain
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../includes/ai-config.php';
require __DIR__ . '/../includes/lomba-data.php';

function jsonError($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['error' => $msg]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jsonError('Method not allowed', 405);
}

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '' || GEMINI_API_KEY === 'GANTI_DENGAN_API_KEY_ANDA') {
  jsonError('Chatbot belum dikonfigurasi. Admin perlu isi env var GEMINI_API_KEY di dashboard Render.', 503);
}

$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($userMessage === '') {
  jsonError('Pesan tidak boleh kosong.');
}
$msgLength = function_exists('mb_strlen') ? mb_strlen($userMessage) : strlen($userMessage);
if ($msgLength > 1000) {
  jsonError('Pesan terlalu panjang (maks. 1000 karakter).');
}

// ------------------------------------------------------------
// Bangun konteks dari data lomba supaya jawaban selalu akurat
// ------------------------------------------------------------
function format_lomba_context($tcc_lomba, $tcc_timeline_default, $tcc_hadiah_default) {
  $out = '';
  foreach ($tcc_lomba as $slug => $l) {
    $timeline = $l['timeline'] ?? $tcc_timeline_default;
    $hadiah   = $l['hadiah'] ?? $tcc_hadiah_default;

    $out .= "\n### {$l['title']} (slug: {$slug})\n";
    $out .= "- Format peserta: {$l['format']}\n";
    $out .= "- Deskripsi: {$l['description']}\n";
    $out .= "- Subtema: " . implode(', ', $l['subtema']) . "\n";
    $out .= "- Link daftar: {$l['link_daftar']}\n";
    $out .= "- Biaya registrasi per gelombang:\n";
    foreach ($l['gelombang'] as $g) {
      $status = $g['status'] === 'open' ? 'OPEN (masih bisa daftar)' : 'CLOSED (sudah tutup)';
      $out .= "  - {$g['nama']}: {$g['harga']} / {$l['format']} — status: {$status}\n";
    }
    $out .= "- Hadiah: " . implode(' / ', array_map(fn($h) => $h['label'], $hadiah)) .
             " (masing-masing dapat: " . implode(', ', $hadiah[0]['items']) . ")\n";
  }

  $out .= "\n### Timeline umum TCC 2026 (berlaku untuk semua lomba kecuali disebutkan lain)\n";
  foreach ($tcc_timeline_default as $t) {
    $out .= "- [{$t['mode']}] {$t['date']}: {$t['title']}\n";
  }

  return $out;
}

$lombaContext = format_lomba_context($tcc_lomba, $tcc_timeline_default, $tcc_hadiah_default);

$systemPrompt = <<<PROMPT
Kamu adalah "TCC Assistant", asisten virtual resmi di situs UKM Creative Computer Club (Triple-C)
Universitas Trunojoyo Madura, khusus membantu calon peserta Trunojoyo Creative Competition (TCC) 2026.

Tugasmu: jawab pertanyaan seputar TCC 2026 (biaya registrasi, gelombang, timeline, hadiah, syarat
peserta, subtema, cara daftar) secara singkat, ramah, dan akurat, HANYA berdasarkan data resmi berikut.
Gunakan Bahasa Indonesia santai tapi sopan. Jawaban singkat (maks 4-5 kalimat / gunakan poin kalau perlu).

Jika ditanya hal di luar cakupan TCC/Triple-C (misalnya soal topik umum, coding, dsb), tolak dengan
sopan dan arahkan kembali ke topik TCC. Jika informasi tidak ada di data berikut, jangan mengarang —
katakan belum ada info dan sarankan hubungi panitia (WA/Email di bagian Kontak).

=== DATA RESMI TCC 2026 ===
{$lombaContext}

### Kontak Panitia
- Instagram: @ukmtriplec
- Email: triplec@trunojoyo.ac.id
- WhatsApp: +62 857-0817-8332
- Sekretariat: Jl. Raya Telang, Kec. Kamal, Bangkalan, Madura 69162
PROMPT;

// ------------------------------------------------------------
// Susun riwayat percakapan (dibatasi 6 pesan terakhir biar ringkas)
// Gemini pakai role 'user' dan 'model' (bukan 'assistant')
// ------------------------------------------------------------
$contents = [];
$recentHistory = array_slice($history, -6);
foreach ($recentHistory as $h) {
  if (!isset($h['role'], $h['content'])) continue;
  $role = $h['role'] === 'assistant' ? 'model' : $h['role'];
  if (!in_array($role, ['user', 'model'], true)) continue;
  $contents[] = ['role' => $role, 'parts' => [['text' => (string) $h['content']]]];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

// ------------------------------------------------------------
// Panggil Google Gemini API
// ------------------------------------------------------------
$payload = json_encode([
  'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
  'contents'            => $contents,
  'generationConfig'    => [
    'maxOutputTokens' => 1024,
    'thinkingConfig'  => ['thinkingLevel' => 'low'],
  ],
]);

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent';

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    'x-goog-api-key: ' . GEMINI_API_KEY,
  ],
  CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
  jsonError('Gagal menghubungi server AI: ' . $curlErr, 502);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
  $apiMsg = $data['error']['message'] ?? 'Terjadi kesalahan pada server AI.';
  // Pesan lebih ramah untuk kasus umum: kuota gratis habis
  if ($httpCode === 429) {
    $apiMsg = 'Kuota gratis chatbot sedang penuh (batas per menit/hari). Coba lagi sebentar lagi ya.';
  }
  jsonError($apiMsg, $httpCode);
}

$reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

if ($reply === '') {
  // Kemungkinan diblokir safety filter Gemini
  $finishReason = $data['candidates'][0]['finishReason'] ?? '';
  if ($finishReason === 'SAFETY') {
    jsonError('Pertanyaan tidak bisa dijawab (terfilter sistem). Coba pertanyaan lain ya.', 400);
  }
  jsonError('AI tidak memberikan balasan. Coba lagi.', 502);
}

echo json_encode(['reply' => $reply]);
