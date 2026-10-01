(function () {
    'use strict';
    // Umi appends its selected theme after initial HTML styles. Maintain one
    // final override layer without interfering with Dark Reader's style nodes.
    function keepStylesheetLast() {
        var stylesheet = document.getElementById('mundo-user-overrides');
        if (!stylesheet) return;
        Array.prototype.forEach.call(document.head.querySelectorAll('link[rel="stylesheet"]'), function (candidate) {
            if (candidate === stylesheet || candidate.href.indexOf('/assets/theme/') === -1) return;
            var version = window.settings && window.settings.ui_version;
            if (version) {
                var url = new URL(candidate.href, document.baseURI);
                if (url.searchParams.get('v') !== version) {
                    url.searchParams.set('v', version);
                    candidate.href = url.href;
                }
            }
            if (stylesheet.compareDocumentPosition(candidate) & window.Node.DOCUMENT_POSITION_FOLLOWING) {
                candidate.parentNode.insertBefore(stylesheet, candidate.nextSibling);
            }
        });
    }
    keepStylesheetLast();
    if ('MutationObserver' in window) {
        new MutationObserver(keepStylesheetLast).observe(document.head, { childList: true });
    }
}());
