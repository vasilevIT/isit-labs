// app.js — клиентская логика страницы: события, работа с DOM, запросы к серверу.

// ============================================================
// Находим нужные элементы страницы (по id из index.html)
// ============================================================
const taskForm = document.getElementById('task-form');
const titleInput = document.getElementById('task-title');
const prioritySelect = document.getElementById('task-priority');
const statusFilter = document.getElementById('status-filter');
const searchInput = document.getElementById('search-input');
const refreshButton = document.getElementById('refresh-button');
const infoBox = document.getElementById('info');
const taskTable = document.getElementById('task-table');
const taskBody = document.getElementById('task-body');
const counter = document.getElementById('counter');

// В данных хранятся английские слова, а пользователю показываем русские подписи
const STATUS_NAMES = { new: 'Новая', in_progress: 'В работе', done: 'Выполнена' };
const PRIORITY_NAMES = { low: 'Низкий', medium: 'Средний', high: 'Высокий' };

// ============================================================
// Вспомогательные функции
// ============================================================

// Показать сообщение в области информации (isError = true — красным цветом)
function showInfo(text, isError) {
  infoBox.textContent = text;
  infoBox.className = isError ? 'message error-message' : 'message';
  infoBox.hidden = false;
}

// Обновить счётчик и показать/скрыть таблицу в зависимости от числа строк
function updateCounter() {
  const count = taskBody.children.length;
  counter.textContent = count;        // изменяем ТЕКСТ элемента
  taskTable.hidden = (count === 0);   // изменяем АТРИБУТ hidden: пустую таблицу не показываем
}

// Добавить в строку таблицы ячейку с текстом и вернуть эту ячейку
function addCell(row, text) {
  const cell = document.createElement('td');   // создаём новый элемент <td>
  cell.textContent = text;                     // записываем в него текст
  row.appendChild(cell);                       // добавляем в строку
  return cell;
}

// ============================================================
// Шаг 3. Работа с DOM
// ============================================================

// Создаёт строку таблицы для одной задачи. Используется и при загрузке с сервера,
// и при добавлении задачи через форму.
function createTaskRow(task) {
  const row = document.createElement('tr');
  row.setAttribute('data-id', task.id);        // изменяем АТРИБУТ: запоминаем номер задачи

  addCell(row, task.id);
  addCell(row, task.title);
  addCell(row, STATUS_NAMES[task.status] || task.status);
  addCell(row, PRIORITY_NAMES[task.priority] || '—');

  // Ячейка с кнопками действий
  const actions = document.createElement('td');

  const doneButton = document.createElement('button');
  doneButton.textContent = 'Выполнено';
  doneButton.addEventListener('click', onDoneClick);
  actions.appendChild(doneButton);

  const deleteButton = document.createElement('button');
  deleteButton.textContent = 'Удалить';
  deleteButton.className = 'danger';           // изменяем КЛАСС оформления кнопки
  deleteButton.addEventListener('click', onDeleteClick);
  actions.appendChild(deleteButton);

  row.appendChild(actions);

  if (task.status === 'done') {
    row.classList.add('done');                 // выполненная задача сразу выглядит зачёркнутой
    doneButton.textContent = 'Вернуть';
  }
  return row;
}

// ============================================================
// Шаг 2. Обработка событий
// ============================================================

// Событие click на кнопке «Выполнено»: меняем класс, текст и атрибут
function onDoneClick(event) {
  const button = event.target;
  const row = button.closest('tr');                 // ближайший родитель — строка таблицы
  row.classList.toggle('done');                     // добавить класс, если нет; убрать, если есть
  const isDone = row.classList.contains('done');

  row.children[2].textContent = isDone ? 'Выполнена' : 'Новая';     // изменить текст ячейки «Статус»
  button.textContent = isDone ? 'Вернуть' : 'Выполнено';             // изменить текст кнопки
  button.setAttribute('title', isDone ? 'Вернуть в работу' : 'Отметить выполненной');  // изменить атрибут
}

// Событие click на кнопке «Удалить»
function onDeleteClick(event) {
  const row = event.target.closest('tr');
  if (!confirm('Удалить задачу «' + row.children[1].textContent + '»?')) {
    return;                                         // пользователь нажал «Отмена»
  }
  row.remove();                                     // УДАЛЯЕМ элемент со страницы
  updateCounter();
  if (taskBody.children.length === 0) {
    showInfo('Список пуст', false);
  }
}

// Событие submit у формы: добавляем новую строку в таблицу
function onFormSubmit(event) {
  event.preventDefault();                           // не перезагружать страницу
  document.getElementById('error-title').textContent = '';

  const title = titleInput.value.trim();            // trim() убирает пробелы по краям
  if (title.length < 3) {
    document.getElementById('error-title').textContent = 'Название — не короче 3 символов';
    return;
  }

  const task = { id: '—', title: title, status: 'new', priority: prioritySelect.value };
  taskBody.insertBefore(createTaskRow(task), taskBody.firstChild);   // добавляем строку в начало таблицы
  updateCounter();
  infoBox.hidden = true;
  taskForm.reset();                                 // очищаем поля формы
  titleInput.focus();
}

// ============================================================
// Шаг 4. Асинхронные запросы (fetch)
// ============================================================

// Получает с сервера список задач. Слово async позволяет внутри использовать await.
async function loadTasks(status, text) {
  // Адрес вида /api/tasks?status=done&q=форма. Пустое значение (status=) означает «без фильтра».
  const url = '/api/tasks?status=' + status + '&q=' + encodeURIComponent(text);

  const response = await fetch(url);                         // 1. отправили запрос и дождались ответа
  if (!response.ok) {                                        // 2. fetch НЕ считает ошибкой коды 404 и 500
    throw new Error('Ошибка сервера: ' + response.status);   //    поэтому проверяем сами
  }
  return await response.json();                              // 3. достали данные (массив задач)
}

// Загружает список и выводит его на страницу
async function refreshList() {
  showInfo('Загрузка…', false);
  refreshButton.disabled = true;                    // пока идёт запрос, кнопка недоступна

  try {
    const tasks = await loadTasks(statusFilter.value, searchInput.value.trim());

    taskBody.innerHTML = '';                        // очищаем таблицу
    for (let i = 0; i < tasks.length; i++) {
      taskBody.appendChild(createTaskRow(tasks[i]));
    }
    updateCounter();

    if (tasks.length === 0) {
      showInfo('Задачи не найдены. Измените фильтр или текст поиска.', false);
    } else {
      showInfo('Загружено задач: ' + tasks.length, false);
    }
  } catch (error) {
    taskBody.innerHTML = '';
    updateCounter();
    if (error.message === 'Failed to fetch') {      // так выглядит ошибка сети (нет интернета)
      showInfo('Нет связи с сервером. Проверьте подключение и повторите попытку.', true);
    } else {
      showInfo('Не удалось загрузить список. ' + error.message, true);
    }
  } finally {
    refreshButton.disabled = false;                 // выполняется в любом случае
  }
}

// ============================================================
// Подключаем обработчики: «когда произойдёт ЭТО событие у ЭТОГО элемента — вызвать ЭТУ функцию»
// ============================================================
taskForm.addEventListener('submit', onFormSubmit);        // отправка формы
refreshButton.addEventListener('click', refreshList);     // нажатие кнопки
statusFilter.addEventListener('change', refreshList);     // выбор элемента списка
searchInput.addEventListener('input', refreshList);       // изменение значения поля

// Первая загрузка при открытии страницы
refreshList();
