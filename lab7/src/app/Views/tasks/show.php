<?php
// Шаблон просмотра одной задачи. Переменные: $task (задача), $project (её проект)
$statusNames = ['new' => 'Новая', 'in_progress' => 'В работе', 'done' => 'Выполнена'];
$priorityNames = ['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий'];
?>
<p><a href="/tasks">← К списку задач</a></p>

<div class="card">
  <h1><?= e($task['title']) ?></h1>
  <dl class="meta">
    <dt>Номер</dt>      <dd><?= e($task['id']) ?></dd>
    <dt>Проект</dt>     <dd><a href="/projects/<?= e($project['id']) ?>"><?= e($project['title']) ?></a></dd>
    <dt>Статус</dt>     <dd><span class="badge badge-<?= e($task['status']) ?>"><?= e($statusNames[$task['status']] ?? $task['status']) ?></span></dd>
    <dt>Приоритет</dt>  <dd><?= e($priorityNames[$task['priority']] ?? $task['priority']) ?></dd>
    <dt>Срок</dt>       <dd><?= e($task['due_date'] ?? '—') ?></dd>
  </dl>
</div>
