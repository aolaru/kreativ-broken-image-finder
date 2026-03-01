jQuery(function ($) {
    let running = false;
    let total = 0;
    let pointer = 0;

    $('#kbif-start-scan').on('click', function (e) {
        e.preventDefault();

        if (running) {
            return;
        }
        running = true;

        $('#kbif-progress-wrapper').show();
        $('#kbif-progress-bar').css('width', '0%');
        $('#kbif-progress-text').text('Preparing scan...');

        startScan();
    });

    function startScan() {
        $.post(KBIF_Ajax.ajax_url, {
            action: 'kbif_scan_step',
            step: 'init',
            nonce: KBIF_Ajax.nonce
        }).done(function (response) {
            if (!response || !response.success) {
                alert(response && response.data ? response.data : 'Error starting scan.');
                running = false;
                return;
            }

            total = response.data.total || 0;
            pointer = 0;

            if (total === 0) {
                $('#kbif-progress-text').text('No posts found to scan.');
                running = false;
                return;
            }

            scanNext();
        }).fail(function () {
            alert('AJAX error while starting the scan.');
            running = false;
        });
    }

    function scanNext() {
        $.post(KBIF_Ajax.ajax_url, {
            action: 'kbif_scan_step',
            step: 'process',
            pointer: pointer,
            nonce: KBIF_Ajax.nonce
        }).done(function (response) {
            if (!response || !response.success) {
                alert(response && response.data ? response.data : 'Error scanning posts.');
                running = false;
                return;
            }

            const data = response.data;
            pointer = data.pointer || pointer;
            total = data.total || total;

            if (total > 0) {
                let percent = Math.round((pointer / total) * 100);
                if (percent > 100) {
                    percent = 100;
                }
                $('#kbif-progress-bar').css('width', percent + '%');
                $('#kbif-progress-text').text('Scanning ' + pointer + '/' + total + ' posts...');
            }

            if (data.finished) {
                finishScan();
            } else {
                // Slight delay to avoid hammering server.
                setTimeout(scanNext, 200);
            }
        }).fail(function () {
            alert('AJAX error during scan.');
            running = false;
        });
    }

    function finishScan() {
        $('#kbif-progress-text').text('Finishing and saving results...');

        $.post(KBIF_Ajax.ajax_url, {
            action: 'kbif_scan_step',
            step: 'finish',
            nonce: KBIF_Ajax.nonce
        }).always(function () {
            // Reload page with flag so we can show a notice.
            const url = new URL(window.location.href);
            url.searchParams.set('kbif_scanned', '1');
            window.location.href = url.toString();
        });
    }
});
