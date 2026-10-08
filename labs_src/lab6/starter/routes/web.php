<?php
// routes/web.php — таблица маршрутов приложения: «какой адрес — какой контроллер и метод».
// Переменная $router создаётся в public/index.php.

use App\Controllers\HomeController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;

// TODO 2.3  Зарегистрируйте маршруты. Образец (так регистрируется главная страница):
//             $router->get('/', [HomeController::class, 'index']);
//           get — для запросов GET (открытие страницы), post — для запросов POST (отправка формы).
//           Нужны такие маршруты:
//
//             GET  /               HomeController      index      главная страница
//             GET  /tasks          TaskController      index      список задач
//             GET  /tasks/create   TaskController      create     форма создания задачи
//             POST /tasks          TaskController      store      приём данных формы
//             GET  /tasks/{id}     TaskController      show       просмотр задачи
//             GET  /projects       ProjectController   index      список проектов
//             GET  /projects/{id}  ProjectController   show       просмотр проекта
//
//           ВАЖНО: маршруты проверяются по порядку. Если поставить /tasks/{id} раньше /tasks/create,
//           то адрес /tasks/create «съест» маршрут с {id} (слово create станет значением id).
//           Поэтому конкретные адреса пишите раньше шаблонных.
//
//           ✔ Проверка: адрес /nonexistent по-прежнему даёт 404, а /tasks теперь даёт ПУСТУЮ страницу без ошибок
//             (маршрут найден, но контроллеры пока пустые). Чтобы убедиться, что нужный контроллер вызван,
//             временно добавьте в его метод строку   echo 'сработал TaskController::index';   и удалите её после проверки.
