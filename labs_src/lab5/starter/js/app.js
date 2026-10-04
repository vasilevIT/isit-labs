// app.js — клиентская логика страницы: события, работа с DOM, запросы к серверу.
// Читайте комментарии: в них написано, что делает код и что нужно дописать (пометки TODO).

// ============================================================
// Находим нужные элементы страницы (по id из index.html). Готово.
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
// Готовые вспомогательные функции (менять не нужно)
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
// ШАГ 3. Работа с DOM
// ============================================================

// Создаёт строку таблицы для одной задачи. Позже её будут использовать и загрузка с сервера,
// и добавление задачи через форму.
function createTaskRow(task) {
  const row = document.createElement('tr');

  // Пример (готово): ячейка с номером задачи
  addCell(row, task.id);

  // TODO 3.1  Добавьте остальные ячейки тем же способом:
  //             addCell(row, task.title);                                   — название
  //             addCell(row, STATUS_NAMES[task.status] || task.status);     — статус
  //             addCell(row, PRIORITY_NAMES[task.priority] || '—');         — приоритет

  // TODO 3.2  Создайте ячейку с кнопками действий:
  //             const actions = document.createElement('td');
  //             const doneButton = document.createElement('button');
  //             doneButton.textContent = 'Выполнено';
  //             doneButton.addEventListener('click', onDoneClick);          — событие (Шаг 2)
  //             actions.appendChild(doneButton);
  //           Так же создайте кнопку «Удалить» (обработчик onDeleteClick) и дайте ей класс оформления:
  //             deleteButton.className = 'danger';
  //           В конце добавьте ячейку в строку: row.appendChild(actions);

  // TODO 3.3  Запомните номер задачи в атрибуте строки:  row.setAttribute('data-id', task.id);

  // TODO 3.4  Если task.status === 'done', выделите строку классом оформления:
  //             row.classList.add('done');   (класс зачёркивает текст, он описан в styles.css)
  //           и поменяйте текст кнопки на «Вернуть».

  return row;
}

// Событие click на кнопке «Выполнено»
function onDoneClick(event) {
  const button = event.target;               // кнопка, на которую нажали
  const row = button.closest('tr');          // ближайший родитель — строка таблицы

  // TODO 3.5  Измените строку с помощью JavaScript:
  //             row.classList.toggle('done');               — добавить класс, если его нет; убрать, если есть
  //             const isDone = row.classList.contains('done');  — есть ли сейчас класс
  //             row.children[2].textContent = isDone ? 'Выполнена' : 'Новая';   — изменить текст в ячейке «Статус»
  //             button.textContent = isDone ? 'Вернуть' : 'Выполнено';          — изменить текст кнопки
  //             button.setAttribute('title', isDone ? 'Вернуть в работу' : 'Отметить выполненной');  — атрибут
  console.log('Нажата кнопка «Выполнено»', row);
}

// Событие click на кнопке «Удалить»
function onDeleteClick(event) {
  const row = event.target.closest('tr');

  // TODO 3.6  Спросите подтверждение: if (!confirm('Удалить задачу?')) { return; }
  //           Затем удалите строку со страницы: row.remove();
  //           и обновите счётчик: updateCounter();
  console.log('Нажата кнопка «Удалить»', row);
}

// ============================================================
// ШАГ 2. Обработка событий
// ============================================================

// Событие submit у формы: добавление новой задачи в таблицу
function onFormSubmit(event) {
  // TODO 2.1  Отмените перезагрузку страницы:  event.preventDefault();

  const title = titleInput.value.trim();     // trim() убирает пробелы по краям
  console.log('Форма отправлена:', title, prioritySelect.value);

  // TODO 2.2  Проверка данных: если title.length < 3, выведите сообщение в элемент с id 'error-title'
  //           (document.getElementById('error-title').textContent = '...') и завершите функцию: return;
  //           Если проверка пройдена, сообщение нужно очистить (записать пустую строку).

  // TODO 3.7  (Шаг 3) Добавьте новую задачу в таблицу:
  //             const task = { id: '—', title: title, status: 'new', priority: prioritySelect.value };
  //             taskBody.insertBefore(createTaskRow(task), taskBody.firstChild);   — вставить строку в начало таблицы
  //             updateCounter();
  //             infoBox.hidden = true;     — спрятать область сообщений
  //             taskForm.reset();          — очистить поля формы
}

// ============================================================
// ШАГ 4. Асинхронные запросы (fetch)
// ============================================================

// Получает с сервера список задач. Слово async позволяет внутри использовать await.
async function loadTasks(status, text) {
  // TODO 4.1  Напишите функцию по образцу:
  //   1) Соберите адрес (tasks — ключ из mock-data.js, у вас может быть другой):
  //        const url = '/api/tasks?status=' + status + '&q=' + encodeURIComponent(text);
  //      Пустое значение (status=) означает «без фильтра», поэтому условия не нужны.
  //   2) Отправьте запрос и дождитесь ответа:   const response = await fetch(url);
  //   3) Проверьте ответ (fetch не считает ошибкой коды 404 и 500!):
  //        if (!response.ok) { throw new Error('Ошибка сервера: ' + response.status); }
  //   4) Достаньте данные и верните их:          return await response.json();
  return [];
}

// Загружает список с сервера и выводит его на страницу
async function refreshList() {
  // Пока выполняется Шаг 2, достаточно вывести в консоль выбранные значения
  console.log('Фильтр:', statusFilter.value, 'Поиск:', searchInput.value);

  // TODO 4.2  Замените console.log выше на настоящую загрузку:
  //   showInfo('Загрузка…', false);
  //   refreshButton.disabled = true;                    — пока идёт запрос, кнопка недоступна
  //   try {
  //     const tasks = await loadTasks(statusFilter.value, searchInput.value.trim());
  //     taskBody.innerHTML = '';                        — очистить таблицу
  //     for (let i = 0; i < tasks.length; i++) {        — по строке на каждую задачу
  //       taskBody.appendChild(createTaskRow(tasks[i]));
  //     }
  //     updateCounter();
  //     если tasks.length === 0 — showInfo('Задачи не найдены', false), иначе showInfo('Загружено задач: ' + tasks.length, false);
  //   } catch (error) {
  //     taskBody.innerHTML = '';  updateCounter();
  //     showInfo('Не удалось загрузить список. ' + error.message, true);     — сообщение красным цветом
  //   } finally {
  //     refreshButton.disabled = false;                 — выполнится в любом случае
  //   }
  //   Дополнительно: ошибка сети (нет интернета) имеет текст 'Failed to fetch' —
  //   для неё покажите понятное сообщение «Нет связи с сервером».
}

// ============================================================
// ШАГ 2 (продолжение). Подключаем обработчики событий
// ============================================================
// «Когда произойдёт ЭТО событие у ЭТОГО элемента — вызвать ЭТУ функцию».
// Пример (готов): форма вызывает onFormSubmit при отправке.
taskForm.addEventListener('submit', onFormSubmit);

// TODO 2.3  Подключите остальные обработчики по образцу:
//   refreshButton  — событие 'click'   — функция refreshList   (нажатие кнопки)
//   statusFilter   — событие 'change'  — функция refreshList   (выбор элемента списка)
//   searchInput    — событие 'input'   — функция refreshList   (изменение значения поля)

// ============================================================
// Запуск страницы
// ============================================================
// TODO 4.3  (Шаг 4) Первая загрузка при открытии страницы: добавьте здесь вызов  refreshList();
