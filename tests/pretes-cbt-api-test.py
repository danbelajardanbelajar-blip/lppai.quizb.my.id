"""Exercise the actual PHP HTTP endpoint with an isolated auth/database fixture."""
import json
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='lppai-cbt-api-') as temp:
    root = Path(temp)
    for folder in ['api', 'includes', 'data']:
        (root / folder).mkdir()
    for name in ['api/pretes-cbt.php', 'includes/pretes-cbt.php', 'includes/pretes-cbt-interruption.php', 'data/pretes-cbt-bank.php']:
        shutil.copy(repo / name, root / name)
    (root / 'includes/auth.php').write_text('''<?php
$_SESSION = [];
if (($_SERVER['HTTP_X_TEST_VERIFIED'] ?? '') === '1') $_SESSION['pretes_cbt_verified_v2_42'] = time();
if (($_SERVER['HTTP_X_TEST_ATTENTION'] ?? '') === '1') $_SESSION['pretes_cbt_attention_42'] = time();
function isLoggedIn() { return ($_SERVER['HTTP_X_TEST_LOGIN'] ?? '') === '1'; }
function verifyCsrf($token) { return $token === 'test-csrf'; }
function getCurrentUser() { return ['id' => 7, 'username' => 'student', 'nim' => '2300007']; }
function getDBConnection() {
    $pdo = new PDO('sqlite:' . __DIR__ . '/state.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS pretes_registrations (id INTEGER PRIMARY KEY, user_id INTEGER)');
    $pdo->exec('INSERT OR IGNORE INTO pretes_registrations VALUES (42, 7), (43, 8)');
    return $pdo;
}
''', encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = (root / 'server.log').open('w')
    process = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log)
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.05)
        def request(payload, login=True, verified=True, attention=False):
            headers = {'Content-Type': 'application/json', 'X-Test-Login': '1' if login else '0', 'X-Test-Verified': '1' if verified else '0', 'X-Test-Attention': '1' if attention else '0'}
            req = urllib.request.Request(f'http://127.0.0.1:{port}/api/pretes-cbt.php', data=json.dumps(payload).encode(), headers=headers)
            try:
                with urllib.request.urlopen(req, timeout=5) as response:
                    return response.status, json.load(response)
            except urllib.error.HTTPError as error:
                return error.code, json.load(error)
        base = {'csrf_token': 'test-csrf', 'registration_id': 42, 'action': 'start'}
        assert request(base, login=False)[0] == 401
        assert request({**base, 'csrf_token': 'forged'})[0] == 403
        assert request(base, verified=False)[0] == 403
        assert request({**base, 'registration_id': 43})[0] == 403
        assert request({**base, 'action': 'unknown'})[0] == 400
        status, data = request(base)
        assert status == 200 and len(data['attempt']['questions']) == 25, data
        assert 'correct_answer' not in json.dumps(data)
        attempt_id = data['attempt']['id']
        assert request(base)[1]['attempt']['id'] == attempt_id
        assert request({**base, 'action': 'save', 'position': 1, 'answer': 'Z'})[0] == 422
        saved = request({**base, 'action': 'save', 'position': 1, 'answer': 'B', 'flagged': True})[1]
        assert saved['attempt']['questions'][0]['answer'] == 'B'
        assert saved['attempt']['questions'][0]['flagged'] is True
        pause = {**base, 'action': 'interrupt', 'position': 2, 'reason': 'page_hidden', 'event_id': 'http_pause_test', 'revision': 0}
        assert request({**pause, 'csrf_token': 'forged'})[0] == 403
        paused = request(pause)[1]['attempt']
        assert paused['paused'] and paused['revision'] == 1
        saved_question = saved['attempt']['questions'][0]
        paused_question = next(q for q in paused['questions'] if q['text'] == saved_question['text'])
        assert paused_question['options'][paused_question['answer']] == saved_question['options']['B']
        assert paused_question['flagged'] is True
        assert request({**base, 'action': 'state'})[1]['attempt']['paused']
        assert request({**base, 'action': 'save', 'position': 2, 'answer': 'C', 'revision': 1})[0] == 409
        assert request({**base, 'action': 'resume'})[0] == 409, 'Fresh camera check required'
        resumed = request({**base, 'action': 'resume'}, attention=True)[1]['attempt']
        assert not resumed['paused']
        assert request({**base, 'action': 'save', 'position': 2, 'answer': 'C', 'revision': 0})[0] == 409
        final = request({**base, 'action': 'submit', 'score': 999, 'revision': 1})[1]
        assert final['attempt']['submitted'] and final['attempt']['score'] in (0, 4)
        assert final['attempt']['questions'] == []
        assert request({**base, 'action': 'submit'})[1]['attempt']['score'] == final['attempt']['score']
        print('PASS: real PHP HTTP API login, CSRF, face gate, ownership, random package, save validation, grading, and repeated submission.')
    finally:
        process.terminate()
        process.wait(timeout=5)
        log.close()
