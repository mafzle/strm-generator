<?php
// index.php - STRM生成工具
date_default_timezone_set('Asia/Shanghai');
$baseDir = __DIR__;
$configFile = $baseDir . '/config.json';
$tasksFile = $baseDir . '/tasks.json';
$favsFile = $baseDir . '/favorites.json';
$logsDir = $baseDir . '/logs';
$locksDir = $baseDir . '/locks';

// 初始化必要的目录
foreach ([$logsDir, $locksDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
}

// 默认配置 (已脱敏个人信息，采用 Docker 推荐默认值)
$defaultConfig = [
    'base_url' => 'http://192.168.1.100:5244',
    'openlist_token' => '',
    'openlist_root' => '/d/TV',
    'local_path' => '/video/TV',
    'path_keyword' => '/TV',
    'interval' => 60,
    'thread_isolation' => false,
    'clean_residual' => false,
    'clean_empty' => false,
    'overwrite' => true,
    'clean_whitelist' => '.actors,extrafanart,metadata,theme-music'
];

if (!file_exists($configFile)) file_put_contents($configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$config = json_decode(file_get_contents($configFile), true);

if (!file_exists($tasksFile)) file_put_contents($tasksFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if (!file_exists($favsFile)) file_put_contents($favsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// 确保后台守护进程运行
function checkAndStartDaemon() {
    global $baseDir;
    $lockFile = $baseDir . '/locks/daemon.pid';
    $isRunning = false;
    if (file_exists($lockFile)) {
        $pid = trim(file_get_contents($lockFile));
        if ($pid) {
            exec("ps -p " . escapeshellarg($pid), $out);
            if (count($out) > 1) $isRunning = true;
        }
    }
    if (!$isRunning) {
        $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
        exec("nohup python3 $pyScript --daemon > /dev/null 2>&1 &");
    }
}
checkAndStartDaemon();

// 处理 API 请求
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action == 'save_config') {
        $data = json_decode(file_get_contents('php://input'), true);
        file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => 'success', 'msg' => '配置已保存']);
        exit;
    }

    if ($action == 'preview') {
        $targetUrl = $_POST['target_url'] ?? '';
        if (empty($targetUrl)) { echo json_encode(['status' => 'error', 'msg' => '请输入 URL']); exit; }

        $parsedUrl = parse_url($targetUrl);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '');
        $path = urldecode($parsedUrl['path'] ?? '/');
        if ($path !== '/') $path = rtrim($path, '/');

        $apiUrl = $baseUrl . '/api/fs/list';
        $postData = json_encode(['path' => $path, 'password' => '', 'page' => 1, 'per_page' => 0, 'refresh' => false]);
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $headers = ['Content-Type: application/json'];
        if (!empty($config['openlist_token'])) $headers[] = 'Authorization: ' . $config['openlist_token'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $response = curl_exec($ch);
        curl_close($ch);

        $resData = json_decode($response, true);
        if (!$resData || $resData['code'] !== 200) {
            echo json_encode(['status' => 'error', 'msg' => "获取失败 (API返回: " . ($resData['message'] ?? '未知错误') . ")"]);
            exit;
        }

        $list = [];
        foreach ($resData['data']['content'] ?? [] as $file) {
            $isDir = $file['is_dir'];
            $name = $file['name'];
            $fullRemotePath = rtrim($path, '/') . '/' . $name;
            $keywordIndex = strpos($fullRemotePath, $config['path_keyword']);
            $suffix = $keywordIndex !== false ? substr($fullRemotePath, $keywordIndex + strlen($config['path_keyword'])) : $fullRemotePath;
            $localFilePath = rtrim($config['local_path'], '/') . $suffix;
            if (!$isDir) {
                $info = pathinfo($localFilePath);
                $localFilePath = $info['dirname'] . '/' . $info['filename'] . '.strm';
            }
            $list[] = [ 'name' => $name, 'is_dir' => $isDir, 'remote_path' => $fullRemotePath, 'exists' => file_exists($localFilePath) ? '是' : '否' ];
        }
        echo json_encode(['status' => 'success', 'data' => $list]);
        exit;
    }

    if ($action == 'generate_realtime') {
        $targetUrl = $_POST['target_url'] ?? '';
        if (empty($targetUrl)) { echo json_encode(['status' => 'error', 'msg' => '请输入 URL']); exit; }

        $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
        $urlArg = escapeshellarg($targetUrl);
        exec("nohup python3 $pyScript --action realtime --url $urlArg > /dev/null 2>&1 &");
        echo json_encode(['status' => 'success', 'msg' => '实时任务已在后台启动！']);
        exit;
    }

    // --- 状态获取辅助函数 ---
    function attachRuntimeState(&$list, $locksDir) {
        foreach ($list as &$t) {
            $t['runtime_state'] = 'none';
            $stateFile = $locksDir . '/' . $t['id'] . '.state';
            if (file_exists($stateFile)) {
                $st = json_decode(file_get_contents($stateFile), true);
                if ($st && isset($st['pid'])) {
                    exec("ps -p " . escapeshellarg($st['pid']), $out);
                    if (count($out) > 1) {
                        $t['runtime_state'] = $st['status'];
                        $t['start_time'] = $st['start_time'] ?? 0;
                    } else {
                        @unlink($stateFile);
                    }
                }
            }
        }
    }

    // --- 常用目录 (Favorites) API ---
    if ($action == 'get_favs') {
        $favs = json_decode(file_get_contents($favsFile), true);
        attachRuntimeState($favs, $locksDir);
        echo json_encode($favs); exit;
    }
    if ($action == 'save_fav') {
        $data = json_decode(file_get_contents('php://input'), true);
        $favs = json_decode(file_get_contents($favsFile), true);
        $isEdit = false;
        foreach ($favs as &$t) {
            if ($t['id'] === $data['id']) { $t = array_merge($t, $data); $isEdit = true; break; }
        }
        if (!$isEdit) { $data['last_run'] = 0; $favs[] = $data; }
        file_put_contents($favsFile, json_encode($favs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if (isset($_GET['run_now']) && $_GET['run_now'] == '1') {
            $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
            $idArg = escapeshellarg($data['id']);
            exec("nohup python3 $pyScript --action fav --job_id $idArg > /dev/null 2>&1 &");
        }
        echo json_encode(['status' => 'success']); exit;
    }
    if ($action == 'delete_fav') {
        $id = $_POST['id'];
        $favs = json_decode(file_get_contents($favsFile), true);
        $favs = array_values(array_filter($favs, function($f) use ($id) { return $f['id'] !== $id; }));
        file_put_contents($favsFile, json_encode($favs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => 'success']); exit;
    }
    if ($action == 'run_fav_now') {
        $id = $_POST['id'];
        $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
        $idArg = escapeshellarg($id);
        exec("nohup python3 $pyScript --action fav --job_id $idArg > /dev/null 2>&1 &");
        echo json_encode(['status' => 'success']); exit;
    }
    if ($action == 'run_all_favs') {
        $favs = json_decode(file_get_contents($favsFile), true);
        $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
        foreach ($favs as $t) {
            $idArg = escapeshellarg($t['id']);
            exec("nohup python3 $pyScript --action fav --job_id $idArg > /dev/null 2>&1 &");
        }
        echo json_encode(['status' => 'success']); exit;
    }

    // --- 定时任务与日志 API ---
    if ($action == 'get_tasks') {
        $tasks = json_decode(file_get_contents($tasksFile), true);
        attachRuntimeState($tasks, $locksDir);
        echo json_encode($tasks); exit;
    }

    if ($action == 'save_task') {
        $data = json_decode(file_get_contents('php://input'), true);
        $tasks = json_decode(file_get_contents($tasksFile), true);
        $isEdit = false;
        foreach ($tasks as &$t) {
            if ($t['id'] === $data['id']) { $t = array_merge($t, $data); $isEdit = true; break; }
        }
        if (!$isEdit) {
            $data['status'] = 'enabled';
            $data['last_run'] = 0;
            $tasks[] = $data;
        }
        file_put_contents($tasksFile, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if (isset($_GET['run_now']) && $_GET['run_now'] == '1') {
            $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
            $taskId = escapeshellarg($data['id']);
            exec("nohup python3 $pyScript --action task --job_id $taskId > /dev/null 2>&1 &");
        }
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action == 'toggle_task') {
        $id = $_POST['id'];
        $tasks = json_decode(file_get_contents($tasksFile), true);
        foreach ($tasks as &$t) { if ($t['id'] === $id) $t['status'] = ($t['status'] == 'enabled') ? 'disabled' : 'enabled'; }
        file_put_contents($tasksFile, json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action == 'run_task_now') {
        $id = $_POST['id'];
        $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
        $taskId = escapeshellarg($id);
        exec("nohup python3 $pyScript --action task --job_id $taskId > /dev/null 2>&1 &");
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action == 'task_control') {
        $id = $_POST['id'];
        $cmd = $_POST['cmd']; // pause, resume, stop
        file_put_contents($locksDir . '/' . $id . '.ctrl', $cmd);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action == 'run_all_tasks') {
        $tasks = json_decode(file_get_contents($tasksFile), true);
        $pyScript = escapeshellarg($baseDir . '/strm_generator.py');
        foreach ($tasks as $t) {
            if ($t['status'] == 'enabled') {
                $taskId = escapeshellarg($t['id']);
                exec("nohup python3 $pyScript --action task --job_id $taskId > /dev/null 2>&1 &");
            }
        }
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action == 'get_logs') {
        $tasks = json_decode(file_get_contents($tasksFile), true);
        $favs = json_decode(file_get_contents($favsFile), true);
        $tabs = [
            ['id' => 'system', 'name' => '系统日志'],
            ['id' => 'realtime', 'name' => '实时任务'],
            ['id' => 'favorites', 'name' => '常用目录']
        ];
        foreach ($tasks as $t) { $tabs[] = ['id' => $t['id'], 'name' => $t['name']]; }
        
        $currentTab = $_GET['tab_id'] ?? 'system';
        $logPath = $logsDir . '/' . $currentTab . '.log';
        $content = file_exists($logPath) ? tailCustom($logPath, 100) : "暂无日志...";
        
        echo json_encode(['status' => 'success', 'tabs' => $tabs, 'content' => $content]); exit;
    }

    if ($action == 'clear_log') {
        $tabId = $_POST['tab_id'];
        @unlink($logsDir . '/' . $tabId . '.log');
        echo json_encode(['status' => 'success']); exit;
    }
}

function tailCustom($filepath, $lines = 1) {
    $f = @fopen($filepath, "rb");
    if ($f === false) return false;
    fseek($f, -1, SEEK_END);
    if (fread($f, 1) != "\n") $lines -= 1;
    $output = '';
    $chunklen = 4096;
    while (ftell($f) > 0 && $lines >= 0) {
        $seek = min(ftell($f), $chunklen);
        fseek($f, -$seek, SEEK_CUR);
        $output = ($chunk = fread($f, $seek)) . $output;
        fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
        $lines -= substr_count($chunk, "\n");
    }
    while ($lines++ < 0) { $output = substr($output, strpos($output, "\n") + 1); }
    fclose($f);
    return trim($output);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>STRM生成工具</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; padding: 20px; background-color: #f5f7fa; color: #333; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto 20px auto; }
        .header h1 { margin: 0; font-size: 24px; color: #1890ff; }
        .header-btns button { padding: 8px 15px; background: #fff; border: 1px solid #d9d9d9; border-radius: 4px; cursor: pointer; margin-left: 10px; font-weight: bold;}
        .header-btns button:hover { color: #1890ff; border-color: #1890ff; }
        
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .action-bar { margin: 20px 0; display: flex; align-items: center; gap: 10px; }
        .action-bar input { flex: 1; padding: 10px; border: 1px solid #1890ff; border-radius: 4px; font-size: 15px; }
        
        button.btn-primary { background: #1890ff; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        button.btn-primary:hover { background: #40a9ff; }
        button.btn-success { background: #52c41a; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        button.btn-success:hover { background: #73d13d; }
        button.btn-warning { background: #faad14; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        button.btn-danger { background: #ff4d4f; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        button { padding: 6px 12px; background: #fff; border: 1px solid #d9d9d9; border-radius: 4px; cursor: pointer; }
        button:hover { background: #f5f5f5; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px; }
        th, td { border: 1px solid #f0f0f0; padding: 10px; text-align: left; }
        th { background: #fafafa; }
        
        /* 模态框样式 & 精确控制 Z-Index */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        #configModal { z-index: 1010; }
        #favModal { z-index: 1020; }
        #cronModal { z-index: 1030; }
        #editFavModal, #editCronModal { z-index: 1040; }
        #logModal { z-index: 1050; } /* 日志最高 */

        .modal-content { background: #fff; padding: 25px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 85vh; overflow-y: auto; position: relative; }
        .modal-large { max-width: 1000px; }
        .modal-close { position: absolute; top: 15px; right: 20px; cursor: pointer; font-size: 20px; font-weight: bold; color: #999; }
        .modal-close:hover { color: #333; }
        .modal-title { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px;}

        .form-group { margin-bottom: 15px; display: flex; align-items: center; flex-wrap: wrap;}
        .form-group label { width: 180px; font-weight: bold; font-size: 14px; }
        .form-group input[type="text"], .form-group input[type="number"], .form-group select { flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .checkbox-group { display: flex; gap: 15px; flex: 1; align-items: center; flex-wrap: wrap;}
        
        .tabs { display: flex; border-bottom: 1px solid #ddd; margin-bottom: 15px; overflow-x: auto;}
        .tab { padding: 8px 15px; cursor: pointer; border: 1px solid transparent; border-bottom: none; margin-bottom: -1px; background: #fafafa; white-space: nowrap;}
        .tab.active { background: #fff; border-color: #ddd; border-top-left-radius: 4px; border-top-right-radius: 4px; font-weight: bold; color: #1890ff;}
        .log-viewer { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; height: 450px; overflow-y: auto; font-family: monospace; font-size: 13px; white-space: pre-wrap; word-wrap: break-word;}
    </style>
</head>
<body>

<div class="header">
    <h1>STRM生成工具</h1>
    <div class="header-btns">
        <button onclick="openModal('logModal'); loadLogs('system')">日志</button>
        <button onclick="openModal('configModal')">配置</button>
        <button onclick="openModal('favModal'); loadFavs(); startTaskPolling()">常用目录</button>
        <button onclick="openModal('cronModal'); loadTasks(); startTaskPolling()">定时任务</button>
    </div>
</div>

<div class="container">
    <div class="action-bar">
        <input type="text" id="target_url" placeholder="输入要处理的 OpenList 页面地址，例如: http://192.168.1.100:5244/TV/动漫">
        <button class="btn-primary" onclick="loadPage()" style="padding:10px 15px;">加载页面 (预览)</button>
        <button class="btn-success" onclick="generateRealtime()" style="padding:10px 15px;">生成 STRM (后台执行)</button>
        <button class="btn-warning" onclick="saveAsFav()" style="padding:10px 15px;">保存目录</button>
        <button class="btn-warning" onclick="openCronAddModal()" style="padding:10px 15px;">加入定时任务</button>
    </div>

    <table>
        <thead><tr><th width="30%">文件名</th><th width="50%">OpenList 地址 (相对路径)</th><th width="20%">本地是否存在</th></tr></thead>
        <tbody id="table_body"><tr><td colspan="3" style="text-align: center; color: #999;">请先输入地址并点击“加载页面”</td></tr></tbody>
    </table>
</div>

<!-- 配置 Modal -->
<div id="configModal" class="modal-overlay">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('configModal')">&times;</span>
        <h2 class="modal-title">基础配置</h2>
        <div class="form-group"><label>OpenList_URL (根地址):</label><input type="text" id="cfg_base_url" value="<?php echo htmlspecialchars($config['base_url']); ?>"></div>
        <div class="form-group"><label>OpenList_令牌:</label><input type="text" id="cfg_openlist_token" value="<?php echo htmlspecialchars($config['openlist_token'] ?? ''); ?>"></div>
        <div class="form-group"><label>Strm 的 OpenList 根路径:</label><input type="text" id="cfg_openlist_root" value="<?php echo htmlspecialchars($config['openlist_root']); ?>"></div>
        <div class="form-group"><label>Srtm 的本地储存路径:</label><input type="text" id="cfg_local_path" value="<?php echo htmlspecialchars($config['local_path']); ?>" title="注意：如果使用 Docker 部署，请填写容器内部的映射路径，如 /video"></div>
        <div class="form-group"><label>路径匹配关键词:</label><input type="text" id="cfg_path_keyword" value="<?php echo htmlspecialchars($config['path_keyword']); ?>"></div>
        
        <div class="form-group">
            <label>执行间隔(秒):</label>
            <input type="number" id="cfg_interval" value="<?php echo htmlspecialchars($config['interval']); ?>" style="max-width: 80px;">
            <span style="color: #888; font-size: 12px; margin-left: 10px;">(+5~15秒随机波动防风控)</span>
        </div>
        
        <div class="form-group">
            <label>生成策略 (实时与常用):</label>
            <div class="checkbox-group">
                <label><input type="checkbox" id="cfg_thread_isolation" <?php echo $config['thread_isolation'] ? 'checked' : ''; ?>> 线程隔离</label>
                <label><input type="checkbox" id="cfg_clean_residual" <?php echo $config['clean_residual'] ? 'checked' : ''; ?>> 清理残留</label>
                <label><input type="checkbox" id="cfg_clean_empty" <?php echo ($config['clean_empty'] ?? false) ? 'checked' : ''; ?>> 清理空目录</label>
                <label><input type="checkbox" id="cfg_overwrite" <?php echo $config['overwrite'] ? 'checked' : ''; ?>> 覆盖生成</label>
            </div>
        </div>
        
        <div class="form-group">
            <label>清理白名单:</label>
            <input type="text" id="cfg_clean_whitelist" value="<?php echo htmlspecialchars($config['clean_whitelist'] ?? '.actors,extrafanart,metadata,theme-music'); ?>" placeholder="包含以下关键字的文件夹将被保护免遭清理">
        </div>
        
        <div style="margin-top: 25px; text-align: right;">
            <button class="btn-primary" onclick="saveConfig()">保存配置</button>
        </div>
    </div>
</div>

<!-- 日志 Modal -->
<div id="logModal" class="modal-overlay">
    <div class="modal-content modal-large">
        <span class="modal-close" onclick="closeModal('logModal')">&times;</span>
        <h2 class="modal-title">运行日志</h2>
        <div class="tabs" id="log_tabs"></div>
        <div class="log-viewer" id="log_content">加载中...</div>
        <div style="text-align: right; margin-top: 10px; display: flex; justify-content: flex-end; gap: 10px;">
            <button class="btn-danger" onclick="clearCurrentLog()">清除日志</button>
            <button class="btn-primary" onclick="refreshCurrentLog()">刷新日志</button>
        </div>
    </div>
</div>

<!-- 常用目录 Modal -->
<div id="favModal" class="modal-overlay">
    <div class="modal-content modal-large">
        <span class="modal-close" onclick="closeModal('favModal'); stopTaskPollingIfBothClosed();">&times;</span>
        <h2 class="modal-title">常用目录管理</h2>
        <div style="margin-bottom: 15px;">
            <button class="btn-primary" onclick="openFavAddModal()">增加目录</button>
            <button class="btn-success" onclick="runAllFavs()">全部执行</button>
        </div>
        <table>
            <thead><tr><th width="5%">序号</th><th width="35%">目录</th><th width="25%">上次执行时间</th><th width="10%">快增模式</th><th width="25%">操作</th></tr></thead>
            <tbody id="fav_table_body"></tbody>
        </table>
    </div>
</div>

<!-- 添加/编辑常用目录 Modal -->
<div id="editFavModal" class="modal-overlay">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('editFavModal')">&times;</span>
        <h2 class="modal-title" id="editFavTitle">添加常用目录</h2>
        <input type="hidden" id="fav_id">
        <div class="form-group"><label>路径 (支持粘贴URL):</label><input type="text" id="fav_path" oninput="autoDecodePath(this)"></div>
        <div class="form-group">
            <label>策略:</label>
            <div class="checkbox-group">
                <label><input type="checkbox" id="fav_fast_inc" checked> 快增模式 (跳过已存在目录)</label>
            </div>
        </div>
        <div style="margin-top: 25px; text-align: right;">
            <button class="btn-primary" onclick="saveFav(false)">保存</button>
            <button class="btn-success" onclick="saveFav(true)">保存并执行</button>
        </div>
    </div>
</div>

<!-- 定时任务列表 Modal -->
<div id="cronModal" class="modal-overlay">
    <div class="modal-content modal-large">
        <span class="modal-close" onclick="closeModal('cronModal'); stopTaskPollingIfBothClosed();">&times;</span>
        <h2 class="modal-title">定时任务管理</h2>
        <div style="margin-bottom: 15px;">
            <button class="btn-primary" onclick="openCronAddModal(true)">增加任务</button>
            <button class="btn-success" onclick="runAllTasks()">全部执行</button>
        </div>
        <table>
            <thead><tr><th width="5%">序号</th><th width="15%">任务名</th><th width="20%">任务路径</th><th width="30%">生成频率 / 下次时间</th><th width="10%">状态</th><th width="20%">操作</th></tr></thead>
            <tbody id="cron_table_body"></tbody>
        </table>
    </div>
</div>

<!-- 编辑/添加任务 Modal -->
<div id="editCronModal" class="modal-overlay">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal('editCronModal')">&times;</span>
        <h2 class="modal-title" id="editCronTitle">添加定时任务</h2>
        <input type="hidden" id="cron_id">
        <div class="form-group"><label>任务名:</label><input type="text" id="cron_name"></div>
        <div class="form-group"><label>路径 (支持粘贴URL):</label><input type="text" id="cron_path" oninput="autoDecodePath(this)"></div>
        <div class="form-group">
            <label>独立生成策略:</label>
            <div class="checkbox-group">
                <label><input type="checkbox" id="cron_fast_inc" checked> 快增模式</label>
                <label><input type="checkbox" id="cron_clean"> 清除残留</label>
                <label><input type="checkbox" id="cron_clean_empty"> 清空目录</label>
                <label><input type="checkbox" id="cron_overwrite" checked> 覆盖生成</label>
            </div>
        </div>
        <div class="form-group">
            <label>间隔单位:</label>
            <select id="cron_unit"><option value="day">一天</option><option value="hour">小时</option><option value="minute">分钟</option></select>
        </div>
        <div class="form-group"><label>间隔值:</label><input type="number" id="cron_value" value="1"></div>
        <div style="margin-top: 25px; text-align: right;">
            <button class="btn-primary" onclick="saveCron(false)">保存任务</button>
            <button class="btn-success" onclick="saveCron(true)">立即执行并保存</button>
        </div>
    </div>
</div>

<script>
    let pollInterval = null;

    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    
    function autoDecodePath(input) {
        try { if (input.value.startsWith('http')) { input.value = decodeURIComponent(new URL(input.value).pathname); } } catch(e) {}
    }

    // 配置与预览
    function saveConfig() {
        const data = {
            base_url: document.getElementById('cfg_base_url').value,
            openlist_token: document.getElementById('cfg_openlist_token').value,
            openlist_root: document.getElementById('cfg_openlist_root').value,
            local_path: document.getElementById('cfg_local_path').value,
            path_keyword: document.getElementById('cfg_path_keyword').value,
            interval: parseInt(document.getElementById('cfg_interval').value),
            thread_isolation: document.getElementById('cfg_thread_isolation').checked,
            clean_residual: document.getElementById('cfg_clean_residual').checked,
            clean_empty: document.getElementById('cfg_clean_empty').checked,
            overwrite: document.getElementById('cfg_overwrite').checked,
            clean_whitelist: document.getElementById('cfg_clean_whitelist').value
        };
        fetch('?action=save_config', { method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'} })
            .then(res => res.json()).then(res => { alert(res.msg); closeModal('configModal'); });
    }

    function loadPage() {
        const url = document.getElementById('target_url').value;
        if (!url) return alert('请输入地址');
        document.getElementById('table_body').innerHTML = '<tr><td colspan="3" style="text-align: center;">加载中...</td></tr>';
        const fd = new FormData(); fd.append('target_url', url);
        fetch('?action=preview', { method: 'POST', body: fd }).then(res => res.json()).then(res => {
            if (res.status === 'error') { document.getElementById('table_body').innerHTML = `<tr><td colspan="3" style="color:red;text-align:center;">${res.msg}</td></tr>`; return; }
            let html = '';
            res.data.forEach(item => {
                let nameHtml = item.is_dir ? `<span style="color:#1890ff;font-weight:bold;">📁 ${item.name}</span>` : `📄 ${item.name}`;
                let extColor = item.exists === '是' ? 'green' : 'red';
                html += `<tr><td>${nameHtml}</td><td>${item.remote_path}</td><td style="color:${extColor}">${item.exists}</td></tr>`;
            });
            document.getElementById('table_body').innerHTML = html || '<tr><td colspan="3" style="text-align: center;">无文件</td></tr>';
        });
    }

    function generateRealtime() {
        const url = document.getElementById('target_url').value;
        if (!url) return alert('请输入地址');
        const fd = new FormData(); fd.append('target_url', url);
        fetch('?action=generate_realtime', { method: 'POST', body: fd }).then(res => res.json()).then(res => alert(res.msg));
    }

    // 日志
    let currentLogTab = 'system';
    function loadLogs(tabId) {
        currentLogTab = tabId;
        fetch(`?action=get_logs&tab_id=${tabId}`).then(res => res.json()).then(res => {
            let tabsHtml = '';
            res.tabs.forEach(t => {
                let act = t.id === tabId ? 'active' : '';
                tabsHtml += `<div class="tab ${act}" onclick="loadLogs('${t.id}')">${t.name}</div>`;
            });
            document.getElementById('log_tabs').innerHTML = tabsHtml;
            document.getElementById('log_content').innerText = res.content;
            document.getElementById('log_content').scrollTop = document.getElementById('log_content').scrollHeight;
        });
    }
    function refreshCurrentLog() { loadLogs(currentLogTab); }
    function clearCurrentLog() {
        if (!confirm('确定清除当前标签页的日志吗？')) return;
        const fd = new FormData(); fd.append('tab_id', currentLogTab);
        fetch('?action=clear_log', {method: 'POST', body: fd}).then(() => refreshCurrentLog());
    }

    // 轮询管理
    function startTaskPolling() {
        if (!pollInterval) pollInterval = setInterval(() => { loadTasks(); loadFavs(); }, 1500);
    }
    function stopTaskPollingIfBothClosed() {
        if (document.getElementById('cronModal').style.display !== 'flex' && 
            document.getElementById('favModal').style.display !== 'flex') {
            if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
        }
    }
    function formatElapsed(startSec) {
        let diff = Math.floor(Date.now()/1000) - startSec;
        if (diff < 0) diff = 0;
        let h = String(Math.floor(diff / 3600)).padStart(2, '0');
        let m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
        let s = String(diff % 60).padStart(2, '0');
        return `${h}.${m}.${s}`;
    }
    function getExtractedPath() {
        let val = document.getElementById('target_url').value;
        try { if(val.startsWith('http')) return decodeURIComponent(new URL(val).pathname); } catch(e){}
        return val;
    }

    // --- 常用目录逻辑 ---
    function saveAsFav() {
        let p = getExtractedPath();
        if (!p) return alert("请先输入地址");
        const data = { id: 'fav_' + Date.now(), path: p, fast_inc: true };
        fetch('?action=save_fav', { method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'} })
            .then(() => alert('已保存到常用目录！'));
    }
    function openFavAddModal() {
        document.getElementById('editFavTitle').innerText = '添加常用目录';
        document.getElementById('fav_id').value = 'fav_' + Date.now();
        document.getElementById('fav_path').value = getExtractedPath();
        document.getElementById('fav_fast_inc').checked = true;
        openModal('editFavModal');
    }
    function editFav(id) {
        fetch('?action=get_favs').then(res => res.json()).then(favs => {
            const f = favs.find(x => x.id === id);
            if (f) {
                document.getElementById('editFavTitle').innerText = '编辑常用目录';
                document.getElementById('fav_id').value = f.id;
                document.getElementById('fav_path').value = f.path;
                document.getElementById('fav_fast_inc').checked = f.fast_inc;
                openModal('editFavModal');
            }
        });
    }
    function saveFav(runNow) {
        const data = {
            id: document.getElementById('fav_id').value,
            path: document.getElementById('fav_path').value,
            fast_inc: document.getElementById('fav_fast_inc').checked
        };
        fetch(`?action=save_fav${runNow ? '&run_now=1' : ''}`, { method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'} })
            .then(res => { closeModal('editFavModal'); loadFavs(); });
    }
    function deleteFav(id) {
        if(!confirm('确定删除此目录吗？')) return;
        const fd = new FormData(); fd.append('id', id);
        fetch('?action=delete_fav', {method: 'POST', body: fd}).then(() => loadFavs());
    }
    function loadFavs() {
        if (document.getElementById('favModal').style.display !== 'flex') return;
        fetch('?action=get_favs').then(res => res.json()).then(favs => {
            let html = '';
            favs.forEach((f, index) => {
                let statusStr = f.last_run > 0 ? new Date(f.last_run * 1000).toLocaleString('zh-CN', {hour12: false}) : '从未执行';
                let actionsHtml = '';
                let disableModify = '';
                
                if (f.runtime_state === 'running') {
                    statusStr = `<span style="color:#1890ff;font-weight:bold;">正在执行：${formatElapsed(f.start_time)}</span>`;
                    actionsHtml = `<button class="btn-warning" onclick="controlTask('${f.id}', 'pause')">暂停</button> `;
                    actionsHtml += `<button class="btn-danger" onclick="controlTask('${f.id}', 'stop')">终止</button> `;
                    disableModify = 'disabled';
                } else if (f.runtime_state === 'paused') {
                    statusStr = `<span style="color:#faad14;font-weight:bold;">已暂停：${formatElapsed(f.start_time)}</span>`;
                    actionsHtml = `<button class="btn-success" onclick="controlTask('${f.id}', 'resume')">继续</button> `;
                    actionsHtml += `<button class="btn-danger" onclick="controlTask('${f.id}', 'stop')">终止</button> `;
                    disableModify = 'disabled';
                } else if (f.runtime_state === 'waiting') {
                    statusStr = `<span style="color:#faad14;font-weight:bold;">等待中...</span>`;
                    actionsHtml = `<button class="btn-danger" onclick="controlTask('${f.id}', 'stop')">终止</button> `;
                    disableModify = 'disabled';
                } else {
                    actionsHtml = `<button class="btn-primary" onclick="runFavNow('${f.id}')">执行</button> `;
                }
                
                actionsHtml += `<button ${disableModify} onclick="editFav('${f.id}')">修改</button> `;
                actionsHtml += `<button ${disableModify} onclick="deleteFav('${f.id}')">删除</button>`;

                let fastIncStr = f.fast_inc ? '<span style="color:green">✔ 启用</span>' : '<span style="color:#999">未启用</span>';

                html += `<tr>
                    <td>${index + 1}</td>
                    <td><div style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${f.path}">${f.path}</div></td>
                    <td>${statusStr}</td>
                    <td>${fastIncStr}</td>
                    <td>${actionsHtml}</td>
                </tr>`;
            });
            document.getElementById('fav_table_body').innerHTML = html || '<tr><td colspan="5" style="text-align:center;">暂无目录</td></tr>';
        });
    }
    function runFavNow(id) {
        const fd = new FormData(); fd.append('id', id);
        fetch('?action=run_fav_now', {method: 'POST', body: fd}).then(() => loadFavs());
    }
    function runAllFavs() {
        if(!confirm('确定立刻执行所有常用目录吗？')) return;
        fetch('?action=run_all_favs').then(() => alert('已在后台批量触发！'));
    }

    // --- 定时任务逻辑 ---
    function openCronAddModal() {
        document.getElementById('editCronTitle').innerText = '添加定时任务';
        document.getElementById('cron_id').value = 'task_' + Date.now();
        document.getElementById('cron_name').value = '新任务_' + Math.floor(Math.random()*1000);
        document.getElementById('cron_path').value = getExtractedPath();
        document.getElementById('cron_fast_inc').checked = true;
        document.getElementById('cron_clean').checked = false;
        document.getElementById('cron_clean_empty').checked = false;
        document.getElementById('cron_overwrite').checked = true;
        openModal('editCronModal');
    }
    function saveCron(runNow) {
        const data = {
            id: document.getElementById('cron_id').value,
            name: document.getElementById('cron_name').value,
            path: document.getElementById('cron_path').value,
            fast_inc: document.getElementById('cron_fast_inc').checked,
            clean: document.getElementById('cron_clean').checked,
            clean_empty: document.getElementById('cron_clean_empty').checked,
            overwrite: document.getElementById('cron_overwrite').checked,
            unit: document.getElementById('cron_unit').value,
            value: parseInt(document.getElementById('cron_value').value)
        };
        fetch(`?action=save_task${runNow ? '&run_now=1' : ''}`, { method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'} })
            .then(res => { closeModal('editCronModal'); loadTasks(); });
    }
    function loadTasks() {
        if (document.getElementById('cronModal').style.display !== 'flex') return;
        fetch('?action=get_tasks').then(res => res.json()).then(tasks => {
            let html = '';
            let now = new Date();
            let midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            
            tasks.forEach((t, index) => {
                let nextRunStr = '';
                let stateHtml = '';
                let actionsHtml = '';
                
                let unitStr = t.unit === 'day' ? '天' : (t.unit === 'hour' ? '小时' : '分钟');
                let modeStr = t.fast_inc ? '快增模式' : '普通模式';
                let freqStr = `每 ${t.value} ${unitStr}执行 | ${modeStr}`;

                if (t.status === 'disabled') {
                    nextRunStr = '已禁用';
                    stateHtml = `<label><input type="checkbox" checked onchange="toggleTask('${t.id}')"> 禁用</label>`;
                    actionsHtml = `<button class="btn-primary" onclick="runTaskNow('${t.id}')">执行</button> `;
                } else {
                    stateHtml = `<label><input type="checkbox" onchange="toggleTask('${t.id}')"> 禁用</label>`;
                    
                    if (t.runtime_state === 'running') {
                        nextRunStr = `<span style="color:#1890ff;font-weight:bold;">进行中：${formatElapsed(t.start_time)}</span>`;
                        actionsHtml = `<button class="btn-warning" onclick="controlTask('${t.id}', 'pause')">暂停</button> `;
                        actionsHtml += `<button class="btn-danger" onclick="controlTask('${t.id}', 'stop')">终止</button> `;
                    } else if (t.runtime_state === 'paused') {
                        nextRunStr = `<span style="color:#faad14;font-weight:bold;">已暂停：${formatElapsed(t.start_time)}</span>`;
                        actionsHtml = `<button class="btn-success" onclick="controlTask('${t.id}', 'resume')">继续</button> `;
                        actionsHtml += `<button class="btn-danger" onclick="controlTask('${t.id}', 'stop')">终止</button> `;
                    } else if (t.runtime_state === 'waiting') {
                        nextRunStr = `<span style="color:#faad14;font-weight:bold;">等待中...</span>`;
                        actionsHtml = `<button class="btn-danger" onclick="controlTask('${t.id}', 'stop')">终止</button> `;
                    } else {
                        let periodMs = 0;
                        if (t.unit === 'day') periodMs = t.value * 86400000;
                        if (t.unit === 'hour') periodMs = t.value * 3600000;
                        if (t.unit === 'minute') periodMs = t.value * 60000;
                        let elapsed = now.getTime() - midnight.getTime();
                        let intervals = Math.floor(elapsed / periodMs);
                        let nextTime = new Date(midnight.getTime() + (intervals + 1) * periodMs);
                        
                        nextRunStr = `<span style="color:#888">${freqStr}</span><br/>` + nextTime.toLocaleString('zh-CN', {hour12: false});
                        
                        actionsHtml = `<button class="btn-primary" onclick="runTaskNow('${t.id}')">执行</button> `;
                    }
                }
                
                actionsHtml += `<button onclick="openModal('logModal'); loadLogs('${t.id}')">日志</button> `;
                actionsHtml += `<button onclick="editTask('${t.id}')">配置</button>`;

                html += `<tr>
                    <td>${index + 1}</td>
                    <td>${t.name}</td>
                    <td><div style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${t.path}">${t.path}</div></td>
                    <td>${nextRunStr}</td>
                    <td>${stateHtml}</td>
                    <td>${actionsHtml}</td>
                </tr>`;
            });
            document.getElementById('cron_table_body').innerHTML = html || '<tr><td colspan="6" style="text-align:center;">暂无任务</td></tr>';
        });
    }

    function toggleTask(id) {
        const fd = new FormData(); fd.append('id', id);
        fetch('?action=toggle_task', {method: 'POST', body: fd}).then(() => loadTasks());
    }
    function runTaskNow(id) {
        const fd = new FormData(); fd.append('id', id);
        fetch('?action=run_task_now', {method: 'POST', body: fd}).then(() => loadTasks());
    }
    function controlTask(id, cmd) {
        const fd = new FormData(); fd.append('id', id); fd.append('cmd', cmd);
        fetch('?action=task_control', {method: 'POST', body: fd}).then(() => { loadTasks(); loadFavs(); });
    }
    function runAllTasks() {
        if(!confirm('确定立刻执行所有启用的任务吗？')) return;
        fetch('?action=run_all_tasks').then(() => alert('已在后台批量触发！'));
    }
    function editTask(id) {
        fetch('?action=get_tasks').then(res => res.json()).then(tasks => {
            const t = tasks.find(x => x.id === id);
            if (t) {
                document.getElementById('editCronTitle').innerText = '编辑定时任务';
                document.getElementById('cron_id').value = t.id;
                document.getElementById('cron_name').value = t.name;
                document.getElementById('cron_path').value = t.path;
                document.getElementById('cron_fast_inc').checked = t.fast_inc ?? false;
                document.getElementById('cron_clean').checked = t.clean;
                document.getElementById('cron_clean_empty').checked = t.clean_empty ?? false;
                document.getElementById('cron_overwrite').checked = t.overwrite;
                document.getElementById('cron_unit').value = t.unit;
                document.getElementById('cron_value').value = t.value;
                openModal('editCronModal');
            }
        });
    }
</script>
</body>
</html>