<?php
// bin/seed-events.php — наполняет журнал правдоподобными тестовыми событиями (ГОТОВО: менять не нужно).
//
// Зачем: чтобы анализировать журнал, нужны тысячи событий, а пока вы нажимаете кнопки в приложении, их десятки.
// Скрипт создаёт события за последние дни так, как они выглядели бы в живой системе:
//   • по будням активность в разы выше, чем по выходным; днём больше, чем ночью (пики утром и после обеда);
//   • участники работают с разной интенсивностью (Игорь больше всех, Анна меньше); удаляют задачи и меняют проекты только руководители;
//   • иногда случаются ошибки сервера (у вошедшего участника или до входа), а в один из дней — «авария» с резким скачком ошибок;
//     бывают и неудачные входы.
// Все созданные события помечены details.synthetic = true: их легко отличить от настоящих и удалить (--clear).
// Эти же данные понадобятся в следующих лабораторных работах (поиск, ClickHouse, Grafana).
//
// Запуск:  php bin/seed-events.php                   # 20 000 событий за 30 дней
//          php bin/seed-events.php --count=2000 --days=14
//          php bin/seed-events.php --clear           # сначала удалить прежние тестовые события
// Случайность управляема (--seed=12): в один и тот же день повторный запуск даёт тот же набор. В другой день числа немного другие
// (сдвигаются дни недели и «сегодня»), но закономерности те же: будни против выходных, дневные пики, день аварии.

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require APP_PATH . '/autoload.php';
require APP_PATH . '/helpers.php';

$o = getopt('', ['count::', 'days::', 'seed::', 'clear']);
$count = max(1, (int) ($o['count'] ?? 20000));
$days = max(2, (int) ($o['days'] ?? 30));
mt_srand((int) ($o['seed'] ?? 12));

$pdo = App\Core\Database::connection();
if (isset($o['clear'])) {
    $removed = $pdo->exec("DELETE FROM events WHERE details->>'synthetic' = 'true'");
    echo "удалено прежних тестовых событий: $removed\n";
}

// ----- участники -----
$users = $pdo->query('SELECT id, name, email, role FROM users ORDER BY id')->fetchAll();
if (!$users) {
    fwrite(STDERR, "В базе нет участников: сначала php bin/reset-db.php\n");
    exit(1);
}
$activity = ['manager' => 0.20, 'developer' => 0.50, 'tester' => 0.30];       // кто сколько работает
$ips = [];
foreach ($users as $i => $u) {
    $ips[$u['id']] = '10.0.1.' . (5 + $i * 6);                                  // у каждого свой рабочий адрес
}

// Выбор по весам: weighted(['a' => 3, 'b' => 1]) вернёт 'a' в три раза чаще, чем 'b'
function weighted(array $weights): string|int
{
    $r = mt_rand() / mt_getrandmax() * array_sum($weights);
    foreach ($weights as $key => $w) {
        if (($r -= $w) <= 0) {
            return $key;
        }
    }
    return array_key_last($weights);
}

// ----- какие дни и часы «популярны» -----
$today = new DateTimeImmutable('today');
$cutoff = new DateTimeImmutable(date('Y-m-d H:00:00'));              // «сейчас», округлённое до часа: в течение часа набор событий одинаков
$dayWeights = [];
for ($d = 0; $d < $days; $d++) {
    $date = $today->modify("-$d days");
    $weekend = (int) $date->format('N') >= 6;
    $dayWeights[$d] = ($weekend ? 0.25 : 1.0) * (1.0 + 0.012 * ($days - $d));   // по выходным почти тихо; нагрузка слегка растёт к сегодняшнему дню
}
$hourWeights = [0 => 0.1, 1 => 0.05, 2 => 0.05, 3 => 0.05, 4 => 0.1, 5 => 0.2, 6 => 0.5, 7 => 1.5, 8 => 4, 9 => 8, 10 => 10, 11 => 9,
                12 => 6, 13 => 5, 14 => 8, 15 => 9, 16 => 8, 17 => 5, 18 => 2.5, 19 => 1.2, 20 => 0.8, 21 => 0.5, 22 => 0.3, 23 => 0.2];
// Сегодняшний день ещё не закончился: событий в нём меньше, чем в полном дне (по доле часов, которые уже прошли)
$passed = 0.0;
foreach ($hourWeights as $hour => $w) {
    $passed += $hour <= (int) date('G') ? $w : 0;
}
$dayWeights[0] *= max(0.05, $passed / array_sum($hourWeights));

// День «аварии»: ошибок в несколько раз больше. Берём ближайший будний день не позже недели назад (он попадёт в «последние 7 дней»)
$incidentDay = 4;
while ((int) $today->modify("-$incidentDay days")->format('N') >= 6 && $incidentDay < $days - 1) {
    $incidentDay++;
}

$mix = [                                                                        // доли типов событий среди действий участников
    'any'     => ['login' => 14, 'logout' => 9, 'login_failed' => 3, 'task_created' => 14, 'task_updated' => 32],
    'manager' => ['task_deleted' => 3, 'project_created' => 1.2, 'project_updated' => 2.5, 'project_deleted' => 0.3],
];
$statuses = ['new', 'in_progress', 'done'];
$fields = ['status', 'status', 'status', 'priority', 'title', 'due_date', 'description'];
$words = ['Сверстать', 'Проверить', 'Подключить', 'Настроить', 'Написать', 'Обновить', 'Согласовать', 'Исправить'];
$things = ['главную страницу', 'форму заявки', 'уведомления', 'экран входа', 'отчёт', 'каталог', 'тексты', 'поиск'];

// ----- генерация -----
$rows = [];
for ($n = 0; $n < $count; $n++) {
    do {                                                                        // время не должно оказаться в будущем
        $d = (int) weighted($dayWeights);
        $h = (int) weighted($hourWeights);
        $at = $today->modify("-$d days")->setTime($h, mt_rand(0, 59), mt_rand(0, 59));
    } while ($at > $cutoff);

    $errorShare = $d === $incidentDay ? 0.07 : 0.008;
    $details = ['synthetic' => true];
    if (mt_rand() / mt_getrandmax() < $errorShare) {                            // ошибка сервера: участник неизвестен
        $type = 'server_error';
        $user = mt_rand(0, 9) < 8 ? $users[mt_rand(0, count($users) - 1)] : null;   // чаще ошибка случилась у вошедшего участника, иногда участник неизвестен
        $entity = [null, null];
        $path = ['/api/tasks', '/api/reports', '/api/projects', '/api/tasks/3'][mt_rand(0, 3)];
        $msg = $d === $incidentDay ? 'SQLSTATE[08006]: соединение с базой потеряно' : ['Таймаут запроса к базе', 'Недопустимые данные в отчёте', 'Redis не отвечает'][mt_rand(0, 2)];
        $description = 'Ошибка сервера: ' . $msg;
        $details += ['class' => 'RuntimeException', 'method' => 'GET', 'path' => $path, 'code' => 500];
        $ip = $user ? $ips[$user['id']] : null;
    } else {
        $role = weighted($activity);
        $candidates = array_values(array_filter($users, fn($u) => $u['role'] === $role)) ?: $users;
        $user = $candidates[mt_rand(0, count($candidates) - 1)];
        $types = $mix['any'] + ($role === 'manager' ? $mix['manager'] : []);
        $type = weighted($types);
        $ip = $ips[$user['id']];
        $entity = [null, null];
        $tid = mt_rand(1, 60);
        switch ($type) {
            case 'login':
                $description = 'Вход в систему: ' . $user['name'];
                $entity = ['user', $user['id']];
                $details += ['email' => $user['email'], 'role' => $user['role']];
                break;
            case 'logout':
                $description = 'Выход из системы: ' . $user['name'];
                $entity = ['user', $user['id']];
                break;
            case 'login_failed':
                $known = mt_rand(0, 9) < 7;                                     // чаще опечатываются знакомые, иногда пробуют чужие адреса
                $description = 'Неудачная попытка входа: ' . ($known ? $user['email'] : 'admin@example.com');
                $details += ['email' => $known ? $user['email'] : 'admin@example.com', 'reason' => $known ? 'wrong_password' : 'unknown_email'];
                if (!$known) {
                    $user = null;
                    $ip = '203.0.113.' . mt_rand(1, 40);
                }
                break;
            case 'task_created':
                $title = $words[mt_rand(0, 7)] . ' ' . $things[mt_rand(0, 7)];
                $description = 'Создана задача «' . $title . '»';
                $entity = ['task', $tid];
                $details += ['project_id' => mt_rand(1, 3), 'title' => $title];
                break;
            case 'task_updated':
                $field = $fields[mt_rand(0, count($fields) - 1)];
                $description = "Изменена задача №$tid ($field)";
                $entity = ['task', $tid];
                $details += ['changed' => [$field]];
                if ($field === 'status') {
                    $details['status'] = [$statuses[mt_rand(0, 1)], $statuses[mt_rand(1, 2)]];
                }
                break;
            case 'task_deleted':
                $description = "Удалена задача №$tid";
                $entity = ['task', $tid];
                break;
            default:                                                            // project_*
                $pid = mt_rand(1, 3);
                $description = ['project_created' => 'Создан проект', 'project_updated' => 'Изменён проект', 'project_deleted' => 'Удалён проект'][$type] . " №$pid";
                $entity = ['project', $pid];
        }
    }
    $rows[] = [
        $at->format('Y-m-d H:i:sP'), $type, App\Core\Events::TYPES[$type], $user['id'] ?? null, $entity[0], $entity[1],
        $description, json_encode($details, JSON_UNESCAPED_UNICODE), $ip, bin2hex(random_bytes(8)),
    ];
}
usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));                               // по времени: номера событий растут вместе со временем, как в живом журнале

// ----- запись пачками по 500 строк -----
$pdo->beginTransaction();
foreach (array_chunk($rows, 500) as $chunk) {
    $sql = 'INSERT INTO events (occurred_at, type, category, user_id, entity_type, entity_id, description, details, ip, request_id) VALUES '
         . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'));
    $pdo->prepare($sql)->execute(array_merge(...$chunk));
}
$pdo->commit();

echo "создано событий: " . count($rows) . " за $days дней\n";
foreach ($pdo->query("SELECT type, COUNT(*) AS n FROM events WHERE details->>'synthetic' = 'true' GROUP BY type ORDER BY n DESC") as $r) {
    printf("  %-16s %6d\n", $r['type'], $r['n']);
}
