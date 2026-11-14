(function () {
    'use strict';

    var dataEl = document.getElementById('nmAiSummaryConfig');
    if (!dataEl) return;

    var config = {};
    try {
        config = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        return;
    }
    if (!config.key) return;

    window.tianliGPT_key = config.key;
    window.tianliGPT_postSelector = config.postSelector || '.article-body';
    if (config.postURL) window.tianliGPT_postURL = config.postURL;
    if (config.blacklist) window.tianliGPT_blacklist = config.blacklist;
    window.tianliGPT_wordLimit = config.wordLimit || 1000;
    window.tianliGPT_typingAnimate = !!config.typingAnimate;
    if (config.title) window.tianliGPT_Title = config.title;
    if (config.name) window.tianliGPT_Name = config.name;
    if (config.injectDom) window.tianliGPT_injectDom = config.injectDom;
    window.tianliGPT_BeginningText = typeof config.beginningText === 'string' ? config.beginningText : '';
    window.tianliGPT_loadingText = !!config.loadingText;
    window.tianliGPT_theme = config.theme || 'default';

    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'https://ai.zhheo.com/static/public/tianli_gpt.min.css';
    document.head.appendChild(css);

    var src = 'https://ai.zhheo.com/static/public/tianli_gpt.min.js';

    // 组件在 DOMContentLoaded 时启动，解析期同步写入可确保事件注册成功
    if (document.readyState === 'loading') {
        document.write('<script src="' + src + '"><\/script>');
        return;
    }

    var script = document.createElement('script');
    script.src = src;
    script.onload = function () {
        if (window.tianliGPT && typeof window.tianliGPT.checkURLAndRun === 'function') {
            window.tianliGPT.checkURLAndRun();
        }
    };
    document.body.appendChild(script);
})();
