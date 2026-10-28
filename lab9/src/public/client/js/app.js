// app.js — логика страницы: вывод данных, обработка событий, запросы к серверу (через функции из api.js).

// ============================================================
// 1. Находим нужные элементы страницы (по их id из index.html)
// ============================================================
const loginScreen = document.getElementById('login-screen');
const mainScreen = document.getElementById('main-screen');
const loginForm = document.getElementById('login-form');
const taskForm = document.getElementById('task-form');
const taskBody = document.getElementById('task-body');
const taskTable = document.getElementById('task-table');
const messageBox = document.getElementById('message');
const statusFilter = document.getElementById('status-filter');
const searchInput = document.getElementById('search-input');

// Подписи для статусов, приоритетов и ролей (на сервере хранятся английские слова)
const STATUS_NAMES = { new: 'Новая', in_progress: 'В работе', done: 'Выполнена' };
const PRIORITY_NAMES = { low: 'Низкий', medium: 'Средний', high: 'Высокий' };
const ROLE_NAMES = { manager: 'Руководитель', developer: 'Разработчик', tester: 'Тестировщик' };

// Какой блок с ошибкой на странице соответствует полю, которое сервер назвал в ответе 422
const ERROR_IDS = { title: 'title', project_id: 'project', due_date: 'date' };

let projects = [];            // проекты с сервера: нужны для выбора в форме и для названия в таблице
let requestCounter = 0;       // номер последнего запроса списка (чтобы старый ответ не затёр новый)
let searchTimer = null;       // таймер задержки поиска

// ============================================================
// 2. Вспомогательные функции
// ============================================================

// Показать один из двух экранов: 'login' или 'main'
function showScreen(name) {
  loginScreen.hidden = (name !== 'login');
  mainScreen.hidden = (name !== 'main');
}

// Защита от XSS: заменяет символы < > & " на безопасные коды,
// чтобы текст от пользователя нельзя было превратить в HTML-код
function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// Дата из формата 2026-10-01 в формат 01.10.2026
function formatDate(isoDate) {
  if (!isoDate) {
    return '—';
  }
  const parts = isoDate.split('-');
  return parts[2] + '.' + parts[1] + '.' + parts[0];
}

// Название проекта по его номеру
function projectName(id) {
  for (let i = 0; i < projects.length; i++) {
    if (projects[i].id === id) {
      return projects[i].title;
    }
  }
  return '—';
}

// Сообщение над таблицей: «Загрузка…», «Ничего не найдено», ошибка
function showMessage(text, isError) {
  messageBox.textContent = text;
  messageBox.className = isError ? 'message error-message' : 'message';
  messageBox.hidden = false;
}

function hideMessage() {
  messageBox.hidden = true;
}

// Красный текст ошибки под полем: setError('title', 'Введите название')
function setError(name, text) {
  document.getElementById('error-' + name).textContent = text;
}

// Убрать все красные сообщения об ошибках
function clearErrors() {
  const errors = document.querySelectorAll('.error');
  for (let i = 0; i < errors.length; i++) {
    errors[i].textContent = '';
  }
}

// Понятный пользователю текст ошибки запроса.
// У ошибок сети (сервер выключен, нет интернета) кода ответа нет: error.status === undefined.
function describeError(error, action) {
  if (error.status === undefined) {
    return 'Нет связи с сервером. Проверьте подключение и повторите.';
  }
  if (error.status === 403) {
    return 'Недостаточно прав для этого действия.';
  }
  return action + ': ' + error.message;
}

// Показывает под полями формы ошибки, которые прислал сервер: { title: '...', project_id: '...' }.
// Возвращает true, если хотя бы одну ошибку удалось показать у поля.
function showFieldErrors(fields) {
  let shown = false;
  for (const name in fields) {
    if (ERROR_IDS[name]) {
      setError(ERROR_IDS[name], fields[name]);
      shown = true;
    }
  }
  return shown;
}

// Сервер сообщил, что токен недействителен (срок вышел или токен отозван): возвращаем пользователя ко входу.
// Эту функцию вызывает checkResponse из api.js.
function handleUnauthorized() {
  localStorage.removeItem('token');
  localStorage.removeItem('user');
  showScreen('login');
  setError('login', 'Сессия истекла. Войдите снова.');
}

// ============================================================
// 3. Работа с DOM: вывод списка задач в таблицу
// ============================================================
function renderTasks(tasks) {
  const user = currentUser();
  let html = '';

  // Для каждой задачи из массива добавляем в строку html одну строку таблицы
  for (let i = 0; i < tasks.length; i++) {
    const task = tasks[i];

    const due = formatDate(task.due_date);          // сервер называет поле due_date (с подчёркиванием)

    // Кнопки в последнем столбце
    let actions = '';
    if (task.status !== 'done') {
      actions += `<button class="done-button" data-id="${task.id}">Выполнена</button> `;
    } else {
      actions += '<span class="done-mark">✓ готово</span> ';
    }
    if (user && user.role === 'manager') {          // кнопку «Удалить» видит только руководитель
      actions += `<button class="danger delete-button" data-id="${task.id}">Удалить</button>`;
    }

    html += `
      <tr>
        <td>${task.id}</td>
        <td>${escapeHtml(task.title)}</td>
        <td>${escapeHtml(projectName(task.project_id))}</td>
        <td>${STATUS_NAMES[task.status] || task.status}</td>
        <td>${PRIORITY_NAMES[task.priority] || '—'}</td>
        <td>${due}</td>
        <td>${actions}</td>
      </tr>`;
  }

  taskBody.innerHTML = html;
  taskTable.hidden = (tasks.length === 0);          // пустую таблицу не показываем

  // Кнопки появились только что, поэтому обработчики вешаем после вывода таблицы
  const doneButtons = document.querySelectorAll('.done-button');
  for (let i = 0; i < doneButtons.length; i++) {
    doneButtons[i].addEventListener('click', onDoneClick);
  }
  const deleteButtons = document.querySelectorAll('.delete-button');
  for (let i = 0; i < deleteButtons.length; i++) {
    deleteButtons[i].addEventListener('click', onDeleteClick);
  }
}

// ============================================================
// 4. Загрузка данных с сервера (асинхронные запросы)
// ============================================================

// Загружает проекты и заполняет список в форме создания задачи
async function loadProjects() {
  projects = await getProjects();
  const select = document.getElementById('task-project');
  let html = '';
  for (let i = 0; i < projects.length; i++) {
    html += `<option value="${projects[i].id}">${escapeHtml(projects[i].title)}</option>`;
  }
  select.innerHTML = html;
}

// Загружает задачи с учётом фильтров и выводит их в таблицу
async function refreshList() {
  const myRequest = ++requestCounter;               // номер этого запроса
  showMessage('Загрузка…', false);

  try {
    const tasks = await getTasks(statusFilter.value, searchInput.value.trim());
    if (myRequest !== requestCounter) {
      return;                                       // пока ждали ответ, отправили более новый запрос: этот ответ устарел
    }
    renderTasks(tasks);
    if (tasks.length === 0) {
      showMessage('Задачи не найдены. Измените фильтры или создайте новую задачу.', false);
    } else {
      hideMessage();
    }
  } catch (error) {
    if (myRequest !== requestCounter) {
      return;
    }
    renderTasks([]);
    showMessage(describeError(error, 'Не удалось загрузить список'), true);
  }
}

// ============================================================
// 5. Обработка событий
// ============================================================

// --- Вход ---
async function onLoginSubmit(event) {
  event.preventDefault();                           // не перезагружать страницу при отправке формы
  clearErrors();

  const email = document.getElementById('login-email').value.trim();
  const password = document.getElementById('login-password').value;

  // Проверка на стороне клиента: не отправляем заведомо неверные данные
  let hasError = false;
  if (email.indexOf('@') === -1) {
    setError('email', 'Введите корректный email');
    hasError = true;
  }
  if (password.length < 6) {
    setError('password', 'Пароль — не короче 6 символов');
    hasError = true;
  }
  if (hasError) {
    return;
  }

  const button = document.getElementById('login-button');
  button.disabled = true;                           // защита от повторного нажатия
  try {
    const result = await login(email, password);
    localStorage.setItem('token', result.token);
    localStorage.setItem('user', JSON.stringify(result.user));
    openMainScreen();
  } catch (error) {
    if (error.status === 401) {
      setError('login', 'Неверный email или пароль');
    } else if (error.status === undefined) {
      setError('login', 'Нет связи с сервером. Попробуйте позже.');
    } else {
      setError('login', error.message);
    }
  } finally {
    button.disabled = false;                        // выполняется в любом случае
  }
}

// --- Создание задачи ---
async function onTaskSubmit(event) {
  event.preventDefault();
  clearErrors();

  const title = document.getElementById('task-title').value.trim();
  const description = document.getElementById('task-description').value.trim();
  const priority = document.getElementById('task-priority').value;
  const dueDate = document.getElementById('task-date').value;
  const projectId = Number(document.getElementById('task-project').value);   // значение select — строка, а серверу нужно число

  let hasError = false;
  if (title.length < 3) {
    setError('title', 'Название — не короче 3 символов');
    hasError = true;
  }
  const today = new Date().toISOString().slice(0, 10);    // сегодняшняя дата вида 2026-10-03
  if (dueDate !== '' && dueDate < today) {
    setError('date', 'Срок не может быть в прошлом');
    hasError = true;
  }
  if (hasError) {
    return;
  }

  const button = document.getElementById('task-button');
  button.disabled = true;
  try {
    await createTask({
      title: title,
      description: description,
      project_id: projectId,
      priority: priority,
      due_date: dueDate === '' ? null : dueDate,    // сервер ждёт дату или null: пустая строка «» не подходит
      status: 'new'
    });
    taskForm.reset();                               // очистить форму
    await refreshList();                            // обновить таблицу
  } catch (error) {
    if (error.status === 422) {
      // ошибки проверки данных: показываем под полями, а если не получилось — общим текстом
      if (!showFieldErrors(error.fields)) {
        setError('form', error.message);
      }
    } else {
      setError('form', describeError(error, 'Не удалось создать задачу'));
    }
  } finally {
    button.disabled = false;
  }
}

// --- Отметить задачу выполненной ---
async function onDoneClick(event) {
  const id = event.target.dataset.id;               // номер задачи из атрибута data-id
  event.target.disabled = true;
  try {
    await updateTask(id, { status: 'done' });       // PATCH: меняем только статус
    await refreshList();
  } catch (error) {
    showMessage(describeError(error, 'Не удалось изменить задачу'), true);
  }
}

// --- Удаление задачи ---
async function onDeleteClick(event) {
  const id = event.target.dataset.id;

  if (!confirm('Удалить задачу №' + id + '?')) {
    return;                                         // пользователь нажал «Отмена»
  }

  event.target.disabled = true;
  try {
    await deleteTask(id);
    await refreshList();
  } catch (error) {
    event.target.disabled = false;
    showMessage(describeError(error, 'Не удалось удалить'), true);
  }
}

// --- Выход ---
async function onLogoutClick() {
  await logout();                                   // сообщаем серверу: токен больше не нужен
  localStorage.removeItem('token');
  localStorage.removeItem('user');
  taskBody.innerHTML = '';                          // чтобы следующий вошедший не увидел чужие данные
  projects = [];
  clearErrors();
  document.getElementById('login-password').value = '';
  showScreen('login');
}

// Поиск: ждём 300 мс после последней нажатой клавиши, чтобы не отправлять запрос на каждую букву
function onSearchInput() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(refreshList, 300);
}

// Подключаем обработчики: «когда произойдёт ЭТО событие у ЭТОГО элемента — вызвать ЭТУ функцию»
loginForm.addEventListener('submit', onLoginSubmit);
taskForm.addEventListener('submit', onTaskSubmit);
statusFilter.addEventListener('change', refreshList);
searchInput.addEventListener('input', onSearchInput);
document.getElementById('refresh-button').addEventListener('click', refreshList);
document.getElementById('logout-button').addEventListener('click', onLogoutClick);

// ============================================================
// 6. Запуск страницы
// ============================================================
async function openMainScreen() {
  const user = currentUser();
  document.getElementById('user-name').textContent = user.name;
  document.getElementById('user-role').textContent = ROLE_NAMES[user.role] || user.role;
  showScreen('main');

  try {
    await loadProjects();
  } catch (error) {
    showMessage(describeError(error, 'Не удалось загрузить проекты'), true);
  }
  await refreshList();
}

// Токен мог остаться от прошлого визита, но уже не действовать: спрашиваем сервер, кто мы
async function start() {
  if (!getToken()) {
    showScreen('login');
    return;
  }
  try {
    const user = await me();
    localStorage.setItem('user', JSON.stringify(user));
    openMainScreen();
  } catch (error) {
    showScreen('login');
    if (error.status === undefined) {
      setError('login', 'Нет связи с сервером. Попробуйте позже.');
    }
  }
}

start();
