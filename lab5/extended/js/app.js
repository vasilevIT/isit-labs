// app.js — логика страницы: вывод данных, обработка событий, запросы к серверу.

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

// Подписи для статусов и приоритетов (в данных хранятся английские слова)
const STATUS_NAMES = { new: 'Новая', in_progress: 'В работе', done: 'Выполнена' };
const PRIORITY_NAMES = { low: 'Низкий', medium: 'Средний', high: 'Высокий' };

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

// ============================================================
// 3. Работа с DOM: вывод списка задач в таблицу
// ============================================================
function renderTasks(tasks) {
  let html = '';

  // Для каждой задачи из массива добавляем в строку html одну строку таблицы
  for (let i = 0; i < tasks.length; i++) {
    const task = tasks[i];
    html += `
      <tr>
        <td>${task.id}</td>
        <td>${escapeHtml(task.title)}</td>
        <td>${STATUS_NAMES[task.status] || task.status}</td>
        <td>${PRIORITY_NAMES[task.priority] || '—'}</td>
        <td>${formatDate(task.dueDate)}</td>
        <td><button class="danger delete-button" data-id="${task.id}">Удалить</button></td>
      </tr>`;
  }

  taskBody.innerHTML = html;
  taskTable.hidden = (tasks.length === 0);   // пустую таблицу не показываем

  // Кнопки «Удалить» появились только что, поэтому обработчики вешаем после вывода таблицы
  const buttons = document.querySelectorAll('.delete-button');
  for (let i = 0; i < buttons.length; i++) {
    buttons[i].addEventListener('click', onDeleteClick);
  }
}

// ============================================================
// 4. Загрузка списка с сервера (асинхронный запрос)
// ============================================================
async function refreshList() {
  showMessage('Загрузка…', false);

  try {
    const tasks = await getTasks(statusFilter.value, searchInput.value.trim());
    renderTasks(tasks);
    if (tasks.length === 0) {
      showMessage('Задачи не найдены. Измените фильтры или создайте новую задачу.', false);
    } else {
      hideMessage();
    }
  } catch (error) {
    renderTasks([]);
    if (error.status === 401) {          // токен недействителен — возвращаемся ко входу
      localStorage.removeItem('token');
      showScreen('login');
    } else if (error.status === undefined) {   // у ошибок сети нет кода ответа сервера
      showMessage('Нет связи с сервером. Проверьте подключение и нажмите «Обновить».', true);
    } else {
      showMessage('Не удалось загрузить список: ' + error.message, true);
    }
  }
}

// ============================================================
// 5. Обработка событий
// ============================================================

// --- Вход ---
async function onLoginSubmit(event) {
  event.preventDefault();                  // не перезагружать страницу при отправке формы
  clearErrors();

  const email = document.getElementById('login-email').value.trim();
  const password = document.getElementById('login-password').value;

  // Проверка на стороне клиента
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
  button.disabled = true;                  // защита от повторного нажатия
  try {
    const result = await login(email, password);
    localStorage.setItem('token', result.token);
    localStorage.setItem('userName', result.user.name);
    openMainScreen();
  } catch (error) {
    setError('login', error.status === 401 ? 'Неверный email или пароль' : error.message);
  } finally {
    button.disabled = false;               // выполняется в любом случае
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
      priority: priority,
      dueDate: dueDate,
      status: 'new'
    });
    taskForm.reset();                      // очистить форму
    await refreshList();                   // обновить таблицу
  } catch (error) {
    setError('form', error.message);
  } finally {
    button.disabled = false;
  }
}

// --- Удаление задачи ---
async function onDeleteClick(event) {
  const id = event.target.dataset.id;      // номер задачи из атрибута data-id

  if (!confirm('Удалить задачу №' + id + '?')) {
    return;                                // пользователь нажал «Отмена»
  }

  event.target.disabled = true;
  try {
    await deleteTask(id);
    await refreshList();
  } catch (error) {
    showMessage('Не удалось удалить: ' + error.message, true);
  }
}

// --- Выход ---
function onLogoutClick() {
  localStorage.removeItem('token');
  localStorage.removeItem('userName');
  showScreen('login');
}

// Подключаем обработчики: «когда произойдёт ЭТО событие у ЭТОГО элемента — вызвать ЭТУ функцию»
loginForm.addEventListener('submit', onLoginSubmit);
taskForm.addEventListener('submit', onTaskSubmit);
statusFilter.addEventListener('change', refreshList);     // выбрали статус в списке
searchInput.addEventListener('input', refreshList);       // ввели букву в поиске
document.getElementById('refresh-button').addEventListener('click', refreshList);
document.getElementById('logout-button').addEventListener('click', onLogoutClick);

// ============================================================
// 6. Запуск страницы
// ============================================================
function openMainScreen() {
  document.getElementById('user-name').textContent = localStorage.getItem('userName') + ' ';
  showScreen('main');
  refreshList();
}

// Если токен уже есть (вход выполнялся ранее) — сразу открываем список
if (getToken()) {
  openMainScreen();
} else {
  showScreen('login');
}
