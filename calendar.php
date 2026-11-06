<?php
require_once 'calendar.inc.php';

header('Content-Type: application/json');

function respond(int $status, $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function dayToArray(?Day $day): ?array
{
    if (!$day) {
        return null;
    }
    return ['epoch' => $day->epoch, 'records' => $day->records];
}

function redirectBack(): void
{
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.html'), true, 303);
    exit;
}

$calendar = new Calendar(new FTree('calendar.db', valueLength: 512));
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $action = $_POST['action'] ?? null;

    if ($action === 'add' && isset($_POST['epoch'], $_POST['record'])) {
        $calendar->add((int) $_POST['epoch'], (string) $_POST['record']);
    }

    if ($action === 'update' && isset($_POST['epoch'], $_POST['recordIdx'], $_POST['event'])) {
        $calendar->update((int) $_POST['epoch'], (int) $_POST['recordIdx'], (string) $_POST['event']);
    }

    if ($action === 'remove' && isset($_POST['epoch'], $_POST['recordIdx'])) {
        $calendar->remove((int) $_POST['epoch'], (int) $_POST['recordIdx']);
    }

    redirectBack();
}

if ($method === 'GET') {
    if (isset($_GET['epoch'])) {
        $day = $calendar->find((int) $_GET['epoch']);
        if (!$day) {
            respond(404, ['error' => 'day not found']);
        }
        respond(200, dayToArray($day));
    }

    if (isset($_GET['fromEpoch'], $_GET['untilEpoch'])) {
        $days = $calendar->index((int) $_GET['fromEpoch'], (int) $_GET['untilEpoch']);
        respond(200, array_values(array_map('dayToArray', $days)));
    }

    respond(400, ['error' => 'provide epoch, or fromEpoch and untilEpoch']);
}

respond(405, ['error' => 'method not allowed']);
