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

    public function __construct()
    {
        $this->tasks = new TaskRepository();
        $this->projects = new ProjectRepository();
    }

    // GET /tasks  или  GET /tasks?status=done
    public function index(): void
    {
        $status = $_GET['status'] ?? '';          // параметр из адреса после «?»; если его нет — пустая строка
        $tasks = $this->tasks->all();

        if ($status !== '') {                     // фильтр по статусу
            $tasks = array_values(array_filter($tasks, fn(array $t): bool => $t['status'] === $status));
        }

        view('tasks/index', [
            'title'  => 'Задачи',
            'tasks'  => $tasks,
            'status' => $status,
        ]);
    }

    // GET /tasks/5   (значение {id} из маршрута приходит в параметр $id)
    public function show(string $id): void
    {
        $task = $this->tasks->find((int) $id);
        if ($task === null) {
            notFound();                           // такой задачи нет: страница 404
        }

        view('tasks/show', [
            'title'   => $task['title'],
            'task'    => $task,
            'project' => $this->projects->find($task['project_id']),
        ]);
    }

    // GET /tasks/create — форма создания
    public function create(): void
    {
        view('tasks/create', [
            'title'    => 'Новая задача',
            'projects' => $this->projects->all(),
            'errors'   => [],
            'old'      => [],
        ]);
    }

    // POST /tasks — обработка формы
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
