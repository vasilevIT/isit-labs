<?php
// app/Controllers/TaskController.php — контроллер задач: список, просмотр, создание.

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProjectRepository;
use App\Models\TaskRepository;

final class TaskController
{
    private TaskRepository $tasks;
    private ProjectRepository $projects;

    // Конструктор вызывается при создании объекта: подготавливаем модели для всех методов (готово)
    public function __construct()
    {
        $this->tasks = new TaskRepository();
        $this->projects = new ProjectRepository();
    }

    // GET /tasks  или  GET /tasks?status=done
    public function index(): void
    {
        // TODO 3.2  Покажите список задач (шаблон app/Views/tasks/index.php уже готов, откройте его и посмотрите,
        //           какие переменные он ждёт: $tasks и $status).
        //             $status = $_GET['status'] ?? '';     // параметр из адреса после «?»; если его нет — пустая строка
        //             $tasks = $this->tasks->all();
        //             if ($status !== '') {                // фильтр по статусу
        //                 $tasks = array_values(array_filter($tasks, fn(array $t): bool => $t['status'] === $status));
        //             }
        //             view('tasks/index', ['title' => 'Задачи', 'tasks' => $tasks, 'status' => $status]);
        //           ✔ Проверка: /tasks — таблица со всеми задачами; /tasks?status=done — только выполненные.
    }

    // GET /tasks/5   (значение {id} из маршрута приходит в параметр $id)
    public function show(string $id): void
    {
        // TODO 3.3  Покажите одну задачу:
        //             $task = $this->tasks->find((int) $id);    // (int) превращает строку '5' в число 5
        //             if ($task === null) {
        //                 notFound();                           // задачи нет: готовая функция покажет страницу 404
        //             }
        //             view('tasks/show', [
        //                 'title'   => $task['title'],
        //                 'task'    => $task,
        //                 'project' => $this->projects->find($task['project_id']),
        //             ]);
        //           ✔ Проверка: /tasks/3 — страница задачи с названием её проекта; /tasks/999 — страница 404.
    }

    // GET /tasks/create — форма создания (ГОТОВО: образец. Разберите, как устроен метод; в повышенной сложности его можно переписать самому)
    public function create(): void
    {
        view('tasks/create', [
            'title'    => 'Новая задача',
            'projects' => $this->projects->all(),
            'errors'   => [],
            'old'      => [],
        ]);
    }

    // POST /tasks — обработка формы (ГОТОВО: образец обработки формы. Читайте комментарии — в них объяснено, что происходит)
    public function store(): void
    {
        // Данные формы приходят в массиве $_POST
        $title = trim($_POST['title'] ?? '');
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $priority = $_POST['priority'] ?? 'medium';
        $dueDate = $_POST['due_date'] ?? '';

        // Проверка данных на сервере (клиентской проверке доверять нельзя)
        $errors = [];
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'Название — не короче 3 символов';
        }
        if ($this->projects->find($projectId) === null) {
            $errors['project_id'] = 'Выберите проект из списка';
        }
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $errors['priority'] = 'Недопустимый приоритет';
        }

        if ($errors) {
            http_response_code(422);              // 422: данные не прошли проверку
            view('tasks/create', [
                'title'    => 'Новая задача',
                'projects' => $this->projects->all(),
                'errors'   => $errors,
                'old'      => $_POST,             // вернуть в форму то, что ввёл пользователь
            ]);
            return;
        }

        $this->tasks->add([
            'project_id' => $projectId,
            'title'      => $title,
            'status'     => 'new',
            'priority'   => $priority,
            'due_date'   => $dueDate !== '' ? $dueDate : null,
        ]);

        redirect('/tasks');                       // после успешной отправки формы — переход на список
    }
}
