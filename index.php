<?php

//ini_set('display_errors', 1);

require __DIR__ . "/secure.php";

define('STATUS_FILE_PATH', '/var/www/html/api/premark/status.txt');

$rec = file_get_contents('php://input');
$data = json_decode($rec, true);
//file_put_contents('/var/www/html/api/premark/temp.txt', $rec);
//exit();
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

function q($text, $die=false, $force_to=false) {
	sendTelegram(
		'sendMessage',
		array(
			'chat_id' => $force_to === false ? SENDER : $force_to,
			'text' => $text,
			'parse_mode' => 'Markdown',
			'disable_web_page_preview' => true
		)
	);
	if ($die) {
		exit(0);
	}
}

function qPoll() {
	sendTelegram(
		'sendPoll',
		array(
			'chat_id' => SENDER,
			'question' => POLL[0],
			'options' => json_encode(POLL[1]),
			'is_anonymous' => false
		)
	);
}

function check($id, $entry, $db, $input) {
	$checks = $entry[5];
	if (!is_null($checks) && $checks > 4 && SENDER != ADMIN) {
		$s = $db->prepare('SELECT mark FROM projects WHERE id = :id');
		$s->bindValue(':id', $id);
		$entry = $s->execute();
		$mark = $entry->fetchArray()[0];
		$m = '';
		if (!is_null($mark)) {
			$m = "\nПоследняя записанная оценка за этот проект: *" . $mark . "*";
		}
		q('Достигнуто максимальное количество проверок. Попробуй снова через час.' . $m, true);
	}
	$link = 'https://portfolio.hse.ru/Project/ProjectDataForViewer?userProjectId=' . $id;
	ini_set('default_socket_timeout', 5);
	$data = json_decode(file_get_contents($link), true);
	if ($data == null) {
		q('Проект не найден', true);
	} else {
		if (!isset($data['title'])) {
			q($link, false, ADMIN);
			q('Не удалось получить проект', true);
		} else {
			$title = html_entity_decode($data['title']);
			if (!isset($data['totalMark']) || is_null($data['totalMark'])) {
				q($title . "\nОценка недоступна");
			} else {
				$mark = $data['totalMark'];
				q($title . "\nОценка: *" . $mark . "*" . "\nДопускается получение 10: *" . ($data['isCanGotMark10'] ? 'Да' : 'Нет') . "*\n\n" . getClosing($db));
				$s = $db->prepare('REPLACE INTO projects(id, mark, title, author, group_name, course, year, module) VALUES(:id, :mark, :title, :author, :group_name, :course, :year, :module)');
				$s->bindValue(':id', $id);
				$s->bindValue(':mark', $mark);
				$s->bindValue(':title', $title);
				$s->bindValue(':author', $data['authors'][0]['name']);
				$s->bindValue(':group_name', $data['groupName']);
				$s->bindValue(':course', $data['courseNum']);
				$s->bindValue(':year', $data['academicYearStr']);
				$s->bindValue(':module', html_entity_decode($data['moduleName']));
				$s->execute();
			}
		}
	}
	if ($entry == false) {
		$s = $db->prepare('INSERT INTO interactions VALUES(:id, :act, :name, :username, :checks_total, :checks_last_hour)');
		$s->bindValue(':id', SENDER);
		$s->bindValue(':act', $id);
		$s->bindValue(':name', getName($input));
		$s->bindValue(':username', getUsername($input));
		$s->bindValue(':checks_total', 1);
		$s->bindValue(':checks_last_hour', 1);
		$s->execute();
	} else {
		$s = $db->prepare('UPDATE interactions SET act = :act, name = :name, username = :username, checks_total = checks_total + 1, checks_last_hour = checks_last_hour + 1 WHERE id = :id');
		$s->bindValue(':id', SENDER);
		$s->bindValue(':act', $id);
		$s->bindValue(':name', getName($input));
		$s->bindValue(':username', getUsername($input));
		$s->execute();
	}
}

function getClosing($db) {
	$entry = CLOSING[array_rand(CLOSING)];
	if (is_array($entry)) {
		$ret = $entry[0];
		foreach ($entry[1] as &$i) {
			$result = $db->query($i);
			$res = $result->fetchArray()[0];
			$pos = strpos($ret, '$');
			if ($pos !== false) {
				$ret = substr_replace($ret, $res, $pos, 1);
			}
		}
		return $ret;
	} else {
		return $entry;
	}
}

function getName($data) {
	$first = 'No first';
	$last = 'No last';
	if (isset($data['message']['chat']['first_name'])) {
		$first = $data['message']['chat']['first_name'];
	}
	if (isset($data['message']['chat']['last_name'])) {
                $last = $data['message']['chat']['last_name'];
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
	$s = 'Error: ' . $message . ' @' . $lineno;
	q($s, false, ADMIN);
	q($s);
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
}

set_error_handler('errorHandler', E_ALL);

$db = new SQLite3('/var/www/premark_core/db_new.db');

if (!empty($data['poll_answer'])) {
	$answer = $data['poll_answer'];
	if (count($answer['option_ids']) === 1) {
		$s = $db->prepare('REPLACE INTO poll_votes(chat_id, question, answer) VALUES(:chat_id, :question, :answer)');
		$s->bindValue(':chat_id', $answer['user']['id']);
		$s->bindValue(':question', POLL[0]);
		$s->bindValue(':answer', POLL[1][$answer['option_ids'][0]]);
		$s->execute();
		q('Спасибо!', false, $answer['user']['id']);
	}
	exit(0);
}

if (empty($data['message']['chat']['id']) || empty($data['message']['text'])) {
	exit(1);
}
define('SENDER', $data['message']['chat']['id']);
define('TEXT', $data['message']['text']);

if (file_get_contents(STATUS_FILE_PATH) == 'down' && SENDER != ADMIN) {
	q("Портфолио упало", true);
}

$s = $db->prepare('SELECT * FROM interactions WHERE id = :id');
$s->bindValue(':id', SENDER);
$entry = $s->execute();
$entry = $entry->fetchArray();

$s = $db->prepare('insert or ignore into stats values(:date, 1)');
$s->bindValue(':date', date('Y-m-d'));
$s->execute();
$s = $db->prepare('update stats set actions = actions + 1 where date = :date');
$s->bindValue(':date', date('Y-m-d'));
$s->execute();

if (TEXT == '/start') {
	q('Привет! Я помогу узнать предварительную оценку проекта. Отправь ссылку на проект в формате https://portfolio.hse.ru/Project/159642');
} else if (TEXT == '/poll') {
	qPoll(POLL[0], POLL[1]);
} else if (TEXT == '/recheck') {
	$act = $entry[1];
	if (is_null($act)) {
		q('Проект пока не проверялся');
	} else {
		check($act, $entry, $db, $data);
	}
} else if (TEXT == '/putdown' && SENDER == ADMIN) {
        file_put_contents(STATUS_FILE_PATH, 'down');
	q('Put down');
} else if (TEXT == '/putup' && SENDER == ADMIN) {
        file_put_contents(STATUS_FILE_PATH, 'up');
	q('Put up');
} else {
	$split = explode('/', TEXT);
	if (count($split) < 2) {
		q('Не похоже на ссылку');
	} else {
		$id = end($split);
		if (strpos($id, '#') !== false) {
			$id = explode('#', $id)[0];
		}
		check($id, $entry, $db, $data);
	}
}
