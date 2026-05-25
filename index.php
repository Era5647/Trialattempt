<?php
session_start();
// Уникальный токен пользователя — генерируется один раз и хранится в сессии + cookie
if (empty($_SESSION['user_token'])) {
    $cookieToken = $_COOKIE['user_token'] ?? null;
    if ($cookieToken && preg_match('/^[a-f0-9]{32}$/', $cookieToken)) {
        $_SESSION['user_token'] = $cookieToken;
    } else {
        $_SESSION['user_token'] = bin2hex(random_bytes(16));
        setcookie('user_token', $_SESSION['user_token'], time() + 60 * 60 * 24 * 365, '/', '', false, true);
    }
}
$userToken = $_SESSION['user_token'];

// 🔧 БД в корне сайта - гарантированно доступно для записи
$dbFile = __DIR__ . '/tickets.db';

// Хэш пароля (     ) — сгенерирован через password_hash()
// Чтобы сменить пароль: замените строку ниже на новый хэш
// Получить хэш: php -r "echo password_hash('НовыйПароль', PASSWORD_DEFAULT);"
define('ADMIN_LOGIN', 'admin');
define('ADMIN_PASS_HASH', '$2b$12$6f4XiH43fSU8TeN6xI8Xdu32.p8rocesxzyQ7flKrNI.MsPH2gNKW');
// ☝️ Это хэш строки "JoinHelpHello_007". Не менять, если пароль не меняется.

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, contact TEXT, category TEXT,
        description TEXT, status TEXT DEFAULT 'new',
        user_token TEXT DEFAULT NULL,
        done_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // === ТАБЛИЦА ИДЕЙ ===
$db->exec("CREATE TABLE IF NOT EXISTS ideas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    description TEXT,
    status TEXT DEFAULT 'new',
    user_token TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
    // Добавляем колонку done_at если её нет (для существующих БД)
    try {
        $db->exec("ALTER TABLE tickets ADD COLUMN done_at DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
    }
    try {
        $db->exec("ALTER TABLE tickets ADD COLUMN user_token TEXT DEFAULT NULL");
    } catch (PDOException $e) {
    }
    @chmod($dbFile, 0666);
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    die("❌ Ошибка БД: " . htmlspecialchars($e->getMessage()));
}

// === АВТО-УДАЛЕНИЕ: заявки со статусом "готово" старше 3 дней ===
$db->exec("DELETE FROM tickets WHERE status = 'done' AND done_at IS NOT NULL AND done_at <= datetime('now', '-3 days')");

// === АВТОРИЗАЦИЯ ===
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
if (isset($_POST['login'])) {
    if ($_POST['user'] === ADMIN_LOGIN && password_verify($_POST['pass'], ADMIN_PASS_HASH)) {
        $_SESSION['admin'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = "Неверный логин/пароль";
    }
}
$isAdmin = $_SESSION['admin'] ?? false;

// === СОЗДАНИЕ ЗАЯВКИ ===
// === ОТПРАВКА ИДЕИ ===
if (isset($_POST['submit_idea']) && !$isAdmin) {
    $stmt = $db->prepare("INSERT INTO ideas (title, description, user_token) VALUES (?, ?, ?)");
    $stmt->execute([
        $_POST['idea_title'],
        $_POST['idea_description'],
        $userToken
    ]);

    $_SESSION['success'] = "💡 Идея отправлена!";
    header("Location: index.php");
    exit;
}
if (isset($_POST['submit_ticket']) && !$isAdmin) {
    $stmt = $db->prepare("INSERT INTO tickets (name, contact, category, description, user_token) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['name'], $_POST['contact'], $_POST['category'], $_POST['description'], $userToken]);

    // Сохраняем сообщение в сессию
    $_SESSION['success'] = "✅ Заявка №" . $db->lastInsertId() . " принята!";

    // РЕДИРЕКТ (очень важно!)
    header("Location: index.php");
    exit;
}
// === ОБРАБОТКА СТАТУСА ===
// === СТАТУС ИДЕИ ===
if ($isAdmin && isset($_POST['idea_status'])) {
    $stmt = $db->prepare("UPDATE ideas SET status = ? WHERE id = ?");
    $stmt->execute([$_POST['idea_status'], $_POST['idea_id']]);

    header('Location: index.php');
    exit;
}

// === УДАЛЕНИЕ ИДЕИ ===
if ($isAdmin && isset($_POST['delete_idea'])) {
    $stmt = $db->prepare("DELETE FROM ideas WHERE id = ?");
    $stmt->execute([$_POST['idea_id']]);

    header('Location: index.php');
    exit;
}
if ($isAdmin && isset($_POST['ticket_id'], $_POST['status'])) {
    $newStatus = $_POST['status'];
    // Если статус "готово" — записываем время, иначе сбрасываем
    if ($newStatus === 'done') {
        $stmt = $db->prepare("UPDATE tickets SET status = ?, done_at = datetime('now') WHERE id = ?");
    } else {
        $stmt = $db->prepare("UPDATE tickets SET status = ?, done_at = NULL WHERE id = ?");
    }
    $stmt->execute([$newStatus, $_POST['ticket_id']]);
    header('Location: index.php');
    exit;
}

// === ЭКСПОРТ В CSV ===
if ($isAdmin && isset($_GET['export'])) {
    // Алматы — UTC+5 (без перехода на летнее время)
    $tz = new DateTimeZone('Asia/Qyzylorda');

    // Хелпер: конвертировать UTC-строку из SQLite в читаемый вид по Алматы
    $fmt = function (?string $utc) use ($tz): string {
        if (!$utc)
            return '';
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        return $dt->format('d.m.Y H:i');
    };

    $catLabel = ['printer' => 'Принтер/МФУ', 'cable' => 'Кабель/розетка', 'pc' => 'Компьютер/ПО', 'network' => 'Сеть/интернет', 'other' => 'Другое'];
    $statusLabel = ['new' => 'Новая', 'progress' => 'В работе', 'done' => 'Готово'];

    $filename = 'tickets_' . (new DateTime('now', $tz))->format('Y-m-d_Hi') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel корректно открывает кириллицу

    $allRows = $db->query("SELECT * FROM tickets ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $active = array_filter($allRows, fn($r) => in_array($r['status'], ['new', 'progress']));
    $done = array_filter($allRows, fn($r) => $r['status'] === 'done');

    // --- Блок 1: Активные заявки ---
    fputcsv($out, ['=== АКТИВНЫЕ ЗАЯВКИ (Новые и В работе) ==='], ';');
    fputcsv($out, ['ID', 'Статус', 'Имя / Отдел', 'Контакт', 'Категория', 'Описание', 'Дата подачи', 'Время подачи'], ';');
    foreach ($active as $r) {
        fputcsv($out, [
            '#' . $r['id'],
            $statusLabel[$r['status']] ?? $r['status'],
            $r['name'],
            $r['contact'],
            $catLabel[$r['category']] ?? $r['category'],
            $r['description'],
            $r['created_at'] ? (new DateTime($r['created_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y') : '',
            $r['created_at'] ? (new DateTime($r['created_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('H:i') : '',
        ], ';');
    }
    if (empty($active))
        fputcsv($out, ['(нет активных заявок)'], ';');

    fputcsv($out, [], ';'); // пустая строка-разделитель

    // --- Блок 2: Завершённые заявки ---
    fputcsv($out, ['=== ЗАВЕРШЁННЫЕ ЗАЯВКИ (Готово) ==='], ';');
    fputcsv($out, ['ID', 'Статус', 'Имя / Отдел', 'Контакт', 'Категория', 'Описание', 'Дата подачи', 'Время подачи', 'Дата выполнения', 'Время выполнения', 'Длительность'], ';');
    foreach ($done as $r) {
        $duration = '';
        if ($r['created_at'] && $r['done_at']) {
            $diff = (new DateTime($r['created_at'], new DateTimeZone('UTC')))->diff(new DateTime($r['done_at'], new DateTimeZone('UTC')));
            if ($diff->days > 0)
                $duration = $diff->days . ' д ' . $diff->h . ' ч';
            elseif ($diff->h > 0)
                $duration = $diff->h . ' ч ' . $diff->i . ' мин';
            else
                $duration = $diff->i . ' мин';
        }
        fputcsv($out, [
            '#' . $r['id'],
            $statusLabel[$r['status']] ?? $r['status'],
            $r['name'],
            $r['contact'],
            $catLabel[$r['category']] ?? $r['category'],
            $r['description'],
            $r['created_at'] ? (new DateTime($r['created_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y') : '',
            $r['created_at'] ? (new DateTime($r['created_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('H:i') : '',
            $r['done_at'] ? (new DateTime($r['done_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y') : '',
            $r['done_at'] ? (new DateTime($r['done_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('H:i') : '',
            $duration,
        ], ';');
    }
    if (empty($done))
        fputcsv($out, ['(нет завершённых заявок)'], ';');

    fclose($out);
    exit;
}

$tickets = $db->query("SELECT * FROM tickets ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$ideas = $db->query("SELECT * FROM ideas ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
// Заявки только текущего пользователя (для публичной страницы)
$stmt = $db->prepare("SELECT * FROM tickets WHERE user_token = ? ORDER BY created_at DESC");
$stmt->execute([$userToken]);
$myTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Хелпер для отображения дат в таблице (Кызылорда UTC+5)
$tzLocal = new DateTimeZone('Asia/Qyzylorda');
$fmtLocal = function (?string $utc) use ($tzLocal): string {
    if (!$utc)
        return '—';
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone($tzLocal);
    return $dt->format('d.m.Y H:i');
};
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Заявки на ремонт</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="./style.css">
</head>

<body>
    <div class="container">

        <h1>Заявки на ремонт</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$isAdmin): ?>

            <div class="card">
                <h2>Оставить заявку</h2>
                <form method="POST">
                    <input type="text" name="name" placeholder="Ваше имя / отдел" required>
                    <input type="text" name="contact" placeholder="Телефон / кабинет" required>

                    <select name="category" required>
                        <option value="">-- Выберите проблему --</option>
                        <option value="printer">🖨️ Принтер / МФУ</option>
                        <option value="cable">🔌 Кабель / розетка</option>
                        <option value="pc">💻 Компьютер / ПО</option>
                        <option value="network">🌐 Сеть / интернет</option>
                        <option value="other">📦 Другое</option>
                    </select>

                    <textarea name="description" rows="4" placeholder="Опишите проблему..." required></textarea>

                    <button type="submit" name="submit_ticket">Отправить заявку</button>
                </form>
                <button onclick="openIdeaModal()">
     Предложить идею
                </button>
            </div>

            <?php if (!empty($myTickets)): ?>
                <div class="card">
                    <h2>Мои заявки</h2>

                    <table>
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Категория</th>
                                <th>Описание</th>
                                <th>Дата подачи</th>
                                <th>Время</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>

                            <?php
                            $catNames = [
                                'printer' => 'Принтер/МФУ',
                                'cable' => 'Кабель/розетка',
                                'pc' => 'Компьютер/ПО',
                                'network' => 'Сеть/интернет',
                                'other' => 'Другое'
                            ];

                            foreach ($myTickets as $m):
                                $dtM = $m['created_at'] ? (new DateTime($m['created_at'], new DateTimeZone('UTC')))->setTimezone($tzLocal) : null;
                                ?>

                                <tr>
                                    <td>#<?= $m['id'] ?></td>
                                    <td><?= $catNames[$m['category']] ?? htmlspecialchars($m['category']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($m['description'])) ?></td>
                                    <td><?= $dtM ? $dtM->format('d.m.Y') : '—' ?></td>
                                    <td><?= $dtM ? $dtM->format('H:i') : '—' ?></td>
                                    <td>
                                        <?php if ($m['status'] === 'new'): ?>
                                            <span class="badge badge-new">Новая</span>
                                        <?php elseif ($m['status'] === 'progress'): ?>
                                            <span class="badge badge-progress">В работе</span>
                                        <?php else: ?>
                                            <span class="badge badge-done">Готово</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="card" style="text-align:center">
                <small>
                    <a href="#" onclick="document.getElementById('admin-form').style.display='block';return false;">
                        Вход для администратора
                    </a>
                </small>

                <form id="admin-form" method="POST" style="display:none;margin-top:1rem;text-align:left">
                    <input type="text" name="user" placeholder="Логин" required>
                    <input type="password" name="pass" placeholder="Пароль" required>
                    <button type="submit" name="login">Войти</button>
                </form>
            </div>

        <?php else: ?>

            <div class="admin-bar">
                <span>Администратор</span>
                <a href="?export=1"><button>Экспорт в CSV</button></a>
                <a href="?logout=1"><button>Выйти</button></a>
            </div>

            <div class="card">
                <h2>Все заявки (<?= count($tickets) ?>)</h2>

                <p>
                    Заявки со статусом «Готово» автоматически удаляются через 3 дня.
                </p>

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>От кого</th>
                            <th>Категория</th>
                            <th>Описание</th>
                            <th>Дата подачи</th>
                            <th>Время подачи</th>
                            <th>Статус</th>
                            <th>Действие</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($tickets as $t): ?>

                            <tr>
                                <td>#<?= $t['id'] ?></td>

                                <td>
                                    <?= htmlspecialchars($t['name']) ?><br>
                                    <small><?= htmlspecialchars($t['contact']) ?></small>
                                </td>

                                <td><?= htmlspecialchars($t['category']) ?></td>
                                <td><?= nl2br(htmlspecialchars($t['description'])) ?></td>

                                <?php
                                $dtCell = $t['created_at']
                                    ? (new DateTime($t['created_at'], new DateTimeZone('UTC')))->setTimezone($tzLocal)
                                    : null;
                                ?>

                                <td><?= $dtCell ? $dtCell->format('d.m.Y') : '—' ?></td>
                                <td><?= $dtCell ? $dtCell->format('H:i') : '—' ?></td>

                                <td>
                                    <span class="badge badge-<?= $t['status'] ?>">
                                        <?= $t['status'] == 'new' ? 'Новая' : ($t['status'] == 'progress' ? 'В работе' : 'Готово') ?>
                                    </span>
                                </td>

                                <td>
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">

                                        <select name="status" onchange="this.form.submit()">
                                            <option value="new" <?= $t['status'] == 'new' ? 'selected' : '' ?>>Новая</option>
                                            <option value="progress" <?= $t['status'] == 'progress' ? 'selected' : '' ?>>В работе
                                            </option>
                                            <option value="done" <?= $t['status'] == 'done' ? 'selected' : '' ?>>Готово</option>
                                        </select>
                                    </form>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
    <h2> Идеи (<?= count($ideas) ?>)</h2>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Идея</th>
                <th>Описание</th>
                <th>Статус</th>
                <th>Дата</th>
                <th>Действие</th>
            </tr>
        </thead>

        <tbody>
        <?php foreach ($ideas as $i): ?>
            <tr>
                <td>#<?= $i['id'] ?></td>
                <td><?= htmlspecialchars($i['title']) ?></td>
                <td><?= nl2br(htmlspecialchars($i['description'])) ?></td>

                <td>
                    <form method="POST">
                        <input type="hidden" name="idea_id" value="<?= $i['id'] ?>">
                        <select name="idea_status" onchange="this.form.submit()">
                            <option value="new" <?= $i['status']=='new'?'selected':'' ?>>Новая</option>
                            <option value="done" <?= $i['status']=='done'?'selected':'' ?>>Сделано</option>
                        </select>
                    </form>
                </td>

                <td><?= $fmtLocal($i['created_at']) ?></td>

                <td>
                    <form method="POST">
                        <input type="hidden" name="idea_id" value="<?= $i['id'] ?>">
                        <button name="delete_idea">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

        <?php endif; ?>
    </div>
    <footer>
    <div class="footer-container">

        <div class="footer-left">
            <img src="1234567890.png" width="100px" alt="">
            <h3>ВКазГТК</h3>
            <a href="https://vkazgtk.edu.kz/ru/">
            <button class="footer-btn" >Перейти на сайт Колледжа</button>
            </a>
        </div>

        <div class="footer-col">
            <h4>Создатели</h4>
            <p>
                Nonnsweety 
            </p>
            <p>
                Sordi44
            </p>
            <p>
                mrHUH
            </p>
        </div>

        <div class="footer-col">
            <h4>Время работы</h4>
            <p>(Пн–Пт): с 09:00 до 18:00.</p>
            <p>Сб - Вс: выходной</p>
            <p>Обед: стандартно с 13:00 до 14:00.</p>
        </div>

        <div class="footer-col">
            <h4>Контакты</h4>
            <p>+7 (707) 237-46-81</p>
        </div>

    </div>

    <div class="footer-bottom">
        <h5>Было сделано для ВКазГТК</h5>
        © 2026 Все права защищены
    </div>
</footer>
<div id="ideaModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeIdeaModal()">×</span>

        <h2> Ваша идея</h2>

        <form method="POST">
            <input type="text" name="idea_title" placeholder="Кратко..." required>
            <textarea name="idea_description" rows="4" placeholder="Опишите идею..." required></textarea>
            <button type="submit" name="submit_idea">Отправить</button>
        </form>
    </div>
</div>

<script>
function openIdeaModal() {
    document.getElementById('ideaModal').style.display = 'flex';
}
function closeIdeaModal() {
    document.getElementById('ideaModal').style.display = 'none';
}
window.onclick = function(e) {
    let modal = document.getElementById('ideaModal');
    if (e.target === modal) modal.style.display = "none";
}
</script>
</body>

</html>