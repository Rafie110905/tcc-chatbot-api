<?php
/**
 * ============================================================
 * KONFIGURASI AI ASSISTANT — TCC CHATBOT (Google Gemini, GRATIS)
 * ============================================================
 * Di Render, JANGAN taruh API key di file ini. Set lewat
 * Environment Variable di dashboard Render:
 *   Key   = GEMINI_API_KEY
 *   Value = AIza... (key dari https://aistudio.google.com/apikey)
 *
 * Kalau env var enggak diisi, fallback ke konstanta di bawah
 * (dikosongin sengaja supaya kamu enggak lupa isi API key
 * lewat env var, bukan hardcode di sini).
 * ============================================================
 */

$envKey = getenv('GEMINI_API_KEY');
define('GEMINI_API_KEY', $envKey !== false ? $envKey : '');

$envModel = getenv('GEMINI_MODEL');
define('GEMINI_MODEL', $envModel !== false && $envModel !== '' ? $envModel : 'gemini-3.6-flash');
