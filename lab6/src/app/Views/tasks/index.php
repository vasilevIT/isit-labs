<?php
// Шаблон списка задач. Переменные: $tasks (массив задач), $status (выбранный фильтр)
$statusNames = ['new' => 'Новая', 'in_progress' => 'В работе', 'done' => 'Выполнена'];
$priorityNames = ['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий'];
?>
<h1>Задачи</h1>

<p class="filters">
  Показать:
  <a href="/tasks" class="<?= $status === '' ? 'active' : '' ?>">все</a>
  <?php foreach ($statusNames as $code => $name): ?>
    <a href="/tasks?status=<?= e($code) ?>" class="<?= $status === $code ? 'active' : '' ?>"><?= e($name) ?></a>
  <?php endforeach; ?>
</p>

<?php if (count($tasks) === 0): ?>
  <p class="empty">Задачи не найдены.</p>
<?php else: ?>
  <table>
    <thead>
      <tr><th>№</th><th>Название</th><th>Статус</th><th>Приоритет</th><th>Срок</th></tr>
    </thead>
    <tbody>
      <?php foreach ($tasks as $task): ?>
        <tr>
          <td><?= e($task['id']) ?></td>
          <td><a href="/tasks/<?= e($task['id']) ?>"><?= e($task['title']) ?></a></td>
          <td><span class="badge badge-<?= e($task['status']) ?>"><?= e($statusNames[$task['status']] ?? $task['status']) ?></span></td>
          <td><?= e($priorityNames[$task['priority']] ?? $task['priority']) ?></td>
          <td><?= e($task['due_date'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
