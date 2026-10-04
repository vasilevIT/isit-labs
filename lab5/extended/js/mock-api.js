/*
 * mock-api.js — учебный «сервер» прямо в браузере.
 *
 * ФАЙЛ МЕНЯТЬ НЕ НУЖНО. Он подменяет window.fetch для адресов, начинающихся с /api/,
 * и отвечает так, как отвечал бы настоящий backend: с задержкой, кодами состояния и JSON.
 * Данные хранятся в localStorage браузера (начальные значения берутся из data/seed.json).
 *
 * Когда в следующих работах появится настоящий backend, этот файл просто отключается
 * (удаляется строка <script src="js/mock-api.js"> в index.html), а код клиента остаётся прежним.
 *
 * Правила API:
 *   POST   /api/auth/login        {email, password}  -> 200 {token, user} | 401   (пароль для всех: 123456)
 *   GET    /api/auth/me                              -> 200 user
 *   GET    /api/<ресурс>?поле=значение&q=текст       -> 200 [ ... ]   (фильтрация, поиск по тексту)
 *   GET    /api/<ресурс>/<id>                        -> 200 {...} | 404
 *   POST   /api/<ресурс>          {...}              -> 201 {...} | 422 {error, fields}
 *   PUT    /api/<ресурс>/<id>     {...}              -> 200 {...} | 404 | 422
 *   PATCH  /api/<ресурс>/<id>     {поле: значение}   -> 200 {...} | 404 | 422
 *   DELETE /api/<ресурс>/<id>                        -> 204       | 404
 *   Все запросы, кроме login, требуют заголовок  Authorization: Bearer <token>, иначе 401.
 *
 * Для проверки обработки ошибок (в консоли браузера):
 *   MockApi.setErrorRate(0.3)      // 30% запросов будут отвечать ошибкой 500
 *   MockApi.setNetworkDown(true)   // «пропал интернет»: fetch завершится ошибкой сети
 *   MockApi.setDelay(1500, 3000)   // медленный сервер
 *   MockApi.reset()                // вернуть данные к начальным
 */
(() => {
  'use strict';

  const DB_KEY = 'mock:db';
  const cfg = { minDelay: 300, maxDelay: 800, errorRate: 0, networkDown: false, seedUrl: 'data/seed.json' };
  const PASSWORD = '123456';
  const realFetch = window.fetch.bind(window);

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const randomDelay = () => cfg.minDelay + Math.random() * (cfg.maxDelay - cfg.minDelay);

  function reply(status, body) {
    const hasBody = body !== undefined && body !== null && status !== 204;
    return new Response(hasBody ? JSON.stringify(body) : null, {
      status,
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
    });
  }

  async function loadDb() {
    const saved = localStorage.getItem(DB_KEY);
    if (saved) return JSON.parse(saved);
    const response = await realFetch(cfg.seedUrl);
    if (!response.ok) throw new Error('Не удалось загрузить ' + cfg.seedUrl);
    const db = await response.json();
    db._sessions = {};
    saveDb(db);
    return db;
  }

  function saveDb(db) {
    localStorage.setItem(DB_KEY, JSON.stringify(db));
  }

  function matches(item, [key, value]) {
    if (key.startsWith('_')) return true;
    if (key === 'q') {
      const text = [item.title, item.name, item.description].filter(Boolean).join(' ').toLowerCase();
      return text.includes(value.toLowerCase());
    }
    return String(item[key]) === value;
  }

  function missingFields(db, resource, data) {
    const required = (db._required && db._required[resource]) || [];
    return required.filter((field) => data[field] === undefined || data[field] === null || String(data[field]).trim() === '');
  }

  async function handle(url, init = {}) {
    const method = (init.method || 'GET').toUpperCase();
    const parsed = new URL(url, location.href);
    const parts = parsed.pathname.replace(/^\/api\/?/, '').split('/').filter(Boolean);
    const headers = new Headers(init.headers || {});

    let body = null;
    if (init.body) {
      try {
        body = JSON.parse(init.body);
      } catch {
        return reply(400, { error: 'Тело запроса не является корректным JSON' });
      }
    }

    await sleep(randomDelay());
    if (cfg.networkDown) throw new TypeError('Failed to fetch');
    if (Math.random() < cfg.errorRate) return reply(500, { error: 'Внутренняя ошибка сервера' });

    const db = await loadDb();

    // --- авторизация ---
    if (parts[0] === 'auth' && parts[1] === 'login' && method === 'POST') {
      const user = db.users.find((u) => body && u.email === body.email);
      if (!user || body.password !== PASSWORD) return reply(401, { error: 'Неверный email или пароль' });
      const token = 'tok_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      db._sessions[token] = user.id;
      saveDb(db);
      return reply(200, { token, user });
    }

    const token = (headers.get('Authorization') || '').replace(/^Bearer\s+/i, '');
    const userId = db._sessions[token];
    if (!userId) return reply(401, { error: 'Требуется авторизация' });

    if (parts[0] === 'auth' && parts[1] === 'me') {
      return reply(200, db.users.find((u) => u.id === userId));
    }

    // --- CRUD по ресурсам ---
    const resource = parts[0];
    if (!resource || resource.startsWith('_') || !Array.isArray(db[resource])) {
      return reply(404, { error: 'Ресурс не найден' });
    }
    const items = db[resource];
    const id = parts[1] !== undefined ? Number(parts[1]) : null;

    if (id === null) {
      if (method === 'GET') {
        let list = items.filter((item) => [...parsed.searchParams].every((pair) => matches(item, pair)));
        const sort = parsed.searchParams.get('_sort');
        if (sort) {
          const dir = parsed.searchParams.get('_order') === 'desc' ? -1 : 1;
          list = [...list].sort((a, b) => (a[sort] > b[sort] ? dir : a[sort] < b[sort] ? -dir : 0));
        }
        return reply(200, list);
      }
      if (method === 'POST') {
        const missing = missingFields(db, resource, body || {});
        if (missing.length) return reply(422, { error: 'Не заполнены обязательные поля', fields: missing });
        const created = { ...body, id: Math.max(0, ...items.map((i) => i.id)) + 1, createdAt: new Date().toISOString() };
        items.push(created);
        saveDb(db);
        return reply(201, created);
      }
      return reply(405, { error: 'Метод не поддерживается' });
    }

    const index = items.findIndex((i) => i.id === id);
    if (index === -1) return reply(404, { error: 'Запись не найдена' });

    if (method === 'GET') return reply(200, items[index]);
    if (method === 'DELETE') {
      items.splice(index, 1);
      saveDb(db);
      return reply(204);
    }
    if (method === 'PUT' || method === 'PATCH') {
      const next = method === 'PUT'
        ? { ...body, id, createdAt: items[index].createdAt }
        : { ...items[index], ...body, id };
      const missing = missingFields(db, resource, next);
      if (missing.length) return reply(422, { error: 'Не заполнены обязательные поля', fields: missing });
      items[index] = next;
      saveDb(db);
      return reply(200, next);
    }
    return reply(405, { error: 'Метод не поддерживается' });
  }

  window.fetch = (input, init) => {
    const url = typeof input === 'string' ? input : input.url;
    const target = new URL(url, location.href);
    if (target.origin === location.origin && target.pathname.startsWith('/api/')) {
      return handle(url, init);
    }
    return realFetch(input, init);
  };

  window.MockApi = {
    config: cfg,
    setErrorRate: (rate) => { cfg.errorRate = rate; },
    setNetworkDown: (down) => { cfg.networkDown = down; },
    setDelay: (min, max) => { cfg.minDelay = min; cfg.maxDelay = max; },
    reset: () => { localStorage.removeItem(DB_KEY); location.reload(); },
  };
})();
