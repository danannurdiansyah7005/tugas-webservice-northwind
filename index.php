<?php
// index.php – Northwind CRUD Multi-Table

/******************************
 * KONFIGURASI DATABASE
 ******************************/
$DB_DRIVER  = 'mysql';
$DB_HOST    = 'sql100.infinityfree.com';
$DB_PORT    = '3306';
$DB_NAME    = 'if0_40273726_northwind';
$DB_USER    = 'if0_40273726';
$DB_PASS    = '2GfW8gwzhROOwp';
$CHARSET    = 'utf8mb4';

$dsn = "{$DB_DRIVER}:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset={$CHARSET}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$CHARSET}"
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
    http_response_code(500);
    die("<h1>Database connection failed</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>");
}

/******************************
 * TABEL YANG DIIZINKAN
 ******************************/
$allowedTables = [
    'customers' => 'Customers',
    'employees' => 'Employees',
    'invoices'  => 'Invoices',
    'orders'    => 'Orders',
    'products'  => 'Products',
    'shippers'  => 'Shippers',
    'suppliers' => 'Suppliers',
];

$defaultSection = 'customers';
$defaultLimit = 20;

// Helper
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function getTableInfo(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("DESCRIBE `$table`");
    $stmt->execute();
    $cols = [];
    $pk = null;
    foreach ($stmt as $row) {
        $cols[$row['Field']] = $row;
        if ($row['Key'] === 'PRI') $pk = $row['Field'];
    }
    return ['columns' => $cols, 'pk' => $pk];
}

function fetchRows(PDO $pdo, string $table, int $limit, int $offset): array {
    $orderBy = '1';
    $info = getTableInfo($pdo, $table);
    if ($info['pk']) $orderBy = "`{$info['pk']}`";
    $sql = "SELECT * FROM `$table` ORDER BY $orderBy LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getTotalCount(PDO $pdo, string $table): int {
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `$table`");
    return (int)$stmt->fetch()['c'];
}

function renderPagination(string $tableKey, int $total, int $limit, int $page): string {
    $pages = max(1, (int)ceil($total / max(1, $limit)));
    if ($pages <= 1) return '';

    $query = $_GET;
    $html = '<nav aria-label="Pagination" class="d-flex justify-content-center mt-3"><ul class="pagination pagination-sm">';

    $prev = max(1, $page - 1);
    $next = min($pages, $page + 1);

    $query["page_$tableKey"] = $prev;
    $html .= '<li class="page-item'.($page<=1?' disabled':'').'"><a class="page-link" href="?'.h(http_build_query($query)).'">&laquo;</a></li>';

    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    for ($p = $start; $p <= $end; $p++) {
        $query["page_$tableKey"] = $p;
        $active = $p === $page ? ' active' : '';
        $html .= '<li class="page-item'.$active.'"><a class="page-link" href="?'.h(http_build_query($query)).'">'.h($p).'</a></li>';
    }

    $query["page_$tableKey"] = $next;
    $html .= '<li class="page-item'.($page>=$pages?' disabled':'').'"><a class="page-link" href="?'.h(http_build_query($query)).'">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

// Handle CRUD actions
$messages = [];
if ($_POST['action'] ?? null) {
    $table = $_POST['table'] ?? '';
    if (!isset($allowedTables[$table])) die('Invalid table');
    $info = getTableInfo($pdo, $table);
    $pk = $info['pk'];

    try {
        if ($_POST['action'] === 'create') {
            $cols = array_keys($info['columns']);
            $cols = array_filter($cols, fn($c) => $c !== $pk || !empty($_POST[$c]));
            $fields = implode('`, `', $cols);
            $placeholders = ':' . implode(', :', $cols);
            $sql = "INSERT INTO `$table` (`$fields`) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            foreach ($cols as $col) {
                $stmt->bindValue(":$col", $_POST[$col] ?? null);
            }
            $stmt->execute();
            $messages[] = ['type' => 'success', 'text' => 'Record created successfully.'];
        } elseif ($_POST['action'] === 'update' && $pk && !empty($_POST[$pk])) {
            $cols = array_keys($info['columns']);
            $cols = array_filter($cols, fn($c) => $c !== $pk);
            $set = implode(', ', array_map(fn($c) => "`$c` = :$c", $cols));
            $sql = "UPDATE `$table` SET $set WHERE `$pk` = :id";
            $stmt = $pdo->prepare($sql);
            foreach ($cols as $col) {
                $stmt->bindValue(":$col", $_POST[$col] ?? null);
            }
            $stmt->bindValue(':id', $_POST[$pk]);
            $stmt->execute();
            $messages[] = ['type' => 'success', 'text' => 'Record updated successfully.'];
        } elseif ($_POST['action'] === 'delete' && $pk && !empty($_POST[$pk])) {
            $sql = "DELETE FROM `$table` WHERE `$pk` = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $_POST[$pk]);
            $stmt->execute();
            $messages[] = ['type' => 'success', 'text' => 'Record deleted successfully.'];
        }
    } catch (Exception $e) {
        $messages[] = ['type' => 'danger', 'text' => 'Error: ' . h($e->getMessage())];
    }
}

// Load data
$tablesData = [];
foreach ($allowedTables as $key => $label) {
    $limit = isset($_GET["limit_$key"]) ? max(1, min(200, (int)$_GET["limit_$key"])) : $defaultLimit;
    $page  = isset($_GET["page_$key"])  ? max(1, (int)$_GET["page_$key"]) : 1;
    $offset = ($page - 1) * $limit;

    $info = getTableInfo($pdo, $key);
    $total = getTotalCount($pdo, $key);
    $rows  = fetchRows($pdo, $key, $limit, $offset);

    $tablesData[$key] = [
        'label'   => $label,
        'columns' => array_keys($info['columns']),
        'pk'      => $info['pk'],
        'rows'    => $rows,
        'total'   => $total,
        'limit'   => $limit,
        'page'    => $page,
    ];
}

$active = isset($_GET['show']) && isset($allowedTables[$_GET['show']])
    ? $_GET['show']
    : $defaultSection;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Northwind – CRUD Multi-Table</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .navbar-brand { font-weight: 700; letter-spacing: .3px; }
        .table-responsive { max-height: 70vh; }
        .action-col { width: 120px; }
        .btn-sm { padding: .25rem .5rem; font-size: .75rem; }
    </style>
</head>
<body>

<!-- Header centered -->
<div class="text-center my-4">
    <h1 class="mb-3">Northwind Database</h1>
    <div class="dropdown d-inline-block">
        <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            Pilih Tabel
        </button>
        <ul class="dropdown-menu">
            <?php foreach ($allowedTables as $key => $label): ?>
                <li><a class="dropdown-item<?= $active === $key ? ' active' : '' ?>" href="?show=<?= h($key) ?>"><?= h($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="container-fluid px-4">

    <!-- Messages -->
    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-<?= h($msg['type']) ?> alert-dismissible fade show" role="alert">
            <?= h($msg['text']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <?php
    $data = $tablesData[$active];
    $label = $data['label'];
    $columns = $data['columns'];
    $pk = $data['pk'];
    $rows = $data['rows'];
    $total = $data['total'];
    $limit = $data['limit'];
    $page = $data['page'];
    $limitParam = "limit_$active";
    $pageParam = "page_$active";
    ?>

    <!-- Controls -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4><?= h($label) ?> <small class="text-muted">(<?= number_format($total) ?> records)</small></h4>
        <form method="get" class="d-flex align-items-center">
            <input type="hidden" name="show" value="<?= h($active) ?>">
            <label class="me-2 mb-0">Limit:</label>
            <input type="number" name="<?= h($limitParam) ?>" value="<?= h($limit) ?>" min="1" max="200" class="form-control form-control-sm" style="width:80px">
            <button type="submit" class="btn btn-sm btn-outline-primary ms-2">Go</button>
        </form>
    </div>

    <!-- Table -->
    <div class="table-responsive mb-3">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th><?= h($col) ?></th>
                    <?php endforeach; ?>
                    <th class="action-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= count($columns) + 1 ?>" class="text-center text-muted py-4">No data.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                                <td><?= h($row[$col] ?? '') ?></td>
                            <?php endforeach; ?>
                            <td>
                                <!-- Edit Button -->
                                <button class="btn btn-sm btn-outline-primary edit-btn" data-bs-toggle="modal" data-bs-target="#crudModal"
                                    data-action="update"
                                    <?= implode(' ', array_map(fn($c) => "data-$c=\"".h($row[$c] ?? '')."\"", $columns)) ?>>
                                    Edit
                                </button>
                                <!-- Delete Form -->
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="table" value="<?= h($active) ?>">
                                    <input type="hidden" name="<?= h($pk) ?>" value="<?= h($row[$pk]) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?= renderPagination($active, $total, $limit, $page); ?>

    <!-- Add Button -->
    <div class="text-end mb-4">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#crudModal" data-action="create">+ Add New Record</button>
    </div>

</div>

<!-- Modal -->
<div class="modal fade" id="crudModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalLabel">Add New Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="modalAction" value="create">
                    <input type="hidden" name="table" value="<?= h($active) ?>">
                    <div class="row" id="modalFields">
                        <!-- Diisi via JS -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const columns = <?= json_encode($columns) ?>;
const pk = <?= $pk ? json_encode($pk) : 'null' ?>;
const activeTable = <?= json_encode($active) ?>;

function populateModal(action, data = {}) {
    document.getElementById('modalAction').value = action;
    const title = action === 'create' ? 'Add New Record' : 'Edit Record';
    document.getElementById('modalLabel').textContent = title;

    let html = '';
    columns.forEach(col => {
        const value = data[col] || '';
        const isPk = (pk && col === pk);
        const readonly = action === 'update' && isPk ? 'readonly' : '';
        const required = !isPk ? 'required' : '';
        html += `
            <div class="col-md-6 mb-3">
                <label class="form-label">${col}</label>
                <input type="text" name="${col}" class="form-control" value="${value}" ${readonly} ${required}>
            </div>
        `;
    });
    document.getElementById('modalFields').innerHTML = html;
}

// Add button
document.querySelector('.btn-success').addEventListener('click', () => {
    populateModal('create');
});

// Edit buttons
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const data = {};
        columns.forEach(col => {
            data[col] = btn.getAttribute('data-' + col) || '';
        });
        populateModal('update', data);
    });
});
</script>

</body>
</html>