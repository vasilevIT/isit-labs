<?php
// Шаблон формы создания задачи.
// Переменные: $projects (список проектов для выбора), $errors (ошибки проверки), $old (прежние значения полей)
?>
<h1>Новая задача</h1>

<!-- action — адрес, на который браузер отправит данные; method="post" — способом POST -->
<form action="/tasks" method="post" class="card form">

  <label for="title">Название</label>
  <input id="title" type="text" name="title" value="<?= e($old['title'] ?? '') ?>">
  <?php if (isset($errors['title'])): ?><div class="error"><?= e($errors['title']) ?></div><?php endif; ?>

  <label for="project_id">Проект</label>
  <select id="project_id" name="project_id">
    <option value="">Выберите проект…</option>
    <?php foreach ($projects as $project): ?>
      <option value="<?= e($project['id']) ?>" <?= ((int) ($old['project_id'] ?? 0)) === $project['id'] ? 'selected' : '' ?>>
        <?= e($project['title']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php if (isset($errors['project_id'])): ?><div class="error"><?= e($errors['project_id']) ?></div><?php endif; ?>

  <label for="priority">Приоритет</label>
  <select id="priority" name="priority">
    <?php foreach (['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий'] as $code => $name): ?>
      <option value="<?= e($code) ?>" <?= ($old['priority'] ?? 'medium') === $code ? 'selected' : '' ?>><?= e($name) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (isset($errors['priority'])): ?><div class="error"><?= e($errors['priority']) ?></div><?php endif; ?>

  <label for="due_date">Срок выполнения</label>
  <input id="due_date" type="date" name="due_date" value="<?= e($old['due_date'] ?? '') ?>">

  <p><button type="submit" class="btn">Создать задачу</button> <a href="/tasks">Отмена</a></p>
</form>
