// api.js — все обращения к серверу собраны в этом файле.
// Остальной код вызывает getTasks(), createTask() и т.д. и не думает про заголовки, адреса и коды ответов.

// ---------- Токен и вошедший участник ----------
// После входа сервер выдаёт токен — «пропуск». Он хранится в браузере (localStorage) и
// отправляется с каждым запросом в заголовке Authorization. Вместе с ним сохраняем данные участника (имя, роль).

function getToken() {
  return localStorage.getItem('token');
}

// Вошедший участник: { id, name, email, role } или null
function currentUser() {
  try {
    return JSON.parse(localStorage.getItem('user'));
  } catch (error) {
    return null;
  }
}

// Заголовки, которые нужно отправлять с каждым запросом
function authHeaders() {
  return {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + getToken()
  };
}

// ---------- Проверка ответа сервера ----------
// Функция fetch не считает ошибками коды 401, 404, 422, 500: она «ругается» только когда нет связи.
// Поэтому response.ok проверяем сами и превращаем ошибку в исключение с понятными полями:
//   error.message — текст от сервера, error.status — код ответа (у ошибок сети его нет),
//   error.fields  — ошибки полей { title: '...', project_id: '...' } (код 422)
async function checkResponse(response, isLogin) {
  if (response.ok) {
    return;
  }

  let data = {};
  try {
    data = await response.json();
  } catch (error) {
    // у ответа может не быть тела (или оно не JSON): тогда останутся значения по умолчанию
  }

  const error = new Error(data.error || 'Ошибка сервера: ' + response.status);
  error.status = response.status;
  // TODO 3.3  Ошибки полей. Сервер при ошибке проверки (код 422) присылает {"error": "...", "errors": {"поле": "сообщение"}}.
  //           Клиент из лабораторной работы №5 ждёт поле fields, поэтому ошибки до пользователя не доходят. Два места:
  //             — api.js, checkResponse(): error.fields = data.errors || {};
  //             — app.js, showFieldErrors(): для каждого поля показать сообщение в блоке ошибок рядом с ним: ERROR_IDS[имя поля] даёт id блока,
  //               setError(id, текст) выводит текст.
  //           Подсказка:
  //              error.fields = data.errors || {};          // сервер присылает объект { поле: сообщение }
  //           ✔ Проверка: в консоли браузера: createTask({title: 'Да', project_id: 77, priority: 'high', status: 'new'}).catch(e => console.log(e.fields))
  //             — выводится {title: "...", project_id: "..."}. Ошибка под полем: в DevTools → Elements измените value у <option> проекта на 999
  //             и нажмите «Создать задачу»: под списком появится «Проекта с таким номером нет».
  error.fields = data.fields;                // так отвечал учебный сервер из лабораторной работы №5

  // TODO 4.1  Сессия истекла. Токен перестаёт действовать (срок вышел, администратор отозвал, пользователь вышел на другой вкладке),
  //           и сервер отвечает 401. Клиент должен не показывать «ошибку сервера», а вернуть пользователя ко входу. Два места:
  //             — api.js, checkResponse(): если код 401 и это не вход (isLogin) — вызвать handleUnauthorized();
  //             — app.js, handleUnauthorized(): удалить из localStorage token и user, показать экран входа showScreen('login')
  //               и написать «Сессия истекла. Войдите снова.» в блок setError('login', ...).
  //           Подсказка:
  //              if (response.status === 401 && !isLogin) {  // токен недействителен: срок вышел или токен отозван
  //                handleUnauthorized();                     // эта функция написана в app.js
  //              }
  //           ✔ Проверка: войдите, в консоли выполните localStorage.setItem('token', 'bad') и нажмите «Обновить»: откроется экран входа с сообщением «Сессия истекла».

  throw error;
}

// ---------- Вход и выход ----------

// POST /api/auth/login — отправляем email и пароль, получаем { token, expires_at, user }
async function login(email, password) {
  const response = await fetch(API_BASE + '/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email, password: password })
  });
  await checkResponse(response, true);      // true: для входа ответ 401 означает «неверный пароль», а не «сессия истекла»
  return await response.json();
}

// GET /api/auth/me — кто вошёл по этому токену (заодно проверяет, что токен ещё действует)
async function me() {
  const response = await fetch(API_BASE + '/api/auth/me', { headers: authHeaders() });
  await checkResponse(response);
  return await response.json();
}

// POST /api/auth/logout — сообщаем серверу, что токен больше не нужен
async function logout() {
  try {
    await fetch(API_BASE + '/api/auth/logout', { method: 'POST', headers: authHeaders() });
  } catch (error) {
    // сервер недоступен: всё равно выходим, токен удалится из браузера
  }
}

// ---------- Проекты ----------

// GET /api/projects
async function getProjects() {
  const response = await fetch(API_BASE + '/api/projects', { headers: authHeaders() });
  await checkResponse(response);
  return await response.json();
}

// ---------- Задачи ----------

// GET /api/tasks?sort=-id&status=new&q=текст
async function getTasks(status, text) {
  // TODO 3.1  Контракт запроса списка. В лабораторной работе №5 клиент просил сортировку так: ?_sort=id&_order=desc.
  //           Настоящий сервер понимает sort=-id (TaskRepository::search). Измените сборку адреса:
  //             — адрес начинается с API_BASE + '/api/tasks?sort=-id';
  //             — значения фильтров обязательно оборачивайте в encodeURIComponent: знаки & # + и пробелы иначе ломают адрес.
  //           Подсказка:
  //              let url = API_BASE + '/api/tasks?sort=-id';              // sort=-id: новые задачи сверху
  //              if (status !== '') {
  //                url = url + '&status=' + encodeURIComponent(status);
  //              }
  //              if (text !== '') {
  //                url = url + '&q=' + encodeURIComponent(text);          // encodeURIComponent: знаки & # + и пробелы иначе ломают адрес
  //              }
  //           ✔ Проверка: откройте клиент, войдите; в Network (F12) запрос списка выглядит как /api/tasks?sort=-id; новые задачи — сверху;
  //             введите в поиске «форм» — через долю секунды в таблице остаётся одна задача.
  let url = API_BASE + '/api/tasks?_sort=id&_order=desc';  // так просил сортировку учебный сервер из лабораторной работы №5
  if (status !== '') {
    url = url + '&status=' + status;
  }
  if (text !== '') {
    url = url + '&q=' + encodeURIComponent(text);
  }

  const response = await fetch(url, { headers: authHeaders() });   // 1. отправили запрос и ждём ответ
  await checkResponse(response);                                   // 2. проверили, что нет ошибки
  return await response.json();                                    // 3. достали данные (массив задач)
}

// POST /api/tasks — в теле запроса отправляем объект задачи
async function createTask(task) {
  const response = await fetch(API_BASE + '/api/tasks', {
    method: 'POST',
    headers: authHeaders(),
    body: JSON.stringify(task)       // объект -> строка JSON
  });
  await checkResponse(response);
  return await response.json();      // сервер вернёт созданную задачу с номером (id)
}

// PATCH /api/tasks/5 — меняем только присланные поля, например { status: 'done' }
async function updateTask(id, changes) {
  const response = await fetch(API_BASE + '/api/tasks/' + id, {
    method: 'PATCH',
    headers: authHeaders(),
    body: JSON.stringify(changes)
  });
  await checkResponse(response);
  return await response.json();
}

// DELETE /api/tasks/5 — тела у ответа нет (код 204)
async function deleteTask(id) {
  const response = await fetch(API_BASE + '/api/tasks/' + id, {
    method: 'DELETE',
    headers: authHeaders()
  });
  await checkResponse(response);
}
