<?php
// Шаблон просмотра проекта. Переменные: $project (проект), $tasks (задачи этого проекта)
$statusNames = ['new' => 'Новая', 'in_progress' => 'В работе', 'done' => 'Выполнена'];
?>
<p><a href="/projects">← Ко всем проектам</a></p>

<div class="card">
  <h1><?= e($project['title']) ?></h1>
  <p><?= e($project['description']) ?></p>
</div>

<h2>Задачи проекта (<?= count($tasks) ?>)</h2>

<?php if (count($tasks) === 0): ?>
  <p class="empty">В проекте пока нет задач.</p>
<?php else: ?>
  <ul class="list">
    <?php foreach ($tasks as $task): ?>
      <li>
        <a href="/tasks/<?= e($task['id']) ?>"><?= e($task['title']) ?></a>
        <span class="badge badge-<?= e($task['status']) ?>"><?= e($statusNames[$task['status']] ?? $task['status']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
