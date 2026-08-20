(function () {
    'use strict';

    var pendingRoots = [];
    var frameId = 0;

    function keepOverrideStylesheetLast() {
        var stylesheet = document.getElementById('mundo-admin-overrides');
        var themeStylesheets;
        var themeStylesheet;

        if (!stylesheet) {
            return;
        }

        themeStylesheets = document.head.querySelectorAll('link[rel="stylesheet"]');
        Array.prototype.some.call(themeStylesheets, function (candidate) {
            if (candidate !== stylesheet && candidate.href.indexOf('/theme/') !== -1) {
                themeStylesheet = candidate;
                return true;
            }
            return false;
        });

        if (
            themeStylesheet &&
            (
                stylesheet.compareDocumentPosition(themeStylesheet) &
                window.Node.DOCUMENT_POSITION_FOLLOWING
            )
        ) {
            themeStylesheet.parentNode.insertBefore(stylesheet, themeStylesheet.nextSibling);
        }
    }

    var iconLabels = [
        ['.anticon-plus', '新增'],
        ['.fa-bars', '打开导航'],
        ['.fa-search', '搜索'],
        ['.fa-sun, .fa-moon', '切换明暗模式'],
        ['.fa-user-circle', '打开账户菜单'],
        ['.fa-times-circle, .anticon-close', '关闭'],
        ['.anticon-edit', '编辑'],
        ['.anticon-copy', '复制'],
        ['.anticon-delete', '删除'],
        ['.anticon-reload', '刷新'],
        ['.anticon-filter', '筛选'],
        ['.anticon-ellipsis, .anticon-more', '更多操作']
    ];

    function matchesOrFind(root, selector) {
        var nodes = [];

        if (root.nodeType !== 1 && root.nodeType !== 9) {
            return nodes;
        }

        if (root.nodeType === 1 && root.matches(selector)) {
            nodes.push(root);
        }

        return nodes.concat(Array.prototype.slice.call(root.querySelectorAll(selector)));
    }

    function hasAccessibleName(element) {
        return Boolean(
            element.getAttribute('aria-label') ||
            element.getAttribute('aria-labelledby') ||
            element.getAttribute('title') ||
            element.textContent.trim()
        );
    }

    function enhanceButtons(root) {
        matchesOrFind(root, 'a:not([href]), a[href="javascript:void(0);"]').forEach(function (anchor) {
            if (!anchor.hasAttribute('role')) {
                anchor.setAttribute('role', 'button');
            }
            if (!anchor.hasAttribute('tabindex')) {
                anchor.setAttribute('tabindex', '0');
            }
        });

        matchesOrFind(root, 'button, .ant-btn, .btn, [role="button"]').forEach(function (control) {
            if (hasAccessibleName(control)) {
                return;
            }

            for (var index = 0; index < iconLabels.length; index += 1) {
                if (control.querySelector(iconLabels[index][0])) {
                    control.setAttribute('aria-label', iconLabels[index][1]);
                    control.setAttribute('title', iconLabels[index][1]);
                    break;
                }
            }
        });
    }

    function enhanceInputs(root) {
        matchesOrFind(root, 'input[placeholder], textarea[placeholder]').forEach(function (input) {
            if (
                !input.getAttribute('aria-label') &&
                !input.getAttribute('aria-labelledby') &&
                !(input.labels && input.labels.length)
            ) {
                input.setAttribute('aria-label', input.getAttribute('placeholder'));
            }
        });

        matchesOrFind(root, '.v2board-auth-box input[placeholder="邮箱"]').forEach(function (input) {
            input.setAttribute('type', 'email');
            input.setAttribute('inputmode', 'email');
            input.setAttribute('autocomplete', 'username');
        });

        matchesOrFind(root, '.v2board-auth-box input[type="password"]').forEach(function (input) {
            input.setAttribute('autocomplete', 'current-password');
        });

        matchesOrFind(root, '.overlay-header input[type="text"]').forEach(function (input) {
            input.setAttribute('type', 'search');
            input.setAttribute('autocomplete', 'off');
        });
    }

    function enhanceNavigation(root) {
        matchesOrFind(root, '.nav-main-link.active').forEach(function (link) {
            link.setAttribute('aria-current', 'page');
        });

        matchesOrFind(root, '.nav-main-link:not(.active)[aria-current="page"]').forEach(function (link) {
            link.removeAttribute('aria-current');
        });

        matchesOrFind(root, '.nav-main-item').forEach(function (item) {
            var trigger = item.firstElementChild;
            if (trigger && trigger.classList.contains('nav-main-link-submenu')) {
                trigger.setAttribute('aria-expanded', item.classList.contains('open') ? 'true' : 'false');
            }
        });

        var accountButton = document.getElementById('page-header-user-dropdown');
        var accountMenu = accountButton && accountButton.parentNode.querySelector('.dropdown-menu');
        if (accountButton && accountMenu) {
            accountButton.setAttribute('aria-expanded', accountMenu.classList.contains('show') ? 'true' : 'false');
        }
    }

    function enhanceLayers(root) {
        matchesOrFind(root, '.ant-drawer-content').forEach(function (drawer) {
            var title = drawer.querySelector('.ant-drawer-title');
            drawer.setAttribute('role', 'dialog');
            drawer.setAttribute('aria-modal', 'true');
            if (title && !drawer.hasAttribute('aria-label')) {
                drawer.setAttribute('aria-label', title.textContent.trim());
            }
        });

        matchesOrFind(root, '.ant-message').forEach(function (message) {
            message.setAttribute('role', 'status');
            message.setAttribute('aria-live', 'polite');
        });

        matchesOrFind(root, '.ant-notification').forEach(function (notification) {
            notification.setAttribute('aria-live', 'polite');
        });
    }

    function enhanceRoot(root) {
        enhanceButtons(root);
        enhanceInputs(root);
        enhanceNavigation(root);
        enhanceLayers(root);

        var main = document.getElementById('main-container');
        if (main && !main.hasAttribute('tabindex')) {
            main.setAttribute('tabindex', '-1');
        }
    }

    function flushEnhancements() {
        frameId = 0;
        var roots = pendingRoots.slice();
        pendingRoots.length = 0;
        roots.forEach(enhanceRoot);
    }

    function scheduleEnhancement(root) {
        if (root && pendingRoots.indexOf(root) === -1) {
            pendingRoots.push(root);
        }
        if (!frameId) {
            frameId = window.requestAnimationFrame(flushEnhancements);
        }
    }

    document.addEventListener('keydown', function (event) {
        var control = event.target.closest && event.target.closest('a[role="button"]');
        if (!control || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }

        event.preventDefault();
        control.click();
    });

    keepOverrideStylesheetLast();
    scheduleEnhancement(document);

    if ('MutationObserver' in window) {
        new MutationObserver(keepOverrideStylesheetLast).observe(document.head, {
            childList: true
        });
    }

    if ('MutationObserver' in window) {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                if (record.type === 'attributes') {
                    scheduleEnhancement(record.target);
                }
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType === 1) {
                        scheduleEnhancement(node);
                    }
                });
            });
        }).observe(document.getElementById('root') || document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class']
        });
    }
}());
