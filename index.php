<?php
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'message' => 'TCC Chatbot API is running. POST to /api/tcc-chat.php']);
