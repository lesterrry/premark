<?php

ini_set('display_errors', 1);
$rec = file_get_contents('php://input');
$data = json_decode($rec, true);

if (empty($data['message']['chat']['id']) || empty($data['message']['text'])) {
	exit(1);
}

$text = $data['message']['text'];
$user = $data['message']['chat']['id'];
define('TOKEN', '5872002901:AAEISDbqEDYjJPGzuof_Vct8tCUvkbJzVDk');
define('CLOSING', "\n❇️Подписывайся на канал: @cockhunter");
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

function q($text, $die=false) {
	sendTelegram(
		'sendMessage',
		array(
			'chat_id' => SENDER,
			'text' => $text,
			'parse_mode' => 'Markdown',
			'disable_web_page_preview' => true
		)
	);
	if ($die) {
		exit(0);
	}
}

function check($id) {
	$link = 'https://portfolio.hse.ru/Project/ProjectDataForViewer?userProjectId=' . $id;
	$data = json_decode(file_get_contents($link), true);
	if ($data == null) {
		q('Проект не найден', true);
	} else {
		if (!isset($data['title'])) {
			q('Не удалось получить проект', true);
		} else {
			if (!isset($data['totalMark'])) {
				q($data['title'] . "\nОценка недоступна");
			} else {
				q($data['title'] . "\nОценка: " . $data['totalMark'] . CLOSING);
			}
		}
	}
}

function getName($data) {
	$first = 'No first';
	$last = 'No last';
	if (isset($data['message']['chat']['first_name'])) {
		$first = $data['message']['chat']['first_name'];
	}
	if (isset($data['message']['chat']['last_name'])) {
                $first = $data['message']['chat']['last_name'];
        }
	return $first . ' ' . $last;
}

function getUsername($data) {
	$username = 'No username';
	if (isset($data['message']['chat']['username'])) {
		$username = $data['message']['chat']['username'];
	}
	return $username;
}

function errorHandler($severity, $message, $filename, $lineno) {
	q('Error: ' . $message . ' @' . $lineno);
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
}

set_error_handler('errorHandler', E_ALL);

$db = new SQLite3('/var/www/premark_core/db_new.db');
$s = $db->prepare('SELECT * FROM interactions WHERE id = :id');
$s->bindValue(':id', SENDER);
$entry = $s->execute();
$entry = $entry->fetchArray();

if (TEXT == '/start') {
	q('Привет! Я помогу узнать предварительную оценку проекта. Отправь ссылку на проект в формате https://portfolio.hse.ru/Project/159642');
} else if (TEXT == '/recheck') {
	$act = $entry->fetchArray()[0];
	if (is_null($act)) {
		q('Проект пока не проверялся');
	} else {
		check($act);
	}
} else {
	$split = explode('/', TEXT);
	if (count($split) < 2) {
		q('Не похоже на ссылку');
	} else {
		$id = end($split);
		check($id);
		if ($entry == false) {
			$s = $db->prepare('INSERT INTO interactions VALUES(:id, :act, :name, :username, :checks_total)');
			$s->bindValue(':id', SENDER);
			$s->bindValue(':act', $id);
			$s->bindValue(':name', getName($data));
			$s->bindValue(':username', getUsername($data));
			$s->bindValue(':checks_total', 0);
			$s->execute();
		} else {
			$s = $db->prepare('UPDATE interactions SET act = :act, name = :name, username = :username, checks_total = checks_total + 1 WHERE id = :id');
			$s->bindValue(':id', SENDER);
			$s->bindValue(':act', $id);
			$s->bindValue(':name', getName($data));
			$s->bindValue(':username', getUsername($data));
			$s->execute();
		}
	}
}
