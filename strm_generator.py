#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os
import sys
import json
import time
import urllib.parse
import urllib.request
import logging
import argparse
import random
import shutil
from datetime import datetime, timedelta

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, 'config.json')
TASKS_FILE = os.path.join(BASE_DIR, 'tasks.json')
FAVS_FILE = os.path.join(BASE_DIR, 'favorites.json')
LOGS_DIR = os.path.join(BASE_DIR, 'logs')
LOCKS_DIR = os.path.join(BASE_DIR, 'locks')

def get_logger(name, clear=False):
    logger = logging.getLogger(name)
    logger.setLevel(logging.INFO)
    logger.handlers = [] # clear previous
    mode = 'a'
    if clear:
        log_file = os.path.join(LOGS_DIR, f"{name}.log")
        if os.path.exists(log_file):
            # 缓冲机制：如果日志是60秒内刚被其他并行任务清空过的，则追加，防止串号和互相抹除
            if time.time() - os.path.getmtime(log_file) > 60:
                mode = 'w'
        else:
            mode = 'w'
    fh = logging.FileHandler(os.path.join(LOGS_DIR, f"{name}.log"), mode=mode, encoding='utf-8')
    formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
    fh.setFormatter(formatter)
    logger.addHandler(fh)
    return logger

def write_system_log(name, start_dt, end_dt, stats):
    sys_log_path = os.path.join(LOGS_DIR, 'system.log')
    
    start_str = start_dt.strftime('%Y-%m-%d %H.%M.%S')
    end_str = end_dt.strftime('%Y-%m-%d %H.%M.%S')
    
    duration_sec = int((end_dt - start_dt).total_seconds())
    h = duration_sec // 3600
    m = (duration_sec % 3600) // 60
    s = duration_sec % 60
    duration_str = f"历时 {h:02d}.{m:02d}.{s:02d}"
    
    log_line = f"[{name}] {start_str} | {end_str} | {duration_str} | 清理 {stats['cleared_dirs']} 个文件夹 | 清理 {stats['cleared_files']} 个文件 | 修复 {stats['fixed']} 个STRM文件 | 生成 {stats['generated']} 个STRM文件\n"
    
    with open(sys_log_path, 'a', encoding='utf-8') as f:
        f.write(log_line)

def load_json(filepath):
    try:
        with open(filepath, 'r', encoding='utf-8') as f: return json.load(f)
    except: return []

def save_json(filepath, data):
    with open(filepath, 'w', encoding='utf-8') as f: json.dump(data, f, indent=4, ensure_ascii=False)

def get_drive_name(path, keyword):
    try:
        idx = path.find(keyword)
        if idx != -1:
            suffix = path[idx + len(keyword):].strip('/')
            return suffix.split('/')[0] if suffix else 'default'
    except: pass
    return 'default'

def write_state(job_id, state_dict):
    try:
        with open(os.path.join(LOCKS_DIR, f"{job_id}.state"), 'w') as f: json.dump(state_dict, f)
    except: pass

def check_task_control(job_id, state_dict, logger):
    """检测并执行 暂停 / 继续 / 终止 指令"""
    ctrl_file = os.path.join(LOCKS_DIR, f"{job_id}.ctrl")
    cmd = None
    if os.path.exists(ctrl_file):
        try:
            with open(ctrl_file, 'r') as f: cmd = f.read().strip()
            os.remove(ctrl_file)
        except: pass

    if cmd == 'stop':
        logger.info("收到 [终止] 指令，任务即将退出...")
        return False
        
    if cmd == 'pause' or state_dict.get('status') == 'paused':
        if state_dict.get('status') != 'paused':
            state_dict['status'] = 'paused'
            write_state(job_id, state_dict)
            logger.info("收到 [暂停] 指令，任务已挂起等待...")
        
        while True:
            time.sleep(1)
            inner_cmd = None
            if os.path.exists(ctrl_file):
                try:
                    with open(ctrl_file, 'r') as f: inner_cmd = f.read().strip()
                    os.remove(ctrl_file)
                except: pass
                
            if inner_cmd == 'stop':
                logger.info("挂起中收到 [终止] 指令，直接退出...")
                return False
            if inner_cmd == 'resume':
                state_dict['status'] = 'running'
                write_state(job_id, state_dict)
                logger.info("收到 [继续] 指令，任务恢复执行...")
                break
    return True

def api_request_list(base_url, path, token, logger):
    url = f"{base_url.rstrip('/')}/api/fs/list"
    api_path = path.rstrip('/') if path != '/' and path else '/'
    data = {"path": api_path, "password": "", "page": 1, "per_page": 0, "refresh": False}
    req = urllib.request.Request(url, data=json.dumps(data).encode('utf-8'))
    req.add_header('Content-Type', 'application/json')
    req.add_header('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
    if token: req.add_header('Authorization', token)
    
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            res_data = json.loads(response.read().decode('utf-8'))
            if res_data.get('code') == 200: return res_data['data'].get('content', [])
            else:
                logger.error(f"API 请求失败: {res_data.get('message')}")
                return []
    except Exception as e:
        logger.error(f"请求 {api_path} 异常: {e}")
        return []

def is_video_file(filename):
    exts = ['.mp4', '.mkv', '.avi', '.ts', '.rmvb', '.flv', '.wmv', '.mov', '.iso']
    return os.path.splitext(filename.lower())[1] in exts

def process_directory(current_path, config, logger, job_id, state_dict, stats, job_type, custom_config=None):
    if not check_task_control(job_id, state_dict, logger): return False

    base_url = config['base_url']
    token = config.get('openlist_token', '')
    local_base_path = config['local_path'].rstrip('/')
    openlist_root = config['openlist_root'].rstrip('/')
    keyword = config['path_keyword']
    
    # 策略隔离判定
    if job_type == 'task':
        overwrite = custom_config.get('overwrite', config.get('overwrite', True))
        clean_residual = custom_config.get('clean', False)
        clean_empty = custom_config.get('clean_empty', False)
        fast_inc = custom_config.get('fast_inc', False)
    elif job_type == 'fav':
        overwrite = config.get('overwrite', True)
        clean_residual = config.get('clean_residual', False)
        clean_empty = config.get('clean_empty', False)
        fast_inc = custom_config.get('fast_inc', False)
    else: # realtime
        overwrite = config.get('overwrite', True)
        clean_residual = config.get('clean_residual', False)
        clean_empty = config.get('clean_empty', False)
        fast_inc = False

    drive_name = get_drive_name(current_path, keyword)
    realtime_lock_file = os.path.join(LOCKS_DIR, f"{drive_name}_realtime.lock")
    
    if config.get('thread_isolation') and job_type != 'realtime':
        while os.path.exists(realtime_lock_file):
            if not check_task_control(job_id, state_dict, logger): return False
            state_dict['status'] = 'waiting'
            write_state(job_id, state_dict)
            time.sleep(3)
        state_dict['status'] = 'running'
        write_state(job_id, state_dict)

    logger.info(f"正在扫描: {current_path}")
    items = api_request_list(base_url, current_path, token, logger)
    if not check_task_control(job_id, state_dict, logger): return False
    
    keyword_idx = current_path.find(keyword)
    suffix = current_path[keyword_idx + len(keyword):] if keyword_idx != -1 else current_path
    local_dir = local_base_path + suffix
    
    valid_video_basenames = []
    sub_dirs = []
    valid_dir_names = []

    # 1. 甄别目录与生成视频
    for item in items:
        if not check_task_control(job_id, state_dict, logger): return False
        
        if item['is_dir']: 
            sub_dirs.append(item)
            valid_dir_names.append(item['name'])
        elif is_video_file(item['name']):
            item_basename, _ = os.path.splitext(item['name'])
            valid_video_basenames.append(item_basename)
            
            file_suffix = suffix.rstrip('/') + '/' + item['name']
            local_file_path = local_base_path + file_suffix
            strm_path = os.path.splitext(local_file_path)[0] + '.strm'
            encoded_suffix = urllib.parse.quote(file_suffix)
            strm_content_url = f"{base_url}{openlist_root}{encoded_suffix}"
            
            os.makedirs(os.path.dirname(strm_path), exist_ok=True)
            
            need_write = True
            is_fix = False
            is_generate = False
            
            if os.path.exists(strm_path):
                with open(strm_path, 'r', encoding='utf-8') as f: old_content = f.read().strip()
                if old_content != strm_content_url:
                    need_write = True
                    is_fix = True
                else:
                    need_write = overwrite
            else:
                is_generate = True
            
            if need_write:
                try:
                    with open(strm_path, 'w', encoding='utf-8') as f: f.write(strm_content_url)
                    if is_fix:
                        stats['fixed'] += 1
                        logger.info(f"[纠错更新] 成功: {strm_path}")
                    elif is_generate:
                        stats['generated'] += 1
                        logger.info(f"[全新生成] 成功: {strm_path}")
                except Exception as e:
                    logger.error(f"写入失败 {strm_path}: {e}")

    # 2. 残留清理 (含文件和多余的文件夹)
    if clean_residual and os.path.exists(local_dir):
        whitelist_str = config.get('clean_whitelist', '.actors,extrafanart,metadata,theme-music')
        ignore_keywords = [k.strip().lower() for k in whitelist_str.split(',') if k.strip()]
        
        for local_f in os.listdir(local_dir):
            if not check_task_control(job_id, state_dict, logger): return False
            local_full_path = os.path.join(local_dir, local_f)
            
            # 清理多余文件夹 (本地存在，但 OpenList 已经删除)
            if os.path.isdir(local_full_path):
                if local_f not in valid_dir_names:
                    # 检测是否命中配置里的白名单关键词
                    is_ignored = any(kw in local_f.lower() for kw in ignore_keywords)
                    
                    # 深度检测：如果目录深层包含任何有效的 .strm 文件，则放弃清理该目录以防误删
                    if not is_ignored:
                        for r, d, f in os.walk(local_full_path):
                            if any(file.lower().endswith('.strm') for file in f):
                                is_ignored = True
                                break
                    
                    if is_ignored:
                        logger.info(f"[清理跳过] 命中白名单或内含STRM文件: {local_full_path}")
                    else:
                        try:
                            shutil.rmtree(local_full_path, ignore_errors=True)
                            stats['cleared_dirs'] += 1
                            logger.info(f"[清理残留目录] 删除: {local_full_path}")
                        except: pass
            
            # 清理多余文件
            elif local_f.endswith('.strm'):
                f_base = os.path.splitext(local_f)[0]
                if f_base not in valid_video_basenames:
                    for td in [local_f, f_base + '.nfo', f_base + '-thumb.jpg', f_base + '.jpg']:
                        td_path = os.path.join(local_dir, td)
                        if os.path.exists(td_path):
                            try:
                                os.remove(td_path)
                                stats['cleared_files'] += 1
                                logger.info(f"[清理残留文件] 删除: {td_path}")
                            except: pass

    # 3. 递归与快增模式
    for d in sub_dirs:
        next_path = current_path.rstrip('/') + '/' + d['name']
        next_local_dir = os.path.join(local_dir, d['name'])
        
        # 快增检测: 跳过已经存在的目录
        if fast_inc and os.path.exists(next_local_dir):
            logger.info(f"[快增模式] 目录已存在，跳过扫描内部: {next_path}")
            continue

        base_interval = int(config.get('interval', 60))
        total_delay = base_interval + random.randint(5, 15)
        logger.info(f"发现目录 [{d['name']}], 休眠 {total_delay} 秒准备进入...")
        
        for _ in range(total_delay):
            if not check_task_control(job_id, state_dict, logger): return False
            time.sleep(1)
            
        if not process_directory(next_path, config, logger, job_id, state_dict, stats, job_type, custom_config):
            return False
            
    # 4. 回溯清理空目录
    if clean_empty and os.path.exists(local_dir):
        if not os.listdir(local_dir):
            try:
                os.rmdir(local_dir)
                stats['cleared_dirs'] += 1
                logger.info(f"[清理空目录] 删除空壳: {local_dir}")
            except: pass

    return True

def run_job(job_type, job_id, url=None):
    """通用执行入口 (实时/定时任务/常用目录)"""
    config = load_json(CONFIG_FILE)
    
    log_name = job_id
    if job_type == 'realtime': log_name = 'realtime'
    elif job_type == 'fav': log_name = 'favorites'
    
    logger = get_logger(log_name, clear=True)
    
    start_dt = datetime.now()
    stats = {'generated': 0, 'fixed': 0, 'cleared_files': 0, 'cleared_dirs': 0}
    state_dict = {"pid": os.getpid(), "status": "waiting", "start_time": int(time.time())}
    write_state(job_id if job_type != 'realtime' else 'realtime', state_dict)
    
    job_name = "实时任务"
    target_path = "/"
    custom_config = None

    if job_type == 'realtime':
        parsed = urllib.parse.urlparse(url)
        target_path = urllib.parse.unquote(parsed.path) or "/"
    else:
        data_file = TASKS_FILE if job_type == 'task' else FAVS_FILE
        records = load_json(data_file)
        custom_config = next((r for r in records if r['id'] == job_id), None)
        if not custom_config: return
        job_name = custom_config.get('name', f"常用目录({os.path.basename(custom_config['path'])})")
        target_path = custom_config['path']

    logger.info("="*40)
    logger.info(f"启动 {job_name} ({target_path})")

    drive_name = get_drive_name(target_path, config['path_keyword'])
    is_realtime = (job_type == 'realtime')
    lock_file = os.path.join(LOCKS_DIR, f"{drive_name}_realtime.lock" if is_realtime else f"{drive_name}_task.lock")
    my_pid = str(os.getpid())
    
    if config.get('thread_isolation'):
        if is_realtime:
            with open(lock_file, 'w') as f: f.write(my_pid)
            state_dict['status'] = 'running'
        else:
            while True:
                if not check_task_control(job_id, state_dict, logger): return
                if not os.path.exists(lock_file):
                    with open(lock_file, 'w') as f: f.write(my_pid)
                    break
                else:
                    try:
                        with open(lock_file, 'r') as f: lock_pid_str = f.read().strip()
                        if lock_pid_str:
                            os.kill(int(lock_pid_str), 0)
                            logger.info(f"[{drive_name}] 等待其他线程释放锁...")
                            time.sleep(10)
                            continue
                    except: pass
                    with open(lock_file, 'w') as f: f.write(my_pid)
                    break
            state_dict['status'] = 'running'
            
    write_state(job_id if job_type != 'realtime' else 'realtime', state_dict)

    try:
        process_directory(target_path, config, logger, job_id if job_type != 'realtime' else 'realtime', state_dict, stats, job_type, custom_config)
        logger.info(f"[{job_name}] 执行结束！")
    finally:
        write_system_log(job_name, start_dt, datetime.now(), stats)
        
        # 释放线程锁
        if config.get('thread_isolation') and os.path.exists(lock_file):
            try:
                with open(lock_file, 'r') as f: current_lock_pid = f.read().strip()
                if current_lock_pid == my_pid: os.remove(lock_file)
            except: pass
            
        # 更新数据库的上次执行时间
        if not is_realtime:
            data_file = TASKS_FILE if job_type == 'task' else FAVS_FILE
            records = load_json(data_file)
            for r in records:
                if r['id'] == job_id: r['last_run'] = int(time.time())
            save_json(data_file, records)
        
        # 销毁内存状态锁
        state_path = os.path.join(LOCKS_DIR, f"{job_id if job_type != 'realtime' else 'realtime'}.state")
        if os.path.exists(state_path):
            try: os.remove(state_path)
            except: pass

def daemon_loop():
    logger = get_logger('daemon', clear=True)
    with open(os.path.join(LOCKS_DIR, 'daemon.pid'), 'w') as f: f.write(str(os.getpid()))
    logger.info("Cron 守护进程已启动，开始持续监控定时任务...")
    
    while True:
        try:
            tasks = load_json(TASKS_FILE)
            now = datetime.now()
            midnight = now.replace(hour=0, minute=0, second=0, microsecond=0)
            
            for t in tasks:
                if t.get('status') != 'enabled': continue
                
                period_sec = 0
                if t['unit'] == 'day': period_sec = t['value'] * 86400
                elif t['unit'] == 'hour': period_sec = t['value'] * 3600
                elif t['unit'] == 'minute': period_sec = t['value'] * 60
                if period_sec == 0: continue
                
                elapsed_sec = (now - midnight).total_seconds()
                intervals_since_midnight = int(elapsed_sec // period_sec)
                last_scheduled_time = midnight + timedelta(seconds=intervals_since_midnight * period_sec)
                last_run_dt = datetime.fromtimestamp(t.get('last_run', 0))
                
                if last_run_dt < last_scheduled_time:
                    logger.info(f"触发定时任务: {t['name']}")
                    cmd = f"nohup python3 \"{os.path.abspath(__file__)}\" --action task --job_id \"{t['id']}\" > /dev/null 2>&1 &"
                    os.system(cmd)
                    t['last_run'] = int(time.time())
                    save_json(TASKS_FILE, tasks)
                    
        except Exception as e:
            logger.error(f"Daemon error: {e}")
            
        time.sleep(30)

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument('--action', choices=['realtime', 'task', 'fav'])
    parser.add_argument('--url', help='Target URL for realtime')
    parser.add_argument('--job_id', help='Job ID for task or fav run')
    parser.add_argument('--daemon', action='store_true', help='Run as cron daemon')
    
    args = parser.parse_args()
    if args.daemon: daemon_loop()
    elif args.action == 'realtime' and args.url: run_job('realtime', 'realtime', args.url)
    elif args.action in ['task', 'fav'] and args.job_id: run_job(args.action, args.job_id)
