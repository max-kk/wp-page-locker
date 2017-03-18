// TODO - minify

(function (wpl_locking, $) {

    $(document).ready(function () {
        wpl_locking.init();
    });

    var objectID, objectType, hasLock, rejectionCountdown, rejectionRequestTimeout, lockRequestInProgress = false;

    wpl_locking.init = function () {
        hasLock = wpLockingVars.hasLock;
        objectID = wpLockingVars.objectID;
        objectType = wpLockingVars.objectType;

        initHeartbeat();

        initUI();

        $("#wp-admin-bar-my-account > .ab-item").append('<img src="' + wpLockingVars.iconUrl + '" height="16"/>');

    };

    function lock_request_timedout() {
        $("#wpl-form-lock-request-status").html(wpLockingVars.strings.noResponse);
        $("#wpl-form-lock-request-button").attr("disabled", false).text(wpLockingVars.strings.requestAgain);
        lockRequestInProgress = false;
        rejectionRequestTimeout = true;
        rejectionCountdown = false;
        wp.heartbeat.interval( 35 );
    }

    function initUI() {
        $("#wpl-form-lock-request-button").click(function () {
            var $this = $(this), key;
            $this.text("Request sent");
            $this.attr("disabled", true);
            $("#wpl-form-lock-request-status").html("");
            rejectionRequestTimeout = false;
            lockRequestInProgress = true;
            wp.heartbeat.interval( 5 );
            rejectionCountdown = setTimeout(lock_request_timedout, 120000);
            $.get(ajaxurl, { action: "wpl_lock_request_" + objectType, object_id: objectID })
                .done(function (response) {
                    json = _parseJson(response);
                    $("#wpl-form-lock-request-status").html(json.html + '<span class="spinner is-active"></span>');
                })
                .fail(function (jqxhr, textStatus, error) {
                    var err = textStatus + ', ' + error;
                    $("#wpl-form-lock-request-status").html(wpLockingVars.strings.requestError + ": " + err);
                });
        });

        $("#wpl-form-reject-lock-request-button").click(function () {
            $.get(ajaxurl, { action: "wpl_reject_lock_request_" + objectType, object_id: objectID, object_type: objectType })
                .done(function (response) {
                    json = _parseJson(response);
                    $('#wpl-form-lock-dialog').hide();
                })
                .fail(function (jqxhr, textStatus, error) {
                    var err = textStatus + ', ' + error;
                    $("#wpl-form-lock-request-status").html(wpLockingVars.strings.requestError + ": " + err);
                    $('#wpl-form-lock-dialog').hide();
                });
        });


    }

    function initHeartbeat() {

        wp.heartbeat.interval( 30 );

        $("#wpfooter").append(wpLockingVars.lockUI);

        // todo: refresh nonces

        //var refreshLockKey = 'wpl-form-refresh-lock-' + objectType;
        var refreshLockKey = 'wpl-form-refresh-lock';

        //var requestLockKey = 'wpl-form-request-lock-' + objectType;
        var requestLockKey = 'wpl-form-request-lock';

        $(document).on('heartbeat-send.' + refreshLockKey, function (e, data) {
            var send = {};

            if (!objectID || !$('#wpl-form-lock-dialog').length)
                return;

            if (hasLock == 0)
                return;

            send['objectID'] = objectID;
            send['objectType'] = objectType;

            data[refreshLockKey] = send;
        });

        $(document).on('heartbeat-send.' + requestLockKey, function (e, data) {
            var send = {};

            if (!lockRequestInProgress)
                return data;

            send['objectID'] = objectID;
            send['objectType'] = objectType;

            data[requestLockKey] = send;
        });

        // update the lock or show the dialog if somebody has taken over editing

        $(document).on('heartbeat-tick.' + refreshLockKey, function (e, data) {
            var received, wrap, avatar, details;

            if (data[refreshLockKey]) {
                received = data[refreshLockKey];

                if (received.lock_error || received.lock_request) {
                    details = received.lock_error ? received.lock_error : received.lock_request;
                    wrap = $('#wpl-form-lock-dialog');
                    if (!wrap.length)
                        return;
                    if (!wrap.is(':visible')) {

                        if (details.avatar_src) {
                            avatar = $('<img class="avatar avatar-64 photo" width="64" height="64" />').attr('src', details.avatar_src.replace(/&amp;/g, '&'));
                            wrap.find('div.wpl-form-locked-avatar').empty().append(avatar);
                        }

                        wrap.show().find('.currently-editing').html(details.text);
                        if (received.lock_request) {
                            $("#wpl-form-reject-lock-request-button").show();
                        } else {
                            $("#wpl-form-reject-lock-request-button").hide();
                        }
                        wrap.find('.wp-tab-first').focus();

                    } else {

                        // dialog is already visible so the context is different

                        if (received.lock_error) {
                            if ($("#wpl-form-reject-lock-request-button").is(":visible")) {
                                if (received.lock_error.avatar_src) {
                                    avatar = $('<img class="avatar avatar-64 photo" width="64" height="64" />').attr('src', received.lock_error.avatar_src.replace(/&amp;/g, '&'));
                                    wrap.find('div.wpl-form-locked-avatar').empty().append(avatar);
                                }
                                $("#wpl-form-reject-lock-request-button").hide();
                                wrap.show().find('.currently-editing').text(received.lock_error.text);
                            }
                        } else if (received.lock_request) {
                            $("#wpl-form-lock-request-status").html(received.lock_request.text);
                        }

                    }
                }
            }
        });


        $(document).on('heartbeat-tick.' + requestLockKey, function (e, data) {
            var received, wrap, status;

            if (data[requestLockKey]) {
                received = data[requestLockKey];

                if (received.status) {
                    status = received.status;
                    wrap = $('#wpl-form-lock-dialog');
                    if (!wrap.length)
                        return;

                    if (status != 'pending') {
                        clearTimeout(rejectionCountdown);
                        rejectionCountdown = false;
                        lockRequestInProgress = false
                    }

                    switch (status) {
                        case "granted" :
                            $("#wpl-form-lock-request-status").html(wpLockingVars.strings.gainedControl);
                            $("#wpl-form-take-over-button").show();
                            $("#wpl-form-lock-request-button").hide();
                            hasLock = true;
                            break;
                        case "deleted" :
                            $("#wpl-form-lock-request-button").text(wpLockingVars.strings.requestAgain).attr("disabled", false);
                            $("#wpl-form-lock-request-status").html(wpLockingVars.strings.rejected);
                            break;
                        case "pending" :
                            $("#wpl-form-lock-request-status").html(wpLockingVars.strings.pending + '<span class="spinner is-active"></span>');
                    }

                }
            }
        });
    }

    function _parseJson (data) {
    // == Parse string from comments ==
    // This function allow exclude problems, if debug enabled, and show some notices, etc,
    // and this breaks JSON structure
        if (data === "0") {
            console.log('wpl/parseJson error - server responded: "0"');
            alert('Invalid response, please contact to administrator!');
        }
        try {
            // Get the valid JSON only from the returned string
            if (data.indexOf('<!--WPL_START-->') >= 0)
                data = data.split('<!--WPL_START-->')[1]; // Strip off before after FV_START

            if (data.indexOf('<!--WPL_END-->') >= 0)
                data = data.split('<!--WPL_END-->')[0]; // Strip off anything after FV_END
            // Parse
            var result = jQuery.parseJSON(data);

            //console.log( result.result === 'success' );
            if (result) {
                return result;
            } else {
                throw 'Invalid response';
            }
        }
        catch (err) {
            console.log('fv/parseJson error - server responded: ' + data);

            //alert('Error: ' + err);
        }
    }

}(window.wpl_locking = window.wpl_locking || {}, jQuery));
