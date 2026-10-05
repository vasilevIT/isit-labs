// api.js — все обращения к серверу собраны в этом файле.
// Остальной код вызывает getTasks(), createTask() и т.д. и не думает про заголовки и адреса.

// Токен (пропуск, который сервер выдал при входе) хранится в браузере, в localStorage
function getToken() {
  return localStorage.getItem('token');
}

// Заголовки, которые нужно отправлять с каждым запросом
function authHeaders() {
  return {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + getToken()
  };
}

// Проверка ответа сервера. Функция fetch не считает ошибками коды 404 и 500,
// поэтому проверять response.ok нужно самим.
async function checkResponse(response) {
  if (!response.ok) {
    const data = await response.json();
    const error = new Error(data.error || 'Ошибка сервера: ' + response.status);
    error.status = response.status;   // 401, 404, 422 ...
    error.fields = data.fields;       // список полей, не прошедших проверку на сервере
    throw error;
  }
}

// ---------- Вход ----------
// POST /api/auth/login — отправляем email и пароль, получаем { token, user }
async function login(email, password) {
  const response = await fetch('/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email, password: password })
  });
  await checkResponse(response);
  return await response.json();
}

// ---------- Получение списка задач ----------
// GET /api/tasks?status=new&q=текст
async function getTasks(status, text) {
  // Собираем адрес с фильтрами. Пустые фильтры в адрес не добавляем.
  let url = '/api/tasks?_sort=id&_order=desc';
  if (status !== '') {
    url = url + '&status=' + status;
  }
  if (text !== '') {
    url = url + '&q=' + encodeURIComponent(text);
  }

  const response = await fetch(url, { headers: authHeaders() });  // 1. отправили запрос и ждём ответ
  await checkResponse(response);                                  // 2. проверили, что нет ошибки
  return await response.json();                                   // 3. достали данные (массив задач)
}

// ---------- Создание задачи ----------
// POST /api/tasks — в теле запроса отправляем объект задачи
async function createTask(task) {
  const response = await fetch('/api/tasks', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(task)      // объект -> строка JSON
  });
  await checkResponse(response);
  return await response.json();     // сервер вернёт созданную задачу с номером (id)
}

// ---------- Удаление задачи ----------
// DELETE /api/tasks/5 — тела у запроса и ответа нет
async function deleteTask(id) {
  const response = await fetch('/api/tasks/' + id, {
    method: 'DELETE',
    headers: authHeaders()
  });
  await checkResponse(response);
}
