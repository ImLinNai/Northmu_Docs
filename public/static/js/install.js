(function () {
    'use strict';

    var form = document.getElementById('installForm');
    if (!form) {
        return;
    }

    var panels = Array.prototype.slice.call(form.querySelectorAll('.install-panel'));
    var stepItems = Array.prototype.slice.call(document.querySelectorAll('#installSteps [data-step]'));
    var stepCounter = document.getElementById('stepCounter');
    var stepPercent = document.getElementById('stepPercent');
    var stepProgress = document.getElementById('stepProgress');
    var envBadge = document.getElementById('envBadge');
    var envAlert = document.getElementById('envAlert');
    var envChecks = document.getElementById('envChecks');
    var envNext = document.getElementById('envNext');
    var envRefresh = document.getElementById('envRefresh');
    var dbResult = document.getElementById('dbResult');
    var dbTest = document.getElementById('dbTest');
    var dbNext = document.getElementById('dbNext');
    var redisEnable = document.getElementById('redisEnable');
    var redisFields = document.getElementById('redisFields');
    var redisResult = document.getElementById('redisResult');
    var redisTest = document.getElementById('redisTest');
    var redisNext = document.getElementById('redisNext');
    var accountResult = document.getElementById('accountResult');
    var deploySummary = document.getElementById('deploySummary');
    var deployStages = document.getElementById('deployStages');
    var deployResult = document.getElementById('deployResult');
    var deployBtn = document.getElementById('deployBtn');
    var deployBack = document.getElementById('deployBack');
    var doneSummary = document.getElementById('doneSummary');
    var tokenInput = form.querySelector('input[name="_token"]');
    var token = tokenInput ? tokenInput.value : '';
    var totalSteps = 7;
    var currentStep = 1;
    var maxReached = 1;
    var envPassed = form.getAttribute('data-env-passed') === '1';
    var dbVerified = '';
    var redisVerified = '';
    var busy = false;
    var deploying = false;
    var finalData = null;
    var deployTimer = null;
    var stages = ['validate', 'database', 'schema', 'data', 'config', 'done'];
    var stageLabels = {
        validate: '校验配置',
        database: '连接数据库',
        schema: '创建数据表',
        data: '写入初始数据',
        config: '生成配置文件',
        done: '完成部署'
    };
    var stageHints = {
        validate: '请返回账号设置或数据库配置，检查填写内容后重试。',
        database: '请确认数据库地址、端口、账号密码与权限，可在第 3 步点击「测试连接」。',
        schema: '创建数据表失败。若数据库中已有同名表，请更换数据库名或清空该数据库后重试。',
        data: '写入初始数据失败，请检查数据库权限与磁盘空间。',
        config: '生成配置文件失败，请检查根目录与 data 目录的写入权限。',
        done: '安装收尾失败，请检查 data 目录权限与 install.lock 文件。'
    };

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function value(name) {
        var el = form.elements[name];
        return el ? String(el.value || '').trim() : '';
    }

    function panelFor(step) {
        for (var i = 0; i < panels.length; i++) {
            if (Number(panels[i].getAttribute('data-step')) === step) {
                return panels[i];
            }
        }
        return null;
    }

    function showResult(el, type, message) {
        if (!el) {
            return;
        }
        var icons = { success: 'ti-circle-check', danger: 'ti-alert-circle', warning: 'ti-alert-triangle', info: 'ti-info-circle' };
        el.className = 'alert alert-' + type + ' mt-3';
        el.innerHTML = '<i class="ti ' + (icons[type] || icons.info) + ' me-1"></i>' + escapeHtml(message);
        el.classList.remove('d-none');
    }

    function hideResult(el) {
        if (!el) {
            return;
        }
        el.classList.add('d-none');
        el.innerHTML = '';
    }

    function setBusy(btn, isBusy, label) {
        if (!btn) {
            return;
        }
        if (isBusy) {
            if (!btn.dataset.originalHtml) {
                btn.dataset.originalHtml = btn.innerHTML;
            }
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>' + escapeHtml(label || '处理中...');
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
                delete btn.dataset.originalHtml;
            }
        }
    }

    function postJSON(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: new FormData(form),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = null;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error(response.ok ? '服务器返回了无法解析的响应，请查看运行日志' : '请求失败（HTTP ' + response.status + '）');
                }
                if (!data || typeof data.code === 'undefined') {
                    throw new Error('请求失败（HTTP ' + response.status + '）');
                }
                return data;
            });
        });
    }

    function renderStepper() {
        stepItems.forEach(function (item) {
            var step = Number(item.getAttribute('data-step'));
            var done = finalData ? true : step < currentStep;
            item.classList.toggle('is-current', step === currentStep && !(finalData && step === 7));
            item.classList.toggle('is-done', done && !(step === currentStep && !finalData));
            item.classList.toggle('is-locked', step > maxReached && !done);
            var dot = item.querySelector('.install-step-dot');
            if (dot) {
                dot.innerHTML = done ? '<i class="ti ti-check"></i>' : String(step);
            }
            if (step === currentStep) {
                item.setAttribute('aria-current', 'step');
            } else {
                item.removeAttribute('aria-current');
            }
        });
        var percent = finalData ? 100 : Math.round(((currentStep - 1) / (totalSteps - 1)) * 100);
        if (stepCounter) {
            stepCounter.textContent = '第 ' + currentStep + ' 步 / 共 ' + totalSteps + ' 步';
        }
        if (stepPercent) {
            stepPercent.textContent = percent + '%';
        }
        if (stepProgress) {
            stepProgress.style.width = percent + '%';
        }
    }

    function go(step) {
        step = Math.max(1, Math.min(totalSteps, step));
        currentStep = step;
        maxReached = Math.max(maxReached, step);
        panels.forEach(function (panel) {
            panel.hidden = Number(panel.getAttribute('data-step')) !== step;
        });
        if (step === 6) {
            renderDeploySummary();
        }
        renderStepper();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function markInvalid(el) {
        if (!el) {
            return;
        }
        el.classList.add('is-invalid');
        el.focus();
    }

    function clearInvalid(panel) {
        if (!panel) {
            return;
        }
        Array.prototype.forEach.call(panel.querySelectorAll('.is-invalid'), function (el) {
            el.classList.remove('is-invalid');
        });
    }

    function validateRequired(panel, names, resultEl, message) {
        clearInvalid(panel);
        for (var i = 0; i < names.length; i++) {
            var el = form.elements[names[i]];
            if (!el || !String(el.value || '').trim()) {
                markInvalid(el);
                showResult(resultEl, 'danger', '请完整填写' + message + '后再继续。');
                return false;
            }
        }
        return true;
    }

    function dbSignature() {
        return [value('db_host'), value('db_port'), value('db_name'), value('db_user'), value('db_pass')].join('|');
    }

    function redisSignature() {
        return [
            redisEnable && redisEnable.checked ? '1' : '0',
            value('redis_host'),
            value('redis_port'),
            value('redis_database'),
            value('redis_prefix'),
            value('redis_ttl'),
            value('redis_password')
        ].join('|');
    }

    function testDatabase(btn) {
        if (!validateRequired(panelFor(3), ['db_host', 'db_port', 'db_name', 'db_user'], dbResult, '数据库连接信息')) {
            return Promise.resolve(false);
        }
        hideResult(dbResult);
        setBusy(btn, true, '测试中...');
        var signature = dbSignature();
        return postJSON('/install/test-db').then(function (data) {
            if (Number(data.code) === 0) {
                dbVerified = signature;
                showResult(dbResult, 'success', data.msg || '数据库连接成功');
                return true;
            }
            dbVerified = '';
            showResult(dbResult, 'danger', data.msg || '数据库连接失败');
            return false;
        }).catch(function (error) {
            dbVerified = '';
            showResult(dbResult, 'danger', error.message);
            return false;
        }).then(function (ok) {
            setBusy(btn, false);
            return ok;
        });
    }

    function testRedis(btn) {
        if (!validateRequired(panelFor(4), ['redis_host', 'redis_port', 'redis_database', 'redis_prefix', 'redis_ttl'], redisResult, 'Redis 连接信息')) {
            return Promise.resolve(false);
        }
        hideResult(redisResult);
        setBusy(btn, true, '测试中...');
        var signature = redisSignature();
        return postJSON('/install/test-redis').then(function (data) {
            if (Number(data.code) === 0) {
                redisVerified = signature;
                showResult(redisResult, 'success', data.msg || 'Redis 连接成功');
                return true;
            }
            redisVerified = '';
            showResult(redisResult, 'danger', data.msg || 'Redis 连接失败');
            return false;
        }).catch(function (error) {
            redisVerified = '';
            showResult(redisResult, 'danger', error.message);
            return false;
        }).then(function (ok) {
            setBusy(btn, false);
            return ok;
        });
    }

    function validateAccount() {
        clearInvalid(panelFor(5));
        var siteName = value('site_name');
        var user = value('admin_user');
        var pass = value('admin_pass');
        var pass2 = value('admin_pass2');
        var byteLength = typeof TextEncoder !== 'undefined' ? new TextEncoder().encode(pass).length : pass.length;

        if (!siteName) {
            markInvalid(form.elements['site_name']);
            return '请填写站点名称';
        }
        if (siteName.length > 50) {
            markInvalid(form.elements['site_name']);
            return '站点名称不能超过 50 个字符';
        }
        if (!/^[A-Za-z0-9_]{3,30}$/.test(user)) {
            markInvalid(form.elements['admin_user']);
            return '管理员账号需为 3-30 位字母、数字或下划线';
        }
        if (byteLength < 6 || byteLength > 64) {
            markInvalid(form.elements['admin_pass']);
            return '管理员密码长度需为 6-64 位';
        }
        if (pass !== pass2) {
            markInvalid(form.elements['admin_pass2']);
            return '两次输入的管理员密码不一致';
        }
        return '';
    }

    function proceedDatabase() {
        if (dbVerified === dbSignature()) {
            go(4);
            return Promise.resolve();
        }
        busy = true;
        return testDatabase(dbNext).then(function (ok) {
            if (ok) {
                go(4);
            }
        }).then(function () {
            busy = false;
        });
    }

    function proceedRedis() {
        if (!redisEnable || !redisEnable.checked) {
            go(5);
            return Promise.resolve();
        }
        if (redisVerified === redisSignature()) {
            go(5);
            return Promise.resolve();
        }
        busy = true;
        return testRedis(redisNext).then(function (ok) {
            if (ok) {
                go(5);
            }
        }).then(function () {
            busy = false;
        });
    }

    function proceedAccount() {
        var error = validateAccount();
        if (error) {
            showResult(accountResult, 'danger', error);
            return;
        }
        hideResult(accountResult);
        go(6);
    }

    function handleNext() {
        if (busy || deploying) {
            return;
        }
        if (currentStep === 1) {
            go(2);
            return;
        }
        if (currentStep === 2) {
            if (!envPassed) {
                showResult(envAlert, 'danger', '环境检测未通过，请解决未通过项后点击「重新检测」。');
                return;
            }
            go(3);
            return;
        }
        if (currentStep === 3) {
            proceedDatabase();
            return;
        }
        if (currentStep === 4) {
            proceedRedis();
            return;
        }
        if (currentStep === 5) {
            proceedAccount();
            return;
        }
        if (currentStep === 6) {
            startDeploy();
        }
    }

    function summaryHtml(rows) {
        return rows.map(function (row) {
            return '<div class="install-summary-row"><span class="install-summary-label">' + escapeHtml(row[0]) +
                '</span><span class="install-summary-value">' + escapeHtml(row[1]) + '</span></div>';
        }).join('');
    }

    function renderDeploySummary() {
        if (!deploySummary) {
            return;
        }
        var rows = [
            ['站点名称', value('site_name') || 'Northmu Docs'],
            ['管理员账号', value('admin_user') || '（未填写）'],
            ['数据库', value('db_host') + ':' + value('db_port') + ' / ' + value('db_name')],
            ['数据库用户', value('db_user') + (value('db_pass') ? '（密码已填写）' : '（无密码）')],
            ['Redis 缓存', redisEnable && redisEnable.checked
                ? ('已启用 · ' + value('redis_host') + ':' + value('redis_port') + ' / DB ' + value('redis_database') + ' · TTL ' + value('redis_ttl') + ' 秒')
                : '未启用（已跳过）']
        ];
        deploySummary.innerHTML = summaryHtml(rows);
    }

    function renderDone(data) {
        if (!doneSummary) {
            return;
        }
        var redis = data.redis || {};
        var db = data.db || {};
        var redisText = Number(redis.enabled) === 1
            ? ('已启用 · ' + (redis.host || '') + ':' + (redis.port || '') + ' / DB ' + (redis.database === undefined ? '' : redis.database))
            : '未启用';
        doneSummary.innerHTML = summaryHtml([
            ['站点名称', data.site_name || ''],
            ['管理员账号', data.admin_user || ''],
            ['系统版本', 'v' + (data.version || '')],
            ['数据库', (db.host || '') + ':' + (db.port || '') + ' / ' + (db.name || '')],
            ['MySQL 版本', db.server_version || ''],
            ['数据表数量', (data.tables === undefined ? '' : data.tables) + ' 张'],
            ['Redis 缓存', redisText],
            ['安装时间', data.installed_at || ''],
            ['部署耗时', (data.duration === undefined ? '' : data.duration) + ' 秒'],
            ['后台地址', '/admin/login']
        ]);
    }

    function stageEl(name) {
        return deployStages ? deployStages.querySelector('[data-stage="' + name + '"]') : null;
    }

    function setStageState(name, state) {
        var el = stageEl(name);
        if (!el) {
            return;
        }
        el.classList.remove('is-pending', 'is-running', 'is-done', 'is-error');
        el.classList.add('is-' + state);
        var icon = el.querySelector('.install-deploy-icon');
        var status = el.querySelector('.install-deploy-status');
        if (icon) {
            if (state === 'running') {
                icon.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
            } else if (state === 'done') {
                icon.innerHTML = '<i class="ti ti-circle-check"></i>';
            } else if (state === 'error') {
                icon.innerHTML = '<i class="ti ti-alert-triangle"></i>';
            } else {
                icon.innerHTML = '<i class="ti ti-clock"></i>';
            }
        }
        if (status) {
            status.textContent = { pending: '等待中', running: '处理中…', done: '完成', error: '失败' }[state] || '等待中';
        }
    }

    function resetStages() {
        stages.forEach(function (name) {
            setStageState(name, 'pending');
        });
    }

    function stopStageAnimation() {
        if (deployTimer) {
            clearInterval(deployTimer);
            deployTimer = null;
        }
    }

    function startStageAnimation() {
        var index = 0;
        setStageState(stages[0], 'running');
        deployTimer = setInterval(function () {
            if (index >= stages.length - 2) {
                stopStageAnimation();
                return;
            }
            setStageState(stages[index], 'done');
            index++;
            setStageState(stages[index], 'running');
        }, 650);
    }

    function failStages(stage, message) {
        var index = stages.indexOf(stage);
        if (index < 0) {
            index = 0;
            for (var i = 0; i < stages.length; i++) {
                if (stageEl(stages[i]) && stageEl(stages[i]).classList.contains('is-running')) {
                    index = i;
                }
            }
        }
        stages.forEach(function (name, i) {
            if (i < index) {
                setStageState(name, 'done');
            } else if (i === index) {
                setStageState(name, 'error');
            } else {
                setStageState(name, 'pending');
            }
        });
        return message;
    }

    function resetDeployButton() {
        deploying = false;
        deployBtn.disabled = false;
        deployBack.disabled = false;
        setBusy(deployBtn, false);
    }

    function startDeploy() {
        if (deploying) {
            return;
        }
        deploying = true;
        busy = true;
        hideResult(deployResult);
        resetStages();
        deployBtn.disabled = true;
        deployBack.disabled = true;
        if (!deployBtn.dataset.originalHtml) {
            deployBtn.dataset.originalHtml = deployBtn.innerHTML;
        }
        deployBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>部署中...';
        startStageAnimation();

        postJSON('/install/deploy').then(function (data) {
            stopStageAnimation();
            if (Number(data.code) === 0 && data.data) {
                finalData = data.data;
                stages.forEach(function (name) {
                    setStageState(name, 'done');
                });
                renderDone(data.data);
                showResult(deployResult, 'success', '部署完成，用时 ' + data.data.duration + ' 秒。');
                busy = false;
                deploying = false;
                setTimeout(function () {
                    go(7);
                }, 400);
                return;
            }
            var stage = data.stage || 'validate';
            failStages(stage, data.msg);
            var hint = stageHints[stage] || '请检查配置后重试。';
            var extra = (stage === 'data' || stage === 'config' || stage === 'done')
                ? ' 若数据库中已写入部分数据，请先清空该数据库再重试。'
                : '';
            showResult(deployResult, 'danger', '部署失败（' + (stageLabels[stage] || '未知阶段') + '）：' + (data.msg || '未知错误') + ' ' + hint + extra);
            resetDeployButton();
            busy = false;
        }).catch(function (error) {
            stopStageAnimation();
            failStages('', error.message);
            showResult(deployResult, 'danger', '部署请求失败：' + error.message + ' 请检查服务状态与网络后重试。');
            resetDeployButton();
            busy = false;
        });
    }

    function renderEnv(checks, canInstall) {
        envPassed = !!canInstall;
        form.setAttribute('data-env-passed', canInstall ? '1' : '0');
        if (envNext) {
            envNext.disabled = !canInstall;
        }
        if (envBadge) {
            envBadge.className = 'badge ' + (canInstall ? 'bg-success-lt text-success' : 'bg-danger-lt text-danger');
            envBadge.textContent = canInstall ? '全部通过' : '存在未通过项';
        }
        if (envAlert) {
            envAlert.className = 'alert ' + (canInstall ? 'alert-success' : 'alert-danger');
            envAlert.innerHTML = canInstall
                ? '<i class="ti ti-circle-check me-1"></i>当前环境已满足安装条件，可以继续下一步。'
                : '<i class="ti ti-alert-circle me-1"></i>存在未通过的环境项，请解决后点击「重新检测」。';
        }
        if (envChecks) {
            envChecks.innerHTML = (checks || []).map(function (check) {
                return '<div class="check-item">' +
                    (check.ok ? '<i class="ti ti-circle-check text-success"></i>' : '<i class="ti ti-circle-x text-danger"></i>') +
                    '<div class="check-label" title="' + escapeHtml(check.tip || '') + '">' + escapeHtml(check.label) + '</div>' +
                    '<div class="check-value">' + escapeHtml(check.value) + '</div>' +
                    '</div>';
            }).join('');
        }
    }

    function syncRedisFields() {
        var enabled = !!(redisEnable && redisEnable.checked);
        if (redisFields) {
            redisFields.classList.toggle('d-none', !enabled);
        }
        if (redisTest) {
            redisTest.disabled = !enabled;
        }
    }

    form.addEventListener('click', function (event) {
        var nextBtn = event.target.closest('[data-install-next]');
        if (nextBtn) {
            event.preventDefault();
            handleNext();
            return;
        }
        var goBtn = event.target.closest('[data-step-go]');
        if (goBtn) {
            event.preventDefault();
            if (deploying) {
                return;
            }
            var target = Number(goBtn.getAttribute('data-step-go'));
            if (currentStep === 4 && target === 5) {
                if (redisEnable) {
                    redisEnable.checked = false;
                }
                syncRedisFields();
            }
            go(target);
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        handleNext();
    });

    form.addEventListener('input', function (event) {
        event.target.classList.remove('is-invalid');
        var name = event.target.name;
        if (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass'].indexOf(name) !== -1) {
            dbVerified = '';
            hideResult(dbResult);
        }
        if (['redis_host', 'redis_port', 'redis_database', 'redis_prefix', 'redis_ttl', 'redis_password'].indexOf(name) !== -1) {
            redisVerified = '';
            hideResult(redisResult);
        }
        if (['site_name', 'admin_user', 'admin_pass', 'admin_pass2'].indexOf(name) !== -1) {
            hideResult(accountResult);
        }
    });

    if (dbTest) {
        dbTest.addEventListener('click', function () {
            if (busy) {
                return;
            }
            busy = true;
            testDatabase(dbTest).then(function () {
                busy = false;
            });
        });
    }

    if (redisTest) {
        redisTest.addEventListener('click', function () {
            if (busy) {
                return;
            }
            busy = true;
            testRedis(redisTest).then(function () {
                busy = false;
            });
        });
    }

    if (redisEnable) {
        redisEnable.addEventListener('change', function () {
            redisVerified = '';
            hideResult(redisResult);
            syncRedisFields();
        });
    }

    if (envRefresh) {
        envRefresh.addEventListener('click', function () {
            if (busy) {
                return;
            }
            busy = true;
            setBusy(envRefresh, true, '检测中...');
            postJSON('/install/check').then(function (data) {
                if (Number(data.code) === 0 && data.data) {
                    renderEnv(data.data.checks, data.data.canInstall);
                } else {
                    showResult(envAlert, 'danger', data.msg || '环境检测失败');
                }
            }).catch(function (error) {
                showResult(envAlert, 'danger', '环境检测失败：' + error.message);
            }).then(function () {
                setBusy(envRefresh, false);
                busy = false;
            });
        });
    }

    if (deployBtn) {
        deployBtn.addEventListener('click', startDeploy);
    }

    window.addEventListener('beforeunload', function (event) {
        if (deploying) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    syncRedisFields();
    renderStepper();
})();
