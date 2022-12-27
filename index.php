<?php
$rec = file_get_contents('php://input');
$data = json_decode($rec, true);

if (empty($data['message']['chat']['id'])) {
        exit();
}

$text = $data['message']['text'];
$user = $data['message']['chat']['id'];
define('TOKEN', '5864116167:AAG5ghXa50RI9Dm2_uhcmZMQVQ-hhv9Ei64');
define('TEXT', $data['message']['text']);
define('SENDER', $data['message']['chat']['id']);

function sendTelegram($method, $response) {
        $ch = curl_init('https://api.telegram.org/bot' . TOKEN . '/' . $method);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $response);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
}

function q($text) {
        sendTelegram(
                'sendMessage',
                array(
                        'chat_id' => SENDER,
                        'text' => $text,
                        'parse_mode' => 'Markdown',
			'disable_web_page_preview' => true
                )
	);
}

if (TEXT == "/start") {
	q("Привет! Я помогу узнать предварительную оценку проекта. Отправь ссылку на проект в формате https://portfolio.hse.ru/Project/159642");
} else {
	$split = explode("/", TEXT);
	if (count($split) < 2) {
		q("Не похоже на ссылку");
	} else {
		$link = "https://portfolio.hse.ru/Project/ProjectDataForViewer?userProjectId=" . end($split);
		$data = json_decode(file_get_contents($link), true);
		if ($data == null) {
			q("Проект не найден");
		} else {
			if (!isset($data['title'])) {
				q("Не удалось получить проект");
			} else {
				if (!isset($data['totalMark'])) {
					q($data['title'] . "\nОценка недоступна");
				} else {
					q($data['title'] . "\nОценка: " . $data['totalMark'] . "\n❇️Подписывайся на канал: @cockhunter");
				}
			}
		}
	}
}
